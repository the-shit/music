<?php

use App\Providers\AuthServiceProvider;
use App\Services\SpotifyService;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

uses(TestCase::class);

describe('AuthServiceProvider', function () {

    describe('Gate Definitions', function () {

        test('defines spotify:play gate', function () {
            expect(Gate::has('spotify:play'))->toBeTrue();
        });

        test('defines spotify:pause gate', function () {
            expect(Gate::has('spotify:pause'))->toBeTrue();
        });

        test('defines spotify:resume gate', function () {
            expect(Gate::has('spotify:resume'))->toBeTrue();
        });

        test('defines spotify:skip gate', function () {
            expect(Gate::has('spotify:skip'))->toBeTrue();
        });

        test('defines spotify:volume gate', function () {
            expect(Gate::has('spotify:volume'))->toBeTrue();
        });

        test('defines spotify:shuffle gate', function () {
            expect(Gate::has('spotify:shuffle'))->toBeTrue();
        });

        test('defines spotify:repeat gate', function () {
            expect(Gate::has('spotify:repeat'))->toBeTrue();
        });

        test('defines spotify:queue gate', function () {
            expect(Gate::has('spotify:queue'))->toBeTrue();
        });

        test('defines spotify:current gate', function () {
            expect(Gate::has('spotify:current'))->toBeTrue();
        });

        test('defines spotify:devices gate', function () {
            expect(Gate::has('spotify:devices'))->toBeTrue();
        });

        test('defines spotify:player gate', function () {
            expect(Gate::has('spotify:player'))->toBeTrue();
        });
    });

    describe('Gate Authorization Logic', function () {

        test('spotify:play returns true when user-modify-playback-state scope is granted', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:play'))->toBeTrue();
        });

        test('spotify:play returns false when scope is not granted', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:play'))->toBeFalse();
        });

        test('spotify:shuffle requires both read and modify scopes', function () {
            // Only read scope - should fail
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:shuffle'))->toBeFalse();

            // Only modify scope - should fail
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:shuffle'))->toBeFalse();

            // Both scopes - should pass
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                    'user-modify-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:shuffle'))->toBeTrue();
        });

        test('spotify:current requires both user-read-playback-state and user-read-currently-playing', function () {
            // Only one scope - should fail
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            expect(Gate::allows('spotify:current'))->toBeFalse();

            // Both scopes - should pass
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                    'user-read-currently-playing',
                ]);
            });

            expect(Gate::allows('spotify:current'))->toBeTrue();
        });

        test('returns false when no scopes are granted', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([]);
            });

            expect(Gate::allows('spotify:play'))->toBeFalse();
            expect(Gate::allows('spotify:pause'))->toBeFalse();
            expect(Gate::allows('spotify:current'))->toBeFalse();
            expect(Gate::allows('spotify:shuffle'))->toBeFalse();
        });

        test('all gates pass with all scopes granted', function () {
            $allScopes = [
                'user-read-playback-state',
                'user-modify-playback-state',
                'user-read-currently-playing',
                'streaming',
                'playlist-read-private',
                'playlist-read-collaborative',
            ];

            $this->mock(SpotifyService::class, function ($mock) use ($allScopes) {
                $mock->shouldReceive('getGrantedScopes')->andReturn($allScopes);
            });

            expect(Gate::allows('spotify:play'))->toBeTrue();
            expect(Gate::allows('spotify:pause'))->toBeTrue();
            expect(Gate::allows('spotify:resume'))->toBeTrue();
            expect(Gate::allows('spotify:skip'))->toBeTrue();
            expect(Gate::allows('spotify:volume'))->toBeTrue();
            expect(Gate::allows('spotify:shuffle'))->toBeTrue();
            expect(Gate::allows('spotify:repeat'))->toBeTrue();
            expect(Gate::allows('spotify:queue'))->toBeTrue();
            expect(Gate::allows('spotify:current'))->toBeTrue();
            expect(Gate::allows('spotify:devices'))->toBeTrue();
            expect(Gate::allows('spotify:player'))->toBeTrue();
        });
    });

    describe('Scope Ability Mappings', function () {

        test('read-playback ability maps to user-read-playback-state', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-playback-state',
                ]);
            });

            expect(Gate::allows('read-playback'))->toBeTrue();
        });

        test('modify-playback ability maps to user-modify-playback-state', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-modify-playback-state',
                ]);
            });

            expect(Gate::allows('modify-playback'))->toBeTrue();
        });

        test('read-currently-playing ability maps to user-read-currently-playing', function () {
            $this->mock(SpotifyService::class, function ($mock) {
                $mock->shouldReceive('getGrantedScopes')->andReturn([
                    'user-read-currently-playing',
                ]);
            });

            expect(Gate::allows('read-currently-playing'))->toBeTrue();
        });
    });
});
