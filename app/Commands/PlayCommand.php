<?php

namespace App\Commands;

use App\Commands\Concerns\ChecksAuthorization;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

class PlayCommand extends Command
{
    use ChecksAuthorization;
    protected $signature = 'play 
                            {query : Song, artist, or playlist to play} 
                            {--device= : Device name or ID to play on} 
                            {--queue : Add to queue instead of playing immediately}
                            {--json : Output as JSON}';

    protected $description = 'Play a specific song/artist/playlist (use "resume" to continue paused playback)';

    public function handle()
    {
        $spotify = app(SpotifyService::class);

        if (! $spotify->isConfigured()) {
            $this->error('❌ Spotify is not configured');
            $this->info('💡 Run "spotify setup" to configure Spotify');
            $this->info('💡 Or set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET env vars');

            return self::FAILURE;
        }

        if (! $this->authorizeOrFail('spotify:play')) {
            return self::FAILURE;
        }

        $query = $this->argument('query');
        $deviceName = $this->option('device');

        if (! $query) {
            $this->error('❌ Please specify what to play');
            $this->info('💡 Usage: spotify play "song name"');
            $this->info('💡 Or use "spotify resume" to continue paused playback');

            return self::FAILURE;
        }

        // Find device by name if specified
        $deviceId = null;
        if ($deviceName) {
            $devices = $spotify->getDevices();
            foreach ($devices as $device) {
                if (stripos($device['name'], $deviceName) !== false || $device['id'] === $deviceName) {
                    $deviceId = $device['id'];
                    $this->info("🔊 Using device: {$device['name']}");
                    break;
                }
            }

            if (! $deviceId) {
                $this->error("❌ Device '{$deviceName}' not found");

                return self::FAILURE;
            }
        }

        if (!$this->option('json')) {
            $this->info("🎵 Searching for: {$query}");
        }

        try {
            $result = $spotify->search($query);

            if ($result) {
                if ($this->option('queue')) {
                    // Add to queue instead of playing
                    $spotify->addToQueue($result['uri']);
                    
                    if ($this->option('json')) {
                        $this->line(json_encode([
                            'success' => true,
                            'action' => 'queued',
                            'track' => [
                                'name' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri']
                            ],
                            'search_query' => $query
                        ]));
                        // Still emit the event but suppress output
                        $this->callSilently('event:emit', [
                            'event' => 'track.queued',
                            'data' => json_encode([
                                'track' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri'],
                                'search_query' => $query,
                            ]),
                        ]);
                    } else {
                        $this->info("➕ Added to queue: {$result['name']} by {$result['artist']}");
                        $this->info('📋 It will play after the current track');

                        // Emit queue event
                        $this->call('event:emit', [
                            'event' => 'track.queued',
                            'data' => json_encode([
                                'track' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri'],
                                'search_query' => $query,
                            ]),
                        ]);
                    }
                } else {
                    // Play immediately
                    $spotify->play($result['uri'], $deviceId);
                    
                    if ($this->option('json')) {
                        $this->line(json_encode([
                            'success' => true,
                            'action' => 'playing',
                            'track' => [
                                'name' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri']
                            ],
                            'device_id' => $deviceId,
                            'search_query' => $query
                        ]));
                        // Still emit the event but suppress output
                        $this->callSilently('event:emit', [
                            'event' => 'track.played',
                            'data' => json_encode([
                                'track' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri'],
                                'search_query' => $query,
                            ]),
                        ]);
                    } else {
                        $this->info("▶️  Playing: {$result['name']} by {$result['artist']}");

                        // Emit play event
                        $this->call('event:emit', [
                            'event' => 'track.played',
                            'data' => json_encode([
                                'track' => $result['name'],
                                'artist' => $result['artist'],
                                'uri' => $result['uri'],
                                'search_query' => $query,
                            ]),
                        ]);
                    }
                }
            } else {
                if ($this->option('json')) {
                    $this->line(json_encode([
                        'success' => false,
                        'error' => "No results found for: {$query}"
                    ]));
                } else {
                    $this->warn("No results found for: {$query}");
                }

                return self::FAILURE;
            }
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
            } else {
                $this->error('Failed to play: '.$e->getMessage());

                // Emit error event
                $this->call('event:emit', [
                    'event' => 'error.playback_failed',
                    'data' => json_encode([
                        'command' => 'play',
                        'action' => 'play',
                        'error' => $e->getMessage(),
                    ]),
                ]);
            }

            return self::FAILURE;
        }

        if (!$this->option('json')) {
            $this->info('✅ Playback started!');
        }

        return self::SUCCESS;
    }
}
