<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only way an account is switched off or on again. Accounts are never
 * deleted from the website; an inactive account keeps all of its data.
 */
class AccountStatusService
{
    /**
     * Block sign-in and end every open session of the account.
     */
    public function deactivate(User $user): void
    {
        if ($user->isSuperAdmin()) {
            throw new LogicException('A super administrator account cannot be deactivated.');
        }

        DB::transaction(function () use ($user): void {
            // A new remember token kills "remember me" cookies, also after reactivation.
            $user->forceFill([
                'deactivated_at' => $user->deactivated_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }

    public function activate(User $user): void
    {
        $user->forceFill(['deactivated_at' => null])->save();
    }
}
