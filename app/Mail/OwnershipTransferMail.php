<?php

namespace App\Mail;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells an organization admin that the owner wants to hand the organization over to them. Nothing changes until they accept. */
class OwnershipTransferMail extends Mailable
{
    public function __construct(public Organization $organization, public User $previousOwner) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->previousOwner->name} wants to make you the owner of {$this->organization->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ownership-transfer', text: 'mail.ownership-transfer-text', with: [
            'url' => url('/settings/organization'),
            'hours' => (int) config('tidemark.invite_hours'),
        ]);
    }
}
