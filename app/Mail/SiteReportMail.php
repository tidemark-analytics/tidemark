<?php

namespace App\Mail;

use App\Models\ReportSubscription;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** The weekly or monthly report of one site. Plain aggregate numbers, no tracking pixel and no tracked links. */
class SiteReportMail extends Mailable
{
    public function __construct(public ReportSubscription $subscription, public array $report) {}

    public function envelope(): Envelope
    {
        $r = $this->report;
        $change = $r['metrics'][0]['change'];
        $trend = $change === null ? '' : ' ('.($change >= 0 ? '+' : '').$change.'%)';

        return new Envelope(subject: ucfirst($this->subscription->frequency).' report for '.$r['site']->domain.': '.number_format($r['visitors']).' '.($r['visitors'] === 1 ? 'visitor' : 'visitors').$trend);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.report', text: 'mail.report-text', with: [
            'r' => $this->report,
            'dashboard' => url('/'.$this->report['site']->domain),
            'unsubscribe' => $this->unsubscribeUrl(),
            'settings' => url('/settings/reports'),
        ]);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }

    private function unsubscribeUrl(): string
    {
        return url('/reports/unsubscribe/'.$this->subscription->token);
    }
}
