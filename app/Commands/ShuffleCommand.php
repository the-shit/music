<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class ShuffleCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'shuffle
                            {state? : on/off/toggle - defaults to toggle}
                            {--json : Output as JSON}';

    protected $description = '🔀 Toggle or set shuffle mode for Spotify playback';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $state = strtolower($this->argument('state') ?? 'toggle');

        try {
            // Get current playback to determine current shuffle state
            $current = $spotify->getCurrentPlayback();

            if (! $current) {
                warning('⚠️  Nothing is currently playing');
                info('💡 Start playing something first');

                return self::FAILURE;
            }

            // Determine the new shuffle state
            $newState = match ($state) {
                'on' => true,
                'off' => false,
                'toggle' => ! ($current['shuffle_state'] ?? false),
                default => throw new \Exception("Invalid state: {$state}. Use 'on', 'off', or 'toggle'")
            };

            // Set shuffle state
            $spotify->setShuffle($newState);

            // Output result
            if ($this->option('json')) {
                $this->line(json_encode([
                    'shuffle' => $newState,
                    'message' => $newState ? 'Shuffle enabled' : 'Shuffle disabled',
                ]));
            } elseif ($newState) {
                info('🔀 Shuffle enabled');
            } else {
                info('➡️  Shuffle disabled');
            }

            // Emit event (but suppress output in JSON mode)
            if (! $this->option('json')) {
                $this->call('event:emit', [
                    'event' => 'playback.shuffle',
                    'data' => json_encode([
                        'shuffle' => $newState,
                        'track' => $current['name'] ?? null,
                        'artist' => $current['artist'] ?? null,
                    ]),
                ]);
            } else {
                // Still emit the event but suppress ALL output
                $this->callSilently('event:emit', [
                    'event' => 'playback.shuffle',
                    'data' => json_encode([
                        'shuffle' => $newState,
                        'track' => $current['name'] ?? null,
                        'artist' => $current['artist'] ?? null,
                    ]),
                ]);
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'error' => true,
                    'message' => $e->getMessage(),
                ]));
            } else {
                error('❌ Failed to change shuffle: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
