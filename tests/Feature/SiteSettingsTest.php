<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->actingAs($this->owner);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->postJson('/api/sites', ['domain' => 'https://www.Example.com/', 'timezone' => 'Europe/Berlin'])->assertCreated()->assertJsonPath('data.domain', 'example.com');
    }

    public function test_domains_are_normalised_unique_and_reserved_words_rejected(): void
    {
        $this->postJson('/api/sites', ['domain' => 'example.com'])->assertUnprocessable();
        $this->postJson('/api/sites', ['domain' => 'sites'])->assertUnprocessable();
        $this->postJson('/api/sites', ['domain' => 'not a domain'])->assertUnprocessable();
    }

    public function test_tracking_toggles_change_the_script_and_create_goals(): void
    {
        $res = $this->patchJson('/api/sites/example.com', ['track_outbound' => true, 'track_downloads' => true])->assertOk();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{10}\.outbound-links\.file-downloads\.js$/', $res->json('data.script'));
        $names = $this->getJson('/api/sites/example.com/goals')->json('data.*.name');
        $this->assertContains('Outbound Link: Click', $names);
        $this->assertContains('File Download', $names);
    }

    public function test_goals_shields_and_people(): void
    {
        $this->postJson('/api/sites/example.com/goals', ['type' => 'event', 'value' => 'Signup'])->assertCreated();
        $this->postJson('/api/sites/example.com/goals', ['type' => 'page', 'value' => '/thanks'])->assertCreated();
        $goal = $this->getJson('/api/sites/example.com/goals')->json('data.0.id');
        $this->deleteJson("/api/sites/example.com/goals/{$goal}")->assertOk();

        $this->postJson('/api/sites/example.com/shields', ['type' => 'country', 'value' => 'de'])->assertCreated();
        $this->postJson('/api/sites/example.com/shields', ['type' => 'ip', 'value' => 'bogus'])->assertUnprocessable();
        $this->assertCount(1, $this->getJson('/api/sites/example.com/shields')->json('data'));

        $viewer = User::create(['name' => 'View', 'email' => 'view@example.com', 'password' => 'password']);
        $this->postJson('/api/sites/example.com/people', ['email' => 'view@example.com', 'role' => 'viewer'])->assertCreated();
        $this->actingAs($viewer)->getJson('/api/sites/example.com')->assertOk()->assertJsonPath('data.can_manage', false);
        $this->patchJson('/api/sites/example.com', ['public' => true])->assertForbidden();
        $this->postJson('/api/sites/example.com/goals', ['type' => 'event', 'value' => 'x'])->assertForbidden();
    }

    public function test_only_the_owner_can_delete_a_site(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
        Site::where('domain', 'example.com')->first()->members()->attach($admin->id, ['role' => 'admin', 'id' => (string) Str::uuid()]);
        $this->actingAs($admin)->deleteJson('/api/sites/example.com')->assertForbidden();
        $this->actingAs($this->owner)->deleteJson('/api/sites/example.com')->assertOk();
        $this->assertSame(0, Site::count());
    }

    public function test_session_writes_require_the_csrf_header(): void
    {
        $this->flushHeaders();
        $this->postJson('/api/sites', ['domain' => 'other.com'])->assertStatus(419);
    }
}
