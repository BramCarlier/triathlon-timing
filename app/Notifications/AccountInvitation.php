<?php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class AccountInvitation extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject(__('Your Triathlon Timing account'))
            ->greeting(__('Hello :name,', ['name'=>$notifiable->name]))
            ->line(__('An organizer (admin) has created your :role account.', ['role'=>__($notifiable->role->label())]))
            ->line(__('Your sign-in email is :email. Choose your own password to activate access. No temporary password is needed.', ['email'=>$notifiable->email]))
            ->action(__('Choose your password'), route('password.reset', ['token'=>$this->token,'email'=>$notifiable->email,'welcome'=>1]))
            ->line(__('This single-use link expires in :minutes minutes. If it expires, use Forgot password on the sign-in page or ask your organizer (admin) to resend your invitation.', ['minutes'=>$minutes]))
            ->line(__('After choosing a password, sign in at :url. If you were not expecting this account, contact the race organizer.', ['url'=>route('login')]));
    }
}
