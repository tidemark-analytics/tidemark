<?php

namespace Tests\Feature;

use App\Mail\SiteReportMail;
use App\Models\Annotation;
use App\Models\ReportSubscription;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The weekly and monthly e-mail reports: who can subscribe, when they are due, what they hold and how to stop them. */
class EmailReportsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $this->site = Site::create(['domain' => 'example.com', 'timezone' => 'UTC']);
        $this->user = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']);
        $this->site->members()->attach($this->user->id, ['role' => 'viewer', 'id' => (string) Str::uuid()]);
    }

    private function as(?User $u = null)
    {
        return $this->actingAs($u ?? $this->user)->withHeader('X-Requested-With', 'XMLHttpRequest');
    }

    /** Pretend it is `$when` (UTC) while the test runs. */
    private function at(string $when): void
    {
        CarbonImmutable::setTestNow($when);
        Carbon::setTestNow($when);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function visits(string $day, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $vid = (string) Str::uuid();
            $visitor = md5($vid);
            DB::table('visits')->insert(['id' => $vid, 'site_id' => $this->site->id, 'visitor_id' => $visitor, 'started_at' => "$day 10:00:00", 'ended_at' => "$day 10:05:00", 'pageviews' => 2, 'is_bounce' => false, 'duration' => 300, 'entry_page' => '/', 'exit_page' => '/pricing', 'country' => 'DE', 'source' => 'Google']);
            DB::table('events')->insert(['site_id' => $this->site->id, 'visit_id' => $vid, 'visitor_id' => $visitor, 'name' => 'pageview', 'pathname' => '/pricing', 'created_at' => "$day 10:00:00"]);
        }
    }

    public function test_choosing_a_frequency_and_turning_it_off(): void
    {
        $this->at('2026-11-04 12:00:00'); // a Wednesday
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'weekly'])->assertOk()->assertJson(['frequency' => 'weekly']);
        $sub = ReportSubscription::first();
        $this->assertSame('2026-11-01', $sub->last_period_end, 'the week that just ended is not sent late');
        $this->assertSame(40, strlen($sub->token));

        $list = $this->as()->getJson('/api/me/reports')->assertOk()->json();
        $this->assertTrue($list['mail']);
        $this->assertSame('weekly', $list['data'][0]['frequency']);

        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'monthly'])->assertOk();
        $this->assertSame(['monthly'], ReportSubscription::pluck('frequency')->all());
        $this->assertSame('2026-10-31', ReportSubscription::first()->last_period_end);

        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'off'])->assertOk()->assertJson(['frequency' => null]);
        $this->assertSame(0, ReportSubscription::count());
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'daily'])->assertStatus(422);
    }

    public function test_only_members_can_subscribe_and_signed_out_cannot(): void
    {
        $other = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'password']);
        $this->as($other)->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'weekly'])->assertNotFound();
        $this->assertSame(0, ReportSubscription::count());
        $this->assertSame([], $this->as($other)->getJson('/api/me/reports')->json('data'));
        $this->app['auth']->forgetGuards();
        $this->withHeader('X-Requested-With', 'XMLHttpRequest')->getJson('/api/me/reports')->assertUnauthorized();
    }

    public function test_a_weekly_report_goes_out_monday_morning_with_the_week_before(): void
    {
        $this->at('2026-11-04 12:00:00');
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'weekly']);
        $this->visits('2026-11-09', 3); // Monday of the week to report on
        $this->visits('2026-11-03', 1); // the week before it
        Annotation::create(['site_id' => $this->site->id, 'user_id' => $this->user->id, 'date' => '2026-11-10', 'note' => 'Launched v2', 'visibility' => 'team']);
        Annotation::create(['site_id' => $this->site->id, 'user_id' => null, 'date' => '2026-11-10', 'note' => 'Someone private', 'visibility' => 'private']);

        $this->at('2026-11-16 07:30:00'); // Monday, before 08:00
        $this->artisan('reports:send')->assertSuccessful();
        Mail::assertNothingSent();

        $this->at('2026-11-16 08:05:00');
        $this->artisan('reports:send')->assertSuccessful();
        Mail::assertSent(SiteReportMail::class, function (SiteReportMail $m) {
            $m->assertHasSubject('Weekly report for example.com: 3 visitors (+200%)');
            $m->assertSeeInHtml('Launched v2');
            $m->assertDontSeeInHtml('Someone private');
            $m->assertSeeInHtml('/pricing');
            $m->assertSeeInHtml('Germany');
            $m->assertSeeInHtml('/reports/unsubscribe/'.ReportSubscription::first()->token);
            $m->assertSeeInText('Unsubscribe: ');

            return $m->hasTo('ada@example.com') && $m->report['window']->label() === '9 Nov – 15 Nov 2026';
        });
        $this->assertSame('2026-11-15', ReportSubscription::first()->last_period_end);

        $this->artisan('reports:send')->assertSuccessful(); // the same week is never sent twice
        Mail::assertSent(SiteReportMail::class, 1);
    }

    public function test_a_monthly_report_covers_the_calendar_month_in_the_sites_timezone(): void
    {
        $this->site->update(['timezone' => 'America/New_York']);
        $this->at('2026-11-12 12:00:00');
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'monthly']);
        $this->visits('2026-11-20', 2);

        $this->at('2026-12-01 12:30:00'); // 07:30 in New York: not yet
        $this->artisan('reports:send');
        Mail::assertNothingSent();

        $this->at('2026-12-01 13:30:00'); // 08:30 in New York
        $this->artisan('reports:send');
        Mail::assertSent(SiteReportMail::class, fn (SiteReportMail $m) => $m->report['window']->label() === 'November 2026' && $m->report['visitors'] === 2);
    }

    public function test_nothing_is_sent_for_a_quiet_period_and_it_is_not_retried(): void
    {
        $this->at('2026-11-04 12:00:00');
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'weekly']);
        $this->at('2026-11-16 09:00:00');
        $this->artisan('reports:send');
        Mail::assertNothingSent();
        $this->assertSame('2026-11-15', ReportSubscription::first()->last_period_end);
    }

    public function test_nothing_is_sent_without_a_real_mail_setup_or_after_leaving_the_site(): void
    {
        $this->at('2026-11-04 12:00:00');
        $this->as()->putJson("/api/me/reports/{$this->site->id}", ['frequency' => 'weekly']);
        $this->visits('2026-11-09', 2);
        $this->at('2026-11-16 09:00:00');

        config(['mail.default' => 'log']);
        $this->artisan('reports:send');
        Mail::assertNothingSent();
        $this->assertSame('2026-11-01', ReportSubscription::first()->last_period_end, 'kept for when mail is set up');

        config(['mail.default' => 'smtp']);
        $this->site->members()->detach($this->user->id);
        $this->artisan('reports:send');
        Mail::assertNothingSent();
        $this->assertSame(0, ReportSubscription::count());
    }

    public function test_the_unsubscribe_link(): void
    {
        $sub = ReportSubscription::create(['user_id' => $this->user->id, 'site_id' => $this->site->id, 'frequency' => 'weekly']);
        $this->get('/reports/unsubscribe/'.$sub->token)->assertOk()->assertSee('Stop weekly reports?')->assertSee('example.com');
        $this->assertSame(1, ReportSubscription::count(), 'opening the link alone changes nothing');

        $this->post('/reports/unsubscribe/'.$sub->token)->assertOk()->assertSee('unsubscribed');
        $this->assertSame(0, ReportSubscription::count());
        $this->post('/reports/unsubscribe/'.$sub->token)->assertOk()->assertSee('Already unsubscribed');
    }

    public function test_deleting_a_site_removes_its_subscriptions(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $this->site->members()->attach($owner->id, ['role' => 'owner', 'id' => (string) Str::uuid()]);
        ReportSubscription::create(['user_id' => $this->user->id, 'site_id' => $this->site->id, 'frequency' => 'weekly']);
        $this->as($owner)->deleteJson('/api/sites/example.com')->assertOk();
        $this->assertSame(0, ReportSubscription::count());
    }
}
