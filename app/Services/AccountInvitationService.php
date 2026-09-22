<?php
namespace App\Services;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Illuminate\Support\Facades\Password;
class AccountInvitationService {
 public function configured(): bool { return config('mail.default') === 'smtp' && (bool)config('mail.mailers.smtp.host'); }
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
