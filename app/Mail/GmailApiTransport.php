<?php

namespace App\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/** Sends through HTTPS; neither SMTP nor a Google app password is used. */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(private readonly array $settings)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }

    public function checkAuthentication(): void
    {
        $this->accessToken();
    }

    private function accessToken(): string
    {
        foreach (['client_id', 'client_secret', 'refresh_token', 'sender'] as $key) {
            if (empty($this->settings[$key])) {
                throw new TransportException('Gmail API configuration is incomplete.');
            }
        }

        try {
            $response = Http::asForm()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->settings['client_id'],
                'client_secret' => $this->settings['client_secret'],
                'refresh_token' => $this->settings['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);
        } catch (\Throwable) {
            // Never chain HTTP exceptions: they may contain credentials or message bodies.
            throw new TransportException('Gmail API token connection failed. Check outbound HTTPS and DNS.');
        }

        if (!$response->successful()) {
            $reason = match ($response->json('error')) {
                'invalid_grant' => 'Google authorization expired or was revoked. Reconnect the sending account.',
                'invalid_client' => 'Google OAuth client credentials were rejected.',
                default => 'Google token request failed (HTTP '.$response->status().').',
            };
            throw new TransportException($reason);
        }

        $token = $response->json('access_token');
        if (!is_string($token) || $token === '') {
            throw new TransportException('Google returned no access token.');
        }

        return $token;
    }

    protected function doSend(SentMessage $message): void
    {
        if (strcasecmp($message->getEnvelope()->getSender()->getAddress(), $this->settings['sender'] ?? '') !== 0) {
            throw new TransportException('The From address must match GMAIL_SENDER.');
        }

        $email = $message->getOriginalMessage();
        if (!$email instanceof Email) {
            throw new TransportException('Gmail API requires a MIME Email message.');
        }
        foreach ($email->getFrom() as $from) {
            if (strcasecmp($from->getAddress(), $this->settings['sender']) !== 0) {
                throw new TransportException('The From address must match GMAIL_SENDER.');
            }
        }
        $visible = array_map(fn ($address) => $address->getAddress(), [...$email->getTo(), ...$email->getCc()]);
        $recipients = array_map(fn ($address) => $address->getAddress(), $message->getEnvelope()->getRecipients());
        if (array_diff($visible, $recipients)) {
            throw new TransportException('Gmail API does not support an envelope excluding visible recipients.');
        }
        $headers = $email->getPreparedHeaders();
        $blind = array_values(array_diff($recipients, $visible));
        // Gmail reads recipients from MIME, unlike SMTP. Restore blind envelope recipients
        // in the API submission; Gmail removes Bcc when delivering to recipients.
        if ($blind) $headers->addMailboxListHeader('Bcc', $blind);
        $mime = $headers->toString().($email->getBody()?->toString() ?? "\r\n");
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        $token = $this->accessToken();
        try {
            // Do not automatically retry sends: a timeout can occur after Google accepted a message.
            $response = Http::withToken($token)->connectTimeout(5)->timeout(15)
                ->post('https://gmail.googleapis.com/gmail/v1/users/'.rawurlencode($this->settings['sender']).'/messages/send', ['raw' => $raw]);
        } catch (\Throwable) {
            throw new TransportException('Gmail API send connection failed; delivery is unconfirmed. Check Gmail Sent before resending.');
        }

        if (!$response->successful()) {
            $reason = match ($response->status()) {
                401 => 'Google authorization was rejected. Reconnect the sending account.',
                403 => 'Google denied sending. Check Gmail API activation, gmail.send permission, sending account and quota.',
                429 => 'Google sending quota was reached. Try again later.',
                default => 'Gmail API rejected the message (HTTP '.$response->status().').',
            };
            throw new TransportException($reason);
        }
        if (!is_string($response->json('id')) || $response->json('id') === '') {
            throw new TransportException('Gmail API returned no message ID; delivery is unconfirmed.');
        }
    }
}
