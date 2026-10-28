<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TeamMail;
use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Teams: named groups of people the account owner shares sites with in one step (the role is chosen per site, in the site's sharing settings). */
class TeamController extends Controller
{
    public function index(Request $r)
    {
        $user = $r->user();
        Invitation::where('expires_at', '<', now())->whereIn('team_id', $user->ownedTeams()->pluck('id'))->delete();
        $teams = $user->ownedTeams()->with(['members' => fn ($q) => $q->orderBy('name'), 'sites'])->orderBy('name')->get();
        $invites = Invitation::whereIn('team_id', $teams->pluck('id'))->orderBy('created_at')->get()->groupBy('team_id');

        return [
            'invites_enabled' => Invitation::enabled(),
            'data' => $teams->map(fn (Team $t) => [
                'id' => $t->id, 'name' => $t->name,
                'members' => $t->members->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
                'invites' => ($invites[$t->id] ?? collect())->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'expires_at' => $i->expires_at->toIso8601String()])->values(),
                'sites' => $t->sites->sortBy('domain')->map(fn ($s) => ['domain' => $s->domain, 'role' => $s->pivot->role])->values(),
            ])->values(),
        ];
    }

    public function store(Request $r)
    {
        $data = $this->validated($r);
        $team = Team::create(['owner_id' => $r->user()->id, 'name' => $data['name']]);

        return response()->json(['data' => ['id' => $team->id, 'name' => $team->name]], 201);
    }

    public function update(Request $r, string $team)
    {
        $t = $this->mine($r, $team);
        $t->update(['name' => $this->validated($r, $t)['name']]);

        return ['ok' => true];
    }

    public function destroy(Request $r, string $team)
    {
        $this->mine($r, $team)->erase();

        return ['ok' => true];
    }

    /** Someone with an account joins at once (and is told by e-mail); an unknown address gets an invitation when those are enabled. */
    public function addMember(Request $r, string $team)
    {
        $t = $this->mine($r, $team);
        $data = $r->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));
        $inviter = $r->user();
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            if (! Invitation::enabled()) {
                throw ValidationException::withMessages(['email' => 'No account with that e-mail. Ask an administrator to create one first.']);
            }
            Invitation::guardAbuse($inviter);
            $invite = Invitation::issueForTeam($email, $inviter, $t);
            Mail::to($email)->send(new TeamMail($t, $inviter, $invite));
            Invitation::countSent($inviter);

            return response()->json(['ok' => true, 'invited' => true], 201);
        }
        if ($t->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already on the team.']);
        }
        $t->members()->attach($user->id, ['id' => (string) Str::uuid()]);
        Invitation::where('email', $email)->where('team_id', $t->id)->delete();
        if (AuthController::mailConfigured()) {
            Mail::to($user->email)->send(new TeamMail($t, $inviter));
        }

        return response()->json(['ok' => true], 201);
    }

    public function removeMember(Request $r, string $team, string $user)
    {
        $this->mine($r, $team)->members()->detach($user);

        return ['ok' => true];
    }

    public function resendInvite(Request $r, string $team, string $invite)
    {
        $t = $this->mine($r, $team);
        $old = Invitation::where('team_id', $t->id)->whereKey($invite)->firstOrFail();
        abort_unless(Invitation::enabled(), 422, 'Invitations are turned off.');
        Invitation::guardAbuse($r->user());
        $new = Invitation::issueForTeam($old->email, $r->user(), $t);
        Mail::to($new->email)->send(new TeamMail($t, $r->user(), $new));
        Invitation::countSent($r->user());

        return ['ok' => true];
    }

    public function revokeInvite(Request $r, string $team, string $invite)
    {
        Invitation::where('team_id', $this->mine($r, $team)->id)->whereKey($invite)->delete();

        return ['ok' => true];
    }

    private function mine(Request $r, string $team): Team
    {
        return $r->user()->ownedTeams()->findOrFail($team);
    }

    private function validated(Request $r, ?Team $existing = null): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:80', Rule::unique('teams', 'name')->where('owner_id', $r->user()->id)->ignore($existing?->id)]], ['name.unique' => 'You already have a team with that name.']);
    }
}
