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
 * Organizations: who sites belong to. Everyone has a Personal one (just theirs: no admins, no invitations, no transfer, never deleted) and
 * can create others. An organization has one owner and any number of admins; admins see and change every site in it and manage its teams,
 * while the organization itself (name, admins, ownership, deletion, billing) stays with the owner.
 */
class OrganizationController extends Controller
{
    /** What the switcher needs besides /me: offers of ownership waiting for this person. */
    public function index(Request $r)
    {
        $incoming = Organization::where('transfer_to', $r->user()->id)->where('transfer_expires_at', '>', now())->with('owner')->get();

        return [
            'incoming_transfers' => $incoming->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'from' => $o->owner?->name, 'expires_at' => $o->transfer_expires_at->toIso8601String()]),
        ];
    }

    public function show(Request $r, string $organization)
    {
        $org = $this->member($r, $organization);
        $owner = $org->owner_id === $r->user()->id;
        if ($owner) {
            Invitation::where('expires_at', '<', now())->where('organization_id', $org->id)->delete();
        }

        return [
            'id' => $org->id, 'name' => $org->name, 'personal' => $org->personal, 'logo_url' => $org->logoUrl(), 'role' => $owner ? 'owner' : 'admin',
            'owner' => $org->owner?->only(['id', 'name']),
            'invites_enabled' => Invitation::enabled(),
            'sites' => $org->sites()->count(),
            'admins' => $org->personal ? [] : $org->admins()->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'avatar_url' => $u->avatarUrl(), 'email' => $owner ? $u->email : null]),
            'invites' => $owner ? Invitation::where('organization_id', $org->id)->orderBy('created_at')->get()->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'expires_at' => $i->expires_at->toIso8601String()]) : [],
            'transfer' => $owner && $org->hasPendingTransfer() ? ['to' => ['id' => $org->transfer_to, 'name' => $org->transferTarget?->name, 'email' => $org->transferTarget?->email], 'expires_at' => $org->transfer_expires_at->toIso8601String()] : null,
        ];
    }

    /** Anyone may start an organization, up to a limit; the owner then holds its plan on Cloud like for everything they own. */
    public function store(Request $r)
    {
        $data = $r->validate(['name' => 'required|string|max:100']);
        if (Organization::ownedCount($r->user()) >= (int) config('tidemark.organization_limit')) {
            throw ValidationException::withMessages(['name' => 'You own the most organizations this install allows.']);
        }
        $org = Organization::create(['owner_id' => $r->user()->id, 'name' => trim($data['name'])]);

        return response()->json(['data' => ['id' => $org->id, 'name' => $org->name]], 201);
    }

    public function update(Request $r, string $organization)
    {
        $org = $this->owned($r, $organization);
        $data = $r->validate(['name' => 'required|string|max:100']);
        $org->update(['name' => trim($data['name'])]);

        return ['ok' => true];
    }

    /** Only an empty organization goes: its sites must be moved or deleted first, so nothing is lost by accident. */
    public function destroy(Request $r, string $organization)
    {
        $org = $this->owned($r, $organization);
        $data = $r->validate(['confirm' => 'required|string']);
        if (mb_strtolower(trim($data['confirm'])) !== mb_strtolower($org->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the organization\'s name exactly to confirm.']);
        }
        if ($org->sites()->exists()) {
            throw ValidationException::withMessages(['confirm' => 'Delete this organization\'s sites first.']);
        }
        DB::transaction(function () use ($org) {
            $org->teams()->each(fn ($t) => $t->erase());
            DB::table('organization_user')->where('organization_id', $org->id)->delete();
            Invitation::where('organization_id', $org->id)->delete();
            $org->delete();
        });

        return ['ok' => true];
    }

    /** An admin steps down. The owner cannot leave: they transfer ownership or delete the organization. */
    public function leave(Request $r, string $organization)
    {
        $org = $this->member($r, $organization);
        abort_if($org->owner_id === $r->user()->id, 422, 'The owner cannot leave. Transfer ownership or delete the organization.');
        $org->admins()->detach($r->user()->id);

        return ['ok' => true];
    }

    // ---- admins ------------------------------------------------------------------------------------------
    /** Someone with an account becomes an admin at once (and is told by e-mail); an unknown address gets an invitation when those are enabled. */
    public function addAdmin(Request $r, string $organization)
    {
        $org = $this->shared($r, $organization);
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

    public function removeAdmin(Request $r, string $organization, string $user)
    {
        $org = $this->shared($r, $organization);
        $org->admins()->detach($user);
        if ($org->transfer_to === $user) {
            $org->update(['transfer_to' => null, 'transfer_expires_at' => null]);
        }

        return ['ok' => true];
    }

    public function resendInvite(Request $r, string $organization, string $invite)
    {
        $org = $this->shared($r, $organization);
        $old = Invitation::where('organization_id', $org->id)->whereKey($invite)->firstOrFail();
        abort_unless(Invitation::enabled(), 422, 'Invitations are turned off.');
        Invitation::guardAbuse($r->user());
        $new = Invitation::issueForOrganization($old->email, $r->user(), $org);
        Mail::to($new->email)->send(new OrganizationAdminMail($org, $r->user(), $new));
        Invitation::countSent($r->user());

        return ['ok' => true];
    }

    public function revokeInvite(Request $r, string $organization, string $invite)
    {
        Invitation::where('organization_id', $this->shared($r, $organization)->id)->whereKey($invite)->delete();

        return ['ok' => true];
    }

    // ---- ownership ---------------------------------------------------------------------------------------
    /** The owner offers the organization to one of its admins. Nothing changes until that person accepts, within the invitation window. */
    public function transfer(Request $r, string $organization)
    {
        $org = $this->shared($r, $organization);
        $data = $r->validate(['user_id' => 'required|string', 'confirm' => 'required|string']);
        if (mb_strtolower(trim($data['confirm'])) !== mb_strtolower($org->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the organization\'s name exactly to confirm.']);
        }
        $to = $org->admins()->where('users.id', $data['user_id'])->first();
        if (! $to) {
            throw ValidationException::withMessages(['user_id' => 'Choose one of the organization\'s admins. Add the person as an admin first.']);
        }
        $this->authorizeTransfer($org, $to);
        $org->update(['transfer_to' => $to->id, 'transfer_expires_at' => now()->addHours((int) config('tidemark.invite_hours'))]);
        if (AuthController::mailConfigured()) {
            Mail::to($to->email)->send(new OwnershipTransferMail($org, $r->user()));
        }

        return ['ok' => true];
    }

    public function cancelTransfer(Request $r, string $organization)
    {
        $this->shared($r, $organization)->update(['transfer_to' => null, 'transfer_expires_at' => null]);

        return ['ok' => true];
    }

    /** The admin takes over: they become the owner, the previous owner stays on as an admin, and the sites follow. */
    public function acceptTransfer(Request $r, string $organization)
    {
        $me = $r->user();
        $org = Organization::where('transfer_to', $me->id)->findOrFail($organization);
        abort_unless($org->hasPendingTransfer(), 410, 'This offer has expired. Ask the owner to send it again.');
        $this->authorizeTransfer($org, $me);
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

    private function authorizeTransfer(Organization $org, User $to): void
    {
        $e = app(Entitlements::class);
        if ($e instanceof AuthorizesOwnershipTransfer && ($why = $e->allowsOwnershipTransfer($org, $to)) !== true) {
            throw ValidationException::withMessages(['user_id' => $why]);
        }
    }

    /** An organization this person owns or administers. */
    private function member(Request $r, string $id): Organization
    {
        return Organization::whereIn('id', Organization::idsFor($r->user()))->findOrFail($id);
    }

    /** One the person owns. */
    private function owned(Request $r, string $id): Organization
    {
        $org = Organization::where('owner_id', $r->user()->id)->findOrFail($id);
        abort_if($org->personal, 422, 'Your Personal organization is just for you: it cannot be renamed or deleted.');

        return $org;
    }

    /** One the person owns that can have other people in it (not Personal). */
    private function shared(Request $r, string $id): Organization
    {
        $org = Organization::where('owner_id', $r->user()->id)->findOrFail($id);
        abort_if($org->personal, 422, 'Your Personal organization is just for you. Create an organization to work with other people.');

        return $org;
    }
}
