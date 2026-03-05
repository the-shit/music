<?php

use App\Services\SessionService;
use App\Services\SpotifyService;

beforeEach(function () {
    $this->spotify = Mockery::mock(SpotifyService::class);
    $this->session = new SessionService($this->spotify);
});

describe('planFromMood', function () {
    it('creates a single-phase plan from mood preset', function () {
        $plan = $this->session->planFromMood('flow', 45);

        expect($plan)->toHaveKeys(['phases', 'total_duration', 'playlist_name']);
        expect($plan['total_duration'])->toBe(45);
        expect($plan['phases'])->toHaveCount(1);
        expect($plan['phases'][0]['mood'])->toBe('flow');
        expect($plan['phases'][0]['duration_minutes'])->toBe(45);
    });

    it('uses preset audio features', function () {
        $plan = $this->session->planFromMood('hype', 30);

        expect($plan['phases'][0]['energy'])->toBe(0.9);
        expect($plan['phases'][0]['valence'])->toBe(0.8);
        expect($plan['phases'][0]['tempo'])->toBe(140);
    });

    it('falls back to flow when mood is unknown', function () {
        $plan = $this->session->planFromMood('nonexistent', 30);

        // Falls back to flow preset
        expect($plan['phases'][0]['energy'])->toBe(0.6);
    });
});

describe('fetchCandidates', function () {
    it('fetches tracks for each phase', function () {
        $this->session->planFromMood('chill', 20);

        $this->spotify->shouldReceive('getSmartRecommendations')
            ->once()
            ->andReturn([
                ['uri' => 'spotify:track:1', 'name' => 'Chill Track', 'artist' => 'Artist'],
            ]);

        $candidates = $this->session->fetchCandidates();

        expect($candidates)->toHaveCount(1);
        expect($candidates[0]['tracks'])->toHaveCount(1);
        expect($candidates[0]['phase']['mood'])->toBe('chill');
    });
});

describe('quickSession', function () {
    it('plans, fetches, and queues in one call', function () {
        $this->spotify->shouldReceive('getSmartRecommendations')
            ->once()
            ->andReturn([
                ['uri' => 'spotify:track:aaa', 'name' => 'Track A', 'artist' => 'Artist A'],
                ['uri' => 'spotify:track:bbb', 'name' => 'Track B', 'artist' => 'Artist B'],
                ['uri' => 'spotify:track:ccc', 'name' => 'Track C', 'artist' => 'Artist C'],
            ]);

        $this->spotify->shouldReceive('addToQueue')->times(3);

        $result = $this->session->quickSession('focus', 15);

        expect($result['tracks_queued'])->toBe(3);
        expect($result['mood'])->toBe('focus');
        expect($result['duration'])->toBe(15);
        expect($result['tracks'])->toHaveCount(3);
    });

    it('skips tracks that fail to queue', function () {
        $this->spotify->shouldReceive('getSmartRecommendations')
            ->andReturn([
                ['uri' => 'spotify:track:ok', 'name' => 'OK', 'artist' => 'A'],
                ['uri' => 'spotify:track:fail', 'name' => 'Fail', 'artist' => 'B'],
            ]);

        $this->spotify->shouldReceive('addToQueue')
            ->with('spotify:track:ok')
            ->once();
        $this->spotify->shouldReceive('addToQueue')
            ->with('spotify:track:fail')
            ->once()
            ->andThrow(new Exception('Device not found'));

        $result = $this->session->quickSession('chill', 10);

        expect($result['tracks_queued'])->toBe(1);
    });
});

describe('queueTracks', function () {
    it('queues tracks from curated plan', function () {
        $this->spotify->shouldReceive('addToQueue')->times(3);

        $curated = [
            'playlist_name' => 'Test Playlist',
            'playlist_description' => 'Test',
            'phases' => [
                [
                    'name' => 'Phase 1',
                    'track_uris' => ['spotify:track:1', 'spotify:track:2'],
                    'dj_note' => 'Good stuff',
                ],
                [
                    'name' => 'Phase 2',
                    'track_uris' => ['spotify:track:3'],
                    'dj_note' => 'Better stuff',
                ],
            ],
        ];

        $queued = $this->session->queueTracks($curated);
        expect($queued)->toBe(3);
    });
});

describe('getters', function () {
    it('returns phases after planning', function () {
        $this->session->planFromMood('ambient', 30);

        expect($this->session->getPhases())->toHaveCount(1);
        expect($this->session->getSessionPlan())->toHaveKey('playlist_name');
    });
});
