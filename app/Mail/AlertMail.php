<?php

namespace App\Mail;

use App\Models\Site;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One alert, as an e-mail. */
class AlertMail extends Mailable
{
    public function __construct(public Site $site, public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Alert: '.$this->payload['summary']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert', text: 'mail.alert-text', with: ['p' => $this->payload, 'settings' => url('/'.$this->site->domain.'/settings/alerts')]);
    }
}
