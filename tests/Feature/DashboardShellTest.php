<?php

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardShellTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledMarker = false;

    protected function setUp(): void
    {
        parent::setUp();
        $marker = storage_path('app/installed');
        if (! file_exists($marker)) {
            file_put_contents($marker, '1');
            $this->createdInstalledMarker = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledMarker) {
            @unlink(storage_path('app/installed'));
        }
        parent::tearDown();
    }

    public function test_a_path_that_is_not_a_site_answers_404_with_the_app(): void
    {
        $this->get('/pricing')->assertNotFound()->assertSee('id="root"', false);
        $this->get('/pricing/anything/else')->assertNotFound();
    }

    public function test_a_site_or_rollup_dashboard_opens_whatever_the_case_of_the_address(): void
    {
        Site::create(['domain' => 'example.com', 'timezone' => 'UTC']);
        Site::create(['kind' => 'rollup', 'domain' => 'all-sites', 'timezone' => 'UTC']);

        $this->get('/example.com')->assertOk();
        $this->get('/Example.com/pages')->assertOk();
        $this->get('/all-sites')->assertOk();
    }

    public function test_the_app_screens_still_open(): void
    {
        foreach (['/login', '/register', '/sites', '/settings/security', '/share/abc'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
