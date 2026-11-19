<?php

use App\Models\CommunitySession;
use App\Models\CommunitySessionParticipant;
use App\Models\User;
use App\Support\SessionFeed;

test('session cards tolerate a missing host without throwing', function () {
    $viewer = User::factory()->create();
    $host = User::factory()->create();
    $session = CommunitySession::factory()->create(['host_id' => $host->id]);

    CommunitySessionParticipant::factory()->create([
        'community_session_id' => $session->id,
        'user_id' => $host->id,
    ]);

    $session->load(['host', 'club', 'participants.user', 'participantRecords', 'translations']);
    $session->setRelation('host', null);

    $card = SessionFeed::toCard($session, $viewer, 'fr');

    expect($card['host'])->toBeNull()
        ->and($card['players'])->toHaveCount(1);
});

test('session cards skip participants whose user account is gone', function () {
    $viewer = User::factory()->create();
    $host = User::factory()->create();
    $session = CommunitySession::factory()->create(['host_id' => $host->id]);

    $row = CommunitySessionParticipant::factory()->create([
        'community_session_id' => $session->id,
        'user_id' => $host->id,
    ]);

    $session->load(['host', 'club', 'participants.user', 'participantRecords', 'translations']);
    $row->setRelation('user', null);
    $session->setRelation('participants', collect([$row]));

    $card = SessionFeed::toCard($session, $viewer, 'fr');

    expect($card['players'])->toBe([])
        ->and($card['host']['id'])->toBe($host->id);
});
