<?php

namespace App\Services\Accounts;

use App\Enums\EditionPieceStatus;
use App\Enums\FoundingCircleClaimStatus;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\RoleEnum;
use App\Enums\UserType;
use App\Exceptions\AccountResetException;
use App\Models\Club;
use App\Models\CommunityEvent;
use App\Models\CommunityPost;
use App\Models\CommunitySession;
use App\Models\CommunitySessionScore;
use App\Models\EditionPiece;
use App\Models\FoundingCircleClaim;
use App\Models\FoundingCircleRegisterEntry;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use App\Services\FoundingCircle\FoundingCircleRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Works out what an account reset would change. Reads only.
 *
 * Super-admins are kept. Every other account goes, with what customers created.
 * What admins created is handed to one super-admin (the heir). Every order and
 * every community event goes, and the edition numbers and stock those orders
 * held come back on sale.
 */
class AccountResetPlanner
{
    public function __construct(private FoundingCircleRegistrar $registrar) {}

    /**
     * The super-admin who receives what admins created.
     *
     * @throws AccountResetException
     */
    public function resolveHeir(?string $email): User
    {
        $superAdmins = $this->superAdmins();

        if ($superAdmins->isEmpty()) {
            throw new AccountResetException('No super admin account exists, so nothing can be kept safely.');
        }

        if ($email !== null && $email !== '') {
            $heir = $superAdmins->first(
                fn (User $user): bool => Str::lower($user->email) === Str::lower(trim($email)),
            );

            return $heir ?? throw new AccountResetException("{$email} is not a super admin account.");
        }

        if ($superAdmins->count() > 1) {
            throw new AccountResetException(
                'There is more than one super admin. Choose who receives the admin content with --heir=<email>: '
                .$superAdmins->pluck('email')->implode(', '),
            );
        }

        return $superAdmins->first();
    }

    public function build(User $heir): AccountResetPlan
    {
        $kept = $this->superAdmins();
        $keptIds = $kept->modelKeys();

        $targets = User::query()->with('roles:id,name')->whereKeyNot($keptIds)->orderBy('id')->get();
        [$admins, $customers] = $targets->partition(
            fn (User $user): bool => $user->isAdmin() || $user->hasAnyRole(UserType::staffRoleValues()),
        );
        $targetIds = $targets->modelKeys();
        $adminIds = $admins->modelKeys();
        $customerIds = $customers->modelKeys();

        $orders = Order::query()->with(['product', 'user:id,email'])->orderBy('id')->get();
        $orderIds = $orders->modelKeys();

        [$register, $keptRegister] = $this->register($keptIds);
        $claims = FoundingCircleClaim::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('user_id', $targetIds)
                ->orWhereIn('order_id', $orderIds))
            ->orderBy('id')
            ->get();

        $pieces = $this->releasedPieces($orders, $targetIds, $register, $keptRegister, $claims);
        $stuck = $this->stuckPieces(array_column($pieces, 'id'), $keptRegister, $keptIds);

        $sessions = CommunitySession::query()
            ->with(['host:id,email', 'club:id,name'])
            ->whereIn('host_id', $customerIds)
            ->orderBy('id')
            ->get();
        $sessionIds = $sessions->modelKeys();

        $clubs = Club::query()->with('submittedBy:id,email')->whereIn('submitted_by_id', $customerIds)->orderBy('id')->get();
        $clubIds = $clubs->modelKeys();

        $events = CommunityEvent::query()->withCount('rsvps')->orderBy('id')->get();

        $posts = CommunityPost::query()->with('author:id,email')->whereIn('author_id', $customerIds)->orderBy('id')->get();
        $postIds = $posts->modelKeys();

        $commentIds = $this->ids(DB::table('community_comments')
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn('author_id', $customerIds)
                ->orWhereIn('community_post_id', $postIds)));
        $messageIds = $this->ids(DB::table('community_session_messages')
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn('community_session_id', $sessionIds)
                ->orWhereIn('user_id', $customerIds)));
        $scoreIds = $this->ids(DB::table('community_session_scores')
            ->where(fn (QueryBuilder $query) => $query
                ->whereIn('community_session_id', $sessionIds)
                ->orWhereIn('submitted_by', $customerIds)));

        $handover = $this->handover($adminIds, [
            'clubs.submitted_by_id' => $clubIds,
            'clubs.approved_by_id' => $clubIds,
            'community_posts.author_id' => $postIds,
            'community_comments.author_id' => $commentIds,
            'community_sessions.host_id' => $sessionIds,
            'community_session_messages.user_id' => $messageIds,
            'community_session_messages.reported_by_id' => $messageIds,
            'community_session_scores.submitted_by' => $scoreIds,
            'community_session_scores.reviewed_by' => $scoreIds,
            'community_reports.reporter_id' => $this->ids(DB::table('community_reports')->whereIn('community_post_id', $postIds)),
            'app_notifications.created_by' => [],
            'founding_circle_claims.reviewed_by_id' => $claims->modelKeys(),
            'monitoring_restarts.operator_id' => [],
        ]);

        [$hostSeats, $scoreTeams] = $this->hostSeats($heir, $adminIds, $scoreIds);

        $transferredSeats = count(array_filter($hostSeats, fn (array $seat): bool => $seat['action'] === 'transfer'));

        return new AccountResetPlan(
            heirId: $heir->id,
            heirEmail: $heir->email,
            keptUsers: $kept->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all(),
            users: $targets->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'kind' => in_array($user->id, $adminIds, true) ? 'admin' : 'customer',
                'roles' => $user->roles->pluck('name')->implode(', '),
            ])->values()->all(),
            orders: $orders->map(fn (Order $order): array => [
                'id' => $order->id,
                'reference' => $order->reference(),
                'email' => $order->email,
                'account' => $order->user?->email ?? 'guest',
                'status' => $order->status->value,
                'amount' => (string) $order->amount,
                'currency' => Str::upper((string) $order->currency),
                'product' => $order->product?->name ?? '-',
                'edition' => $order->edition_number !== null ? (string) $order->edition_number : '-',
            ])->values()->all(),
            releasedPieces: $pieces,
            stuckPieces: $stuck,
            stock: $this->stock($orders),
            registerEntries: $register->map(fn (FoundingCircleRegisterEntry $entry): array => [
                'id' => $entry->id,
                'user_id' => $entry->user_id,
                'name' => (string) $entry->name,
                'number' => (string) ($entry->edition_number ?? '-'),
                'reason' => match (true) {
                    $entry->user_id === null => 'no account',
                    ! in_array($entry->user_id, $keptIds, true) => 'account deleted',
                    default => 'order deleted',
                },
            ])->values()->all(),
            roleRemovals: $register
                ->pluck('user_id')
                ->filter(fn (?int $userId): bool => in_array($userId, $keptIds, true))
                ->values()
                ->all(),
            claimIds: $claims->modelKeys(),
            sessions: $sessions->map(fn (CommunitySession $session): array => [
                'id' => $session->id,
                'host' => $session->host?->email ?? '-',
                'sport' => $session->sport->value,
                'starts_at' => (string) $session->starts_at?->format('Y-m-d H:i'),
                'club' => $session->club?->name ?? '-',
            ])->values()->all(),
            clubs: $clubs->map(fn (Club $club): array => [
                'id' => $club->id,
                'name' => $club->name,
                'city' => (string) $club->city,
                'status' => $club->status->value,
                'submitter' => $club->submittedBy?->email ?? '-',
            ])->values()->all(),
            events: $events->map(fn (CommunityEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => (string) $event->starts_at?->format('Y-m-d H:i'),
                'rsvps' => (int) $event->rsvps_count,
            ])->values()->all(),
            posts: $posts->map(fn (CommunityPost $post): array => [
                'id' => $post->id,
                'author' => $post->author?->email ?? '-',
                'excerpt' => Str::limit(Str::squish((string) $post->content), 60),
            ])->values()->all(),
            commentIds: $commentIds,
            messageIds: $messageIds,
            scoreIds: $scoreIds,
            handover: $handover,
            hostSeats: $hostSeats,
            scoreTeams: $scoreTeams,
            otherRows: $this->otherRows(
                $targets,
                $customerIds,
                $sessionIds,
                $postIds,
                count($claims),
                count($commentIds),
                count($messageIds),
                count($scoreIds),
                $transferredSeats,
            ),
            files: $this->files($targets, $keptIds, $sessionIds, $clubs, $events, $messageIds),
            stripeSessionIds: $orders
                ->filter(fn (Order $order): bool => $order->status === OrderStatus::Incomplete
                    && filled($order->stripe_checkout_session_id))
                ->pluck('stripe_checkout_session_id')
                ->values()
                ->all(),
            warnings: $this->warnings($orders, $sessions, $clubIds, $sessionIds, $adminIds, $commentIds, $messageIds, $scoreIds, $stuck),
        );
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function superAdmins(): EloquentCollection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->where('name', RoleEnum::SUPER_ADMIN->value))
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    private function ids(QueryBuilder $query): array
    {
        return $query->orderBy('id')->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
    }

    /**
     * Register places to remove, and the ones a kept account keeps. A place
     * survives only when a kept account holds it without an order.
     *
     * @param  list<int>  $keptIds
     * @return array{0: EloquentCollection<int, FoundingCircleRegisterEntry>, 1: EloquentCollection<int, FoundingCircleRegisterEntry>}
     */
    private function register(array $keptIds): array
    {
        [$keep, $remove] = FoundingCircleRegisterEntry::query()
            ->with('product')
            ->orderBy('id')
            ->get()
            ->partition(fn (FoundingCircleRegisterEntry $entry): bool => $entry->order_id === null
                && in_array($entry->user_id, $keptIds, true));

        return [$remove->values(), $keep->values()];
    }

    private function pieceFor(FoundingCircleRegisterEntry $entry): ?EditionPiece
    {
        $product = $entry->product ?? Product::founding();

        if ($product === null || $entry->edition_number === null) {
            return null;
        }

        return $this->registrar->findPiece($product, (int) $entry->edition_number);
    }

    /**
     * Every racket that comes back on sale, with why. Archive numbers stay in
     * the archive, and a number a kept account holds without an order stays
     * theirs; both only lose a leftover order link.
     *
     * @param  EloquentCollection<int, Order>  $orders
     * @param  list<int>  $targetIds
     * @param  EloquentCollection<int, FoundingCircleRegisterEntry>  $register
     * @param  EloquentCollection<int, FoundingCircleRegisterEntry>  $keptRegister
     * @param  EloquentCollection<int, FoundingCircleClaim>  $claims
     * @return list<array{id: int, product_id: int, product: string, number: string, from: string, to: string, reason: string}>
     */
    private function releasedPieces(
        EloquentCollection $orders,
        array $targetIds,
        EloquentCollection $register,
        EloquentCollection $keptRegister,
        EloquentCollection $claims,
    ): array {
        $keptPieceIds = $keptRegister
            ->map(fn (FoundingCircleRegisterEntry $entry): ?int => $this->pieceFor($entry)?->id)
            ->filter()
            ->all();

        $reasons = [];

        $linked = EditionPiece::query()
            ->whereNotNull('order_id')
            ->orWhereIn('id', $orders->pluck('edition_piece_id')->filter()->all())
            ->pluck('id');

        foreach ($linked as $id) {
            $reasons[$id] = 'linked to an order';
        }

        foreach (EditionPiece::query()->whereIn('reserved_by_user_id', $targetIds)->pluck('id') as $id) {
            $reasons[$id] ??= 'held by a deleted account';
        }

        $ownedStatuses = [EditionPieceStatus::Allocated, EditionPieceStatus::Returning];

        foreach ($register as $entry) {
            $piece = $this->pieceFor($entry);

            if ($piece !== null && in_array($piece->status, $ownedStatuses, true) && ! in_array($piece->id, $keptPieceIds, true)) {
                $reasons[$piece->id] ??= 'Founding Circle place removed';
            }
        }

        $approved = $claims
            ->filter(fn (FoundingCircleClaim $claim): bool => $claim->status === FoundingCircleClaimStatus::Approved)
            ->pluck('edition_piece_id')
            ->filter()
            ->all();

        foreach (EditionPiece::query()->whereIn('id', $approved)->whereIn('status', $ownedStatuses)->pluck('id') as $id) {
            if (! in_array($id, $keptPieceIds, true)) {
                $reasons[$id] ??= 'approved claim removed';
            }
        }

        return EditionPiece::query()
            ->with('product')
            ->whereIn('id', array_keys($reasons))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->map(function (EditionPiece $piece) use ($keptPieceIds, $reasons): array {
                $stays = $piece->status === EditionPieceStatus::Archive || in_array($piece->id, $keptPieceIds, true);

                return [
                    'id' => $piece->id,
                    'product_id' => $piece->product_id,
                    'product' => $piece->product?->name ?? '-',
                    'number' => (string) $piece->edition_number,
                    'from' => $piece->status->value,
                    'to' => $stays ? $piece->status->value : EditionPieceStatus::Available->value,
                    'reason' => $reasons[$piece->id],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Rackets that are taken although nothing known holds them. Reported for the
     * operator to check by hand, never changed.
     *
     * @param  list<int>  $releasedIds
     * @param  EloquentCollection<int, FoundingCircleRegisterEntry>  $keptRegister
     * @param  list<int>  $keptIds
     * @return list<array{id: int, product: string, number: string, status: string}>
     */
    private function stuckPieces(array $releasedIds, EloquentCollection $keptRegister, array $keptIds): array
    {
        $keptPieceIds = $keptRegister
            ->map(fn (FoundingCircleRegisterEntry $entry): ?int => $this->pieceFor($entry)?->id)
            ->filter()
            ->all();

        return EditionPiece::query()
            ->with('product')
            ->whereIn('status', [EditionPieceStatus::Reserved, EditionPieceStatus::Allocated, EditionPieceStatus::Returning])
            ->whereNotIn('id', [...$releasedIds, ...$keptPieceIds])
            ->where(fn (Builder $query) => $query
                ->whereNull('reserved_by_user_id')
                ->orWhereNotIn('reserved_by_user_id', $keptIds))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->map(fn (EditionPiece $piece): array => [
                'id' => $piece->id,
                'product' => $piece->product?->name ?? '-',
                'number' => (string) $piece->edition_number,
                'status' => $piece->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Stock products get a unit back for each order that still holds one.
     * Canceled, failed and refunded orders returned theirs already.
     *
     * @param  EloquentCollection<int, Order>  $orders
     * @return list<array{product_id: int, product: string, from: int, to: int}>
     */
    private function stock(EloquentCollection $orders): array
    {
        $holding = [OrderStatus::Incomplete, ...OrderStatus::fulfillment()];

        return $orders
            ->filter(fn (Order $order): bool => $order->product !== null
                && $order->product->isSimple()
                && $order->product->stock_quantity !== null
                && in_array($order->status, $holding, true))
            ->groupBy('product_id')
            ->map(function (Collection $productOrders): array {
                $product = $productOrders->first()->product;

                return [
                    'product_id' => $product->id,
                    'product' => $product->name,
                    'from' => (int) $product->stock_quantity,
                    'to' => (int) $product->stock_quantity + $productOrders->count(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /**
     * Rows that move from a deleted admin to the heir. Rows that go away with a
     * deleted parent are left out.
     *
     * @param  list<int>  $adminIds
     * @param  array<string, list<int>>  $columns  "table.column" => ids that will be deleted instead.
     * @return list<array{table: string, column: string, ids: list<int>}>
     */
    private function handover(array $adminIds, array $columns): array
    {
        $handover = [];

        foreach ($columns as $target => $deletedIds) {
            [$table, $column] = explode('.', $target);

            $ids = $this->ids(DB::table($table)->whereIn($column, $adminIds)->whereNotIn('id', $deletedIds));

            if ($ids !== []) {
                $handover[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
            }
        }

        return $handover;
    }

    /**
     * The host also sits in the player list and in the score teams of their own
     * session, so those follow the session to the heir.
     *
     * @param  list<int>  $adminIds
     * @param  list<int>  $deletedScoreIds
     * @return array{0: list<array{session_id: int, from: int, action: string}>, 1: list<array{score_id: int, team_a: list<int>, team_b: list<int>}>}
     */
    private function hostSeats(User $heir, array $adminIds, array $deletedScoreIds): array
    {
        $seats = [];
        $teams = [];

        $sessions = CommunitySession::query()
            ->with(['participantRecords', 'scores'])
            ->whereIn('host_id', $adminIds)
            ->orderBy('id')
            ->get();

        foreach ($sessions as $session) {
            $hostId = (int) $session->host_id;

            if ($session->participantRecords->contains('user_id', $hostId)) {
                $seats[] = [
                    'session_id' => $session->id,
                    'from' => $hostId,
                    'action' => $session->participantRecords->contains('user_id', $heir->id) ? 'drop' : 'transfer',
                ];
            }

            foreach ($session->scores->sortBy('id') as $score) {
                $change = $this->teamsWithHeir($score, $hostId, $heir->id);

                if ($change !== null && ! in_array($score->id, $deletedScoreIds, true)) {
                    $teams[] = $change;
                }
            }
        }

        return [$seats, $teams];
    }

    /**
     * @return array{score_id: int, team_a: list<int>, team_b: list<int>}|null
     */
    private function teamsWithHeir(CommunitySessionScore $score, int $hostId, int $heirId): ?array
    {
        $teamA = array_map(intval(...), array_values((array) $score->team_a));
        $teamB = array_map(intval(...), array_values((array) $score->team_b));
        $players = [...$teamA, ...$teamB];

        if (! in_array($hostId, $players, true) || in_array($heirId, $players, true)) {
            return null;
        }

        $swap = fn (int $id): int => $id === $hostId ? $heirId : $id;

        return [
            'score_id' => $score->id,
            'team_a' => array_map($swap, $teamA),
            'team_b' => array_map($swap, $teamB),
        ];
    }

    /**
     * How many further rows disappear, for the operator to read.
     *
     * @param  EloquentCollection<int, User>  $targets
     * @param  list<int>  $customerIds
     * @param  list<int>  $sessionIds
     * @param  list<int>  $postIds
     * @return array<string, int>
     */
    private function otherRows(
        EloquentCollection $targets,
        array $customerIds,
        array $sessionIds,
        array $postIds,
        int $claims,
        int $comments,
        int $messages,
        int $scores,
        int $transferredSeats,
    ): array {
        $targetIds = $targets->modelKeys();
        $morph = (new User)->getMorphClass();
        $subscriptionIds = DB::table('subscriptions')->whereIn('user_id', $targetIds)->pluck('id');

        return [
            'payments' => DB::table('payments')->count(),
            'order_status_events' => DB::table('order_status_events')->count(),
            'order_returns' => DB::table('order_returns')->count(),
            'return_items' => DB::table('return_items')->count(),
            'return_photos' => DB::table('return_photos')->count(),
            'return_events' => DB::table('return_events')->count(),
            'founding_circle_claims' => $claims,
            'community_comments' => $comments,
            'community_session_messages' => $messages,
            'community_session_scores' => $scores,
            'community_session_participants' => DB::table('community_session_participants')
                ->whereIn('community_session_id', $sessionIds)
                ->orWhereIn('user_id', $targetIds)
                ->count() - $transferredSeats,
            'community_session_chat_reads' => DB::table('community_session_chat_reads')
                ->whereIn('community_session_id', $sessionIds)
                ->orWhereIn('user_id', $targetIds)
                ->count(),
            'community_likes' => DB::table('community_likes')
                ->whereIn('community_post_id', $postIds)
                ->orWhereIn('user_id', $targetIds)
                ->count(),
            'community_post_hides' => DB::table('community_post_hides')
                ->whereIn('community_post_id', $postIds)
                ->orWhereIn('user_id', $targetIds)
                ->count(),
            'community_reports' => DB::table('community_reports')
                ->whereIn('community_post_id', $postIds)
                ->orWhereIn('reporter_id', $customerIds)
                ->count(),
            'event_rsvps' => DB::table('event_rsvps')->count(),
            'user_notifications' => DB::table('user_notifications')->whereIn('user_id', $targetIds)->count(),
            'notifications' => DB::table('notifications')->where('notifiable_type', $morph)->whereIn('notifiable_id', $targetIds)->count(),
            'sessions (logins)' => DB::table('sessions')->whereIn('user_id', $targetIds)->count(),
            'password_reset_tokens' => DB::table('password_reset_tokens')->whereIn('email', $targets->pluck('email')->all())->count(),
            'subscriptions' => $subscriptionIds->count(),
            'subscription_items' => DB::table('subscription_items')->whereIn('subscription_id', $subscriptionIds->all())->count(),
            'model_has_roles' => DB::table('model_has_roles')->where('model_type', $morph)->whereIn('model_id', $targetIds)->count(),
        ];
    }

    /**
     * Uploaded files that belong only to rows being deleted. A file a kept row
     * still points at stays.
     *
     * @param  EloquentCollection<int, User>  $targets
     * @param  list<int>  $keptIds
     * @param  list<int>  $sessionIds
     * @param  EloquentCollection<int, Club>  $clubs
     * @param  EloquentCollection<int, CommunityEvent>  $events
     * @param  list<int>  $messageIds
     * @return list<array{disk: string, path: string, directory: bool}>
     */
    private function files(
        EloquentCollection $targets,
        array $keptIds,
        array $sessionIds,
        EloquentCollection $clubs,
        EloquentCollection $events,
        array $messageIds,
    ): array {
        $keptAvatars = User::query()->whereKey($keptIds)->pluck('avatar')->filter()->all();
        $keptClubImages = Club::query()->whereKeyNot($clubs->modelKeys())->pluck('image_path')->filter()->all();

        $publicFiles = $targets->pluck('avatar')->diff($keptAvatars)
            ->merge($clubs->pluck('image_path')->diff($keptClubImages))
            ->merge($events->pluck('thumbnail'))
            ->merge(DB::table('community_session_message_attachments')->whereIn('message_id', $messageIds)->orderBy('id')->pluck('path'))
            ->merge(DB::table('return_photos')->orderBy('id')->pluck('path'))
            ->filter()
            ->unique()
            ->values();

        $matchCardDisk = (string) config('maison.match_cards.disk');

        $directories = collect($sessionIds)
            ->flatMap(fn (int $id): array => [
                ['disk' => 'public', 'path' => "session-chat/{$id}", 'directory' => true],
                ['disk' => $matchCardDisk, 'path' => "match-cards/{$id}", 'directory' => true],
            ])
            ->merge(OrderReturn::query()->orderBy('id')->pluck('id')->map(
                fn (int $id): array => ['disk' => 'public', 'path' => "returns/{$id}", 'directory' => true],
            ));

        return $publicFiles
            ->map(fn (string $path): array => ['disk' => 'public', 'path' => $path, 'directory' => false])
            ->merge($directories)
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, Order>  $orders
     * @param  EloquentCollection<int, CommunitySession>  $sessions
     * @param  list<int>  $clubIds
     * @param  list<int>  $sessionIds
     * @param  list<int>  $adminIds
     * @param  list<int>  $commentIds
     * @param  list<int>  $messageIds
     * @param  list<int>  $scoreIds
     * @param  list<array{id: int, product: string, number: string, status: string}>  $stuck
     * @return list<string>
     */
    private function warnings(
        EloquentCollection $orders,
        EloquentCollection $sessions,
        array $clubIds,
        array $sessionIds,
        array $adminIds,
        array $commentIds,
        array $messageIds,
        array $scoreIds,
        array $stuck,
    ): array {
        $warnings = [];

        $paid = $orders->filter(fn (Order $order): bool => $order->status->isFulfillment());

        if ($paid->isNotEmpty()) {
            $totals = $paid
                ->groupBy(fn (Order $order): string => Str::upper((string) $order->currency))
                ->map(fn (Collection $group, string $currency): string => $currency.' '.number_format((float) $group->sum('amount'), 2, '.', ''))
                ->implode(', ');

            $warnings[] = "{$paid->count()} PAID order(s), {$totals}, will be removed from this database. No refund is made. Export them first if accounting needs them.";
        }

        $openReturns = OrderReturn::query()
            ->whereIn('status', array_filter(ReturnStatus::cases(), fn (ReturnStatus $status): bool => $status->isOpen()))
            ->count();

        if ($openReturns > 0) {
            $warnings[] = "{$openReturns} return(s) are still open and will be removed.";
        }

        $upcoming = $sessions->filter(
            fn (CommunitySession $session): bool => $session->cancelled_at === null && $session->ends_at?->isFuture() === true,
        )->count();

        if ($upcoming > 0) {
            $warnings[] = "{$upcoming} upcoming session(s) will be removed. Players get no email.";
        }

        $losingClub = $this->ids(DB::table('community_sessions')->whereIn('club_id', $clubIds)->whereNotIn('id', $sessionIds));

        if ($losingClub !== []) {
            $warnings[] = 'Kept session(s) lose their club because the club is removed: '.implode(', ', $losingClub).'.';
        }

        $adminContent = [
            'comment(s)' => DB::table('community_comments')->whereIn('id', $commentIds)->whereIn('author_id', $adminIds)->count(),
            'chat message(s)' => DB::table('community_session_messages')->whereIn('id', $messageIds)->whereIn('user_id', $adminIds)->count(),
            'score(s)' => DB::table('community_session_scores')->whereIn('id', $scoreIds)->whereIn('submitted_by', $adminIds)->count(),
        ];

        foreach (array_filter($adminContent) as $label => $count) {
            $warnings[] = "{$count} admin {$label} sit under a customer post or session and are removed with it.";
        }

        $queued = $this->queuedJobs();

        if ($queued > 0) {
            $warnings[] = "{$queued} job(s) are waiting in the queue. Let the queue finish before you execute.";
        }

        if ($stuck !== []) {
            $warnings[] = count($stuck).' edition number(s) are taken although no order or account holds them. They are NOT changed; check them by hand.';
        }

        return $warnings;
    }

    private function queuedJobs(): int
    {
        try {
            return (int) Queue::size();
        } catch (Throwable) {
            return 0;
        }
    }
}
