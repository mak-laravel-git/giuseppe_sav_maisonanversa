<?php

namespace App\Services\Accounts;

use App\Enums\EditionPieceStatus;
use App\Enums\RoleEnum;
use App\Exceptions\AccountResetException;
use App\Models\Club;
use App\Models\CommunityComment;
use App\Models\CommunityEvent;
use App\Models\CommunityPost;
use App\Models\CommunitySession;
use App\Models\Product;
use App\Models\User;
use App\Services\Edition\EditionInventory;
use App\Services\FoundingCircle\FoundingCircleRegisterSnapshot;
use App\Services\Stripe\CheckoutSessionExpirer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Applies an account reset. Everything in the database happens in one
 * transaction that is checked before it commits; files, Stripe and caches are
 * only touched afterwards.
 */
class AccountResetExecutor
{
    private const CHUNK = 500;

    /** Written to the backup file in full before anything changes. */
    private const BACKUP_TABLES = [
        'users', 'model_has_roles', 'model_has_permissions', 'notifications', 'subscriptions', 'subscription_items',
        'orders', 'payments', 'order_status_events', 'order_returns', 'return_items', 'return_photos', 'return_events',
        'products', 'edition_pieces', 'founding_circle_register', 'founding_circle_claims',
        'clubs', 'community_sessions', 'community_session_participants', 'community_session_messages',
        'community_session_message_attachments', 'community_session_scores', 'community_session_chat_reads',
        'community_events', 'event_rsvps', 'community_posts', 'community_comments', 'community_likes',
        'community_reports', 'community_post_hides', 'translations', 'app_notifications', 'user_notifications',
        'newsletter_subscribers', 'inquiries', 'monitoring_restarts',
    ];

    /** A reset must never add or remove a row here. */
    private const UNCHANGED_TABLES = [
        'products', 'product_sections', 'product_section_items', 'product_faqs', 'edition_pieces', 'opening_offers',
        'newsletter_subscribers', 'inquiries', 'journal_articles', 'faqs', 'dressing_items', 'legal_pages',
        'home_heroes', 'story_pages', 'seo_metas', 'site_settings', 'commerce_settings', 'roles', 'permissions',
        'role_has_permissions', 'app_notifications', 'posts', 'attachments', 'monitoring_restarts',
    ];

    /** Must end up empty: every order and every event is removed. */
    private const EMPTIED_TABLES = [
        'orders', 'payments', 'order_status_events', 'order_returns', 'return_items', 'return_photos',
        'return_events', 'community_events', 'event_rsvps',
    ];

    public function __construct(
        private AccountResetPlanner $planner,
        private EditionInventory $inventory,
        private FoundingCircleRegisterSnapshot $register,
        private CheckoutSessionExpirer $checkoutSessions,
    ) {}

    /**
     * Apply the reviewed plan.
     *
     * @return array{plan: AccountResetPlan, backup: string, problems: list<string>}
     *
     * @throws AccountResetException when the data no longer matches the reviewed code, or a check fails. Nothing is changed then.
     */
    public function execute(User $heir, string $reviewedCode): array
    {
        [$plan, $backup] = DB::transaction(function () use ($heir, $reviewedCode): array {
            $this->lock();

            $plan = $this->planner->build($heir);

            if (! hash_equals($plan->code(), $reviewedCode)) {
                throw new AccountResetException('The data changed since the dry run. Run the dry run again and review it. Nothing was changed.');
            }

            $backup = $this->backup();
            $expected = $this->expectedCounts($plan);

            $this->apply($plan);
            $this->verify($plan, $expected);

            return [$plan, $backup];
        });

        return [
            'plan' => $plan,
            'backup' => $backup,
            'problems' => $this->finish($plan),
        ];
    }

    private function lock(): void
    {
        foreach (['users', 'orders', 'edition_pieces', 'products', 'founding_circle_register'] as $table) {
            DB::table($table)->lockForUpdate()->pluck('id');
        }
    }

    /**
     * @return string Path of the backup file on the private local disk.
     */
    private function backup(): string
    {
        $disk = Storage::disk('local');
        $path = 'account-reset/'.now()->format('Y-m-d_His').'.json';

        $disk->makeDirectory('account-reset');
        $handle = fopen($disk->path($path), 'wb');

        if ($handle === false) {
            throw new AccountResetException('The backup file could not be created. Nothing was changed.');
        }

        try {
            $this->write($handle, '{');

            foreach (self::BACKUP_TABLES as $index => $table) {
                $this->write($handle, ($index === 0 ? '' : ',')."\n".json_encode($table).':[');
                $separator = '';

                foreach (DB::table($table)->cursor() as $row) {
                    $this->write($handle, $separator."\n".json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
                    $separator = ',';
                }

                $this->write($handle, ']');
            }

            $this->write($handle, "\n}\n");
        } finally {
            fclose($handle);
        }

        @chmod($disk->path($path), 0600);

        return $path;
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $contents): void
    {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new AccountResetException('The backup file could not be written (disk full?). Nothing was changed.');
        }
    }

    /**
     * @return array<string, int>
     */
    private function expectedCounts(AccountResetPlan $plan): array
    {
        $expected = [];

        foreach (self::UNCHANGED_TABLES as $table) {
            $expected[$table] = DB::table($table)->count();
        }

        foreach (self::EMPTIED_TABLES as $table) {
            $expected[$table] = 0;
        }

        $removed = [
            'clubs' => count($plan->clubs),
            'community_sessions' => count($plan->sessions),
            'community_posts' => count($plan->posts),
            'community_comments' => count($plan->commentIds),
            'community_session_messages' => count($plan->messageIds),
            'community_session_scores' => count($plan->scoreIds),
            'founding_circle_register' => count($plan->registerEntries),
            'founding_circle_claims' => count($plan->claimIds),
        ];

        foreach ($removed as $table => $count) {
            $expected[$table] = DB::table($table)->count() - $count;
        }

        $expected['users'] = count($plan->keptUsers);

        return $expected;
    }

    private function apply(AccountResetPlan $plan): void
    {
        // Admin content changes hands first, or removing the account would take it along.
        $this->handOver($plan);

        $this->deleteRows('founding_circle_register', 'id', array_column($plan->registerEntries, 'id'));

        foreach (User::query()->whereKey($plan->roleRemovals)->get() as $user) {
            if ($user->isFoundingCircle()) {
                $user->removeRole(RoleEnum::FOUNDING_CIRCLE->value);
            }
        }

        $this->releaseEditions($plan);

        foreach ($plan->stock as $change) {
            DB::table('products')->where('id', $change['product_id'])->update(['stock_quantity' => $change['to']]);
        }

        $this->deleteRows('founding_circle_claims', 'id', $plan->claimIds);

        // Payments, status history and returns follow through their foreign keys.
        $this->deleteRows('orders', 'id', array_column($plan->orders, 'id'));

        $this->deleteModels(CommunitySession::class, array_column($plan->sessions, 'id'));
        $this->deleteRows('community_session_messages', 'id', $plan->messageIds);
        $this->deleteRows('community_session_scores', 'id', $plan->scoreIds);

        // Comments before posts, so their translations are removed too.
        $this->deleteModels(CommunityComment::class, $plan->commentIds);
        $this->deleteModels(CommunityPost::class, array_column($plan->posts, 'id'));
        $this->deleteModels(CommunityEvent::class, array_column($plan->events, 'id'));
        $this->deleteModels(Club::class, array_column($plan->clubs, 'id'));

        $this->deleteAccounts($plan);
    }

    private function handOver(AccountResetPlan $plan): void
    {
        foreach ($plan->handover as $change) {
            foreach (array_chunk($change['ids'], self::CHUNK) as $ids) {
                DB::table($change['table'])->whereIn('id', $ids)->update([$change['column'] => $plan->heirId]);
            }
        }

        foreach ($plan->hostSeats as $seat) {
            $row = DB::table('community_session_participants')
                ->where('community_session_id', $seat['session_id'])
                ->where('user_id', $seat['from']);

            if ($seat['action'] === 'transfer') {
                $row->update(['user_id' => $plan->heirId]);
            } else {
                $row->delete();
            }
        }

        foreach ($plan->scoreTeams as $teams) {
            DB::table('community_session_scores')->where('id', $teams['score_id'])->update([
                'team_a' => json_encode($teams['team_a'], JSON_THROW_ON_ERROR),
                'team_b' => json_encode($teams['team_b'], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private function releaseEditions(AccountResetPlan $plan): void
    {
        foreach ($plan->releasedPieces as $piece) {
            $changes = ['order_id' => null, 'updated_at' => now()];

            // A number that stays taken (kept holder) only loses the order link.
            if ($piece['to'] === EditionPieceStatus::Available->value) {
                $changes += [
                    'status' => EditionPieceStatus::Available->value,
                    'reserved_by_user_id' => null,
                    'reserved_until' => null,
                    'allocated_at' => null,
                ];
            } elseif ($piece['to'] === EditionPieceStatus::Archive->value) {
                $changes += [
                    'reserved_by_user_id' => null,
                    'reserved_until' => null,
                    'allocated_at' => null,
                ];
            }

            DB::table('edition_pieces')->where('id', $piece['id'])->update($changes);
        }
    }

    private function deleteAccounts(AccountResetPlan $plan): void
    {
        $userIds = $plan->userIds();
        $emails = array_column($plan->users, 'email');
        $subscriptionIds = DB::table('subscriptions')->whereIn('user_id', $userIds)->pluck('id')->all();

        $this->deleteRows('subscription_items', 'subscription_id', $subscriptionIds);
        $this->deleteRows('subscriptions', 'id', $subscriptionIds);
        $this->deleteRows('sessions', 'user_id', $userIds);
        $this->deleteRows('password_reset_tokens', 'email', $emails);

        foreach (array_chunk($userIds, self::CHUNK) as $ids) {
            DB::table('notifications')
                ->where('notifiable_type', (new User)->getMorphClass())
                ->whereIn('notifiable_id', $ids)
                ->delete();
        }

        // One model at a time so roles and permissions are detached.
        $this->deleteModels(User::class, $userIds);
    }

    /**
     * @param  list<int|string>  $values
     */
    private function deleteRows(string $table, string $column, array $values): void
    {
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            DB::table($table)->whereIn($column, $chunk)->delete();
        }
    }

    /**
     * Delete through Eloquent so model events clean up translations and roles.
     *
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     */
    private function deleteModels(string $model, array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $model::query()->whereKey($chunk)->get()->each->delete();
        }
    }

    /**
     * Last look before committing. Any surprise rolls the whole reset back.
     *
     * @param  array<string, int>  $expected
     */
    private function verify(AccountResetPlan $plan, array $expected): void
    {
        foreach ($expected as $table => $count) {
            $actual = DB::table($table)->count();

            $this->ensure($actual === $count, "table {$table} has {$actual} row(s), expected {$count}");
        }

        $this->ensure(
            DB::table('users')->whereNotIn('id', $plan->keptUserIds())->doesntExist(),
            'an account other than a super admin remains',
        );

        $this->ensure(
            DB::table('edition_pieces')->whereNotNull('order_id')->doesntExist(),
            'an edition number is still linked to an order',
        );

        foreach ($plan->releasedPieces as $piece) {
            $this->ensure(
                DB::table('edition_pieces')->where('id', $piece['id'])->where('status', $piece['to'])->exists(),
                "edition number {$piece['number']} did not end up {$piece['to']}",
            );
        }

        $this->ensure(
            DB::table('founding_circle_register')
                ->where(fn ($query) => $query->whereNull('user_id')->orWhereNotIn('user_id', $plan->keptUserIds()))
                ->doesntExist(),
            'a register place has no kept account',
        );

        foreach ($plan->handover as $change) {
            $moved = 0;

            foreach (array_chunk($change['ids'], self::CHUNK) as $ids) {
                $moved += DB::table($change['table'])->whereIn('id', $ids)->where($change['column'], $plan->heirId)->count();
            }

            $this->ensure(
                $moved === count($change['ids']),
                "{$change['table']}.{$change['column']} was not fully handed over",
            );
        }
    }

    private function ensure(bool $condition, string $failure): void
    {
        if (! $condition) {
            throw new AccountResetException("Check failed: {$failure}. Everything was rolled back.");
        }
    }

    /**
     * Work that cannot be rolled back, so it runs only after the commit. A
     * failure is reported and never undoes the database.
     *
     * @return list<string>
     */
    private function finish(AccountResetPlan $plan): array
    {
        $problems = [];

        foreach ($plan->files as $file) {
            try {
                $disk = Storage::disk($file['disk']);
                $deleted = $file['directory'] ? $disk->deleteDirectory($file['path']) : $disk->delete($file['path']);

                if (! $deleted) {
                    $problems[] = "Could not delete {$file['disk']}:{$file['path']}";
                }
            } catch (Throwable $exception) {
                $problems[] = "Could not delete {$file['disk']}:{$file['path']} ({$exception->getMessage()})";
            }
        }

        foreach ($plan->stripeSessionIds as $sessionId) {
            $this->checkoutSessions->expire($sessionId);
        }

        try {
            $productIds = array_unique([
                ...array_column($plan->releasedPieces, 'product_id'),
                ...array_column($plan->stock, 'product_id'),
            ]);

            foreach (Product::query()->whereKey($productIds)->get() as $product) {
                $this->inventory->bust($product);
            }

            $this->register->bust();
        } catch (Throwable $exception) {
            $problems[] = "Could not clear the caches ({$exception->getMessage()}). Run php artisan cache:clear.";
        }

        return $problems;
    }
}
