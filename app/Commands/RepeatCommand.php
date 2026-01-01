<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class RepeatCommand extends Command
{
    use ChecksAuthorization;
    protected $signature = 'repeat 
                            {state? : off/track/context/toggle - defaults to toggle}
                            {--json : Output as JSON}';

    protected $description = '🔁 Set repeat mode for Spotify playback (off/track/context)';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" first');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:repeat')) {
            return self::FAILURE;
        }

        $state = strtolower($this->argument('state') ?? 'toggle');

        try {
            // Get current playback to determine current repeat state
            $current = $spotify->getCurrentPlayback();

            if (! $current) {
                $this->warn('⚠️  Nothing is currently playing');
                $this->info('💡 Start playing something first');

                return self::FAILURE;
            }

            $currentRepeat = $current['repeat_state'] ?? 'off';

            // Determine the new repeat state
            if ($state === 'toggle') {
                // Cycle through: off -> context -> track -> off
                $newState = match ($currentRepeat) {
                    'off' => 'context',
                    'context' => 'track',
                    'track' => 'off',
                    default => 'off'
                };
            } elseif (in_array($state, ['off', 'track', 'context'])) {
                $newState = $state;
            } else {
                throw new \Exception("Invalid state: {$state}. Use 'off', 'track', 'context', or 'toggle'");
            }

            // Set repeat state
            $spotify->setRepeat($newState);

            // Output result
            if ($this->option('json')) {
                $this->line(json_encode([
                    'repeat' => $newState,
                    'message' => $this->getRepeatMessage($newState),
                ]));
            } else {
                $this->info($this->getRepeatIcon($newState).' '.$this->getRepeatMessage($newState));
            }

            // Emit event (but suppress output in JSON mode)
            if (!$this->option('json')) {
                $this->call('event:emit', [
                    'event' => 'playback.repeat',
                    'data' => json_encode([
                        'repeat' => $newState,
                        'track' => $current['name'] ?? null,
                        'artist' => $current['artist'] ?? null,
                    ]),
                ]);
            } else {
                // Still emit the event but suppress ALL output
                $this->callSilently('event:emit', [
                    'event' => 'playback.repeat',
                    'data' => json_encode([
                        'repeat' => $newState,
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
                $this->error('❌ Failed to change repeat mode: '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function getRepeatMessage(string $state): string
    {
        return match ($state) {
            'off' => 'Repeat disabled',
            'track' => 'Repeat current track',
            'context' => 'Repeat current context (album/playlist)',
            default => 'Unknown repeat state'
        };
    }

    private function getRepeatIcon(string $state): string
    {
        return match ($state) {
            'off' => '➡️ ',
            'track' => '🔂',
            'context' => '🔁',
            default => '❓'
        };
    }
}
