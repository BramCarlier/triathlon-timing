<?php
namespace App\Services;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\Password;
class AccountInvitationService {
 public function configured(): bool {
  if(config('mail.default') === 'smtp') return (bool)config('mail.mailers.smtp.host');
  if(config('mail.default') !== 'gmail') return false;
  foreach(['client_id','client_secret','refresh_token','sender'] as $key) if(!config('services.gmail.'.$key)) return false;
  return strcasecmp((string)config('mail.from.address'), (string)config('services.gmail.sender')) === 0;
 }
 public function send(User $user): bool {
  if (!$this->configured() || !$user->is_active || !$user->force_password_change) return false;
  try {
   $token=Password::broker()->createToken($user);
   $user->notify(new AccountInvitation($token));
   $user->forceFill(['invitation_sent_at'=>now()])->save();
   return true;
  } catch (\Throwable $exception) {
   // Do not log SMTP credentials or invitation URLs embedded in transport errors.
   logger()->warning('Account invitation could not be sent.', ['user_id'=>$user->id,'exception_type'=>$exception::class]);
   return false;
  }
 }
}
