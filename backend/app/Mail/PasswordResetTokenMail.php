<?php

namespace App\Mail;

use App\Actions\Auth\ForgotPasswordAction;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetTokenMail extends Mailable
{
    public function __construct(public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Fayadhowr Password Reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.password-reset-token',
            with: [
                'token' => $this->token,
                'expiryMinutes' => ForgotPasswordAction::TOKEN_EXPIRY_MINUTES,
            ],
        );
    }
}
