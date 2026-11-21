<?php

namespace App\Domain\Reports\Mail;

use App\Domain\Reports\Services\OwnerPackPresenter;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The weekly owner pack as an e-mail: a styled HTML part and a plain-text part (for clients that show text only, and for screen readers on some phones),
 * both drawn from the same prepared figures ({@see OwnerPackPresenter}). Aggregates only: no customer data is in it.
 */
class OwnerPackMail extends Mailable
{
    /** @param array<string, mixed> $pack the presenter's output */
    public function __construct(public readonly array $pack, public readonly string $text, public readonly string $subjectLine) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.owner-pack', text: 'emails.owner-pack-text', with: ['pack' => $this->pack, 'text' => $this->text]);
    }
}
