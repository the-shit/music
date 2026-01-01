<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class SetupCommand extends Command
{
    protected $signature = 'setup {--reset : Reset existing credentials}';

    protected $description = 'Set up Spotify API credentials with beautiful guided setup';

    public function handle()
    {
        if ($this->option('reset')) {
            return $this->handleReset();
        }

        return $this->handleSetup();
    }

    private function handleReset(): int
    {
        if (! confirm('This will remove your stored Spotify credentials. Continue?')) {
            info('Setup cancelled.');

            return self::SUCCESS;
        }

        $this->clearStoredCredentials();

        // Emit reset event
        $this->call('event:emit', [
            'event' => 'setup.reset',
            'data' => json_encode([
                'credentials_cleared' => true,
                'token_cleared' => file_exists($_SERVER['HOME'].'/.spotify_token'),
            ]),
        ]);

        info('✅ Spotify credentials cleared');
        note('Run: music setup');

        return self::SUCCESS;
    }

    private function handleSetup(): int
    {
        // Check if already configured
        if ($this->hasStoredCredentials() && ! $this->option('reset')) {
            info('✅ Spotify is already configured');
            note('Run: music login (if not authenticated)');
            note('Run: music setup --reset (to reconfigure)');

            return self::SUCCESS;
        }

        $this->displayWelcome();

        if (! confirm('Ready to set up Spotify integration?', true)) {
            info('Setup cancelled.');

            return self::SUCCESS;
        }

        return $this->executeSetupTasks();
    }

    private function executeSetupTasks(): int
    {
        $this->newLine();
        $this->line('🎵 <options=bold>Setting up Spotify CLI</options>');
        $this->newLine();

        // Emit setup started event
        $this->call('event:emit', [
            'event' => 'setup.started',
            'data' => json_encode([
                'reset' => $this->option('reset'),
            ]),
        ]);

        $appUrl = null;
        $credentials = null;
        $defaultPort = 8888;

        try {
            // Task 1: Determine callback port
            $this->task('Determining callback port', function () use (&$defaultPort) {
                $defaultPort = $this->findAvailablePort();

                return true;
            });

            // Task 2: Open Spotify Developer Dashboard
            $this->task('Opening Spotify Developer Dashboard', function () use (&$appUrl) {
                $appUrl = 'https://developer.spotify.com/dashboard/applications';
                $this->openBrowser($appUrl);

                return true;
            });

            // Task 3: Display app configuration
            $this->task('Preparing app configuration', function () use ($defaultPort) {
                $this->newLine();
                $this->displayAppConfiguration($defaultPort);

                return true;
            });

            // Task 4: Wait for app creation
            $this->task('Waiting for app creation', function () use ($defaultPort) {
                $redirectUri = "http://127.0.0.1:{$defaultPort}/callback";

                info('📋 Now create your Spotify app in the browser');
                note('Follow the 6 steps shown above');

                $this->newLine();
                $this->line('<fg=cyan;options=bold>📋 Quick Copy: Redirect URI</fg=cyan;options=bold>');
                $this->line("<fg=green;options=bold>   {$redirectUri}</fg=green;options=bold>");
                $this->newLine();

                // Try to copy to clipboard
                if ($this->copyToClipboard($redirectUri)) {
                    note('✅ Redirect URI copied to clipboard!');
                } else {
                    note('💡 Tip: Triple-click the green URL above to select it easily');
                }

                return confirm('✅ Have you created the app and are viewing its settings page?', true);
            });

            // Task 5: Collect credentials
            $this->task('Collecting app credentials', function () use (&$credentials) {
                $credentials = $this->collectCredentials();

                return $credentials !== null;
            });

            // Task 6: Validate credentials
            $this->task('Validating credentials', function () use ($credentials) {
                return $this->validateCredentials($credentials);
            });

            // Task 7: Store credentials
            $this->task('Storing credentials securely', function () use ($credentials, $defaultPort) {
                $this->storeCredentials($credentials);
                $stored = $this->hasStoredCredentials();

                if ($stored) {
                    // Emit credentials configured event
                    $this->call('event:emit', [
                        'event' => 'setup.credentials_configured',
                        'data' => json_encode([
                            'client_id_configured' => true,
                            'callback_port' => $defaultPort,
                            'validation_passed' => true,
                        ]),
                    ]);
                }

                return $stored;
            });

            // Task 8: Test connection
            $this->task('Testing Spotify connection', function () use ($credentials) {
                return spin(
                    fn () => $this->testSpotifyConnection($credentials),
                    'Validating credentials with Spotify API...'
                );
            });

            $this->newLine();
            $this->displaySuccess();

            // Emit setup completed event
            $willAutoLogin = confirm('🔐 Would you like to authenticate with Spotify now?', true);
            $this->call('event:emit', [
                'event' => 'setup.completed',
                'data' => json_encode([
                    'auto_login_triggered' => $willAutoLogin,
                    'credentials_stored' => true,
                ]),
            ]);

            // Offer to start authentication immediately
            if ($willAutoLogin) {
                $this->newLine();
                info('🚀 Starting Spotify authentication...');

                return $this->call('login');
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            error("❌ Setup failed: {$e->getMessage()}");
            note('You can try running the setup again');

            return self::FAILURE;
        }
    }

    private function displayWelcome(): void
    {
        info('🎵 Spotify CLI Setup');
        note('This will guide you through setting up your personal Spotify integration.');
        note('You\'ll need to create a Spotify app (takes 2 minutes).');
    }

    private function displayAppConfiguration(int $port): void
    {
        $username = trim(shell_exec('whoami')) ?: 'Developer';
        $appName = "Music CLI - {$username}";
        $redirectUri = "http://127.0.0.1:{$port}/callback";

        info('📋 Step-by-step Spotify app creation:');

        $this->newLine();
        note('1. 📱 App Name: Enter any name you prefer (suggestion: "'.$appName.'")');
        note('2. 📝 App Description: Enter any description (suggestion: "Spotify CLI Tool")');
        note('3. 🌐 Website URL: Enter any URL (suggestion: "https://github.com/your-user/music")');

        $this->newLine();
        $this->line('<fg=yellow;options=bold>4. 🔗 REDIRECT URI - COPY THIS EXACTLY:</fg=yellow;options=bold>');
        $this->line("<fg=green;options=bold>   {$redirectUri}</fg=green;options=bold>");
        $this->newLine();

        note('5. 📡 Which APIs: Select "Web API" (✅ Web API only)');
        note('6. ✅ Accept Terms of Service and click "Save"');

        $this->newLine();
        warning('⚠️  IMPORTANT: Must use 127.0.0.1 (not localhost) for security');
        note("💡 Using port {$port} for OAuth callback server");
    }

    private function collectCredentials(): ?array
    {
        info('🔑 App Credentials');
        note('In your Spotify app dashboard:');
        note('1. Copy your Client ID (visible by default)');
        note('2. Click "View client secret" and copy the secret');

        $clientId = text(
            label: '📋 Client ID',
            placeholder: 'Paste your Spotify app Client ID',
            required: true,
            validate: fn (string $value) => strlen($value) < 20
                ? 'Client ID appears to be too short'
                : null
        );

        $clientSecret = password(
            label: '🔐 Client Secret',
            placeholder: 'Paste your Spotify app Client Secret',
            required: true,
            validate: fn (string $value) => strlen($value) < 20
                ? 'Client Secret appears to be too short'
                : null
        );

        return [
            'client_id' => trim($clientId),
            'client_secret' => trim($clientSecret),
        ];
    }

    private function validateCredentials(array $credentials): bool
    {
        // Basic validation
        if (strlen($credentials['client_id']) < 20) {
            throw new \Exception('Client ID appears to be invalid (too short)');
        }

        if (strlen($credentials['client_secret']) < 20) {
            throw new \Exception('Client Secret appears to be invalid (too short)');
        }

        // Pattern validation
        if (! preg_match('/^[a-zA-Z0-9]+$/', $credentials['client_id'])) {
            throw new \Exception('Client ID contains invalid characters');
        }

        if (! preg_match('/^[a-zA-Z0-9]+$/', $credentials['client_secret'])) {
            throw new \Exception('Client Secret contains invalid characters');
        }

        return true;
    }

    private function storeCredentials(array $credentials): void
    {
        // Store in the component's .env file
        $envFile = base_path('.env');

        // But ALSO store in the parent app's .env if we're running as a component
        $parentEnvFile = dirname(dirname(base_path())).'/.env';

        // Store in component's .env
        $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';

        // Remove old values if they exist
        $envContent = preg_replace('/^SPOTIFY_CLIENT_ID=.*/m', '', $envContent);
        $envContent = preg_replace('/^SPOTIFY_CLIENT_SECRET=.*/m', '', $envContent);
        $envContent = trim($envContent);

        // Add new values
        $envContent .= "\n\n# Spotify API Credentials\n";
        $envContent .= "SPOTIFY_CLIENT_ID={$credentials['client_id']}\n";
        $envContent .= "SPOTIFY_CLIENT_SECRET={$credentials['client_secret']}\n";

        file_put_contents($envFile, $envContent);
    }

    private function testSpotifyConnection(array $credentials): bool
    {
        try {
            // Test client credentials flow
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.base64_encode($credentials['client_id'].':'.$credentials['client_secret']),
            ])->asForm()->post('https://accounts.spotify.com/api/token', [
                'grant_type' => 'client_credentials',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return isset($data['access_token']);
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function displaySuccess(): void
    {
        info('🎉 Spotify CLI setup complete!');

        note('🚀 What\'s next?');
        note('1. 🔐 music login (authenticate with Spotify)');
        note('2. 🎵 music current (see what\'s playing)');
        note('3. ▶️  music play "Never Gonna Give You Up"');
        note('4. ⏸️  music pause');

        note('💡 Pro Tips:');
        note("• Run music setup --reset to reconfigure");
        note("• Your token is stored securely in ~/.config/spotify-cli/");
        note('• All commands support --help for usage info');
    }

    private function hasStoredCredentials(): bool
    {
        $envFile = base_path('.env');
        if (! file_exists($envFile)) {
            return false;
        }

        $envContent = file_get_contents($envFile);

        return str_contains($envContent, 'SPOTIFY_CLIENT_ID') &&
               str_contains($envContent, 'SPOTIFY_CLIENT_SECRET');
    }

    private function clearStoredCredentials(): void
    {
        $envFile = base_path('.env');
        if (file_exists($envFile)) {
            $envContent = file_get_contents($envFile);
            $envContent = preg_replace('/^SPOTIFY_CLIENT_ID=.*/m', '', $envContent);
            $envContent = preg_replace('/^SPOTIFY_CLIENT_SECRET=.*/m', '', $envContent);
            file_put_contents($envFile, trim($envContent));
        }

        // Clear token from config directory (PHAR compatible path)
        $tokenFile = config('spotify.token_path');
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
        }

        // Also clean up legacy token locations
        $legacyLocations = [
            ($_SERVER['HOME'] ?? getenv('HOME')).'/.spotify_token',
            base_path('storage/spotify_token.json'),
        ];
        foreach ($legacyLocations as $legacyFile) {
            if (file_exists($legacyFile)) {
                unlink($legacyFile);
            }
        }
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

        return 8888;
    }

    private function openBrowser(string $url): void
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

    private function copyToClipboard(string $text): bool
    {
        $os = PHP_OS_FAMILY;

        try {
            $command = match ($os) {
                'Darwin' => "echo '{$text}' | pbcopy",
                'Linux' => "echo '{$text}' | xclip -selection clipboard",
                'Windows' => "echo {$text} | clip",
                default => null
            };

            if ($command) {
                Process::run($command);

                return true;
            }
        } catch (\Exception $e) {
            // Clipboard copy failed
        }

        return false;
    }
}
