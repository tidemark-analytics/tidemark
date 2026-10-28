<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Either an invitation to help manage an organization (with a sign-up link), or a note to someone who already has an account here. */
class OrganizationAdminMail extends Mailable
{
    public function __construct(public Organization $organization, public User $inviter, public ?Invitation $invitation = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invitation
            ? "{$this->inviter->name} invited you to help manage {$this->organization->name} on ".config('app.name')
            : "You are now an admin of {$this->organization->name} on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.organization-admin', text: 'mail.organization-admin-text', with: [
            'invited' => $this->invitation !== null,
            'url' => $this->invitation ? $this->invitation->url() : url('/sites'),
            'hours' => (int) config('tidemark.invite_hours'),
        ]);
    }
}
