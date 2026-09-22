<?php

namespace App\Console\Commands;

use App\Mail\GmailApiTransport;
use App\Services\AccountInvitationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

class CheckMail extends Command
{
    protected $signature = 'timing:check-mail';
    protected $description = 'Check Gmail API token refresh without sending email or printing credentials';

    public function handle(AccountInvitationService $invitations): int
    {
        if (config('mail.default') !== 'gmail' || !$invitations->configured()) {
            $this->error('Select MAIL_MAILER=gmail and configure its OAuth credentials and matching sender first.');
            return self::FAILURE;
        }
        $transport = Mail::mailer('gmail')->getSymfonyTransport();
        if (!$transport instanceof GmailApiTransport) {
            $this->error('The Gmail API transport is not registered.');
            return self::FAILURE;
        }
        try {
            $transport->checkAuthentication();
        } catch (TransportException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
        $this->info('Google token refresh succeeded. No email was sent. Test an invitation to verify sender access and delivery.');
        return self::SUCCESS;
    }
}
