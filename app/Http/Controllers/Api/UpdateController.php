<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Updates\UpdateException;
use App\Updates\Updater;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function __construct(private Updater $updater) {}

    private function admin(Request $r): void
    {
        abort_unless($r->user()->is_admin, 403);
    }

    public function show(Request $r)
    {
        $this->admin($r);
        $staged = is_file($this->updater->stagedPath()) ? $this->safeInspect() : null;

        return [
            'version' => $this->updater->version(),
            'limits' => $this->updater->limits(),
            'zip' => class_exists(\ZipArchive::class),
            'writable' => $this->updater->canWrite(),
            'last' => $this->updater->lastUpdate(),
            'staged' => $staged,
        ];
    }

    /** Receive one slice of the zip (so hosts with small upload limits still work). */
    public function chunk(Request $r)
    {
        $this->admin($r);
        $data = $r->validate(['id' => 'required|string', 'index' => 'required|integer|min:0', 'total' => 'required|integer|min:1|max:2000', 'chunk' => 'required|file']);
        try {
            $done = $this->updater->stageChunk($data['id'], (int) $data['index'], (int) $data['total'], $r->file('chunk')->getRealPath());

            return ['done' => $done, 'staged' => $done ? $this->updater->inspect($this->updater->stagedPath()) : null];
        } catch (UpdateException $e) {
            @unlink($this->updater->stagedPath());

            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function apply(Request $r)
    {
        $this->admin($r);
        $force = $r->boolean('force');
        try {
            return ['result' => $this->updater->apply($this->updater->stagedPath(), $force)];
        } catch (UpdateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function check(Request $r)
    {
        $this->admin($r);
        try {
            return ['latest' => $this->updater->latest($r->boolean('fresh'))];
        } catch (UpdateException $e) {
            return ['latest' => null, 'error' => $e->getMessage()];
        }
    }

    /** Download the latest GitHub release, verify it, then install it. */
    public function download(Request $r)
    {
        $this->admin($r);
        try {
            $this->updater->downloadLatest();

            return ['result' => $this->updater->apply($this->updater->stagedPath())];
        } catch (UpdateException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function discard(Request $r)
    {
        $this->admin($r);
        @unlink($this->updater->stagedPath());

        return ['ok' => true];
    }

    private function safeInspect(): ?array
    {
        try {
            return $this->updater->inspect($this->updater->stagedPath());
        } catch (UpdateException) {
            return null;
        }
    }
}
