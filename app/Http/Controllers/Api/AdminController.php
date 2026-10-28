<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ReportSubscription;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;

/** Minimal user administration — accounts are created here because there is no e-mail invitation flow on shared hosting. */
class AdminController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->user()->is_admin, 403);

        return ['data' => User::withCount('sites')->orderBy('name')->get(['id', 'name', 'email', 'is_admin', 'created_at'])];
    }

    public function store(Request $r)
    {
        abort_unless($r->user()->is_admin, 403);
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8', 'is_admin' => 'boolean']);
        $u = User::create($data);
        $u->forceFill(['is_admin' => (bool) ($data['is_admin'] ?? false), 'email_verified_at' => now()])->save(); // accounts an administrator creates need no confirmation
        Organization::forOwner($u);

        return response()->json(['data' => $u], 201);
    }

    public function update(Request $r, string $user)
    {
        abort_unless($r->user()->is_admin, 403);
        $u = User::findOrFail($user);
        $data = $r->validate(['name' => 'sometimes|string|max:100', 'email' => 'sometimes|email|unique:users,email,'.$u->id.',id', 'password' => 'sometimes|string|min:8', 'is_admin' => 'sometimes|boolean']);
        abort_if($u->id === $r->user()->id && isset($data['is_admin']) && ! $data['is_admin'], 422, 'You cannot remove your own admin role.');
        $u->fill(collect($data)->except('is_admin')->all());
        if (isset($data['is_admin'])) {
            $u->is_admin = $data['is_admin'];
        }
        $u->save();

        return ['data' => $u];
    }

    public function destroy(Request $r, string $user)
    {
        abort_unless($r->user()->is_admin, 403);
        abort_if($user === $r->user()->id, 422, 'You cannot delete yourself.');
        $u = User::findOrFail($user);
        $u->sites()->detach();
        $u->teams()->detach();
        \DB::table('organization_user')->where('user_id', $u->id)->delete();
        // organizations they owned go with them; the sites in them keep their data
        $u->ownedOrganizations()->each(function (Organization $o) {
            $o->teams()->each(fn ($t) => $t->erase());
            \DB::table('organization_user')->where('organization_id', $o->id)->delete();
            Site::where('organization_id', $o->id)->update(['organization_id' => null]);
            $o->delete();
        });
        $u->apiKeys()->delete();
        ReportSubscription::where('user_id', $u->id)->delete();
        $u->delete();

        return ['ok' => true];
    }
}
