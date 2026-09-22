<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckReverbConnection extends Command
{
    protected $signature = 'timing:check-reverb-connection';
    protected $description = 'Check the public Reverb handshake without displaying credentials';

    public function handle(): int
    {
        $key = (string) config('reverb.apps.apps.0.key');
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $assets = glob(public_path('build/assets/app-*.js'));
        $matches = $assets && str_contains(file_get_contents($assets[0]), $key);
        $this->line('Frontend contains current Reverb key: '.($matches ? 'yes' : 'no'));
        foreach (['local' => ['tcp://127.0.0.1:'.config('reverb.servers.reverb.port'), '127.0.0.1'], 'public' => ['tls://'.$host.':443', $host]] as $name => [$address, $hostname]) {
            $socket = @stream_socket_client($address, $errno, $error, 5);
            if (!$socket) { $this->error($name.': connection failed'); return self::FAILURE; }
            stream_set_timeout($socket, 3);
            fwrite($socket, "GET /app/".rawurlencode($key)."?protocol=7&client=js&version=8.4.0 HTTP/1.1\r\nHost: ".$hostname."\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nOrigin: https://".$host."\r\n\r\n");
            $response = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 8192);
                if ($chunk === false || $chunk === '') break;
                $response .= $chunk;
                if (str_contains($response, 'pusher:connection_established') || str_contains($response, 'pusher:error')) break;
            }
            fclose($socket);
            $connected = str_contains($response, 'pusher:connection_established');
            $this->line($name.': '.($connected ? 'WebSocket established' : 'WebSocket handshake failed'));
            if (!$connected) return self::FAILURE;
        }
        return $matches ? self::SUCCESS : self::FAILURE;
    }
}
