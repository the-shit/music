<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyPlayerService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

class RepeatCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'repeat
                            {state? : off/track/context/toggle - defaults to toggle}
                            {--json : Output as JSON}';

    protected $description = '🔁 Set repeat mode for Spotify playback (off/track/context)';

    public function handle(SpotifyPlayerService $player): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $state = strtolower($this->argument('state') ?? 'toggle');

        try {
            // Get current playback to determine current repeat state
            $current = $player->getCurrentPlayback();

            if (! $current) {
                warning('⚠️  Nothing is currently playing');
                info('💡 Start playing something first');

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
            $player->setRepeat($newState);

            // Output result
            if ($this->option('json')) {
                $this->line(json_encode([
                    'repeat' => $newState,
                    'message' => $this->getRepeatMessage($newState),
                ]));
            } else {
                info($this->getRepeatIcon($newState).' '.$this->getRepeatMessage($newState));
            }

            // Emit event (but suppress output in JSON mode)
            if (! $this->option('json')) {
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
                error('❌ Failed to change repeat mode: '.$e->getMessage());
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
