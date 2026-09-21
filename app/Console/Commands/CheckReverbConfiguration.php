<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckReverbConfiguration extends Command
{
    protected $signature = 'timing:check-reverb';
    protected $description = 'Validate Reverb credentials without displaying their values';

    public function handle(): int
    {
        if (config('broadcasting.default') !== 'reverb') {
            $this->error('BROADCAST_CONNECTION must be reverb for live timing updates.');
            return self::FAILURE;
        }

        $key = (string) config('reverb.apps.apps.0.key');
        $secret = (string) config('reverb.apps.apps.0.secret');
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $key)) {
            $this->error('REVERB_APP_KEY must contain only letters, digits, underscores or hyphens. It is a WebSocket URL identifier, not a base64 Laravel APP_KEY.');
            return self::FAILURE;
        }
        if (!$secret || !config('reverb.apps.apps.0.app_id') || hash_equals($key, $secret) || hash_equals((string) config('app.key'), $secret)) {
            $this->error('Set a Reverb app ID and an independent secret. Do not reuse REVERB_APP_KEY or Laravel APP_KEY as the Reverb secret.');
            return self::FAILURE;
        }
        $this->info('Reverb credentials are configured with a URL-safe application key.');
        return self::SUCCESS;
    }
}
