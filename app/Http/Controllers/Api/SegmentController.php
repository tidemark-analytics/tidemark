<?php

namespace App\Http\Controllers\Api;

use App\Analytics\Support\Filters;
use App\Http\Controllers\Controller;
use App\Models\Segment;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Saved filter sets. Site segments are for everyone who can see the site and are managed by owners and admins; personal ones belong to their creator. */
class SegmentController extends Controller
{
    /** Segments the viewer can pick, including for public and shared dashboards (site segments only). */
    public function index(Request $r)
    {
        /** @var Site $site */
        $site = $r->attributes->get('site');
        if (! $site->segmentsAllowed()) {
            return ['allowed' => false, 'data' => []];
        }
        $user = $r->user();

        return ['allowed' => true, 'data' => Segment::where('site_id', $site->id)->visibleTo($user)->orderBy('name')->get()->map(fn ($s) => $this->present($s, $user))->all()];
    }

    public function store(Request $r)
    {
        $site = $this->allowedSite($r);
        $data = $this->validated($r);
        $this->guardType($r, $site, $data['type']);
        if (Segment::where('site_id', $site->id)->count() >= Segment::MAX_PER_SITE) {
            throw ValidationException::withMessages(['name' => 'This site has reached the limit of '.Segment::MAX_PER_SITE.' segments.']);
        }
        $segment = Segment::create(['site_id' => $site->id, 'user_id' => $r->user()->id, 'type' => $data['type'], 'name' => $data['name'], 'filters' => $data['filters']]);

        return response()->json(['data' => $this->present($segment, $r->user())], 201);
    }

    public function update(Request $r, string $site, string $segment)
    {
        $site = $this->allowedSite($r);
        $model = Segment::where('site_id', $site->id)->visibleTo($r->user())->findOrFail($segment);
        $this->guardType($r, $site, $model->type, $model);
        $data = $this->validated($r, partial: true);
        if (isset($data['type']) && $data['type'] !== $model->type) {
            $this->guardType($r, $site, $data['type']);
        }
        $model->update($data);

        return ['data' => $this->present($model->fresh(), $r->user())];
    }

    public function destroy(Request $r, string $site, string $segment)
    {
        $site = $this->allowedSite($r);
        $model = Segment::where('site_id', $site->id)->visibleTo($r->user())->findOrFail($segment);
        $this->guardType($r, $site, $model->type, $model);
        $model->delete();

        return ['ok' => true];
    }

    private function allowedSite(Request $r): Site
    {
        /** @var Site $site */
        $site = $r->attributes->get('site');
        abort_unless($site->segmentsAllowed(), 403, 'Segments are not available on this plan.');
        abort_unless($r->attributes->get('role') !== null, 403, 'Only people with access to this site can save segments.');

        return $site;
    }

    /** Anyone with access may keep personal segments; site segments are for owners and admins. */
    private function guardType(Request $r, Site $site, string $type, ?Segment $existing = null): void
    {
        $manager = in_array($r->attributes->get('role'), ['owner', 'admin'], true);
        if ($type === 'site') {
            abort_unless($manager, 403, 'Only owners and admins can change segments shared with everyone.');
        } elseif ($existing && $existing->user_id !== $r->user()->id) {
            abort(403, 'This is another person\'s segment.');
        }
    }

    /** @return array{name?:string,type?:string,filters?:array} */
    private function validated(Request $r, bool $partial = false): array
    {
        $rules = ['name' => 'required|string|max:100', 'type' => 'required|in:personal,site', 'filters' => 'required|array|min:1|max:20'];
        $data = $r->validate($partial ? array_map(fn ($v) => 'sometimes|'.$v, $rules) : $rules);
        if (isset($data['filters'])) {
            $clean = array_values(array_filter(Filters::sanitize($data['filters']), fn ($f) => $f[1] !== 'segment'));
            if (! $clean) {
                throw ValidationException::withMessages(['filters' => 'Add at least one filter to save a segment.']);
            }
            $data['filters'] = $clean;
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function present(Segment $s, $user): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'type' => $s->type, 'filters' => $s->filters, 'mine' => $user && $s->user_id === $user->id];
    }
}
