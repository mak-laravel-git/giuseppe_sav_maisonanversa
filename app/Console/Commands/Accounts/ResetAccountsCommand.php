<?php

namespace App\Console\Commands\Accounts;

use App\Exceptions\AccountResetException;
use App\Services\Accounts\AccountResetExecutor;
use App\Services\Accounts\AccountResetPlan;
use App\Services\Accounts\AccountResetPlanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('maison:reset-accounts {--heir= : Email of the super admin who receives admin content (only needed with several super admins)} {--execute : Apply the changes (default is a dry run)} {--plan= : Plan code printed by the dry run, required with --execute}')]
#[Description('Delete every account except super admins, with all orders and events, and put their edition numbers back on sale')]
class ResetAccountsCommand extends Command
{
    private const CONFIRMATION = 'DELETE';

    public function handle(AccountResetPlanner $planner, AccountResetExecutor $executor): int
    {
        try {
            $heir = $planner->resolveHeir($this->option('heir'));
            $plan = $planner->build($heir);

            $this->report($plan);

            if (! $this->option('execute')) {
                $this->newLine();
                $this->info('DRY RUN: nothing was changed.');
                $this->line("To apply exactly this plan: php artisan maison:reset-accounts --execute --plan={$plan->code()}"
                    .($this->option('heir') ? " --heir={$plan->heirEmail}" : ''));

                return self::SUCCESS;
            }

            if ($plan->isEmpty()) {
                $this->info('Nothing to delete.');

                return self::SUCCESS;
            }

            if ($this->option('plan') !== $plan->code()) {
                $this->error($this->option('plan') === null
                    ? 'Add --plan=<code> from a dry run you have reviewed. Nothing was changed.'
                    : 'The plan code does not match the current data. Run the dry run again and review it. Nothing was changed.');

                return self::FAILURE;
            }

            if (! app()->isDownForMaintenance()) {
                $this->warn('The site is NOT in maintenance mode. Running "php artisan down" first is strongly advised.');
            }

            $database = DB::connection()->getDatabaseName();
            $answer = $this->ask("This permanently deletes the data listed above from database \"{$database}\". Type ".self::CONFIRMATION.' to continue');

            if ($answer !== self::CONFIRMATION) {
                $this->info('Aborted. Nothing was changed.');

                return self::FAILURE;
            }

            $result = $executor->execute($heir, $plan->code());
        } catch (AccountResetException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done. Deleted '.count($result['plan']->users).' account(s) and '.count($result['plan']->orders).' order(s); released '.count($result['plan']->releasedPieces).' edition record(s).');
        $this->line('Backup of the affected tables: '.storage_path('app/private/'.$result['backup']));
        $this->line('It holds personal data and password hashes. Copy it somewhere safe, then delete it when no longer needed.');

        foreach ($result['problems'] as $problem) {
            $this->warn($problem);
        }

        return self::SUCCESS;
    }

    private function report(AccountResetPlan $plan): void
    {
        $connection = DB::connection();

        $this->line('Environment: '.app()->environment());
        $this->line("Database: {$connection->getName()} / {$connection->getDatabaseName()}");
        $this->line('Site: '.config('app.url'));

        $this->section('KEPT super admin account(s)', ['ID', 'Name', 'Email'], $plan->keptUsers);
        $this->line("Admin content is handed to: {$plan->heirEmail}");

        $this->section('Accounts to DELETE', ['ID', 'Name', 'Email', 'Kind', 'Roles'], $plan->users);

        $this->section(
            'Orders to DELETE',
            ['ID', 'Reference', 'Email', 'Account', 'Status', 'Amount', 'Cur', 'Product', 'Edition'],
            $plan->orders,
        );

        $this->section(
            'Edition numbers to release',
            ['ID', 'Product ID', 'Product', 'Number', 'Status now', 'Status after', 'Reason'],
            $plan->releasedPieces,
        );

        $this->section('Stock to add back', ['Product ID', 'Product', 'Stock now', 'Stock after'], $plan->stock);

        $this->section(
            'Founding Circle register places to DELETE',
            ['ID', 'User ID', 'Name', 'Number', 'Reason'],
            $plan->registerEntries,
        );

        $this->section('Sessions to DELETE (hosted by customers)', ['ID', 'Host', 'Sport', 'Starts', 'Club'], $plan->sessions);
        $this->section('Clubs to DELETE (submitted by customers)', ['ID', 'Name', 'City', 'Status', 'Submitted by'], $plan->clubs);
        $this->section('Events to DELETE (all)', ['ID', 'Title', 'Starts', 'RSVPs'], $plan->events);
        $this->section('Community posts to DELETE (by customers)', ['ID', 'Author', 'Text'], $plan->posts);

        $this->section(
            "HANDED OVER to {$plan->heirEmail} (not deleted)",
            ['What', 'Rows', 'IDs'],
            array_map(fn (array $change): array => [
                "{$change['table']}.{$change['column']}",
                count($change['ids']),
                implode(', ', $change['ids']),
            ], $plan->handover),
        );

        if ($plan->hostSeats !== [] || $plan->scoreTeams !== []) {
            $this->line('Host seats moving with their session: '.count($plan->hostSeats).'; score teams updated: '.count($plan->scoreTeams).'.');
        }

        $rows = [];

        foreach (array_filter($plan->otherRows) as $table => $count) {
            $rows[] = [$table, $count];
        }

        $this->section('Related rows removed', ['Table', 'Rows'], $rows);

        $this->section(
            'Files and folders to DELETE (if present)',
            ['Disk', 'Path', 'Folder'],
            array_map(fn (array $file): array => [$file['disk'], $file['path'], $file['directory'] ? 'yes' : 'no'], $plan->files),
        );

        $this->newLine();
        $this->line('Open Stripe checkout sessions to expire: '.count($plan->stripeSessionIds));
        $this->line('NOT changed: products, journal, FAQs, pages, settings, admin-submitted and seeded clubs, newsletter subscribers, contact messages, Stripe payments, Brevo contacts.');

        if ($plan->stuckPieces !== []) {
            $this->section('Taken edition numbers with no owner (NOT changed)', ['ID', 'Product', 'Number', 'Status'], $plan->stuckPieces);
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->warn('WARNINGS');

            foreach ($plan->warnings as $warning) {
                $this->warn(" - {$warning}");
            }
        }

        $this->newLine();
        $this->line("Plan code: {$plan->code()}");
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int|string, mixed>>  $rows
     */
    private function section(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->info($title.' ('.count($rows).')');

        if ($rows === []) {
            $this->line('  none');

            return;
        }

        $this->table($headers, array_map(array_values(...), $rows));
    }
}
