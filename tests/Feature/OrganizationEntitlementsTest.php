<?php

namespace Tests\Feature;

use App\Contracts\Entitlements;
use App\Contracts\ScopesToOrganizations;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** @var array<int,mixed> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest');
        $test = $this;
        $this->app->instance(Entitlements::class, new class($test) implements Entitlements, ScopesToOrganizations
        {
            public function __construct(private OrganizationEntitlementsTest $t) {}

            public function canCreateSite(User $user): true|string
            {
                $this->t->record('user', $user->id);

                return true;
            }

            public function allowsRecording(array $site): bool
            {
                $this->t->record('recording', $site['organization_id'] ?? null);

                return true;
            }

            public function canCreateSiteIn(Organization $organization): true|string
            {
                $this->t->record('org', $organization->name);

                return $organization->name === 'Full' ? 'This organization is on the Free plan: upgrade it to add sites.' : true;
            }

            public function moveWarnings(Site $site, Organization $to): array
            {
                return $to->name === 'Short' ? ['Data older than 6 months would be removed.'] : [];
            }
        });
    }

    public function record(string $kind, mixed $value): void
    {
        $this->calls[] = [$kind, $value];
    }

    private function owner(): User
    {
        $u = User::create(['name' => 'Dana', 'email' => 'dana@example.com', 'password' => 'password']);
        $this->actingAs($u);

        return $u;
    }

    public function test_adding_a_site_is_judged_against_the_organization_it_goes_into(): void
    {
        $this->owner();
        $full = $this->postJson('/api/organizations', ['name' => 'Full'])->json('data.id');
        $ok = $this->postJson('/api/organizations', ['name' => 'Roomy'])->json('data.id');

        $this->postJson('/api/sites', ['domain' => 'a.com', 'organization' => $full])->assertUnprocessable()->assertJsonPath('errors.domain.0', 'This organization is on the Free plan: upgrade it to add sites.');
        $this->postJson('/api/sites', ['domain' => 'b.com', 'organization' => $ok])->assertCreated();
        $this->assertSame([['org', 'Full'], ['org', 'Roomy']], $this->calls); // the person-level check is not consulted
        $this->assertNull(Site::where('domain', 'a.com')->first());
    }

    public function test_moving_a_site_checks_the_destination_and_shows_its_warnings(): void
    {
        $this->owner();
        $from = $this->postJson('/api/organizations', ['name' => 'Roomy'])->json('data.id');
        $short = $this->postJson('/api/organizations', ['name' => 'Short'])->json('data.id');
        $full = $this->postJson('/api/organizations', ['name' => 'Full'])->json('data.id');
        $this->postJson('/api/sites', ['domain' => 'a.com', 'organization' => $from])->assertCreated();

        $dests = collect($this->getJson('/api/sites/a.com/move')->json('destinations'));
        $this->assertSame(['Data older than 6 months would be removed.'], $dests->firstWhere('name', 'Short')['impact']['warnings']);
        $this->assertSame([], $dests->firstWhere('name', 'Full')['impact']['warnings']);

        $this->postJson('/api/sites/a.com/move', ['organization' => $full])->assertUnprocessable()->assertJsonPath('errors.organization.0', 'This organization is on the Free plan: upgrade it to add sites.');
        $this->postJson('/api/sites/a.com/move', ['organization' => $short])->assertOk();
        $this->assertSame($short, Site::where('domain', 'a.com')->value('organization_id'));
    }

    public function test_the_recording_check_gets_the_sites_organization(): void
    {
        $this->owner();
        $org = $this->postJson('/api/organizations', ['name' => 'Roomy'])->json('data.id');
        $this->postJson('/api/sites', ['domain' => 'example.com', 'organization' => $org])->assertCreated();
        $this->calls = [];
        $this->call('POST', '/api/event', [], [], [], $this->transformHeadersToServerVars(['Accept-Language' => 'en-US,en;q=0.9', 'User-Agent' => self::CHROME, 'Content-Type' => 'application/json']),
            json_encode(['n' => 'pageview', 'u' => 'https://example.com/', 'd' => 'example.com', 'w' => 1200]))->assertStatus(202);
        $this->assertSame([['recording', $org]], $this->calls);
        $this->assertSame(1, DB::table('events')->count());
    }
}
