<?php

namespace App\Commands;

use App\Commands\Concerns\RequiresSpotifyConfig;
use App\Services\SpotifyService;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class PlaylistCreateCommand extends Command
{
    use RequiresSpotifyConfig;

    protected $signature = 'playlist:create
                            {name : Name of the playlist to create}
                            {--description= : Playlist description}
                            {--public : Make the playlist public (default is private)}
                            {--json : Output as JSON}';

    protected $description = 'Create a new Spotify playlist';

    public function handle(SpotifyService $spotify): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $name = $this->argument('name');
        $description = $this->option('description') ?? '';
        $public = (bool) $this->option('public');

        try {
            $result = $spotify->createPlaylist($name, $description, $public);
        } catch (\Exception $e) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => true, 'message' => $e->getMessage()]));
            } else {
                error('Failed to create playlist: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if (! $result) {
            if ($this->option('json')) {
                $this->line(json_encode(['error' => true, 'message' => 'Spotify API returned no result']));
            } else {
                error('Failed to create playlist — Spotify API returned no result');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'created' => true,
                'id' => $result['id'],
                'name' => $result['name'],
                'url' => $result['external_urls']['spotify'] ?? null,
                'public' => $public,
            ]));

            return self::SUCCESS;
        }

        $url = $result['external_urls']['spotify'] ?? null;
        $visibility = $public ? 'public' : 'private';

        info("Created {$visibility} playlist: {$result['name']}");

        if ($url) {
            note($url);
        }

        if ($description) {
            note($description);
        }

        return self::SUCCESS;
    }
}
