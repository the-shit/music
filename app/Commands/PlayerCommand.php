<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyDiscoveryService;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class PlayerCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'player';

    protected $description = '🎵 Interactive Spotify player with visual controls';

    private SpotifyPlayerService $player;

    private SpotifyDiscoveryService $discovery;

    private bool $running = true;

    public function handle(SpotifyPlayerService $player, SpotifyDiscoveryService $discovery): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $this->player = $player;
        $this->discovery = $discovery;

        // Check if we're in an interactive terminal
        if (! $this->input->isInteractive()) {
            error('❌ Player requires an interactive terminal');
            info('💡 Run without piping or in a proper terminal');

            return self::FAILURE;
        }

        info('🎵 Spotify Interactive Player');
        info('Loading...');

        while ($this->running) {
            try {
                $this->showPlayer();
            } catch (\Exception $e) {
                error('Error: '.$e->getMessage());
                $this->running = false;
            }
        }

        return self::SUCCESS;
    }

    private function showPlayer(): void
    {
        // Get current playback state
        $current = spin(
            fn (): ?array => $this->player->getCurrentPlayback(),
            'Refreshing...'
        );

        if (! $current) {
            warning('⏸️  Nothing playing');
            $action = select(
                'What would you like to do?',
                [
                    'search' => '🔍 Search and play',
                    'resume' => '▶️  Resume playback',
                    'playlists' => '📚 Browse playlists',
                    'devices' => '📱 Switch device',
                    'exit' => '🚪 Exit player',
                ]
            );

            $this->handleAction($action);

            return;
        }

        // Clear screen for better display
        $this->clearScreen();

        // Display current track info
        $this->displayNowPlaying($current);

        // Show control menu
        $action = select(
            'Controls',
            $this->getControlOptions($current['is_playing']),
            scroll: 10
        );

        $this->handleAction($action, $current);
    }

    private function displayNowPlaying(array $current): void
    {
        $this->newLine();
        $this->line('┌─────────────────────────────────────────────────┐');
        $this->line('│ 🎵 Now Playing                                  │');
        $this->line('├─────────────────────────────────────────────────┤');

        // Track info
        $track = substr($current['name'], 0, 40);
        $artist = substr($current['artist'], 0, 40);
        $album = substr($current['album'], 0, 40);

        $this->line(sprintf('│ %-47s │', $track));
        $this->line(sprintf('│ %s %-46s │', '👤', $artist));
        $this->line(sprintf('│ %s %-46s │', '💿', $album));

        // Progress bar
        $progress = $this->formatProgress($current['progress_ms'], $current['duration_ms']);
        $this->line(sprintf('│ %-47s │', $progress));

        // Volume if available
        if (isset($current['device']['volume_percent'])) {
            $volume = $this->formatVolume($current['device']['volume_percent']);
            $this->line(sprintf('│ %-47s │', $volume));
        }

        // Playback modes
        $modes = $this->formatPlaybackModes(
            $current['shuffle_state'] ?? false,
            $current['repeat_state'] ?? 'off'
        );
        if ($modes !== '' && $modes !== '0') {
            $this->line(sprintf('│ %-47s │', $modes));
        }

        $this->line('└─────────────────────────────────────────────────┘');
        $this->newLine();
    }

    private function formatProgress(int $progressMs, int $durationMs): string
    {
        $progressSec = floor($progressMs / 1000);
        $durationSec = floor($durationMs / 1000);

        $progressMin = floor($progressSec / 60);
        $progressSec %= 60;

        $durationMin = floor($durationSec / 60);
        $durationSec %= 60;

        $percentage = $durationMs > 0 ? ($progressMs / $durationMs) : 0;
        $barLength = 30;
        $filled = floor($percentage * $barLength);

        $bar = str_repeat('━', (int) $filled).'●'.str_repeat('━', (int) ($barLength - $filled - 1));

        return sprintf(
            '%s %s %d:%02d/%d:%02d',
            $progressMs < $durationMs ? '▶️' : '⏸️',
            $bar,
            $progressMin, $progressSec,
            $durationMin, $durationSec
        );
    }

    private function formatVolume(int $volume): string
    {
        $icon = match (true) {
            $volume === 0 => '🔇',
            $volume <= 33 => '🔈',
            $volume <= 66 => '🔉',
            default => '🔊'
        };

        $barLength = 20;
        $filled = floor($volume * $barLength / 100);
        $bar = str_repeat('▓', (int) $filled).str_repeat('░', (int) ($barLength - $filled));

        return sprintf('%s %s %d%%', $icon, $bar, $volume);
    }

    private function formatPlaybackModes(bool $shuffle, string $repeat): string
    {
        $modes = [];

        if ($shuffle) {
            $modes[] = '🔀 Shuffle';
        }

        if ($repeat !== 'off') {
            $repeatIcon = $repeat === 'track' ? '🔂' : '🔁';
            $repeatText = $repeat === 'track' ? 'Repeat Track' : 'Repeat All';
            $modes[] = $repeatIcon.' '.$repeatText;
        }

        return $modes !== [] ? implode('  ', $modes) : '';
    }

    private function getControlOptions(bool $isPlaying): array
    {
        $options = [];

        if ($isPlaying) {
            $options['pause'] = '⏸️  Pause';
        } else {
            $options['resume'] = '▶️  Resume';
        }

        $options += [
            'next' => '⏭️  Next track',
            'previous' => '⏮️  Previous track',
            'volume' => '🔊 Adjust volume',
            'shuffle' => '🔀 Toggle shuffle',
            'repeat' => '🔁 Change repeat mode',
            'search' => '🔍 Search',
            'queue' => '📋 View queue',
            'playlists' => '📚 Browse playlists',
            'devices' => '📱 Switch device',
            'refresh' => '🔄 Refresh',
            'exit' => '🚪 Exit player',
        ];

        return $options;
    }

    private function handleAction(string $action, ?array $current = null): void
    {
        try {
            switch ($action) {
                case 'pause':
                    $this->player->pause();
                    info('⏸️  Paused');
                    break;

                case 'resume':
                    $this->player->resume();
                    info('▶️  Resumed');
                    break;

                case 'next':
                    $this->player->next();
                    info('⏭️  Skipped to next');
                    break;

                case 'previous':
                    $this->player->previous();
                    info('⏮️  Back to previous');
                    break;

                case 'volume':
                    $this->adjustVolume($current);
                    break;

                case 'shuffle':
                    $this->toggleShuffle($current);
                    break;

                case 'repeat':
                    $this->changeRepeatMode($current);
                    break;

                case 'search':
                    $this->searchAndPlay();
                    break;

                case 'queue':
                    $this->showQueue();
                    break;

                case 'playlists':
                    $this->browsePlaylists();
                    break;

                case 'devices':
                    $this->switchDevice();
                    break;

                case 'refresh':
                    // Just refresh the display
                    break;

                case 'exit':
                    $this->running = false;
                    info('👋 Goodbye!');
                    break;
            }
        } catch (\Exception $e) {
            error('Error: '.$e->getMessage());
        }
    }

    private function adjustVolume(?array $current): void
    {
        $currentVolume = $current['device']['volume_percent'] ?? 50;

        $newVolume = select(
            "Current volume: {$currentVolume}%",
            [
                '0' => '🔇 Mute (0%)',
                '25' => '🔈 Quiet (25%)',
                '50' => '🔉 Medium (50%)',
                '75' => '🔊 Loud (75%)',
                '100' => '🔊 Max (100%)',
                'custom' => '🎚️  Custom...',
            ]
        );

        if ($newVolume === 'custom') {
            $newVolume = $this->ask('Enter volume (0-100)');
        }

        $this->player->setVolume((int) $newVolume);
        info("🔊 Volume set to {$newVolume}%");
    }

    private function searchAndPlay(): void
    {
        $query = $this->ask('🔍 Search for');

        if (empty($query)) {
            return;
        }

        info("Searching for: {$query}");

        $results = spin(
            fn (): array => $this->discovery->searchMultiple($query, 'track', 10),
            'Searching...'
        );

        if (empty($results)) {
            warning('No results found');

            return;
        }

        // Build options for selection
        $options = [];
        foreach ($results as $track) {
            $label = sprintf(
                '🎵 %s - %s (%s)',
                substr($track['name'], 0, 30),
                substr($track['artist'], 0, 25),
                substr($track['album'], 0, 20)
            );
            $options[$track['uri']] = $label;
        }

        $uri = select(
            'Select a track',
            $options,
            scroll: 10
        );

        if ($uri) {
            $this->player->play($uri);
            $selected = collect($results)->firstWhere('uri', $uri);
            info("▶️  Playing: {$selected['name']} by {$selected['artist']}");
        }
    }

    private function showQueue(): void
    {
        $queue = spin(
            fn (): array => $this->player->getQueue(),
            'Loading queue...'
        );

        if (empty($queue['queue'])) {
            warning('📋 Queue is empty');

            return;
        }

        info('📋 Upcoming tracks:');
        $this->newLine();

        // Show up to 10 tracks in queue
        $tracks = array_slice($queue['queue'], 0, 10);
        foreach ($tracks as $index => $track) {
            $number = str_pad((string) ($index + 1), 2, ' ', STR_PAD_LEFT);
            $name = substr($track['name'] ?? 'Unknown', 0, 35);
            $artist = substr($track['artists'][0]['name'] ?? 'Unknown', 0, 25);

            $this->line("{$number}. {$name} - {$artist}");
        }

        if (count($queue['queue']) > 10) {
            $this->line('    ... and '.(count($queue['queue']) - 10).' more');
        }

        $this->newLine();
        $this->ask('Press Enter to continue');
    }

    private function browsePlaylists(): void
    {
        $playlists = spin(
            fn (): array => $this->discovery->getPlaylists(20),
            'Loading playlists...'
        );

        if (empty($playlists)) {
            warning('No playlists found');

            return;
        }

        // Build options for selection
        $options = [];
        foreach ($playlists as $playlist) {
            $name = substr($playlist['name'], 0, 40);
            $tracks = $playlist['tracks']['total'] ?? 0;
            $owner = substr($playlist['owner']['display_name'] ?? 'Unknown', 0, 20);

            $label = sprintf('📚 %s (%d tracks) by %s', $name, $tracks, $owner);
            $options[$playlist['id']] = $label;
        }

        $options['back'] = '← Back to player';

        $playlistId = select(
            'Select a playlist',
            $options,
            scroll: 10
        );

        if ($playlistId === 'back') {
            return;
        }

        // Play the selected playlist
        if ($this->player->playPlaylist($playlistId)) {
            $selected = collect($playlists)->firstWhere('id', $playlistId);
            info("▶️  Playing playlist: {$selected['name']}");
        } else {
            error('Failed to play playlist');
        }
    }

    private function switchDevice(): void
    {
        $devices = spin(
            fn (): array => $this->player->getDevices(),
            'Loading devices...'
        );

        if (empty($devices)) {
            warning('No devices found');

            return;
        }

        $options = [];
        foreach ($devices as $device) {
            $status = $device['is_active'] ? '🟢' : '⚪';
            $options[$device['id']] = "{$status} {$device['name']} ({$device['type']})";
        }

        $deviceId = select('Select device', $options);

        $this->player->transferPlayback($deviceId);
        info('✅ Switched device');
    }

    private function toggleShuffle(?array $current): void
    {
        $currentShuffle = $current['shuffle_state'] ?? false;
        $newState = ! $currentShuffle;

        $this->player->setShuffle($newState);

        if ($newState) {
            info('🔀 Shuffle enabled');
        } else {
            info('➡️  Shuffle disabled');
        }
    }

    private function changeRepeatMode(?array $current): void
    {
        $currentRepeat = $current['repeat_state'] ?? 'off';

        $newState = select(
            "Current: {$currentRepeat}",
            [
                'off' => '➡️  Off',
                'context' => '🔁 Repeat All (playlist/album)',
                'track' => '🔂 Repeat Track',
            ]
        );

        $this->player->setRepeat($newState);

        $message = match ($newState) {
            'off' => '➡️  Repeat disabled',
            'context' => '🔁 Repeat all enabled',
            'track' => '🔂 Repeat track enabled',
            default => 'Repeat mode changed'
        };

        info($message);
    }

    private function clearScreen(): void
    {
        // Clear screen for better display (works on Unix-like systems)
        if (PHP_OS_FAMILY !== 'Windows') {
            system('clear');
        }
    }
}
