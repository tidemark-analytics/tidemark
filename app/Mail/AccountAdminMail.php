<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Either an invitation to help manage someone's account (with a sign-up link), or a note to someone who already has an account. */
class AccountAdminMail extends Mailable
{
    public function __construct(public User $inviter, public ?Invitation $invitation = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitation
            ? "{$this->inviter->name} invited you to help manage their ".config('app.name').' account'
            : "{$this->inviter->name} made you an admin of their ".config('app.name').' account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-admin', text: 'mail.account-admin-text', with: [
            'invited' => $this->invitation !== null,
            'url' => $this->invitation ? $this->invitation->url() : url('/sites'),
            'hours' => (int) config('tidemark.invite_hours'),
        ]);
    }
}
