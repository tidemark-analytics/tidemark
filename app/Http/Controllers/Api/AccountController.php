<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountAdminMail;
use App\Models\AccountAdmin;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The people who help the account owner manage the account. Only the owner names them (an admin cannot add more admins). Account admins
 * are admin on every site the owner has, add sites for the account, and manage its teams; billing and the account itself stay with the owner.
 */
class AccountController extends Controller
{
    public function admins(Request $r)
    {
        $user = $r->user();
        Invitation::where('expires_at', '<', now())->where('account_id', $user->id)->delete();

        return [
            'invites_enabled' => Invitation::enabled(),
            'data' => $user->accountAdmins()->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
            'invites' => Invitation::where('account_id', $user->id)->orderBy('created_at')->get()->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'expires_at' => $i->expires_at->toIso8601String()]),
        ];
    }

    /** Someone with an account becomes an admin at once (and is told by e-mail); an unknown address gets an invitation when those are enabled. */
    public function addAdmin(Request $r)
    {
        $data = $r->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));
        $owner = $r->user();
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            if (! Invitation::enabled()) {
                throw ValidationException::withMessages(['email' => 'No account with that e-mail. Ask an administrator to create one first.']);
            }
            Invitation::guardAbuse($owner);
            $invite = Invitation::issueForAccount($email, $owner);
            Mail::to($email)->send(new AccountAdminMail($owner, $invite));
            Invitation::countSent($owner);

            return response()->json(['ok' => true, 'invited' => true], 201);
        }
        if ($user->id === $owner->id) {
            throw ValidationException::withMessages(['email' => 'You already own this account.']);
        }
        if (AccountAdmin::where('owner_id', $owner->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already an admin of your account.']);
        }
        AccountAdmin::create(['owner_id' => $owner->id, 'user_id' => $user->id]);
        Invitation::where('email', $email)->where('account_id', $owner->id)->delete();
        if (AuthController::mailConfigured()) {
            Mail::to($user->email)->send(new AccountAdminMail($owner));
        }

        return response()->json(['ok' => true], 201);
    }

    public function removeAdmin(Request $r, string $user)
    {
        AccountAdmin::where('owner_id', $r->user()->id)->where('user_id', $user)->delete();

        return ['ok' => true];
    }

    public function resendInvite(Request $r, string $invite)
    {
        $old = Invitation::where('account_id', $r->user()->id)->whereKey($invite)->firstOrFail();
        abort_unless(Invitation::enabled(), 422, 'Invitations are turned off.');
        Invitation::guardAbuse($r->user());
        $new = Invitation::issueForAccount($old->email, $r->user());
        Mail::to($new->email)->send(new AccountAdminMail($r->user(), $new));
        Invitation::countSent($r->user());

        return ['ok' => true];
    }

    public function revokeInvite(Request $r, string $invite)
    {
        Invitation::where('account_id', $r->user()->id)->whereKey($invite)->delete();

        return ['ok' => true];
    }
}
