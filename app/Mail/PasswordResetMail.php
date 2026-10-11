<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Password-reset email (architecture §2.3).
 *
 * Contains the one-time reset token. In production this would be a reset
 * link; here we include the token directly for API consumption. The mail
 * driver is `log` in development — emails are logged, not sent.
 */
class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly int $userId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset Request',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        return <<<HTML
        <h2>Password Reset</h2>
        <p>You have requested a password reset. Use the token below within 30 minutes:</p>
        <p><strong>{$this->token}</strong></p>
        <p>If you did not request this, ignore this email.</p>
        HTML;
    }
}
