<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AuthorizesOwnershipTransfer;
use App\Contracts\Entitlements;
use App\Http\Controllers\Controller;
use App\Mail\OrganizationAdminMail;
use App\Mail\OwnershipTransferMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The organization a person owns: its name, who administers it with them, and handing it to one of those admins. Only the owner does
 * this. An admin sees every site and manages teams, but the organization itself (name, admins, ownership, billing) stays with the owner.
 */
class OrganizationController extends Controller
{
    public function show(Request $r)
    {
        $user = $r->user();
        $org = $user->ownedOrganizations()->orderBy('created_at')->first();
        if ($org) {
            Invitation::where('expires_at', '<', now())->where('organization_id', $org->id)->delete();
        }
        $incoming = Organization::where('transfer_to', $user->id)->where('transfer_expires_at', '>', now())->with('owner')->get();

        return [
            'invites_enabled' => Invitation::enabled(),
            'organization' => $org ? [
                'id' => $org->id, 'name' => $org->name,
                'admins' => $org->admins()->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]),
                'invites' => Invitation::where('organization_id', $org->id)->orderBy('created_at')->get()->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'expires_at' => $i->expires_at->toIso8601String()]),
                'transfer' => $org->hasPendingTransfer() ? ['to' => ['id' => $org->transfer_to, 'name' => $org->transferTarget?->name, 'email' => $org->transferTarget?->email], 'expires_at' => $org->transfer_expires_at->toIso8601String()] : null,
            ] : null,
            // organizations this person helps manage, and offers to take one over
            'member_of' => $user->administeredOrganizations()->with('owner')->orderBy('name')->get()->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'owner' => $o->owner?->name]),
            'incoming_transfers' => $incoming->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'from' => $o->owner?->name, 'expires_at' => $o->transfer_expires_at->toIso8601String()]),
        ];
    }

    /** For someone who joined to help (or was invited to a team) and has none of their own yet. */
    public function store(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100']);
        if ($r->user()->ownedOrganizations()->exists()) {
            throw ValidationException::withMessages(['name' => 'You already own an organization.']);
        }
        $org = Organization::forOwner($r->user(), $data['name']);

        return response()->json(['data' => ['id' => $org->id, 'name' => $org->name]], 201);
    }

    public function update(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100']);
        $this->mine($r)->update(['name' => trim($data['name'])]);

        return ['ok' => true];
    }

    // ---- admins ------------------------------------------------------------------------------------------
    /** Someone with an account becomes an admin at once (and is told by e-mail); an unknown address gets an invitation when those are enabled. */
    public function addAdmin(Request $r)
    {
        $org = $this->mine($r);
        $data = $r->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));
        $owner = $r->user();
        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            if (! Invitation::enabled()) {
                throw ValidationException::withMessages(['email' => 'No account with that e-mail. Ask an administrator to create one first.']);
            }
            Invitation::guardAbuse($owner);
            $invite = Invitation::issueForOrganization($email, $owner, $org);
            Mail::to($email)->send(new OrganizationAdminMail($org, $owner, $invite));
            Invitation::countSent($owner);

            return response()->json(['ok' => true, 'invited' => true], 201);
        }
        if ($user->id === $org->owner_id) {
            throw ValidationException::withMessages(['email' => 'You own this organization.']);
        }
        if ($org->admins()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person is already an admin.']);
        }
        $org->admins()->attach($user->id, ['id' => (string) Str::uuid()]);
        Invitation::where('email', $email)->where('organization_id', $org->id)->delete();
        if (AuthController::mailConfigured()) {
            Mail::to($user->email)->send(new OrganizationAdminMail($org, $owner));
        }

        return response()->json(['ok' => true], 201);
    }

    public function removeAdmin(Request $r, string $user)
    {
        $org = $this->mine($r);
        $org->admins()->detach($user);
        if ($org->transfer_to === $user) {
            $org->update(['transfer_to' => null, 'transfer_expires_at' => null]);
        }

        return ['ok' => true];
    }

    public function resendInvite(Request $r, string $invite)
    {
        $org = $this->mine($r);
        $old = Invitation::where('organization_id', $org->id)->whereKey($invite)->firstOrFail();
        abort_unless(Invitation::enabled(), 422, 'Invitations are turned off.');
        Invitation::guardAbuse($r->user());
        $new = Invitation::issueForOrganization($old->email, $r->user(), $org);
        Mail::to($new->email)->send(new OrganizationAdminMail($org, $r->user(), $new));
        Invitation::countSent($r->user());

        return ['ok' => true];
    }

    public function revokeInvite(Request $r, string $invite)
    {
        Invitation::where('organization_id', $this->mine($r)->id)->whereKey($invite)->delete();

        return ['ok' => true];
    }

    // ---- ownership ---------------------------------------------------------------------------------------
    /** The owner offers the organization to one of its admins. Nothing changes until that person accepts, within the invitation window. */
    public function transfer(Request $r)
    {
        $org = $this->mine($r);
        $data = $r->validate(['user_id' => 'required|string', 'confirm' => 'required|string']);
        if (mb_strtolower(trim($data['confirm'])) !== mb_strtolower($org->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the organization\'s name exactly to confirm.']);
        }
        $to = $org->admins()->where('users.id', $data['user_id'])->first();
        if (! $to) {
            throw ValidationException::withMessages(['user_id' => 'Choose one of the organization\'s admins. Add the person as an admin first.']);
        }
        $this->authorize($org, $to);
        $org->update(['transfer_to' => $to->id, 'transfer_expires_at' => now()->addHours((int) config('tidemark.invite_hours'))]);
        if (AuthController::mailConfigured()) {
            Mail::to($to->email)->send(new OwnershipTransferMail($org, $r->user()));
        }

        return ['ok' => true];
    }

    public function cancelTransfer(Request $r)
    {
        $this->mine($r)->update(['transfer_to' => null, 'transfer_expires_at' => null]);

        return ['ok' => true];
    }

    /** The admin takes over: they become the owner, the previous owner stays on as an admin, and the sites follow. */
    public function acceptTransfer(Request $r, string $organization)
    {
        $me = $r->user();
        $org = Organization::where('transfer_to', $me->id)->findOrFail($organization);
        abort_unless($org->hasPendingTransfer(), 410, 'This offer has expired. Ask the owner to send it again.');
        $this->authorize($org, $me);
        $previous = $org->owner_id;
        DB::transaction(function () use ($org, $me, $previous) {
            $org->admins()->detach($me->id);
            $org->admins()->attach($previous, ['id' => (string) Str::uuid()]);
            $org->update(['owner_id' => $me->id, 'transfer_to' => null, 'transfer_expires_at' => null]);
            // each site's owner row follows the organization
            foreach ($org->sites()->where('kind', '!=', 'rollup')->pluck('id') as $siteId) {
                DB::table('site_user')->where('site_id', $siteId)->where('user_id', $me->id)->delete();
                DB::table('site_user')->where('site_id', $siteId)->where('role', 'owner')->update(['user_id' => $me->id, 'updated_at' => now()]);
            }
        });

        return ['ok' => true];
    }

    public function declineTransfer(Request $r, string $organization)
    {
        Organization::where('transfer_to', $r->user()->id)->findOrFail($organization)->update(['transfer_to' => null, 'transfer_expires_at' => null]);

        return ['ok' => true];
    }

    private function authorize(Organization $org, User $to): void
    {
        $e = app(Entitlements::class);
        if ($e instanceof AuthorizesOwnershipTransfer && ($why = $e->allowsOwnershipTransfer($org, $to)) !== true) {
            throw ValidationException::withMessages(['user_id' => $why]);
        }
    }

    private function mine(Request $r): Organization
    {
        return $r->user()->ownedOrganizations()->orderBy('created_at')->firstOrFail();
    }
}
