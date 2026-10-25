<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Notes on the graph. Anyone with access to a site may keep private notes; owners and admins may also share them. */
class AnnotationController extends Controller
{
    /** Notes the viewer may see (public and shared views: public ones only), optionally between two dates. */
    public function index(Request $r)
    {
        /** @var Site $site */
        $site = $r->attributes->get('site');
        $q = Annotation::where('site_id', $site->id)->visibleTo($r->user(), $r->attributes->get('role'));
        if ($r->filled('from')) {
            $q->where('date', '>=', (string) $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->where('date', '<=', (string) $r->input('to'));
        }

        return ['data' => $q->orderBy('date')->orderBy('created_at')->get()->map(fn ($a) => self::present($a, $r->user(), $r->attributes->get('role')))->all()];
    }

    public function store(Request $r)
    {
        $site = $this->member($r);
        $data = $this->validated($r);
        $this->guardVisibility($r, $data['visibility']);
        if (Annotation::where('site_id', $site->id)->count() >= Annotation::MAX_PER_SITE) {
            throw ValidationException::withMessages(['note' => 'This site has reached the limit of '.Annotation::MAX_PER_SITE.' notes.']);
        }
        $note = Annotation::create($data + ['site_id' => $site->id, 'user_id' => $r->user()->id]);

        return response()->json(['data' => self::present($note, $r->user(), $r->attributes->get('role'))], 201);
    }

    public function update(Request $r, string $site, string $annotation)
    {
        $s = $this->member($r);
        $note = $this->editable($r, $s, $annotation);
        $data = $this->validated($r, partial: true);
        if (isset($data['visibility']) && $data['visibility'] !== $note->visibility) {
            $this->guardVisibility($r, $data['visibility']);
        }
        $note->update($data);

        return ['data' => self::present($note->fresh(), $r->user(), $r->attributes->get('role'))];
    }

    public function destroy(Request $r, string $site, string $annotation)
    {
        $s = $this->member($r);
        $this->editable($r, $s, $annotation)->delete();

        return ['ok' => true];
    }

    /** @return array{id: string, date: string, note: string, visibility: string, mine: bool, editable: bool} */
    public static function present(Annotation $a, $user, ?string $role): array
    {
        return ['id' => $a->id, 'date' => $a->date->toDateString(), 'note' => $a->note, 'visibility' => $a->visibility, 'mine' => $user && $a->user_id === $user->id, 'editable' => $a->editableBy($user, $role)];
    }

    private function member(Request $r): Site
    {
        abort_unless($r->attributes->get('role') !== null, 403, 'Only people with access to this site can add notes.');

        return $r->attributes->get('site');
    }

    private function editable(Request $r, Site $site, string $id): Annotation
    {
        $note = Annotation::where('site_id', $site->id)->visibleTo($r->user(), $r->attributes->get('role'))->findOrFail($id);
        abort_unless($note->editableBy($r->user(), $r->attributes->get('role')), 403, 'You can only change your own notes, or notes shared with the site if you are an owner or admin.');

        return $note;
    }

    /** Notes only for the author are open to everyone with access; notes the whole site (or the public) sees are for owners and admins. */
    private function guardVisibility(Request $r, string $visibility): void
    {
        abort_unless($visibility === 'private' || in_array($r->attributes->get('role'), ['owner', 'admin'], true), 403, 'Only owners and admins can share notes with the site.');
    }

    /** @return array{date?: string, note?: string, visibility?: string} */
    private function validated(Request $r, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes|' : '';
        $data = $r->validate([
            'date' => [$partial ? 'sometimes' : 'required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:'.now()->addYears(2)->toDateString()],
            'note' => [$partial ? 'sometimes' : 'required', 'string', 'min:1', 'max:280'],
            'visibility' => [$partial ? 'sometimes' : 'required', 'in:'.implode(',', Annotation::VISIBILITIES)],
        ]);
        if (isset($data['note'])) {
            $data['note'] = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $data['note']));
            if ($data['note'] === '') {
                throw ValidationException::withMessages(['note' => 'Write something.']);
            }
        }

        return $data;
    }
}
