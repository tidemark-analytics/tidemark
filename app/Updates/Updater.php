<?php

namespace App\Updates;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ZipArchive;

/**
 * Installs a Tidemark release zip over the running application.
 *
 * Flow: chunks are assembled into storage/app/updates/incoming.zip, inspected, then applied (backup → copy files →
 * leave a "pending" marker). The next request finishes the job with the new code in memory: it runs migrations and
 * clears caches (see RunPendingUpdate), because the process that copied the files still runs the old classes.
 */
class Updater
{
    /** Paths (relative to the app root) that an update never touches. */
    public const PRESERVE = ['.env', 'storage', 'bootstrap/cache', 'database/database.sqlite', 'dist', 'node_modules', '.git', 'public/storage'];

    /** Directories where files missing from the new release are removed, so deleted code doesn't linger. */
    public const PRUNE = ['app', 'routes', 'resources/views', 'public/assets/js'];

    /** Directories and files saved before applying an update. */
    public const BACKUP = ['app', 'config', 'database/migrations', 'routes', 'resources', 'public', 'bootstrap/app.php', 'composer.json', 'composer.lock', 'artisan', 'VERSION'];

    private string $base;

    public function __construct(?string $base = null)
    {
        $this->base = rtrim($base ?? base_path(), '/');
    }

    public function version(): string
    {
        return is_file($this->base.'/VERSION') ? trim((string) file_get_contents($this->base.'/VERSION')) : 'dev';
    }

    public function dir(): string
    {
        $dir = $this->base.'/storage/app/updates';
        is_dir($dir) || @mkdir($dir, 0775, true);

        return $dir;
    }

    public function stagedPath(): string
    {
        return $this->dir().'/incoming.zip';
    }

    public function pendingPath(): string
    {
        return $this->base.'/storage/framework/update-pending';
    }

    public function migratedMarkerPath(): string
    {
        return $this->base.'/storage/framework/migrated-version';
    }

    /**
     * True after an in-app update, or after the files were replaced by hand (FTP): the release's VERSION file
     * differs from the version whose migrations last ran here.
     */
    public function hasPendingWork(): bool
    {
        if (is_file($this->pendingPath())) {
            return true;
        }
        if (! is_file($this->base.'/storage/app/installed')) {
            return false; // not installed yet — the web installer runs the migrations
        }
        $marker = is_file($this->migratedMarkerPath()) ? trim((string) file_get_contents($this->migratedMarkerPath())) : null;

        return $marker !== $this->version();
    }

    /** @return array{upload:int,post:int,chunk:int} */
    public function limits(): array
    {
        $upload = self::bytes((string) ini_get('upload_max_filesize'));
        $post = self::bytes((string) ini_get('post_max_size'));
        $cap = min($upload ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);
        $chunk = (int) max(64 * 1024, min(4 * 1024 * 1024, $cap * 0.8));

        return ['upload' => $upload, 'post' => $post, 'chunk' => $chunk];
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        $n = (int) $v;
        $unit = strtolower(substr($v, -1));

        return match ($unit) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    public function canWrite(): bool
    {
        return is_writable($this->base) && is_writable($this->base.'/app') && is_writable($this->dir());
    }

    /**
     * The newest published release on GitHub (cached for six hours unless $fresh).
     * Only called when an administrator opens the Updates page — Tidemark never phones home on its own.
     *
     * @return array{version:string,current:string,newer:bool,notes:string,url:string,published_at:?string,zip_url:?string,sha_url:?string,size:?int}
     *
     * @throws UpdateException
     */
    public function latest(bool $fresh = false): array
    {
        $repo = (string) config('tidemark.update_repo');
        if ($fresh) {
            Cache::forget('tdm:latest-release');
        }
        $release = Cache::remember('tdm:latest-release', 3600, fn () => $this->fetchLatestRelease($repo));
        $version = (string) $release['version'];
        $tag = (string) $release['tag'];
        $current = $this->version();

        // Asset links: use GitHub's own list when we have it, otherwise the release naming convention.
        $base = "https://github.com/{$repo}/releases/download/{$tag}/";
        $assets = collect($release['assets'] ?? []);
        $zip = $assets->first(fn ($a) => preg_match('/^tidemark-.+\.zip$/', $a['name'] ?? ''));
        $zipUrl = $zip['browser_download_url'] ?? "{$base}tidemark-{$version}.zip";
        $sha = $zip ? $assets->first(fn ($a) => ($a['name'] ?? '') === $zip['name'].'.sha256') : null;
        $shaUrl = $sha['browser_download_url'] ?? ($zip ? null : "{$base}tidemark-{$version}.zip.sha256");

        $latest = [
            'version' => $version, 'current' => $current,
            'newer' => $version !== '' && ($current === 'dev' || version_compare($version, $current, '>')),
            'notes' => (string) ($release['body'] ?? ''), 'url' => "https://github.com/{$repo}/releases/tag/{$tag}", 'published_at' => $release['published_at'] ?? null,
            'zip_url' => $zipUrl, 'sha_url' => $shaUrl, 'size' => $zip['size'] ?? null,
        ];
        $this->rememberLatest($latest['version'], $latest['url']);

        return $latest;
    }

    /**
     * The newest release. The version comes from github.com/<repo>/releases/latest, which answers with a redirect to the tag and
     * isn't subject to the API's low anonymous rate limit (shared hosts often exhaust it). The API is then asked, best effort,
     * for the release notes and the exact asset list.
     *
     * @return array<string,mixed>
     *
     * @throws UpdateException
     */
    private function fetchLatestRelease(string $repo): array
    {
        $headers = ['User-Agent' => 'Tidemark/'.$this->version()];
        $tag = null;
        try {
            $res = Http::timeout(15)->withOptions(['allow_redirects' => false])->withHeaders($headers)->get("https://github.com/{$repo}/releases/latest");
            if (preg_match('#/releases/tag/(v?\d+\.\d+\.\d+[^/?\s]*)$#', (string) $res->header('Location'), $m)) {
                $tag = rawurldecode($m[1]);
            }
        } catch (\Throwable) {
            // fall through to the API
        }

        $api = null;
        try {
            $res = Http::timeout(15)->withHeaders($headers + ['Accept' => 'application/vnd.github+json'])->get("https://api.github.com/repos/{$repo}/releases/latest");
            if ($res->ok() && is_array($res->json())) {
                $api = $res->json();
            } elseif (! $tag && $res->status() === 404) {
                throw new UpdateException('No published release was found.');
            } elseif (! $tag) {
                throw new UpdateException('GitHub answered with an error ('.$res->status().'). Try again later.');
            }
        } catch (UpdateException $e) {
            throw $e;
        } catch (\Throwable) {
            if (! $tag) {
                throw new UpdateException('Could not reach GitHub. Check the server\'s internet access, or upload the release zip manually.');
            }
        }

        $tag ??= (string) ($api['tag_name'] ?? '');
        if (! preg_match('/^v?\d+\.\d+\.\d+([\-+][\w.\-]+)?$/', $tag)) {
            throw new UpdateException('GitHub did not report a usable release version.');
        }
        $apiMatches = $api && (string) ($api['tag_name'] ?? '') === $tag;

        return [
            'tag' => $tag, 'version' => ltrim($tag, 'v'),
            'body' => $apiMatches ? (string) ($api['body'] ?? '') : '', 'published_at' => $apiMatches ? ($api['published_at'] ?? null) : null,
            'assets' => $apiMatches ? ($api['assets'] ?? []) : [],
        ];
    }

    public function latestPath(): string
    {
        return $this->dir().'/latest.json';
    }

    private function rememberLatest(string $version, string $url, ?string $error = null): void
    {
        file_put_contents($this->latestPath(), json_encode(['version' => $version, 'url' => $url, 'checked_at' => time(), 'error' => $error]));
    }

    /** @return array{version:string,url:string,checked_at:int,error:?string}|null */
    public function lastCheck(): ?array
    {
        $d = is_file($this->latestPath()) ? json_decode((string) file_get_contents($this->latestPath()), true) : null;

        return is_array($d) && isset($d['checked_at']) ? $d + ['version' => '', 'url' => '', 'error' => null] : null;
    }

    /**
     * A newer release found by the last check, or null. This reads a small file; it never contacts GitHub.
     *
     * @return array{version:string,url:string}|null
     */
    public function availableUpdate(): ?array
    {
        $d = $this->lastCheck();
        $current = $this->version();
        if (! $d || $d['version'] === '' || $current === 'dev' || ! version_compare($d['version'], $current, '>')) {
            return null;
        }

        return ['version' => $d['version'], 'url' => $d['url']];
    }

    /**
     * Ask GitHub for the latest release if the last check is older than $maxAgeHours. Failures are remembered so an offline
     * host doesn't retry on every request. Does nothing when TIDEMARK_UPDATE_CHECK is off.
     */
    public function refreshIfStale(int $maxAgeHours = 24): void
    {
        if (! config('tidemark.update_check')) {
            return;
        }
        $last = $this->lastCheck();
        if ($last && time() - $last['checked_at'] < $maxAgeHours * 3600) {
            return;
        }
        try {
            $this->latest(true);
        } catch (UpdateException $e) {
            $this->rememberLatest($last['version'] ?? '', $last['url'] ?? '', $e->getMessage());
        }
    }

    /**
     * Download the latest release zip into the staging slot and verify its checksum.
     *
     * @return array{version:string,current:string,newer:bool,root:string,files:int}
     *
     * @throws UpdateException
     */
    public function downloadLatest(): array
    {
        $latest = $this->latest(true);
        $prefix = 'https://github.com/'.config('tidemark.update_repo').'/releases/download/';
        if (! $latest['zip_url'] || ! str_starts_with($latest['zip_url'], $prefix)) {
            throw new UpdateException('The latest release has no downloadable zip.');
        }
        if (! $latest['sha_url'] || ! str_starts_with($latest['sha_url'], $prefix)) {
            throw new UpdateException('The latest release has no checksum file, so it cannot be verified.');
        }
        @set_time_limit(0);
        $target = $this->stagedPath();
        $tmp = $target.'.download';
        try {
            $sum = Http::timeout(30)->withHeaders(['User-Agent' => 'Tidemark/'.$this->version()])->get($latest['sha_url']);
            Http::timeout(600)->withHeaders(['User-Agent' => 'Tidemark/'.$this->version()])->sink($tmp)->get($latest['zip_url'])->throw();
        } catch (\Throwable) {
            @unlink($tmp);
            throw new UpdateException('The download failed. Try again, or upload the release zip manually.');
        }
        $expected = strtolower(substr(trim((string) $sum->body()), 0, 64));
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new UpdateException('The downloaded file did not match its checksum and was discarded.');
        }
        rename($tmp, $target);
        try {
            $info = $this->inspect($target);
            if ($info['version'] !== $latest['version']) {
                throw new UpdateException('The downloaded release does not match the version GitHub announced.');
            }
        } catch (UpdateException $e) {
            @unlink($target);
            throw $e;
        }

        return $info;
    }

    /**
     * Append one chunk of an upload. Returns true once the last chunk arrived and the zip is staged.
     *
     * @throws UpdateException
     */
    public function stageChunk(string $id, int $index, int $total, string $chunkPath): bool
    {
        if (! preg_match('/^[a-f0-9-]{36}$/', $id) || $total < 1 || $index < 0 || $index >= $total) {
            throw new UpdateException('Invalid upload.');
        }
        $part = $this->dir()."/{$id}.part";
        $next = is_file($part.'.idx') ? (int) file_get_contents($part.'.idx') : 0;
        if ($index !== $next) {
            throw new UpdateException('Upload chunks arrived out of order. Start the upload again.');
        }
        $out = fopen($part, $index === 0 ? 'wb' : 'ab');
        $in = fopen($chunkPath, 'rb');
        if (! $out || ! $in) {
            throw new UpdateException('Could not write the upload to storage/app/updates.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        file_put_contents($part.'.idx', (string) ($index + 1));

        if ($index + 1 < $total) {
            return false;
        }
        @unlink($part.'.idx');
        @unlink($this->stagedPath());
        rename($part, $this->stagedPath());

        return true;
    }

    /**
     * Validate a release zip and describe it.
     *
     * @return array{version:string,current:string,newer:bool,root:string,files:int}
     *
     * @throws UpdateException
     */
    public function inspect(string $zipPath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new UpdateException('PHP\'s zip extension is not available on this server.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('That file is not a valid zip archive.');
        }
        try {
            $names = [];
            $bytes = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = $st['name'];
                if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name) || str_contains($name, "\0")) {
                    throw new UpdateException('The archive contains unsafe file paths.');
                }
                $names[] = $name;
                $bytes += $st['size'];
            }
            if (count($names) > 60000 || $bytes > 800 * 1024 * 1024) {
                throw new UpdateException('The archive is unexpectedly large.');
            }
            $root = $this->detectRoot($names);
            foreach (['artisan', 'VERSION', 'app/Updates/Updater.php', 'vendor/autoload.php', 'config/tidemark.php'] as $required) {
                if (! in_array($root.$required, $names, true)) {
                    throw new UpdateException("This doesn't look like a Tidemark release (missing {$required}).");
                }
            }
            $version = trim((string) $zip->getFromName($root.'VERSION'));
            if (! preg_match('/^\d+\.\d+\.\d+([\-+][\w.\-]+)?$/', $version)) {
                throw new UpdateException('The release has no valid version number.');
            }
            $current = $this->version();

            return ['version' => $version, 'current' => $current, 'newer' => $current === 'dev' || version_compare($version, $current, '>'), 'root' => $root, 'files' => count($names)];
        } finally {
            $zip->close();
        }
    }

    /** @param array<int,string> $names */
    private function detectRoot(array $names): string
    {
        if (in_array('artisan', $names, true)) {
            return '';
        }
        $first = explode('/', $names[0] ?? '', 2)[0];

        return $first !== '' && in_array($first.'/artisan', $names, true) ? $first.'/' : '';
    }

    private function preserved(string $rel): bool
    {
        foreach (self::PRESERVE as $p) {
            if ($rel === $p || str_starts_with($rel, $p.'/')) {
                return true;
            }
        }

        return (bool) preg_match('#^database/[^/]+\.sqlite(-\w+)?$#', $rel);
    }

    /**
     * Back up, copy the release over the app and mark the update as pending.
     *
     * @return array{from:string,to:string,backup:string,files:int,removed:int}
     *
     * @throws UpdateException
     */
    public function apply(string $zipPath, bool $force = false): array
    {
        $info = $this->inspect($zipPath);
        if (! $info['newer'] && ! $force) {
            throw new UpdateException("This install is already on {$info['current']}. Choose \"Install anyway\" to reinstall {$info['version']}.");
        }
        if (! $this->canWrite()) {
            throw new UpdateException('The application folder is not writable by PHP, so it cannot update itself. Upload the files manually instead.');
        }
        if (is_file($this->pendingPath())) {
            throw new UpdateException('A previous update is still finishing. Reload the page and try again.');
        }

        @set_time_limit(0);
        $backup = $this->backup($info['current']);
        $written = [];
        $zip = new ZipArchive;
        $zip->open($zipPath);
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $rel = substr($name, strlen($info['root']));
                if ($rel === '' || str_ends_with($name, '/') || $this->preserved($rel)) {
                    continue;
                }
                $target = $this->base.'/'.$rel;
                is_dir(dirname($target)) || mkdir(dirname($target), 0775, true);
                $tmp = $target.'.tdm-new';
                $in = $zip->getStream($name);
                $out = $in ? fopen($tmp, 'wb') : false;
                if (! $in || ! $out) {
                    throw new UpdateException("Could not write {$rel}.");
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                @chmod($tmp, 0644);
                if (! rename($tmp, $target)) {
                    throw new UpdateException("Could not replace {$rel}.");
                }
                $written[$rel] = true;
            }
        } catch (\Throwable $e) {
            $zip->close();
            $this->restore($backup);
            throw $e instanceof UpdateException ? $e : new UpdateException($e->getMessage(), 0, $e);
        }
        $zip->close();

        $removed = $this->prune($written);
        file_put_contents($this->pendingPath(), json_encode(['from' => $info['current'], 'to' => $info['version'], 'at' => time(), 'backup' => basename($backup)]));
        @unlink($zipPath);
        self::resetOpcache();

        return ['from' => $info['current'], 'to' => $info['version'], 'backup' => basename($backup), 'files' => count($written), 'removed' => $removed];
    }

    /** @param array<string,true> $keep */
    private function prune(array $keep): int
    {
        $removed = 0;
        foreach (self::PRUNE as $dir) {
            $root = $this->base.'/'.$dir;
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = ltrim(substr($file->getPathname(), strlen($this->base)), '/');
                if ($file->isFile() && ! isset($keep[$rel]) && ! $this->preserved($rel) && @unlink($file->getPathname())) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function backup(string $version): string
    {
        $dir = $this->dir().'/backups';
        is_dir($dir) || @mkdir($dir, 0775, true);
        $path = $dir.'/backup-'.preg_replace('/[^\w.\-]/', '', $version).'-'.date('Ymd-His').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new UpdateException('Could not create a backup in storage/app/updates/backups.');
        }
        foreach (self::BACKUP as $item) {
            $abs = $this->base.'/'.$item;
            if (is_file($abs)) {
                $zip->addFile($abs, $item);
            } elseif (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    $rel = ltrim(substr($file->getPathname(), strlen($this->base)), '/');
                    if ($file->isFile() && ! $this->preserved($rel)) {
                        $zip->addFile($file->getPathname(), $rel);
                    }
                }
            }
        }
        $zip->close();
        $old = glob($dir.'/backup-*.zip') ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 3)) as $f) {
            @unlink($f);
        }

        return $path;
    }

    private function restore(string $backup): void
    {
        $zip = new ZipArchive;
        if ($zip->open($backup) === true) {
            $zip->extractTo($this->base);
            $zip->close();
        }
    }

    /**
     * Run by the first request after an update, with the new code loaded.
     *
     * @return array<string,mixed>|null
     */
    public function finishIfPending(): ?array
    {
        $flag = $this->pendingPath();
        if (! is_file($flag)) {
            if (! $this->hasPendingWork()) {
                return null;
            }
            // files were replaced outside the updater: record the change so the same path handles it
            $from = is_file($this->migratedMarkerPath()) ? trim((string) file_get_contents($this->migratedMarkerPath())) : 'unknown';
            file_put_contents($flag, json_encode(['from' => $from, 'to' => $this->version(), 'at' => time(), 'backup' => null]));
        }
        $lock = fopen($flag, 'r');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return null; // another request is already finishing it
        }
        try {
            $meta = json_decode((string) stream_get_contents($lock), true) ?: [];
            foreach (glob($this->base.'/bootstrap/cache/*.php') ?: [] as $cached) {
                @unlink($cached);
            }
            self::resetOpcache();
            $meta['ok'] = true;
            try {
                Artisan::call('migrate', ['--force' => true]);
                $meta['migrations'] = trim(Artisan::output());
                Artisan::call('view:clear');
            } catch (\Throwable $e) {
                $meta['ok'] = false;
                $meta['error'] = $e->getMessage();
            }
            $meta['finished_at'] = time();
            file_put_contents($this->dir().'/last.json', json_encode($meta));
            if ($meta['ok']) {
                file_put_contents($this->migratedMarkerPath(), $this->version());
            }
            @unlink($flag);

            return $meta;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed>|null */
    public function lastUpdate(): ?array
    {
        $f = $this->dir().'/last.json';

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }
}
