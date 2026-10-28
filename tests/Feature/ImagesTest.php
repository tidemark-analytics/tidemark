<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($this->owner);
        $this->org = $this->postJson('/api/organizations', ['name' => 'Acme'])->json('data.id');
    }

    /** A real PNG of the given size, built by hand so the tests need no image extension. */
    private function png(int $w = 64, int $h = 64, string $name = 'a.png'): UploadedFile
    {
        $row = "\0".str_repeat("\x30\x90\xa0", $w);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress(str_repeat($row, $h))).$chunk('IEND', '');
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_a_profile_photo_is_stored_served_and_removed(): void
    {
        $this->assertNull($this->getJson('/api/me')->json('user.avatar_url'));
        $url = $this->postJson('/api/me/avatar', ['image' => $this->png()])->assertOk()->json('avatar_url');
        $this->assertStringContainsString('/api/avatars/users/'.$this->owner->id.'?v=', $url);
        $this->assertSame($url, $this->getJson('/api/me')->json('user.avatar_url'));

        $res = $this->get('/api/avatars/users/'.$this->owner->id)->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $res->headers->get('Cache-Control'));
        $this->assertStringStartsWith("\x89PNG", $res->getContent());
        $this->assertArrayNotHasKey('avatar', $this->getJson('/api/me')->json('user')); // the bytes never ride along in JSON

        $this->deleteJson('/api/me/avatar')->assertOk()->assertJsonPath('avatar_url', null);
        $this->get('/api/avatars/users/'.$this->owner->id)->assertNotFound();
    }

    public function test_only_small_raster_images_are_accepted_whatever_they_are_called(): void
    {
        $svg = UploadedFile::fake()->createWithContent('a.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->postJson('/api/me/avatar', ['image' => $svg])->assertUnprocessable();
        $this->postJson('/api/me/avatar', ['image' => UploadedFile::fake()->createWithContent('a.png', 'not an image at all')])->assertUnprocessable();
        $this->postJson('/api/me/avatar', ['image' => $this->png(16, 16)])->assertUnprocessable(); // too small
        $this->postJson('/api/me/avatar', ['image' => $this->png(1100, 8)])->assertUnprocessable(); // too large
        $this->assertNull($this->owner->fresh()->avatar_mime);
    }

    public function test_the_owner_sets_an_organization_logo_and_others_cannot(): void
    {
        $url = $this->postJson("/api/organizations/{$this->org}/logo", ['image' => $this->png()])->assertOk()->json('logo_url');
        $this->assertStringContainsString("/api/avatars/organizations/{$this->org}?v=", $url);
        $orgs = collect($this->getJson('/api/me')->json('user.organizations'));
        $this->assertSame($url, $orgs->firstWhere('name', 'Acme')['logo_url']);
        $this->get("/api/avatars/organizations/{$this->org}")->assertOk()->assertHeader('Content-Type', 'image/png');

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
        $this->postJson("/api/organizations/{$this->org}/admins", ['email' => 'admin@example.com'])->assertCreated();
        $this->actingAs($admin);
        $this->postJson("/api/organizations/{$this->org}/logo", ['image' => $this->png()])->assertNotFound(); // admins don't own it
        $this->deleteJson("/api/organizations/{$this->org}/logo")->assertNotFound();

        $this->actingAs($this->owner);
        $this->deleteJson("/api/organizations/{$this->org}/logo")->assertOk()->assertJsonPath('logo_url', null);
        $this->get("/api/avatars/organizations/{$this->org}")->assertNotFound();
    }

    public function test_personal_shows_its_owners_photo_and_takes_no_logo_of_its_own(): void
    {
        $personal = Organization::personalFor($this->owner);
        $this->postJson("/api/organizations/{$personal->id}/logo", ['image' => $this->png()])->assertUnprocessable();
        $photo = $this->postJson('/api/me/avatar', ['image' => $this->png()])->json('avatar_url');
        $me = collect($this->getJson('/api/me')->json('user.organizations'))->firstWhere('personal', true);
        $this->assertSame($photo, $me['logo_url']);
        $this->get("/api/avatars/organizations/{$personal->id}")->assertNotFound();
    }

    public function test_images_need_a_signed_in_person(): void
    {
        $this->postJson('/api/me/avatar', ['image' => $this->png()]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/avatars/users/'.$this->owner->id)->assertUnauthorized();
    }
}
