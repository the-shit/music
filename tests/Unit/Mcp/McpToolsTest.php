<?php

use App\Mcp\SpotifyServer;
use App\Mcp\Tools\CurrentTool;
use App\Mcp\Tools\DevicesTool;
use App\Mcp\Tools\PauseTool;
use App\Mcp\Tools\PlayTool;
use App\Mcp\Tools\QueueAddTool;
use App\Mcp\Tools\QueueShowTool;
use App\Mcp\Tools\RepeatTool;
use App\Mcp\Tools\ResumeTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\ShuffleTool;
use App\Mcp\Tools\SkipTool;
use App\Mcp\Tools\VolumeTool;
use App\Services\SpotifyService;
use Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// PauseTool
// ---------------------------------------------------------------------------

describe('PauseTool', function () {

    it('returns success text when pause succeeds', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('pause')->once();
        });

        SpotifyServer::tool(PauseTool::class)
            ->assertOk()
            ->assertSee('Playback paused.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, fn ($mock) => $mock->shouldReceive('pause'));

        SpotifyServer::tool(PauseTool::class)
            ->assertName('pause');
    });

    it('has a description', function () {
        $this->mock(SpotifyService::class, fn ($mock) => $mock->shouldReceive('pause'));

        SpotifyServer::tool(PauseTool::class)
            ->assertDescription('Pause Spotify playback');
    });

});

// ---------------------------------------------------------------------------
// ResumeTool
// ---------------------------------------------------------------------------

describe('ResumeTool', function () {

    it('returns success text when resume succeeds', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('resume')->once();
        });

        SpotifyServer::tool(ResumeTool::class)
            ->assertOk()
            ->assertSee('Playback resumed.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, fn ($mock) => $mock->shouldReceive('resume'));

        SpotifyServer::tool(ResumeTool::class)
            ->assertName('resume');
    });

    it('has a description', function () {
        $this->mock(SpotifyService::class, fn ($mock) => $mock->shouldReceive('resume'));

        SpotifyServer::tool(ResumeTool::class)
            ->assertDescription('Resume Spotify playback from where it was paused');
    });

});

// ---------------------------------------------------------------------------
// SkipTool
// ---------------------------------------------------------------------------

describe('SkipTool', function () {

    it('skips to the next track by default', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('next')->once();
        });

        SpotifyServer::tool(SkipTool::class, ['direction' => 'next'])
            ->assertOk()
            ->assertSee('Skipped to next track.');
    });

    it('skips to the next track when direction is omitted', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('next')->once();
        });

        SpotifyServer::tool(SkipTool::class)
            ->assertOk()
            ->assertSee('Skipped to next track.');
    });

    it('skips to the previous track', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('previous')->once();
        });

        SpotifyServer::tool(SkipTool::class, ['direction' => 'previous'])
            ->assertOk()
            ->assertSee('Skipped to previous track.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, fn ($mock) => $mock->shouldReceive('next'));

        SpotifyServer::tool(SkipTool::class)
            ->assertName('skip');
    });

});

// ---------------------------------------------------------------------------
// PlayTool
// ---------------------------------------------------------------------------

describe('PlayTool', function () {

    it('plays a track immediately when found', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->with('Bohemian Rhapsody')
                ->andReturn([
                    'uri' => 'spotify:track:abc123',
                    'name' => 'Bohemian Rhapsody',
                    'artist' => 'Queen',
                ]);
            $mock->shouldReceive('play')
                ->once()
                ->with('spotify:track:abc123');
        });

        SpotifyServer::tool(PlayTool::class, ['query' => 'Bohemian Rhapsody'])
            ->assertOk()
            ->assertSee('Now playing: Bohemian Rhapsody by Queen');
    });

    it('adds to queue when queue flag is true', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->with('Bohemian Rhapsody')
                ->andReturn([
                    'uri' => 'spotify:track:abc123',
                    'name' => 'Bohemian Rhapsody',
                    'artist' => 'Queen',
                ]);
            $mock->shouldReceive('addToQueue')
                ->once()
                ->with('spotify:track:abc123');
        });

        SpotifyServer::tool(PlayTool::class, ['query' => 'Bohemian Rhapsody', 'queue' => true])
            ->assertOk()
            ->assertSee('Queued: Bohemian Rhapsody by Queen');
    });

    it('returns an error response when no results found', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->with('xyzzy nonexistent')
                ->andReturn(null);
        });

        SpotifyServer::tool(PlayTool::class, ['query' => 'xyzzy nonexistent'])
            ->assertHasErrors()
            ->assertSee('No results found for "xyzzy nonexistent".');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn(null);
        });

        SpotifyServer::tool(PlayTool::class, ['query' => 'test'])
            ->assertName('play');
    });

});

// ---------------------------------------------------------------------------
// CurrentTool
// ---------------------------------------------------------------------------

describe('CurrentTool', function () {

    it('returns nothing-playing when no playback active', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertOk()
            ->assertSee('Nothing is currently playing.');
    });

    it('returns formatted playback info when playing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Stairway to Heaven',
                'artist' => 'Led Zeppelin',
                'album' => 'Led Zeppelin IV',
                'progress_ms' => 93000,
                'duration_ms' => 482000,
                'is_playing' => true,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => ['name' => 'MacBook Pro'],
            ]);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertOk()
            ->assertSee('Stairway to Heaven by Led Zeppelin')
            ->assertSee('Album: Led Zeppelin IV')
            ->assertSee('State: Playing')
            ->assertSee('Shuffle: Off')
            ->assertSee('Repeat: off')
            ->assertSee('Device: MacBook Pro');
    });

    it('formats time correctly as minutes and seconds', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Song',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 93000,  // 1:33
                'duration_ms' => 240000, // 4:00
                'is_playing' => true,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => ['name' => 'Phone'],
            ]);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertOk()
            ->assertSee('1:33 / 4:00');
    });

    it('shows paused state when not playing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Song',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 0,
                'duration_ms' => 180000,
                'is_playing' => false,
                'shuffle_state' => true,
                'repeat_state' => 'track',
                'device' => ['name' => 'Speaker'],
            ]);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertOk()
            ->assertSee('State: Paused')
            ->assertSee('Shuffle: On');
    });

    it('shows unknown device when device name is missing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'name' => 'Song',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 0,
                'duration_ms' => 180000,
                'is_playing' => true,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => [],
            ]);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertOk()
            ->assertSee('Device: Unknown device');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->andReturn(null);
        });

        SpotifyServer::tool(CurrentTool::class)
            ->assertName('current');
    });

});

// ---------------------------------------------------------------------------
// VolumeTool
// ---------------------------------------------------------------------------

describe('VolumeTool', function () {

    it('returns current volume when level is omitted', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'device' => ['volume_percent' => 72],
            ]);
        });

        SpotifyServer::tool(VolumeTool::class)
            ->assertOk()
            ->assertSee('Current volume: 72%');
    });

    it('reports could not determine volume when device has no volume_percent', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            // Returns playback with device but no volume_percent key
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'device' => [],
            ]);
        });

        SpotifyServer::tool(VolumeTool::class)
            ->assertOk()
            ->assertSee('Could not determine current volume.');
    });

    it('sets volume to the given level', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setVolume')->once()->with(50);
        });

        SpotifyServer::tool(VolumeTool::class, ['level' => 50])
            ->assertOk()
            ->assertSee('Volume set to 50%.');
    });

    it('clamps volume to 0 minimum', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setVolume')->once()->with(0);
        });

        SpotifyServer::tool(VolumeTool::class, ['level' => -10])
            ->assertOk()
            ->assertSee('Volume set to 0%.');
    });

    it('clamps volume to 100 maximum', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setVolume')->once()->with(100);
        });

        SpotifyServer::tool(VolumeTool::class, ['level' => 150])
            ->assertOk()
            ->assertSee('Volume set to 100%.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->andReturn(null);
        });

        SpotifyServer::tool(VolumeTool::class)
            ->assertName('volume');
    });

});

// ---------------------------------------------------------------------------
// QueueAddTool
// ---------------------------------------------------------------------------

describe('QueueAddTool', function () {

    it('adds a found track to the queue', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->with('Hotel California')
                ->andReturn([
                    'uri' => 'spotify:track:hotel',
                    'name' => 'Hotel California',
                    'artist' => 'Eagles',
                ]);
            $mock->shouldReceive('addToQueue')
                ->once()
                ->with('spotify:track:hotel');
        });

        SpotifyServer::tool(QueueAddTool::class, ['query' => 'Hotel California'])
            ->assertOk()
            ->assertSee('Added to queue: Hotel California by Eagles');
    });

    it('returns an error when no track is found', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')
                ->once()
                ->with('nothing here')
                ->andReturn(null);
        });

        SpotifyServer::tool(QueueAddTool::class, ['query' => 'nothing here'])
            ->assertHasErrors()
            ->assertSee('No results found for "nothing here".');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn(null);
        });

        SpotifyServer::tool(QueueAddTool::class, ['query' => 'test'])
            ->assertName('queue_add');
    });

});

// ---------------------------------------------------------------------------
// QueueShowTool
// ---------------------------------------------------------------------------

describe('QueueShowTool', function () {

    it('shows empty queue message when queue is empty', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->once()->andReturn(['queue' => []]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('Queue is empty.');
    });

    it('shows empty queue message when getQueue returns empty array', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->once()->andReturn([]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('Queue is empty.');
    });

    it('lists upcoming tracks with numbering', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'Track One', 'artists' => [['name' => 'Artist A']]],
                    ['name' => 'Track Two', 'artists' => [['name' => 'Artist B']]],
                ],
            ]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('Up next:')
            ->assertSee('1. Track One by Artist A')
            ->assertSee('2. Track Two by Artist B');
    });

    it('shows currently playing track when present', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => [
                    'name' => 'Now Song',
                    'artists' => [['name' => 'Now Artist']],
                ],
                'queue' => [
                    ['name' => 'Next Song', 'artists' => [['name' => 'Next Artist']]],
                ],
            ]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('Now playing: Now Song by Now Artist')
            ->assertSee('Up next:')
            ->assertSee('1. Next Song by Next Artist');
    });

    it('handles tracks with missing artist gracefully', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => [
                    ['name' => 'Mystery Track', 'artists' => []],
                ],
            ]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('Mystery Track by Unknown');
    });

    it('limits the display to 20 tracks', function () {
        $tracks = [];
        for ($i = 1; $i <= 25; $i++) {
            $tracks[] = ['name' => "Track {$i}", 'artists' => [['name' => "Artist {$i}"]]];
        }

        $this->mock(SpotifyService::class, function ($mock) use ($tracks) {
            $mock->shouldReceive('getQueue')->once()->andReturn([
                'currently_playing' => null,
                'queue' => $tracks,
            ]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertOk()
            ->assertSee('20. Track 20')
            ->assertDontSee('21. Track 21');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getQueue')->andReturn(['queue' => []]);
        });

        SpotifyServer::tool(QueueShowTool::class)
            ->assertName('queue_show');
    });

});

// ---------------------------------------------------------------------------
// SearchTool
// ---------------------------------------------------------------------------

describe('SearchTool', function () {

    it('returns formatted search results for tracks', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('Beatles', 'track', 5)
                ->andReturn([
                    ['name' => 'Hey Jude', 'artist' => 'The Beatles', 'album' => 'Past Masters'],
                    ['name' => 'Let It Be', 'artist' => 'The Beatles', 'album' => 'Let It Be'],
                ]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'Beatles'])
            ->assertOk()
            ->assertSee('Search results for "Beatles" (tracks):')
            ->assertSee('1. Hey Jude by The Beatles (Past Masters)')
            ->assertSee('2. Let It Be by The Beatles (Let It Be)');
    });

    it('returns empty message when no results found', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('xyzzy', 'track', 5)
                ->andReturn([]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'xyzzy'])
            ->assertOk()
            ->assertSee('No tracks found for "xyzzy".');
    });

    it('supports searching by type', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('Led Zeppelin', 'artist', 5)
                ->andReturn([
                    ['name' => 'Led Zeppelin', 'artist' => '', 'album' => ''],
                ]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'Led Zeppelin', 'type' => 'artist'])
            ->assertOk()
            ->assertSee('Search results for "Led Zeppelin" (artists):')
            ->assertSee('1. Led Zeppelin');
    });

    it('respects custom limit clamped at 20', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test', 'track', 20)
                ->andReturn([]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'test', 'limit' => 999])
            ->assertOk();
    });

    it('enforces minimum limit of 1', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test', 'track', 1)
                ->andReturn([]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'test', 'limit' => 0])
            ->assertOk();
    });

    it('uses default limit of 5 when not specified', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')
                ->once()
                ->with('test', 'track', 5)
                ->andReturn([]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'test'])
            ->assertOk();
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('searchMultiple')->andReturn([]);
        });

        SpotifyServer::tool(SearchTool::class, ['query' => 'test'])
            ->assertName('search');
    });

});

// ---------------------------------------------------------------------------
// DevicesTool
// ---------------------------------------------------------------------------

describe('DevicesTool', function () {

    it('returns no devices message when devices list is empty', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([]);
        });

        SpotifyServer::tool(DevicesTool::class)
            ->assertOk()
            ->assertSee('No Spotify devices available. Open Spotify on any device.');
    });

    it('lists available devices with type and volume', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'name' => 'MacBook Pro',
                    'type' => 'Computer',
                    'is_active' => true,
                    'volume_percent' => 80,
                ],
                [
                    'name' => 'iPhone',
                    'type' => 'Smartphone',
                    'is_active' => false,
                    'volume_percent' => 50,
                ],
            ]);
        });

        SpotifyServer::tool(DevicesTool::class)
            ->assertOk()
            ->assertSee('Available devices:')
            ->assertSee('- MacBook Pro [Computer] vol:80% (active)')
            ->assertSee('- iPhone [Smartphone] vol:50%');
    });

    it('marks only the active device with (active) suffix', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'name' => 'Speaker',
                    'type' => 'Speaker',
                    'is_active' => false,
                    'volume_percent' => 60,
                ],
            ]);
        });

        SpotifyServer::tool(DevicesTool::class)
            ->assertOk()
            ->assertDontSee('(active)');
    });

    it('shows ? when volume is not available', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'name' => 'Chromecast',
                    'type' => 'CastAudio',
                    'is_active' => false,
                ],
            ]);
        });

        SpotifyServer::tool(DevicesTool::class)
            ->assertOk()
            ->assertSee('vol:?%');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->andReturn([]);
        });

        SpotifyServer::tool(DevicesTool::class)
            ->assertName('devices');
    });

});

// ---------------------------------------------------------------------------
// ShuffleTool
// ---------------------------------------------------------------------------

describe('ShuffleTool', function () {

    it('enables shuffle when enabled is true', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setShuffle')->once()->with(true);
        });

        SpotifyServer::tool(ShuffleTool::class, ['enabled' => true])
            ->assertOk()
            ->assertSee('Shuffle turned on.');
    });

    it('disables shuffle when enabled is false', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setShuffle')->once()->with(false);
        });

        SpotifyServer::tool(ShuffleTool::class, ['enabled' => false])
            ->assertOk()
            ->assertSee('Shuffle turned off.');
    });

    it('toggles shuffle off when currently on and no argument passed', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')
                ->once()
                ->andReturn(['shuffle_state' => true]);
            $mock->shouldReceive('setShuffle')->once()->with(false);
        });

        SpotifyServer::tool(ShuffleTool::class)
            ->assertOk()
            ->assertSee('Shuffle turned off.');
    });

    it('toggles shuffle on when currently off and no argument passed', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')
                ->once()
                ->andReturn(['shuffle_state' => false]);
            $mock->shouldReceive('setShuffle')->once()->with(true);
        });

        SpotifyServer::tool(ShuffleTool::class)
            ->assertOk()
            ->assertSee('Shuffle turned on.');
    });

    it('handles playback with no shuffle_state key during toggle', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            // Returns playback without shuffle_state → defaults to false → toggles to true
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([]);
            $mock->shouldReceive('setShuffle')->once()->with(true);
        });

        SpotifyServer::tool(ShuffleTool::class)
            ->assertOk()
            ->assertSee('Shuffle turned on.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setShuffle')->withAnyArgs();
        });

        SpotifyServer::tool(ShuffleTool::class, ['enabled' => true])
            ->assertName('shuffle');
    });

});

// ---------------------------------------------------------------------------
// RepeatTool
// ---------------------------------------------------------------------------

describe('RepeatTool', function () {

    it('sets repeat mode to off', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setRepeat')->once()->with('off');
        });

        SpotifyServer::tool(RepeatTool::class, ['mode' => 'off'])
            ->assertOk()
            ->assertSee('Repeat mode set to off.');
    });

    it('sets repeat mode to track', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setRepeat')->once()->with('track');
        });

        SpotifyServer::tool(RepeatTool::class, ['mode' => 'track'])
            ->assertOk()
            ->assertSee('Repeat mode set to track.');
    });

    it('sets repeat mode to context', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setRepeat')->once()->with('context');
        });

        SpotifyServer::tool(RepeatTool::class, ['mode' => 'context'])
            ->assertOk()
            ->assertSee('Repeat mode set to context.');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('setRepeat')->withAnyArgs();
        });

        SpotifyServer::tool(RepeatTool::class, ['mode' => 'off'])
            ->assertName('repeat');
    });

});
