<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class LoginCommand extends Command
{
    protected $signature = 'login';

    protected $description = 'Authenticate with Spotify';

    public function handle()
    {
        $clientId = config('spotify.client_id');
        $clientSecret = config('spotify.client_secret');

        if (! $clientId || ! $clientSecret) {
            error('❌ Missing Spotify credentials');
            info('💡 Run "music setup" to get started');

            return self::FAILURE;
        }

        info('🎵 Spotify Authentication');

        // Find available port
        $port = $this->findAvailablePort();
        $redirectUri = "http://127.0.0.1:{$port}/callback";

        // Emit login started event
        $this->call('event:emit', [
            'event' => 'auth.login_started',
            'data' => json_encode([
                'redirect_uri' => $redirectUri,
                'callback_port' => $port,
            ]),
        ]);

        if ($port !== 8888) {
            warning("⚠️  Using port {$port} because 8888 is in use");
            warning('⚠️  You must add this EXACT URI to your Spotify app:');
            warning("   {$redirectUri}");
            info('');
            info('Or kill the process using port 8888:');
            info('   lsof -ti:8888 | xargs kill -9');

            if (! confirm('Continue with port '.$port.'?')) {
                return self::FAILURE;
            }
        }

        info("📋 Using redirect URI: {$redirectUri}");

        // Generate auth URL
        $scopes = [
            'user-read-playback-state',
            'user-modify-playback-state',
            'user-read-currently-playing',
            'streaming',
            'playlist-read-private',
            'playlist-read-collaborative',
        ];

        // Store scopes for event emission
        $this->scopes = $scopes;

        $state = bin2hex(random_bytes(16));
        $authUrl = 'https://accounts.spotify.com/authorize?'.http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);

        // Start local server
        $serverScript = $this->createCallbackServer();
        $serverProcess = Process::timeout(60)->start("php -S 127.0.0.1:{$port} {$serverScript}");

        sleep(1); // Give server time to start

        // Open browser
        info('🌐 Opening browser for authorization...');
        $this->openUrl($authUrl);

        // Wait for callback with spinner
        $code = spin(
            fn () => $this->waitForAuthCode(),
            'Waiting for authorization...'
        );

        $serverProcess->stop();

        if (! $code) {
            error('❌ Authorization timeout or cancelled');

            // Emit login failed event
            $this->call('event:emit', [
                'event' => 'auth.login_failed',
                'data' => json_encode([
                    'error' => 'timeout_or_cancelled',
                    'reason' => 'User did not complete authorization within timeout period',
                ]),
            ]);

            return self::FAILURE;
        }

        info('🔐 Got authorization code!');

        // Exchange code for token
        $tokenData = spin(
            fn () => $this->exchangeCodeForToken($clientId, $clientSecret, $code, $redirectUri),
            'Exchanging code for access token...'
        );

        if (! $tokenData || ! $tokenData['access_token']) {
            error('❌ Failed to get access token');

            // Emit login failed event
            $this->call('event:emit', [
                'event' => 'auth.login_failed',
                'data' => json_encode([
                    'error' => 'token_exchange_failed',
                    'reason' => 'Failed to exchange authorization code for access token',
                ]),
            ]);

            return self::FAILURE;
        }

        // Save complete token data to config directory (PHAR compatible)
        $tokenFile = config('spotify.token_path');
        $configDir = dirname($tokenFile);
        if (! is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        file_put_contents($tokenFile, json_encode($tokenData, JSON_PRETTY_PRINT));
        chmod($tokenFile, 0600); // Only owner can read/write

        // Emit login completed event
        $this->call('event:emit', [
            'event' => 'auth.login_completed',
            'data' => json_encode([
                'user_authenticated' => true,
                'scopes_granted' => explode(' ', implode(' ', $scopes)),
            ]),
        ]);

        info('✅ Successfully authenticated with Spotify!');
        info('');
        info('Try these commands:');
        info('  music play "Never Gonna Give You Up"');
        info('  music pause');
        info('  music current');

        return self::SUCCESS;
    }

    private function findAvailablePort(): int
    {
        $ports = [8888, 8889, 8890, 8891, 8892];

        foreach ($ports as $port) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if (! $connection) {
                return $port;
            }
            fclose($connection);
        }

        return 8888; // Fallback
    }

    private function createCallbackServer(): string
    {
        $serverScript = sys_get_temp_dir().'/spotify_callback.php';
        $codeFile = sys_get_temp_dir().'/spotify_code.txt';

        file_put_contents($serverScript, '<?php
$uri = $_SERVER["REQUEST_URI"];
if (str_starts_with($uri, "/callback") && isset($_GET["code"])) {
    file_put_contents("'.$codeFile.'", $_GET["code"]);
    echo "<!DOCTYPE html>
<html>
<head>
    <title>Music CLI - Spotify Connected</title>
    <style>
        body {
            background: #000;
            color: #1DB954;
            font-family: -apple-system, system-ui, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            text-align: center;
        }
        h1 {
            font-size: 4em;
            margin: 0;
        }
        p {
            font-size: 1.5em;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class=\"container\">
        <h1>🎵 ✅</h1>
        <p>Music CLI is connected to Spotify!</p>
        <p style=\"font-size: 1em; color: #666;\">You can close this window</p>
    </div>
</body>
</html>";
    exit;
}
echo "Waiting for Spotify callback...";
');

        return $serverScript;
    }

    private function waitForAuthCode(): ?string
    {
        $codeFile = sys_get_temp_dir().'/spotify_code.txt';
        $timeout = 60;
        $start = time();

        // Clear old code file if exists
        if (file_exists($codeFile)) {
            unlink($codeFile);
        }

        while ((time() - $start) < $timeout) {
            if (file_exists($codeFile)) {
                $code = trim(file_get_contents($codeFile));
                unlink($codeFile);

                return $code;
            }
            usleep(100000); // 100ms
        }

        return null;
    }

    private function exchangeCodeForToken(string $clientId, string $clientSecret, string $code, string $redirectUri): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Basic '.base64_encode($clientId.':'.$clientSecret),
        ])->asForm()->post('https://accounts.spotify.com/api/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            return [
                'access_token' => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_in' => $data['expires_in'] ?? 3600,
                'expires_at' => time() + ($data['expires_in'] ?? 3600),
            ];
        }

        return null;
    }

    private function openUrl(string $url): void
    {
        $os = PHP_OS_FAMILY;

        $command = match ($os) {
            'Darwin' => "open '{$url}'",
            'Linux' => "xdg-open '{$url}'",
            'Windows' => "start {$url}",
            default => null
        };

        if ($command) {
            Process::run($command);
        }
    }
}
