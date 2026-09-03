<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $email = (string) $notifiable->getEmailForPasswordReset();
        $name = (string) ($notifiable->name ?? 'Usuario');

        $url = rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?'
            .http_build_query([
                'token' => $this->token,
                'email' => $email,
            ]);

        return (new MailMessage)
            ->from(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            )
            ->subject('Restablece tu contraseña — Ardoise')
            ->view('emails.reset-password', [
                'url' => $url,
                'name' => $name,
                'email' => $email,
                'expireMinutes' => $expireMinutes,
                'appName' => (string) config('app.name', 'Ardoise'),
                // Logo embebido CID desde resources/views (vía $message->embed en el blade)
                'logoPath' => public_path('images/emails/ardoise-logo.png'),
            ]);
    }
}
