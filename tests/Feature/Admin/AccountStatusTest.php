<?php

use App\Enums\RoleEnum;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\AccountStatusService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function statusAccount(RoleEnum $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role->value);
    $user->syncTypeFromRoles();

    return $user;
}

function inactiveAccount(RoleEnum $role = RoleEnum::USER): User
{
    $user = statusAccount($role);
    app(AccountStatusService::class)->deactivate($user);

    return $user->refresh();
}

dataset('account lists', [
    'admins' => ['admin.admins.status', RoleEnum::EDITOR],
    'customers' => ['admin.customers.status', RoleEnum::USER],
]);

it('lets a super admin switch an account off and on again', function (string $route, RoleEnum $role) {
    $superAdmin = statusAccount(RoleEnum::SUPER_ADMIN);
    $account = statusAccount($role);

    $this->actingAs($superAdmin)
        ->patch(route($route, ['user' => $account]), ['active' => false])
        ->assertRedirect();

    expect($account->fresh()->isActive())->toBeFalse();

    $this->actingAs($superAdmin)
        ->patch(route($route, ['user' => $account]), ['active' => true])
        ->assertRedirect();

    expect($account->fresh()->isActive())->toBeTrue();
})->with('account lists');

it('forbids an admin who is not a super admin from changing the status', function (string $route, RoleEnum $role) {
    $admin = statusAccount(RoleEnum::ADMIN);
    $account = statusAccount($role);

    $this->actingAs($admin)
        ->patch(route($route, ['user' => $account]), ['active' => false])
        ->assertForbidden();

    expect($account->fresh()->isActive())->toBeTrue();
})->with('account lists');

it('never switches off a super admin account', function () {
    $superAdmin = statusAccount(RoleEnum::SUPER_ADMIN);
    $other = statusAccount(RoleEnum::SUPER_ADMIN);

    $this->actingAs($superAdmin)
        ->patch(route('admin.admins.status', ['user' => $other]), ['active' => false])
        ->assertForbidden();

    expect($other->fresh()->isActive())->toBeTrue()
        ->and(fn () => app(AccountStatusService::class)->deactivate($other))->toThrow(LogicException::class);
});

it('requires to be told whether the account becomes active', function () {
    $superAdmin = statusAccount(RoleEnum::SUPER_ADMIN);
    $account = statusAccount(RoleEnum::USER);

    $this->actingAs($superAdmin)
        ->patch(route('admin.customers.status', ['user' => $account]), [])
        ->assertSessionHasErrors('active');
});

it('refuses to sign in an inactive account and says why', function () {
    $account = inactiveAccount();

    $this->post(route('login.store'), ['email' => $account->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'Dit account is inactief. Neem contact op met Maison Anversa.']);

    $this->assertGuest();
});

it('does not reveal that an account is inactive after a wrong password', function () {
    $account = inactiveAccount();

    $this->post(route('login.store'), ['email' => $account->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('signs out an inactive account on its next request', function () {
    $account = inactiveAccount();

    $this->actingAs($account)
        ->get(localized('member.dashboard'))
        ->assertRedirect(localized('maison.home'));

    $this->assertGuest();
});

it('answers a background request of an inactive account with 401', function () {
    $account = inactiveAccount();

    $this->actingAs($account)
        ->getJson(localized('member.dashboard'))
        ->assertUnauthorized();

    $this->assertGuest();
});

it('lets a reactivated account sign in again', function () {
    $account = inactiveAccount();
    app(AccountStatusService::class)->activate($account);

    $this->post(route('login.store'), ['email' => $account->email, 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($account);
});

it('ends open sessions and remember-me cookies of a switched off account', function () {
    config(['session.driver' => 'database']);
    $account = statusAccount(RoleEnum::USER);
    $token = $account->remember_token;
    DB::table('sessions')->insert([
        'id' => 'open-session',
        'user_id' => $account->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    app(AccountStatusService::class)->deactivate($account);

    $this->assertDatabaseMissing('sessions', ['id' => 'open-session']);
    expect($account->fresh()->remember_token)->not->toBe($token);
});

it('lets members switch off their own account and keeps their data', function () {
    $member = statusAccount(RoleEnum::USER);
    $order = Order::factory()->forUser($member)->create();

    $this->actingAs($member)
        ->post(localized('member.profile.deactivate'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($member->fresh()->isActive())->toBeFalse()
        ->and($order->fresh()->user_id)->toBe($member->id);
});

it('keeps a member active when the password is wrong', function () {
    $member = statusAccount(RoleEnum::USER);

    $this->actingAs($member)
        ->post(localized('member.profile.deactivate'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    expect($member->fresh()->isActive())->toBeTrue();
});

it('forbids a super admin from switching off their own account', function () {
    $superAdmin = statusAccount(RoleEnum::SUPER_ADMIN);

    $this->actingAs($superAdmin)
        ->post(localized('profile.deactivate'), ['password' => 'password'])
        ->assertForbidden();

    expect($superAdmin->fresh()->isActive())->toBeTrue();
});
