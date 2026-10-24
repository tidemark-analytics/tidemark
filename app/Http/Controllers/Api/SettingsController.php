<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Support\Sql;
use App\Http\Controllers\Controller;
use App\Models\Funnel;
use App\Models\FunnelStep;
use App\Models\Goal;
use App\Models\SharedLink;
use App\Models\ShieldRule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $data = $r->validate(['type' => ['required', Rule::in(['event', 'page'])], 'value' => 'required|string|max:255', 'currency' => ['nullable', 'regex:/^[A-Za-z]{3}$/']]);
        $value = trim($data['value']);
        if ($data['type'] === 'page' && ! str_starts_with($value, '/')) {
            $value = '/'.$value;
        }
        if (Goal::where('site_id', $site->id)->where('type', $data['type'])->where('value', $value)->exists()) {
            throw ValidationException::withMessages(['value' => 'That goal already exists.']);
        }
        $currency = $this->currencyFor($site, $data['type'], $data['currency'] ?? null);

        return response()->json(['data' => Goal::create(['site_id' => $site->id, 'type' => $data['type'], 'value' => $value, 'currency' => $currency])], 201);
    }

    /** Turn revenue reporting on, change its currency, or turn it off (null) for a custom-event goal. */
    public function updateGoal(Request $r, string $site, string $goal)
    {
        $site = $r->attributes->get('site');
        $model = $site->goals()->findOrFail($goal);
        $data = $r->validate(['currency' => ['present', 'nullable', 'regex:/^[A-Za-z]{3}$/']]);
        $currency = $this->currencyFor($site, $model->type, $data['currency']);
        if ($currency !== $model->currency && $model->type === 'event') {
            // amounts converted into the previous currency no longer apply
            Sql::connection()->table('events')->where('site_id', $site->id)->where('name', $model->value)->update(['revenue_reporting' => null]);
        }
        $model->update(['currency' => $currency]);

        return ['data' => $model->fresh()];
    }

    private function currencyFor($site, string $type, ?string $currency): ?string
    {
        if ($currency === null || $currency === '') {
            return null;
        }
        if ($type !== 'event') {
            throw ValidationException::withMessages(['currency' => 'Only custom events can report revenue.']);
        }
        if (! $site->revenueAllowed()) {
            throw ValidationException::withMessages(['currency' => 'Revenue reporting is not available on this plan.']);
        }

        return strtoupper($currency);
    }

    public function deleteGoal(Request $r, string $site, string $goal)
    {
        $site = $r->attributes->get('site');
        $inFunnel = FunnelStep::where('goal_id', $goal)->join('funnels', 'funnels.id', '=', 'funnel_steps.funnel_id')->where('funnels.site_id', $site->id)->value('funnels.name');
        if ($inFunnel) {
            throw ValidationException::withMessages(['goal' => "This goal is a step in the \"{$inFunnel}\" funnel. Remove it from the funnel first."]);
        }
        $site->goals()->whereKey($goal)->delete();

        return ['ok' => true];
    }

    // ---- funnels -----------------------------------------------------------------------------------------
    public function funnels(Request $r)
    {
        $funnels = $r->attributes->get('site')->funnels()->with('steps')->orderBy('created_at')->get();

        return ['data' => $funnels->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'steps' => $f->steps->map(fn ($s) => ['goal_id' => $s->goal_id])->all()])->all()];
    }

    public function createFunnel(Request $r)
    {
        $site = $r->attributes->get('site');
        $funnel = DB::transaction(fn () => $this->saveFunnel($site, new Funnel(['site_id' => $site->id]), $this->funnelData($r, $site)));

        return response()->json(['data' => $funnel], 201);
    }

    public function updateFunnel(Request $r, string $site, string $funnel)
    {
        $site = $r->attributes->get('site');
        $model = $site->funnels()->findOrFail($funnel);

        return ['data' => DB::transaction(fn () => $this->saveFunnel($site, $model, $this->funnelData($r, $site)))];
    }

    public function deleteFunnel(Request $r, string $site, string $funnel)
    {
        $model = $r->attributes->get('site')->funnels()->findOrFail($funnel);
        FunnelStep::where('funnel_id', $model->id)->delete();
        $model->delete();

        return ['ok' => true];
    }

    /** @return array{name:string,steps:array<int,string>} */
    private function funnelData(Request $r, $site): array
    {
        abort_unless($site->funnelsAllowed(), 403, 'Funnels are not available on this plan.');
        $data = $r->validate(['name' => 'required|string|max:100', 'steps' => 'required|array|min:'.Funnel::MIN_STEPS.'|max:'.Funnel::MAX_STEPS, 'steps.*' => 'required|string']);
        $known = $site->goals()->pluck('id')->all();
        foreach ($data['steps'] as $id) {
            if (! in_array($id, $known, true)) {
                throw ValidationException::withMessages(['steps' => 'Each step must be one of this site\'s goals.']);
            }
        }

        return ['name' => trim($data['name']), 'steps' => array_values($data['steps'])];
    }

    private function saveFunnel($site, Funnel $funnel, array $data): Funnel
    {
        $funnel->fill(['name' => $data['name']])->save();
        FunnelStep::where('funnel_id', $funnel->id)->delete();
        foreach ($data['steps'] as $i => $goalId) {
            FunnelStep::create(['funnel_id' => $funnel->id, 'goal_id' => $goalId, 'position' => $i + 1]);
        }

        return $funnel->load('steps');
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
        abort_if($site->isRollup(), 422, 'Shields apply to the sites a rollup adds up, not to the rollup.');
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
        abort_if($site->isRollup(), 422, 'A rollup is private to its owner. Share it with a shared link instead.');
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
