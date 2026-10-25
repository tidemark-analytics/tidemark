<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AuthController;
use App\Mail\SiteReportMail;
use App\Models\ReportSubscription;
use App\Reports\ReportWindow;
use App\Reports\SiteReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendReports extends Command
{
    protected $signature = 'reports:send';

    protected $description = 'Send the weekly and monthly e-mail reports that are due';

    public function handle(): int
    {
        if (! AuthController::mailConfigured()) {
            return self::SUCCESS;
        }
        $sent = 0;
        foreach (ReportSubscription::with(['user', 'site'])->cursor() as $sub) {
            $user = $sub->user;
            $site = $sub->site;
            // someone who left the site, or a deleted account, stops getting its reports
            if (! $user || ! $site || ! $site->members()->whereKey($user->id)->exists()) {
                $sub->delete();

                continue;
            }
            if (config('tidemark.verify_email') && ! $user->hasVerifiedEmail()) {
                continue;
            }
            $now = CarbonImmutable::now($site->timezone);
            $window = ReportWindow::latest($sub->frequency, $now);
            if (! $window->isDue($now) || ($sub->last_period_end !== null && $sub->last_period_end >= $window->key())) {
                continue;
            }
            try {
                $report = SiteReport::build($site, $window, $user);
                if (! $report['empty']) {
                    Mail::to($user)->send(new SiteReportMail($sub, $report));
                    $sent++;
                }
                $sub->update(['last_period_end' => $window->key()]);
            } catch (\Throwable $e) {
                report($e); // tried again at the next run
            }
        }
        $this->info("Sent {$sent} report(s).");

        return self::SUCCESS;
    }
}
