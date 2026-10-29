<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The six-digit code for signing in (or turning on e-mail codes). */
class TwoFactorCodeMail extends Mailable
{
    public function __construct(public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your ".config('app.name').' sign-in code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.two-factor-code', text: 'mail.two-factor-code-text', with: ['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
