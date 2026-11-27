<?php

use App\Enums\EditionPieceStatus;
use App\Enums\OrderStatus;
use App\Enums\RoleEnum;
use App\Enums\SessionScoreStatus;
use App\Models\AppNotification;
use App\Models\Club;
use App\Models\CommunityComment;
use App\Models\CommunityEvent;
use App\Models\CommunityPost;
use App\Models\CommunitySession;
use App\Models\CommunitySessionMessage;
use App\Models\CommunitySessionParticipant;
use App\Models\CommunitySessionScore;
use App\Models\EditionPiece;
use App\Models\EventRsvp;
use App\Models\FoundingCircleRegisterEntry;
use App\Models\Inquiry;
use App\Models\NewsletterSubscriber;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounts\AccountResetPlanner;
use App\Services\Edition\EditionAllocator;
use App\Services\FoundingCircle\FoundingCircleRegistrar;
use App\Services\Stripe\CheckoutSessionExpirer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    Storage::fake('public');
    Storage::fake('local');
    Mail::fake();
});

function resetAccount(RoleEnum $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role->value);
    $user->syncTypeFromRoles();

    return $user;
}

function resetPlanCode(User $heir): string
{
    return app(AccountResetPlanner::class)->build($heir)->code();
}

function resetConfirmationQuestion(): string
{
    $database = DB::connection()->getDatabaseName();

    return "This permanently deletes the data listed above from database \"{$database}\". Type DELETE to continue";
}

/**
 * @param  array<string, mixed>  $options
 */
function executeReset(User $heir, array $options = []): mixed
{
    return test()->artisan('maison:reset-accounts', [
        '--execute' => true,
        '--plan' => resetPlanCode($heir),
        ...$options,
    ])->expectsQuestion(resetConfirmationQuestion(), 'DELETE');
}

function paidFoundingOrder(User $user): Order
{
    $order = Order::factory()->forUser($user)->paid()->create();
    app(EditionAllocator::class)->allocate($order);
    app(FoundingCircleRegistrar::class)->inscribeFromOrder($order->refresh());

    return $order->refresh();
}

it('changes nothing on a dry run', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);
    $order = paidFoundingOrder($customer);

    $this->artisan('maison:reset-accounts')
        ->expectsOutputToContain($order->reference())
        ->expectsOutputToContain('DRY RUN: nothing was changed.')
        ->expectsOutputToContain(resetPlanCode($superAdmin))
        ->assertSuccessful();

    $this->assertModelExists($customer);
    $this->assertModelExists($order);
    expect(EditionPiece::query()->find($order->edition_piece_id)->status)->toBe(EditionPieceStatus::Allocated)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses to execute without the plan code of a reviewed dry run', function () {
    resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);

    $this->artisan('maison:reset-accounts', ['--execute' => true])
        ->expectsOutputToContain('Add --plan=<code>')
        ->assertFailed();

    $this->assertModelExists($customer);
});

it('refuses to execute when the data changed after the dry run', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);
    $reviewed = resetPlanCode($superAdmin);
    $newOrder = Order::factory()->forUser($customer)->create();

    $this->artisan('maison:reset-accounts', ['--execute' => true, '--plan' => $reviewed])
        ->expectsOutputToContain('does not match the current data')
        ->assertFailed();

    $this->assertModelExists($customer);
    $this->assertModelExists($newOrder);
});

it('changes nothing when the confirmation word is not typed', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);

    $this->artisan('maison:reset-accounts', ['--execute' => true, '--plan' => resetPlanCode($superAdmin)])
        ->expectsQuestion(resetConfirmationQuestion(), 'yes')
        ->assertFailed();

    $this->assertModelExists($customer);
});

it('removes every order and puts its edition number and stock back on sale', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);
    $assigned = resetAccount(RoleEnum::USER);

    $paid = paidFoundingOrder($customer);
    $return = OrderReturn::factory()->forOrder($paid)->create();
    Payment::factory()->create(['order_id' => $paid->id]);
    app(FoundingCircleRegistrar::class)->assign($assigned, 7);

    $founding = Product::founding();
    $available = EditionPiece::query()->where('product_id', $founding->id)->where('status', EditionPieceStatus::Available);
    $availableBefore = (clone $available)->count();
    $held = (clone $available)->orderByDesc('id')->firstOrFail();
    $held->update([
        'status' => EditionPieceStatus::Reserved,
        'reserved_by_user_id' => $customer->id,
        'reserved_until' => now()->addMinutes(10),
    ]);
    $archived = EditionPiece::query()->where('status', EditionPieceStatus::Archive)->firstOrFail();

    $simple = Product::factory()->create(['stock_quantity' => 5]);
    Order::factory()->paid()->create(['product_id' => $simple->id]);
    Order::factory()->create(['product_id' => $simple->id, 'status' => OrderStatus::Canceled]);

    executeReset($superAdmin)->assertSuccessful();

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertModelMissing($return);
    $this->assertDatabaseCount('founding_circle_register', 0);

    $released = EditionPiece::query()->find($paid->edition_piece_id);
    expect($released->status)->toBe(EditionPieceStatus::Available)
        ->and($released->order_id)->toBeNull()
        ->and($held->fresh()->status)->toBe(EditionPieceStatus::Available)
        ->and($held->fresh()->reserved_until)->toBeNull()
        // The paid number and the number assigned by hand are both on sale again.
        ->and((clone $available)->count())->toBe($availableBefore + 2)
        ->and($archived->fresh()->status)->toBe(EditionPieceStatus::Archive)
        ->and($simple->fresh()->stock_quantity)->toBe(6);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('keeps only the super admin and hands admin content over', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $admin = resetAccount(RoleEnum::ADMIN);
    $editor = resetAccount(RoleEnum::EDITOR);
    $customer = resetAccount(RoleEnum::USER);

    $adminClub = Club::factory()->create(['submitted_by_id' => $admin->id, 'approved_by_id' => $admin->id]);
    $seededClub = Club::factory()->create();
    $customerClub = Club::factory()->partner()->create(['submitted_by_id' => $customer->id, 'approved_by_id' => $admin->id]);

    $session = CommunitySession::factory()->create(['host_id' => $admin->id, 'club_id' => $customerClub->id]);
    CommunitySessionParticipant::factory()->create(['community_session_id' => $session->id, 'user_id' => $admin->id]);
    CommunitySessionParticipant::factory()->create(['community_session_id' => $session->id, 'user_id' => $customer->id]);
    $adminMessage = CommunitySessionMessage::factory()->create(['community_session_id' => $session->id, 'user_id' => $admin->id]);
    $customerMessage = CommunitySessionMessage::factory()->create(['community_session_id' => $session->id, 'user_id' => $customer->id]);
    $score = CommunitySessionScore::factory()->create([
        'community_session_id' => $session->id,
        'submitted_by' => $admin->id,
        'reviewed_by' => $admin->id,
        'status' => SessionScoreStatus::Approved,
        'team_a' => [$admin->id],
        'team_b' => [$customer->id],
    ]);

    $adminPost = CommunityPost::factory()->create(['author_id' => $editor->id]);
    $adminReply = CommunityComment::factory()->create(['community_post_id' => $adminPost->id, 'author_id' => $admin->id]);
    $customerReply = CommunityComment::factory()->create(['community_post_id' => $adminPost->id, 'author_id' => $customer->id]);
    $notification = AppNotification::factory()->create(['created_by' => $admin->id]);

    executeReset($superAdmin)->assertSuccessful();

    expect(User::query()->pluck('id')->all())->toBe([$superAdmin->id])
        ->and($superAdmin->fresh()->isSuperAdmin())->toBeTrue()
        ->and($adminClub->fresh()->submitted_by_id)->toBe($superAdmin->id)
        ->and($adminClub->fresh()->approved_by_id)->toBe($superAdmin->id)
        ->and($session->fresh()->host_id)->toBe($superAdmin->id)
        ->and($session->fresh()->club_id)->toBeNull()
        ->and($session->participantRecords()->pluck('user_id')->all())->toBe([$superAdmin->id])
        ->and($adminMessage->fresh()->user_id)->toBe($superAdmin->id)
        ->and($score->fresh()->submitted_by)->toBe($superAdmin->id)
        ->and($score->fresh()->reviewed_by)->toBe($superAdmin->id)
        ->and($score->fresh()->team_a)->toBe([$superAdmin->id])
        ->and($adminPost->fresh()->author_id)->toBe($superAdmin->id)
        ->and($adminReply->fresh()->author_id)->toBe($superAdmin->id)
        ->and($notification->fresh()->created_by)->toBe($superAdmin->id);

    $this->assertModelExists($seededClub);
    $this->assertModelMissing($customerClub);
    $this->assertModelMissing($customerMessage);
    $this->assertModelMissing($customerReply);
});

it('removes customer content, all events and their files but keeps everything else', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $admin = resetAccount(RoleEnum::ADMIN);
    $customer = resetAccount(RoleEnum::USER, ['avatar' => 'avatars/customer.jpg']);
    $superAdmin->update(['avatar' => 'avatars/super.jpg']);

    $session = CommunitySession::factory()->create(['host_id' => $customer->id]);
    $replyInCustomerSession = CommunitySessionMessage::factory()->create(['community_session_id' => $session->id, 'user_id' => $admin->id]);
    $post = CommunityPost::factory()->create(['author_id' => $customer->id]);
    $replyOnCustomerPost = CommunityComment::factory()->create(['community_post_id' => $post->id, 'author_id' => $admin->id]);
    $event = CommunityEvent::factory()->create(['thumbnail' => 'community-events/thumb.jpg']);
    EventRsvp::factory()->create(['community_event_id' => $event->id, 'user_id' => $customer->id]);
    $subscriber = NewsletterSubscriber::factory()->create(['user_id' => $customer->id]);
    $inquiry = Inquiry::factory()->create(['user_id' => $customer->id]);
    $products = Product::query()->count();

    foreach (['avatars/customer.jpg', 'avatars/super.jpg', 'community-events/thumb.jpg', "session-chat/{$session->id}/photo.jpg"] as $path) {
        Storage::disk('public')->put($path, 'file');
    }

    executeReset($superAdmin)->assertSuccessful();

    $this->assertModelMissing($session);
    $this->assertModelMissing($replyInCustomerSession);
    $this->assertModelMissing($post);
    $this->assertModelMissing($replyOnCustomerPost);
    $this->assertModelMissing($event);
    $this->assertDatabaseCount('event_rsvps', 0);
    $this->assertDatabaseMissing('translations', ['translatable_type' => (new CommunityPost)->getMorphClass(), 'translatable_id' => $post->id]);
    expect($subscriber->fresh()->user_id)->toBeNull()
        ->and($inquiry->fresh()->user_id)->toBeNull()
        ->and(Product::query()->count())->toBe($products);

    Storage::disk('public')->assertMissing(['avatars/customer.jpg', 'community-events/thumb.jpg', "session-chat/{$session->id}/photo.jpg"]);
    Storage::disk('public')->assertExists('avatars/super.jpg');

    $backups = Storage::disk('local')->files('account-reset');
    expect($backups)->toHaveCount(1);
    $backup = json_decode(Storage::disk('local')->get($backups[0]), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($backup['users'], 'email'))->toContain($customer->email);
});

it('expires the Stripe checkout of an unpaid order so it cannot be paid later', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_open']);
    Order::factory()->paid()->create();

    $this->mock(CheckoutSessionExpirer::class)
        ->shouldReceive('expire')
        ->once()
        ->with('cs_test_open');

    executeReset($superAdmin)->assertSuccessful();
});

it('removes the admin seat when the super admin already sits in that session', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $admin = resetAccount(RoleEnum::ADMIN);
    $session = CommunitySession::factory()->create(['host_id' => $admin->id]);
    CommunitySessionParticipant::factory()->create(['community_session_id' => $session->id, 'user_id' => $admin->id]);
    CommunitySessionParticipant::factory()->create(['community_session_id' => $session->id, 'user_id' => $superAdmin->id]);

    executeReset($superAdmin)->assertSuccessful();

    expect($session->participantRecords()->pluck('user_id')->all())->toBe([$superAdmin->id]);
});

it('rolls everything back when a check fails', function () {
    $superAdmin = resetAccount(RoleEnum::SUPER_ADMIN);
    $customer = resetAccount(RoleEnum::USER);
    $order = paidFoundingOrder($customer);
    $event = CommunityEvent::factory()->create();
    CommunityEvent::deleting(fn (): bool => false);

    executeReset($superAdmin)
        ->expectsOutputToContain('Everything was rolled back.')
        ->assertFailed();

    $this->assertModelExists($customer);
    $this->assertModelExists($order);
    $this->assertModelExists($event);
    expect(EditionPiece::query()->find($order->edition_piece_id)->status)->toBe(EditionPieceStatus::Allocated)
        ->and(FoundingCircleRegisterEntry::query()->count())->toBe(1);
});

it('does nothing when there is no super admin', function () {
    $customer = resetAccount(RoleEnum::USER);

    $this->artisan('maison:reset-accounts')
        ->expectsOutputToContain('No super admin account exists')
        ->assertFailed();

    $this->assertModelExists($customer);
});

it('asks which super admin receives the content when there are several', function () {
    resetAccount(RoleEnum::SUPER_ADMIN);
    resetAccount(RoleEnum::SUPER_ADMIN);

    $this->artisan('maison:reset-accounts')
        ->expectsOutputToContain('Choose who receives the admin content with --heir=<email>')
        ->assertFailed();
});

it('keeps every super admin and gives the content to the chosen one', function () {
    $first = resetAccount(RoleEnum::SUPER_ADMIN);
    $chosen = resetAccount(RoleEnum::SUPER_ADMIN);
    $admin = resetAccount(RoleEnum::ADMIN);
    $post = CommunityPost::factory()->create(['author_id' => $admin->id]);

    executeReset($chosen, ['--heir' => $chosen->email])->assertSuccessful();

    expect($post->fresh()->author_id)->toBe($chosen->id)
        ->and(User::query()->orderBy('id')->pluck('id')->all())->toBe([$first->id, $chosen->id]);
});

it('refuses an heir who is not a super admin', function () {
    resetAccount(RoleEnum::SUPER_ADMIN);
    $admin = resetAccount(RoleEnum::ADMIN);

    $this->artisan('maison:reset-accounts', ['--heir' => $admin->email])
        ->expectsOutputToContain('is not a super admin account')
        ->assertFailed();
});

/*
 * A new column pointing at users must be added to the reset on purpose, or its
 * rows would silently disappear or lose their owner on the live database.
 */
test('every foreign key to users is one the account reset accounts for', function () {
    $columns = collect(Schema::getTables(Schema::getCurrentSchemaName()))
        ->pluck('name')
        ->flatMap(fn (string $table) => collect(Schema::getForeignKeys($table))
            ->where('foreign_table', 'users')
            ->map(fn (array $key): string => $table.'.'.$key['columns'][0]))
        ->sort()
        ->values()
        ->all();

    expect($columns)->toBe([
        'app_notifications.created_by',
        'clubs.approved_by_id',
        'clubs.submitted_by_id',
        'community_comments.author_id',
        'community_likes.user_id',
        'community_post_hides.user_id',
        'community_posts.author_id',
        'community_reports.reporter_id',
        'community_session_chat_reads.user_id',
        'community_session_messages.reported_by_id',
        'community_session_messages.user_id',
        'community_session_participants.user_id',
        'community_session_scores.reviewed_by',
        'community_session_scores.submitted_by',
        'community_sessions.host_id',
        'edition_pieces.reserved_by_user_id',
        'event_rsvps.user_id',
        'founding_circle_claims.reviewed_by_id',
        'founding_circle_claims.user_id',
        'founding_circle_register.user_id',
        'inquiries.user_id',
        'monitoring_restarts.operator_id',
        'newsletter_subscribers.user_id',
        'order_returns.user_id',
        'order_status_events.user_id',
        'orders.user_id',
        'return_events.user_id',
        'user_notifications.user_id',
    ]);
});
