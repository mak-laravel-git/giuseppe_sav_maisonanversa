<?php

namespace App\Services\Accounts;

/**
 * Every change an account reset would make, resolved to ids. The dry run prints
 * it and the execution applies exactly it.
 */
final readonly class AccountResetPlan
{
    /**
     * @param  list<array{id: int, name: string, email: string}>  $keptUsers
     * @param  list<array{id: int, name: string, email: string, kind: string, roles: string}>  $users
     * @param  list<array{id: int, reference: string, email: string, account: string, status: string, amount: string, currency: string, product: string, edition: string}>  $orders
     * @param  list<array{id: int, product_id: int, product: string, number: string, from: string, to: string, reason: string}>  $releasedPieces
     * @param  list<array{id: int, product: string, number: string, status: string}>  $stuckPieces
     * @param  list<array{product_id: int, product: string, from: int, to: int}>  $stock
     * @param  list<array{id: int, user_id: int|null, name: string, number: string, reason: string}>  $registerEntries
     * @param  list<int>  $roleRemovals  Kept accounts that lose the Founding Circle role.
     * @param  list<int>  $claimIds
     * @param  list<array{id: int, host: string, sport: string, starts_at: string, club: string}>  $sessions
     * @param  list<array{id: int, name: string, city: string, status: string, submitter: string}>  $clubs
     * @param  list<array{id: int, title: string, starts_at: string, rsvps: int}>  $events
     * @param  list<array{id: int, author: string, excerpt: string}>  $posts
     * @param  list<int>  $commentIds
     * @param  list<int>  $messageIds
     * @param  list<int>  $scoreIds
     * @param  list<array{table: string, column: string, ids: list<int>}>  $handover
     * @param  list<array{session_id: int, from: int, action: string}>  $hostSeats
     * @param  list<array{score_id: int, team_a: list<int>, team_b: list<int>}>  $scoreTeams
     * @param  array<string, int>  $otherRows
     * @param  list<array{disk: string, path: string, directory: bool}>  $files
     * @param  list<string>  $stripeSessionIds
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $heirId,
        public string $heirEmail,
        public array $keptUsers,
        public array $users,
        public array $orders,
        public array $releasedPieces,
        public array $stuckPieces,
        public array $stock,
        public array $registerEntries,
        public array $roleRemovals,
        public array $claimIds,
        public array $sessions,
        public array $clubs,
        public array $events,
        public array $posts,
        public array $commentIds,
        public array $messageIds,
        public array $scoreIds,
        public array $handover,
        public array $hostSeats,
        public array $scoreTeams,
        public array $otherRows,
        public array $files,
        public array $stripeSessionIds,
        public array $warnings,
    ) {}

    public function isEmpty(): bool
    {
        return $this->users === []
            && $this->orders === []
            && $this->releasedPieces === []
            && $this->registerEntries === []
            && $this->events === [];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_column($this->users, 'id');
    }

    /**
     * @return list<int>
     */
    public function keptUserIds(): array
    {
        return array_column($this->keptUsers, 'id');
    }

    /**
     * Fingerprint of the reviewed changes. It moves when anything that matters
     * moves (a new order, a status change, a new session), so an execution can
     * never apply something the operator did not see. Throwaway rows such as
     * logins, likes and inbox entries are left out on purpose.
     */
    public function code(): string
    {
        $fingerprint = [
            $this->heirId,
            $this->keptUserIds(),
            $this->userIds(),
            array_map(fn (array $order): array => [$order['id'], $order['status'], $order['amount']], $this->orders),
            $this->releasedPieces,
            $this->stock,
            array_column($this->registerEntries, 'id'),
            $this->roleRemovals,
            $this->claimIds,
            array_column($this->sessions, 'id'),
            array_column($this->clubs, 'id'),
            array_column($this->events, 'id'),
            array_column($this->posts, 'id'),
            $this->commentIds,
            $this->messageIds,
            $this->scoreIds,
            $this->handover,
            $this->hostSeats,
            $this->scoreTeams,
        ];

        return substr(hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
