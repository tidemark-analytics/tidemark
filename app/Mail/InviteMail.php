<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Either an invitation with a sign-up link, or (for someone who already has an account) a note that they were given access. */
class InviteMail extends Mailable
{
    public function __construct(public Site $site, public User $inviter, public string $role, public ?Invitation $invitation = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitation
            ? "{$this->inviter->name} invited you to {$this->site->domain} on ".config('app.name')
            : "You now have access to {$this->site->domain} on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invite', text: 'mail.invite-text', with: [
            'invited' => $this->invitation !== null,
            'url' => $this->invitation ? $this->invitation->url() : url('/'.$this->site->domain),
            'hours' => (int) config('tidemark.invite_hours'),
            'access' => $this->role === 'admin' ? 'view statistics and change settings' : 'view statistics',
        ]);
    }
}
