<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\SharedLink;
use App\Models\ShieldRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Per-site settings: goals, shared links, shields and people. */
class SettingsController extends Controller
{
    // ---- goals -------------------------------------------------------------------------------------------
    public function goals(Request $r)
    {
        return ['data' => $r->attributes->get('site')->goals()->orderBy('created_at')->get()];
    }

    public function createGoal(Request $r)
    {
        $site = $r->attributes->get('site');
        $data = $r->validate(['type' => ['required', Rule::in(['event', 'page'])], 'value' => 'required|string|max:255']);
        $value = trim($data['value']);
        if ($data['type'] === 'page' && ! str_starts_with($value, '/')) {
            $value = '/'.$value;
        }
        if (Goal::where('site_id', $site->id)->where('type', $data['type'])->where('value', $value)->exists()) {
            throw ValidationException::withMessages(['value' => 'That goal already exists.']);
        }

        return response()->json(['data' => Goal::create(['site_id' => $site->id, 'type' => $data['type'], 'value' => $value])], 201);
    }

    public function deleteGoal(Request $r, string $site, string $goal)
    {
        $r->attributes->get('site')->goals()->whereKey($goal)->delete();

        return ['ok' => true];
    }

    // ---- shared links ------------------------------------------------------------------------------------
    public function sharedLinks(Request $r)
    {
        return ['data' => $r->attributes->get('site')->sharedLinks()->orderBy('created_at')->get()];
    }

    public function createSharedLink(Request $r)
    {
        $site = $r->attributes->get('site');
        $data = $r->validate(['name' => 'required|string|max:100', 'password' => 'nullable|string|min:4|max:100']);
        $link = SharedLink::create([
            'site_id' => $site->id, 'name' => $data['name'], 'slug' => Str::random(24),
            'password_hash' => ! empty($data['password']) ? Hash::make($data['password']) : null,
        ]);

        return response()->json(['data' => $link], 201);
    }

    public function updateSharedLink(Request $r, string $site, string $link)
    {
        $model = $r->attributes->get('site')->sharedLinks()->findOrFail($link);
        $data = $r->validate(['name' => 'sometimes|required|string|max:100', 'password' => 'sometimes|nullable|string|min:4|max:100']);
        if (array_key_exists('name', $data)) {
            $model->name = $data['name'];
        }
        if (array_key_exists('password', $data)) {
            $model->password_hash = $data['password'] ? Hash::make($data['password']) : null;
        }
        $model->save();

        return ['data' => $model];
    }

    public function deleteSharedLink(Request $r, string $site, string $link)
    {
        $r->attributes->get('site')->sharedLinks()->whereKey($link)->delete();

        return ['ok' => true];
    }

    // ---- shields (block lists) ---------------------------------------------------------------------------
    public function shields(Request $r)
    {
        return ['data' => $r->attributes->get('site')->shields()->orderBy('created_at')->get()];
    }

    public function createShield(Request $r)
    {
        $site = $r->attributes->get('site');
        $data = $r->validate(['type' => ['required', Rule::in(['ip', 'country', 'page', 'hostname'])], 'value' => 'required|string|max:255']);
        $value = trim($data['value']);
        if ($data['type'] === 'country') {
            $value = strtoupper($value);
            if (! preg_match('/^[A-Z]{2}$/', $value)) {
                throw ValidationException::withMessages(['value' => 'Use a two-letter country code, e.g. US.']);
            }
        }
        if ($data['type'] === 'ip' && ! preg_match('/^[0-9a-fA-F:.*\/]+$/', $value)) {
            throw ValidationException::withMessages(['value' => 'Enter an IP address, a CIDR range or a pattern like 192.168.*.']);
        }
        if (count($site->shields) >= 500) {
            throw ValidationException::withMessages(['value' => 'Too many rules.']);
        }
        $rule = ShieldRule::firstOrCreate(['site_id' => $site->id, 'type' => $data['type'], 'value' => $value]);
        Cache::forget('tdm:site:'.$site->domain);

        return response()->json(['data' => $rule], 201);
    }

    public function deleteShield(Request $r, string $site, string $rule)
    {
        $s = $r->attributes->get('site');
        $s->shields()->whereKey($rule)->delete();
        Cache::forget('tdm:site:'.$s->domain);

        return ['ok' => true];
    }

    // ---- people ------------------------------------------------------------------------------------------
    public function people(Request $r)
    {
        return ['data' => $r->attributes->get('site')->members()->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role])];
    }

    /** Add an existing account to the site (accounts are created by an administrator; there is no e-mail invite flow). */
    public function addPerson(Request $r)
    {
        $site = $r->attributes->get('site');
        $data = $r->validate(['email' => 'required|email', 'role' => ['required', Rule::in(['admin', 'viewer'])]]);
        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'No account with that e-mail. Ask an administrator to create one first.']);
        }
        if ($site->members()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'This person already has access.']);
        }
        $site->members()->attach($user->id, ['role' => $data['role'], 'id' => (string) Str::uuid()]);

        return response()->json(['ok' => true], 201);
    }

    public function updatePerson(Request $r, string $site, string $user)
    {
        $s = $r->attributes->get('site');
        $data = $r->validate(['role' => ['required', Rule::in(['admin', 'viewer'])]]);
        abort_if($s->members()->where('users.id', $user)->first()?->pivot->role === 'owner', 422, 'The owner role cannot be changed.');
        $s->members()->updateExistingPivot($user, ['role' => $data['role']]);

        return ['ok' => true];
    }

    public function removePerson(Request $r, string $site, string $user)
    {
        $s = $r->attributes->get('site');
        abort_if($s->members()->where('users.id', $user)->first()?->pivot->role === 'owner', 422, 'The owner cannot be removed.');
        $s->members()->detach($user);

        return ['ok' => true];
    }
}
