<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RestablecerContrasenaNotificacion extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url'), '/')
            . '/restablecer-contrasena?'
            . http_build_query([
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

        return (new MailMessage)
            ->subject('Restablece tu contrasena - Pollo Fresco')
            ->greeting('Hola, ' . ($notifiable->nombres ?: 'usuario') . '.')
            ->line('Recibimos una solicitud para cambiar la contrasena de tu cuenta.')
            ->action('Crear nueva contrasena', $url)
            ->line('Este enlace vence en ' . config('auth.passwords.users.expire') . ' minutos.')
            ->line('Si no solicitaste este cambio, ignora este correo. Tu contrasena seguira siendo la misma.')
            ->salutation('Equipo de Pollo Fresco');
    }
}
