<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use LaravelZero\Framework\Commands\Command;

class ServeCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'serve
        {--port=9876 : Port to listen on}
        {--host=127.0.0.1 : Host to bind to}';

    protected $description = 'Run the Spotify request server (accepts Slack slash commands)';

    public function handle()
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $port = (int) $this->option('port');
        $host = $this->option('host');

        // Write the request handler script
        $handlerScript = $this->createHandler();

        $this->info('🎵 Spotify Request Server');
        $this->info("📡 Listening on http://{$host}:{$port}");
        $this->newLine();
        $this->info('Slack slash command URL: http://<your-domain>:{$port}/slack/queue');
        $this->info('API endpoint: POST http://{$host}:{$port}/api/queue?track=<query>');
        $this->newLine();
        $this->info('Ctrl+C to stop');
        $this->newLine();

        // Clean up handler script on exit
        register_shutdown_function(function () use ($handlerScript) {
            @unlink($handlerScript);
        });

        // Start the PHP built-in server
        $listen = escapeshellarg("{$host}:{$port}");
        $router = escapeshellarg($handlerScript);
        passthru("php -S {$listen} {$router}");

        return self::SUCCESS;
    }

    private function createHandler(): string
    {
        $scriptPath = sys_get_temp_dir().'/spotify_server.php';
        $configDir = config('spotify.config_dir');
        $tokenPath = config('spotify.token_path');
        $credentialsPath = $configDir.'/credentials.json';

        $script = <<<'PHP'
<?php
// Minimal Spotify request server for slash commands

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

// Health check
if ($uri === '/' || $uri === '/health') {
    echo json_encode(['status' => 'ok', 'service' => 'spotify-request-server']);
    exit;
}

// Slack slash command handler
if (str_starts_with($uri, '/slack/queue') && $method === 'POST') {
    // Verify Slack signing token if configured
    $slackToken = getSlackVerificationToken();
    if ($slackToken !== null) {
        $requestToken = $_POST['token'] ?? '';
        if (!hash_equals($slackToken, $requestToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid verification token']);
            exit;
        }
    }

    $text = $_POST['text'] ?? '';
    $userName = $_POST['user_name'] ?? 'someone';
    $responseUrl = $_POST['response_url'] ?? null;

    if (empty($text)) {
        echo json_encode([
            'response_type' => 'ephemeral',
            'text' => 'Usage: /queue <song name or artist - song>',
        ]);
        exit;
    }

    $result = queueTrack($text);

    if ($result) {
        $response = [
            'response_type' => 'in_channel',
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ":musical_note: *{$userName}* queued:\n*{$result['name']}* by {$result['artist']}",
                    ],
                ],
            ],
        ];
    } else {
        $response = [
            'response_type' => 'ephemeral',
            'text' => "Couldn't find: {$text}",
        ];
    }

    echo json_encode($response);
    exit;
}

// Simple API endpoint
if (str_starts_with($uri, '/api/queue') && $method === 'POST') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $params);
    $track = $params['track'] ?? '';

    // Also accept JSON body
    if (empty($track)) {
        $body = json_decode(file_get_contents('php://input'), true);
        $track = $body['track'] ?? $body['query'] ?? '';
    }

    if (empty($track)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing track parameter']);
        exit;
    }

    $result = queueTrack($track);

    if ($result) {
        echo json_encode(['queued' => true, 'track' => $result]);
    } else {
        http_response_code(404);
        echo json_encode(['queued' => false, 'error' => "Not found: {$track}"]);
    }
    exit;
}

// Now playing endpoint
if ($uri === '/api/now' || $uri === '/api/current') {
    $current = getCurrentPlayback();
    echo json_encode($current ?: ['is_playing' => false]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Not found']);
exit;

// --- Helper functions ---

function getCredentials(): array {
    $credentialsPath = 'CREDENTIALS_PATH_PLACEHOLDER';
    if (!file_exists($credentialsPath)) return [];
    return json_decode(file_get_contents($credentialsPath), true) ?? [];
}

function getSlackVerificationToken(): ?string {
    $creds = getCredentials();
    return $creds['slack_verification_token'] ?? null;
}

function getTokenData(): ?array {
    $tokenPath = 'TOKEN_PATH_PLACEHOLDER';
    if (!file_exists($tokenPath)) return null;
    return json_decode(file_get_contents($tokenPath), true);
}

function refreshToken(array $tokenData): ?array {
    $creds = getCredentials();
    $clientId = $creds['client_id'] ?? '';
    $clientSecret = $creds['client_secret'] ?? '';

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['access_token'])) {
        $tokenData['access_token'] = $data['access_token'];
        $tokenData['expires_at'] = time() + ($data['expires_in'] ?? 3600);
        $tokenPath = 'TOKEN_PATH_PLACEHOLDER';
        file_put_contents($tokenPath, json_encode($tokenData, JSON_PRETTY_PRINT));
        return $tokenData;
    }
    return null;
}

function getAccessToken(): ?string {
    $tokenData = getTokenData();
    if (!$tokenData) return null;

    // Refresh if expired
    if (($tokenData['expires_at'] ?? 0) < time() + 60) {
        $tokenData = refreshToken($tokenData);
        if (!$tokenData) return null;
    }

    return $tokenData['access_token'] ?? null;
}

function spotifyGet(string $endpoint, array $params = []): ?array {
    $token = getAccessToken();
    if (!$token) return null;

    $url = 'https://api.spotify.com/v1/' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function spotifyPost(string $endpoint, array $params = []): bool {
    $token = getAccessToken();
    if (!$token) return false;

    $url = 'https://api.spotify.com/v1/' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        CURLOPT_POSTFIELDS => '',
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

function searchTrack(string $query): ?array {
    $data = spotifyGet('search', ['q' => $query, 'type' => 'track', 'limit' => 1]);

    if (isset($data['tracks']['items'][0])) {
        $track = $data['tracks']['items'][0];
        return [
            'uri' => $track['uri'],
            'name' => $track['name'],
            'artist' => $track['artists'][0]['name'] ?? 'Unknown',
            'album' => $track['album']['name'] ?? 'Unknown',
        ];
    }

    return null;
}

function getActiveDeviceId(): ?string {
    $data = spotifyGet('me/player/devices');
    $devices = $data['devices'] ?? [];

    // Find active device first
    foreach ($devices as $device) {
        if ($device['is_active'] ?? false) return $device['id'];
    }

    // Fall back to first device
    return $devices[0]['id'] ?? null;
}

function queueTrack(string $query): ?array {
    $track = searchTrack($query);
    if (!$track) return null;

    $deviceId = getActiveDeviceId();
    if (!$deviceId) return null;

    $success = spotifyPost('me/player/queue', [
        'uri' => $track['uri'],
        'device_id' => $deviceId,
    ]);

    if ($success) {
        // Log the event
        $configDir = 'CONFIG_DIR_PLACEHOLDER';
        $eventFile = $configDir . '/events.jsonl';
        $event = json_encode([
            'component' => 'spotify',
            'event' => 'spotify.track.queued_via_server',
            'data' => $track,
            'timestamp' => date('c'),
        ]);
        @file_put_contents($eventFile, $event . "\n", FILE_APPEND | LOCK_EX);

        return $track;
    }

    return null;
}

function getCurrentPlayback(): ?array {
    $data = spotifyGet('me/player');

    if (isset($data['item'])) {
        return [
            'name' => $data['item']['name'],
            'artist' => $data['item']['artists'][0]['name'] ?? 'Unknown',
            'album' => $data['item']['album']['name'] ?? 'Unknown',
            'is_playing' => $data['is_playing'] ?? false,
        ];
    }

    return null;
}
PHP;

        // Replace placeholders with paths only — no secrets in the temp file
        $script = str_replace('TOKEN_PATH_PLACEHOLDER', $tokenPath, $script);
        $script = str_replace('CREDENTIALS_PATH_PLACEHOLDER', $credentialsPath, $script);
        $script = str_replace('CONFIG_DIR_PLACEHOLDER', $configDir, $script);

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0600);

        return $scriptPath;
    }
}
