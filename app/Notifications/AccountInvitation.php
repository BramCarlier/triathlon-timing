<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class AccountInvitation extends Notification {
 public function __construct(public string $token) {}
 public function via(object $notifiable): array { return ['mail']; }
 public function toMail(object $notifiable): MailMessage {
  return (new MailMessage)->subject('Your Triathlon Timing account')
   ->greeting('Hello '.$notifiable->name.',')
   ->line('An administrator has created your '.($notifiable->role->value==='admin'?'administrator':$notifiable->role->value).' account.')
   ->line('Your sign-in email is '.$notifiable->email.'. Choose your own password to activate access. No temporary password is needed.')
   ->action('Choose your password',route('password.reset',['token'=>$this->token,'email'=>$notifiable->email,'welcome'=>1]))
   ->line('This single-use link expires in '.config('auth.passwords.users.expire',60).' minutes. If it expires, use Forgot password on the sign-in page or ask your administrator to resend your invitation.')
   ->line('After choosing a password, sign in at '.route('login').'. If you were not expecting this account, contact the race organizer.');
 }
}
