<?php

use App\Mcp\Resources\DevicesResource;
use App\Mcp\Resources\NowPlayingResource;
use App\Mcp\SpotifyServer;
use App\Services\SpotifyService;
use Tests\TestCase;

uses(TestCase::class);

// ---------------------------------------------------------------------------
// NowPlayingResource
// ---------------------------------------------------------------------------

describe('NowPlayingResource', function () {

    it('returns playing false JSON when nothing is playing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn(null);
        });

        $response = SpotifyServer::resource(NowPlayingResource::class);

        $response->assertOk();
        $response->assertSee('"playing":false');
    });

    it('returns full playback JSON when a track is playing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'is_playing' => true,
                'name' => 'Kashmir',
                'artist' => 'Led Zeppelin',
                'album' => 'Physical Graffiti',
                'progress_ms' => 120000,
                'duration_ms' => 515000,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => ['name' => 'MacBook Pro'],
            ]);
        });

        $response = SpotifyServer::resource(NowPlayingResource::class);

        $response->assertOk();
        $response->assertSee('"playing":true');
        $response->assertSee('"track":"Kashmir"');
        $response->assertSee('"artist":"Led Zeppelin"');
        $response->assertSee('"album":"Physical Graffiti"');
        $response->assertSee('"progress_ms":120000');
        $response->assertSee('"duration_ms":515000');
        $response->assertSee('"shuffle":false');
        $response->assertSee('"repeat":"off"');
        $response->assertSee('"device":"MacBook Pro"');
    });

    it('returns null device when device name is missing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->once()->andReturn([
                'is_playing' => false,
                'name' => 'Song',
                'artist' => 'Artist',
                'album' => 'Album',
                'progress_ms' => 0,
                'duration_ms' => 180000,
                'shuffle_state' => false,
                'repeat_state' => 'off',
                'device' => [],
            ]);
        });

        $response = SpotifyServer::resource(NowPlayingResource::class);

        $response->assertOk();
        $response->assertSee('"device":null');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->andReturn(null);
        });

        SpotifyServer::resource(NowPlayingResource::class)
            ->assertName('now-playing');
    });

    it('has the correct URI', function () {
        $resource = app(NowPlayingResource::class);
        expect($resource->uri())->toBe('spotify://now-playing');
    });

    it('has application/json MIME type', function () {
        $resource = app(NowPlayingResource::class);
        expect($resource->mimeType())->toBe('application/json');
    });

    it('has a description', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getCurrentPlayback')->andReturn(null);
        });

        SpotifyServer::resource(NowPlayingResource::class)
            ->assertDescription('Current Spotify playback state — track, artist, album, progress, device');
    });

});

// ---------------------------------------------------------------------------
// DevicesResource
// ---------------------------------------------------------------------------

describe('DevicesResource', function () {

    it('returns empty JSON array when no devices are available', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([]);
        });

        SpotifyServer::resource(DevicesResource::class)
            ->assertOk()
            ->assertSee('[]');
    });

    it('returns JSON array of devices with mapped fields', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'device-1',
                    'name' => 'MacBook Pro',
                    'type' => 'Computer',
                    'is_active' => true,
                    'volume_percent' => 75,
                ],
                [
                    'id' => 'device-2',
                    'name' => 'iPhone',
                    'type' => 'Smartphone',
                    'is_active' => false,
                    'volume_percent' => 40,
                ],
            ]);
        });

        $response = SpotifyServer::resource(DevicesResource::class);

        $response->assertOk();
        $response->assertSee('"id":"device-1"');
        $response->assertSee('"name":"MacBook Pro"');
        $response->assertSee('"type":"Computer"');
        $response->assertSee('"active":true');
        $response->assertSee('"volume":75');
        $response->assertSee('"id":"device-2"');
        $response->assertSee('"active":false');
    });

    it('defaults active to false when is_active is missing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'device-1',
                    'name' => 'Chromecast',
                    'type' => 'CastAudio',
                    // is_active not present
                ],
            ]);
        });

        SpotifyServer::resource(DevicesResource::class)
            ->assertOk()
            ->assertSee('"active":false');
    });

    it('sets volume to null when volume_percent is missing', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->once()->andReturn([
                [
                    'id' => 'device-1',
                    'name' => 'Chromecast',
                    'type' => 'CastAudio',
                    'is_active' => false,
                    // volume_percent not present
                ],
            ]);
        });

        SpotifyServer::resource(DevicesResource::class)
            ->assertOk()
            ->assertSee('"volume":null');
    });

    it('has the correct MCP name', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->andReturn([]);
        });

        SpotifyServer::resource(DevicesResource::class)
            ->assertName('devices');
    });

    it('has the correct URI', function () {
        $resource = app(DevicesResource::class);
        expect($resource->uri())->toBe('spotify://devices');
    });

    it('has application/json MIME type', function () {
        $resource = app(DevicesResource::class);
        expect($resource->mimeType())->toBe('application/json');
    });

    it('has a description', function () {
        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('getDevices')->andReturn([]);
        });

        SpotifyServer::resource(DevicesResource::class)
            ->assertDescription('Available Spotify playback devices');
    });

});
