<?php

namespace Tests\Feature;

use App\Contracts\AllowsBranding;
use App\Contracts\Entitlements;
use App\Models\Site;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertCreated();
        $this->patchJson('/api/sites/example.com', ['public' => true])->assertOk();
    }

    private function logo(string $name = 'logo.png', ?string $bytes = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? base64_decode(self::PNG));
    }

    public function test_the_owner_sets_a_name_color_and_logo_that_anonymous_visitors_receive(): void
    {
        $this->patchJson('/api/sites/example.com/branding', ['brand_name' => 'Example Co', 'brand_color' => '#aa3366'])->assertOk()->assertJsonPath('data.branding.allowed', true);
        $this->postJson('/api/sites/example.com/branding/logo', ['logo' => $this->logo()])->assertOk()->assertJsonPath('data.branding.has_logo', true);

        $this->app['auth']->forgetGuards();
        $public = $this->getJson('/api/sites/example.com')->assertOk()
            ->assertJsonPath('data.brand.name', 'Example Co')->assertJsonPath('data.brand.color', '#aa3366');
        $this->assertStringStartsWith('data:image/png;base64,', $public->json('data.brand.logo'));
        $this->assertArrayNotHasKey('branding', $public->json('data'));

        $this->actingAs(Site::first()->members()->first())->deleteJson('/api/sites/example.com/branding/logo')->assertOk()->assertJsonPath('data.brand.logo', null);
    }

    public function test_invalid_colors_and_logos_are_rejected(): void
    {
        $this->patchJson('/api/sites/example.com/branding', ['brand_color' => 'red'])->assertUnprocessable();
        $this->postJson('/api/sites/example.com/branding/logo', ['logo' => UploadedFile::fake()->create('big.png', 200, 'image/png')])->assertUnprocessable();
        $this->postJson('/api/sites/example.com/branding/logo', ['logo' => UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4')])->assertUnprocessable();
        $this->assertNull(Site::first()->brand());
    }

    public function test_only_managers_can_change_branding(): void
    {
        $viewer = User::create(['name' => 'View', 'email' => 'view@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'view@example.com', 'role' => 'viewer'])->assertCreated();
        $this->actingAs($viewer)->patchJson('/api/sites/example.com/branding', ['brand_name' => 'Nope'])->assertForbidden();
    }

    public function test_an_extension_can_limit_branding_and_the_default_look_returns(): void
    {
        $this->patchJson('/api/sites/example.com/branding', ['brand_name' => 'Example Co'])->assertOk();
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements AllowsBranding
        {
            public function allowsBranding(Site $site): bool
            {
                return false;
            }
        });

        $this->patchJson('/api/sites/example.com/branding', ['brand_name' => 'Changed'])->assertForbidden()->assertJsonPath('error', 'branding_unavailable');
        $this->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.brand', null)->assertJsonPath('data.branding.allowed', false);
    }
}
