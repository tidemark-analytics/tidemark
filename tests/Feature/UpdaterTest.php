<?php

namespace Tests\Feature;

use App\Models\User;
use App\Updates\UpdateException;
use App\Updates\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/tdm-upd-'.uniqid();
        foreach (['app/Old', 'storage/app', 'storage/framework', 'database', 'config', 'bootstrap/cache'] as $d) {
            mkdir($this->base.'/'.$d, 0775, true);
        }
        file_put_contents($this->base.'/VERSION', '1.0.0');
        file_put_contents($this->base.'/.env', 'APP_KEY=secret');
        file_put_contents($this->base.'/artisan', 'old');
        file_put_contents($this->base.'/app/Old/Gone.php', '<?php // removed in the new release');
        file_put_contents($this->base.'/app/Keep.php', 'old');
        file_put_contents($this->base.'/database/database.sqlite', 'user data');
        file_put_contents($this->base.'/storage/app/data.txt', 'keep me');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    /** @param array<string,string> $extra */
    private function release(string $version, array $extra = [], string $root = 'tidemark-X/', bool $complete = true): string
    {
        $files = ['artisan' => 'new', 'VERSION' => $version, 'app/Updates/Updater.php' => '<?php', 'vendor/autoload.php' => '<?php', 'config/tidemark.php' => '<?php', 'app/Keep.php' => 'new', '.env' => 'OVERWRITTEN', 'storage/app/data.txt' => 'OVERWRITTEN', 'database/database.sqlite' => 'OVERWRITTEN'] + $extra;
        if (! $complete) {
            unset($files['vendor/autoload.php']);
        }
        $path = $this->base.'/release-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($root.$name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_apply_replaces_code_keeps_user_data_and_prunes_removed_files(): void
    {
        $result = (new Updater($this->base))->apply($this->release('1.1.0'));

        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0'], ['from' => $result['from'], 'to' => $result['to']]);
        $this->assertSame('1.1.0', trim(file_get_contents($this->base.'/VERSION')));
        $this->assertSame('new', file_get_contents($this->base.'/app/Keep.php'));
        $this->assertFileDoesNotExist($this->base.'/app/Old/Gone.php');
        $this->assertSame('APP_KEY=secret', file_get_contents($this->base.'/.env'));
        $this->assertSame('user data', file_get_contents($this->base.'/database/database.sqlite'));
        $this->assertSame('keep me', file_get_contents($this->base.'/storage/app/data.txt'));
        $this->assertFileExists($this->base.'/storage/framework/update-pending');
        $this->assertCount(1, glob($this->base.'/storage/app/updates/backups/backup-1.0.0-*.zip'));
        $this->assertEmpty(glob($this->base.'/**/*.tdm-new'));
    }

    public function test_release_without_a_wrapping_folder_is_accepted(): void
    {
        $info = (new Updater($this->base))->inspect($this->release('1.2.0', [], ''));
        $this->assertSame(['1.2.0', true], [$info['version'], $info['newer']]);
    }

    public function test_unsafe_incomplete_and_non_zip_files_are_rejected(): void
    {
        $updater = new Updater($this->base);
        foreach ([
            [$this->release('1.1.0', ['../evil.php' => 'x']), 'unsafe'],
            [$this->release('1.1.0', [], 'tidemark-X/', false), 'missing vendor/autoload.php'],
            [$this->release('banana'), 'valid version'],
        ] as [$zip, $message]) {
            try {
                $updater->inspect($zip);
                $this->fail("expected rejection: {$message}");
            } catch (UpdateException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        file_put_contents($this->base.'/not.zip', 'hello');
        $this->expectException(UpdateException::class);
        $updater->inspect($this->base.'/not.zip');
    }

    public function test_older_or_equal_versions_need_force(): void
    {
        $updater = new Updater($this->base);
        $this->assertFalse($updater->inspect($this->release('1.0.0'))['newer']);
        $this->assertFalse($updater->inspect($this->release('0.9.0'))['newer']);
        try {
            $updater->apply($this->release('1.0.0'));
            $this->fail('should refuse');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('already on 1.0.0', $e->getMessage());
        }
        $this->assertSame('1.0.0', $updater->apply($this->release('1.0.0'), true)['to']);
    }

    public function test_finishing_runs_migrations_once_and_clears_the_marker(): void
    {
        $updater = new Updater($this->base);
        $updater->apply($this->release('1.1.0'));
        $meta = $updater->finishIfPending();
        $this->assertTrue($meta['ok']);
        $this->assertFileDoesNotExist($this->base.'/storage/framework/update-pending');
        $this->assertNull($updater->finishIfPending());
        $this->assertSame('1.0.0', $updater->lastUpdate()['from']);
    }

    public function test_upload_endpoints_are_admin_only_and_assemble_chunks(): void
    {
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password']);
        $admin->forceFill(['is_admin' => true])->save();
        $member = User::create(['name' => 'M', 'email' => 'm@example.com', 'password' => 'password']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');

        $this->actingAs($member)->getJson('/api/admin/update')->assertForbidden();
        $this->postJson('/api/admin/update/apply')->assertForbidden();

        $this->actingAs($admin)->getJson('/api/admin/update')->assertOk()->assertJsonStructure(['version', 'limits' => ['chunk'], 'writable']);

        $zip = $this->release('9.9.9');
        $bytes = file_get_contents($zip);
        $mid = intdiv(strlen($bytes), 2);
        $id = '11111111-2222-3333-4444-555555555555';
        $send = fn (int $i, string $part) => $this->post('/api/admin/update/chunk', ['id' => $id, 'index' => $i, 'total' => 2, 'chunk' => UploadedFile::fake()->createWithContent('c.bin', $part)], ['Accept' => 'application/json']);

        $send(1, substr($bytes, $mid))->assertStatus(422); // out of order
        $send(0, substr($bytes, 0, $mid))->assertOk()->assertJson(['done' => false]);
        $send(1, substr($bytes, $mid))->assertOk()->assertJsonPath('staged.version', '9.9.9');
        $this->assertFileExists(storage_path('app/updates/incoming.zip'));
        $this->deleteJson('/api/admin/update')->assertOk();
        $this->assertFileDoesNotExist(storage_path('app/updates/incoming.zip'));
    }
}
