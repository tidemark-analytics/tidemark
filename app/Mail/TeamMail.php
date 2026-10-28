<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Either an invitation to join a team (with a sign-up link), or a note to someone who already has an account that they were added. */
class TeamMail extends Mailable
{
    public function __construct(public Team $team, public User $inviter, public ?Invitation $invitation = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitation
            ? "{$this->inviter->name} invited you to the {$this->team->name} team on ".config('app.name')
            : "You were added to the {$this->team->name} team on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.team', text: 'mail.team-text', with: [
            'invited' => $this->invitation !== null,
            'url' => $this->invitation ? $this->invitation->url() : url('/sites'),
            'hours' => (int) config('tidemark.invite_hours'),
        ]);
    }
}
