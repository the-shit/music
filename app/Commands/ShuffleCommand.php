<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class ShuffleCommand extends Command
{
    use ChecksAuthorization;

    protected $signature = 'shuffle
                            {state? : on/off/toggle - defaults to toggle}
                            {--json : Output as JSON}';

    protected $description = '🔀 Toggle or set shuffle mode for Spotify playback';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" first');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:shuffle')) {
            return self::FAILURE;
        }

        $state = strtolower($this->argument('state') ?? 'toggle');

        try {
            // Get current playback to determine current shuffle state
            $current = $spotify->getCurrentPlayback();

            if (! $current) {
                $this->warn('⚠️  Nothing is currently playing');
                $this->info('💡 Start playing something first');

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
            } else {
                if ($newState) {
                    $this->info('🔀 Shuffle enabled');
                } else {
                    $this->info('➡️  Shuffle disabled');
                }
            }

            // Emit event (but suppress output in JSON mode)
            if (!$this->option('json')) {
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
                $this->error('❌ Failed to change shuffle: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
