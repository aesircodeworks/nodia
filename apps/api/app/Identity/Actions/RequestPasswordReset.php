<?php

namespace App\Identity\Actions;

use App\Identity\Data\RequestPasswordResetData;
use App\Identity\Mail\PasswordResetMail;
use App\Identity\Models\StaffPasswordResetToken;
use App\Identity\Support\PasswordResetTokenHasher;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * POST /v1/auth/staff/password/reset (stage-03 plan, task breakdown item
 * 16): always renders 202 regardless of whether the email resolves to a
 * user, mirroring App\Identity\Actions\RequestCustomerClaim's own
 * enumeration-safe design; a reset token is only ever issued and mailed
 * for a genuine, resolving user, deferred to the enclosing transaction's
 * commit (DB::afterCommit) so a rolled-back request never mails a token
 * for a user that was never really found.
 */
final class RequestPasswordReset
{
    public function __invoke(RequestPasswordResetData $data): void
    {
        $user = User::query()->where('email', $data->email)->first();

        if ($user === null) {
            return;
        }

        $plainToken = Str::random(64);

        StaffPasswordResetToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => PasswordResetTokenHasher::hash($plainToken),
            'expires_at' => Date::now()->addMinutes(config()->integer('identity.reset_token_ttl_minutes')),
        ]);

        DB::afterCommit(function () use ($user, $plainToken): void {
            Mail::to($user->email)->send(new PasswordResetMail($user->name, $plainToken));
        });
    }
}
