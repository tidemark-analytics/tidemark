<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountAdmin;
use App\Models\Invitation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** The person on the other end of an invitation link. Public: the link itself is the credential (single use, 72 hours). */
class InviteController extends Controller
{
    public function show(Request $request, string $token)
    {
        $invite = $this->live($token);
        $has = User::whereRaw('lower(email) = ?', [strtolower($invite->email)])->exists();

        return ['email' => $invite->email, 'role' => $invite->role, 'site' => $invite->site?->domain, 'team' => $invite->team?->name, 'account' => $invite->account_id ? $invite->inviter?->name : null, 'inviter' => $invite->inviter?->name, 'has_account' => $has, 'signed_in_as' => Auth::user()?->email];
    }

    public function accept(Request $request, string $token)
    {
        $invite = $this->live($token);
        $existing = User::whereRaw('lower(email) = ?', [strtolower($invite->email)])->first();
        if ($existing) {
            // They registered in the meantime: only that signed-in person can take the access, nobody can create a second account.
            abort_unless(Auth::id() === $existing->id, 409, 'An account with this e-mail already exists. Sign in with it, then open the link again.');
            $user = $existing;
        } else {
            $data = $request->validate(['name' => 'required|string|max:100', 'password' => ['required', 'confirmed', Password::min(8)]]);
            $user = User::create(['name' => $data['name'], 'email' => $invite->email, 'password' => $data['password']]);
            $user->forceFill(['email_verified_at' => now()])->save(); // they proved they own the inbox
            event(new Registered($user));
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        $landing = null;
        if ($invite->account_id) {
            AccountAdmin::firstOrCreate(['owner_id' => $invite->account_id, 'user_id' => $user->id]);
            $landing = Site::memberOf($user)->orderBy('domain')->value('domain');
        } elseif ($invite->team) {
            if (! $invite->team->members()->where('users.id', $user->id)->exists()) {
                $invite->team->members()->attach($user->id, ['id' => (string) Str::uuid()]);
            }
            $landing = $invite->team->sites()->orderBy('domain')->value('domain');
        } else {
            $site = $invite->site;
            if (! $site->members()->where('users.id', $user->id)->exists()) {
                $site->members()->attach($user->id, ['role' => $invite->role, 'id' => (string) Str::uuid()]);
            }
            $landing = $site->domain;
        }
        $invite->delete();

        return ['ok' => true, 'site' => $landing];
    }

    private function live(string $token): Invitation
    {
        $invite = Invitation::live($token);
        abort_if(! $invite || ! ($invite->site || $invite->team || $invite->account_id), 410, 'This invitation has expired or was already used. Ask for a new one.');

        return $invite;
    }
}
