<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Contracts\LimitsSiteList;
use App\Models\User;
use App\Support\DefaultEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'is_admin' => true]);
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'password']);
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $this->actingAs($this->admin)->postJson('/api/sites', ['domain' => 'mine.example.com'])->assertCreated();
        $this->actingAs($other)->postJson('/api/sites', ['domain' => 'theirs.example.com'])->assertCreated();
        $this->actingAs($other)->postJson('/api/sites', ['domain' => 'theirs2.example.com'])->assertCreated();
    }

    public function test_administrators_normally_see_every_site(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/sites')->assertOk()->assertJsonPath('other_sites', null);

        $this->assertCount(3, $res->json('data'));
    }

    public function test_an_extension_can_limit_the_list_to_the_administrators_own_sites(): void
    {
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements LimitsSiteList
        {
            public function otherSitesPath(User $user): ?string
            {
                return '/settings/customers';
            }
        });

        $res = $this->actingAs($this->admin)->getJson('/api/sites')->assertOk()
            ->assertJsonPath('other_sites.path', '/settings/customers')->assertJsonPath('other_sites.count', 2);
        $this->assertSame(['mine.example.com'], array_column($res->json('data'), 'domain'));
        // administrators can still open any site by its address
        $this->actingAs($this->admin)->getJson('/api/sites/theirs.example.com')->assertOk();
    }

    public function test_an_empty_path_shows_only_their_own_sites_without_any_hint_of_the_rest(): void
    {
        $this->app->instance(Entitlements::class, new class extends DefaultEntitlements implements LimitsSiteList
        {
            public function otherSitesPath(User $user): ?string
            {
                return '';
            }
        });

        $res = $this->actingAs($this->admin)->getJson('/api/sites')->assertOk()->assertJsonPath('other_sites', null);
        $this->assertSame(['mine.example.com'], array_column($res->json('data'), 'domain'));
    }
}
