<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class PlayerCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'player';

    protected $description = '🎵 Interactive Spotify player with visual controls';

    private SpotifyService $spotify;

    private bool $running = true;

    public function handle()
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $this->spotify = app(SpotifyService::class);

        // Check if we're in an interactive terminal
        if (! $this->input->isInteractive()) {
            $this->error('❌ Player requires an interactive terminal');
            $this->info('💡 Run without piping or in a proper terminal');

            return self::FAILURE;
        }

        $this->info('🎵 Spotify Interactive Player');
        $this->info('Loading...');

        while ($this->running) {
            try {
                $this->showPlayer();
            } catch (\Exception $e) {
                $this->error('Error: '.$e->getMessage());
                $this->running = false;
            }
        }

        return self::SUCCESS;
    }

    private function showPlayer(): void
    {
        // Get current playback state
        $current = spin(
            fn () => $this->spotify->getCurrentPlayback(),
            'Refreshing...'
        );

        if (! $current) {
            $this->warn('⏸️  Nothing playing');
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
        if ($modes) {
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
        $progressSec = $progressSec % 60;

        $durationMin = floor($durationSec / 60);
        $durationSec = $durationSec % 60;

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

        return $modes ? implode('  ', $modes) : '';
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
                    $this->spotify->pause();
                    info('⏸️  Paused');
                    break;

                case 'resume':
                    $this->spotify->resume();
                    info('▶️  Resumed');
                    break;

                case 'next':
                    $this->spotify->next();
                    info('⏭️  Skipped to next');
                    break;

                case 'previous':
                    $this->spotify->previous();
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
            $this->error('Error: '.$e->getMessage());
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

        $this->spotify->setVolume((int) $newVolume);
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
            fn () => $this->spotify->searchMultiple($query, 'track', 10),
            'Searching...'
        );

        if (empty($results)) {
            $this->warn('No results found');

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
            $this->spotify->play($uri);
            $selected = collect($results)->firstWhere('uri', $uri);
            info("▶️  Playing: {$selected['name']} by {$selected['artist']}");
        }
    }

    private function showQueue(): void
    {
        $queue = spin(
            fn () => $this->spotify->getQueue(),
            'Loading queue...'
        );

        if (empty($queue['queue'])) {
            $this->warn('📋 Queue is empty');

            return;
        }

        $this->info('📋 Upcoming tracks:');
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
            fn () => $this->spotify->getPlaylists(20),
            'Loading playlists...'
        );

        if (empty($playlists)) {
            $this->warn('No playlists found');

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
        if ($this->spotify->playPlaylist($playlistId)) {
            $selected = collect($playlists)->firstWhere('id', $playlistId);
            info("▶️  Playing playlist: {$selected['name']}");
        } else {
            $this->error('Failed to play playlist');
        }
    }

    private function switchDevice(): void
    {
        $devices = spin(
            fn () => $this->spotify->getDevices(),
            'Loading devices...'
        );

        if (empty($devices)) {
            $this->warn('No devices found');

            return;
        }

        $options = [];
        foreach ($devices as $device) {
            $status = $device['is_active'] ? '🟢' : '⚪';
            $options[$device['id']] = "{$status} {$device['name']} ({$device['type']})";
        }

        $deviceId = select('Select device', $options);

        $this->spotify->transferPlayback($deviceId);
        info('✅ Switched device');
    }

    private function toggleShuffle(?array $current): void
    {
        $currentShuffle = $current['shuffle_state'] ?? false;
        $newState = ! $currentShuffle;

        $this->spotify->setShuffle($newState);

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

        $this->spotify->setRepeat($newState);

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
