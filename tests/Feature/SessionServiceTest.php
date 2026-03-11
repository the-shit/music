<?php

use App\Services\SessionService;
use App\Services\SpotifyService;

beforeEach(function (): void {
    $this->spotify = Mockery::mock(SpotifyService::class);
    $this->session = new SessionService($this->spotify);
});

describe('planFromMood', function (): void {
    it('creates a single-phase plan from mood preset', function (): void {
        $plan = $this->session->planFromMood('flow', 45);

        expect($plan)->toHaveKeys(['phases', 'total_duration', 'playlist_name']);
        expect($plan['total_duration'])->toBe(45);
        expect($plan['phases'])->toHaveCount(1);
        expect($plan['phases'][0]['mood'])->toBe('flow');
        expect($plan['phases'][0]['duration_minutes'])->toBe(45);
    });

    it('uses preset audio features', function (): void {
        $plan = $this->session->planFromMood('hype', 30);

        expect($plan['phases'][0]['energy'])->toBe(0.9);
        expect($plan['phases'][0]['valence'])->toBe(0.8);
        expect($plan['phases'][0]['tempo'])->toBe(140);
    });

    it('falls back to flow when mood is unknown', function (): void {
        $plan = $this->session->planFromMood('nonexistent', 30);

        // Falls back to flow preset
        expect($plan['phases'][0]['energy'])->toBe(0.6);
    });
});

describe('fetchCandidates', function (): void {
    it('fetches tracks for each phase', function (): void {
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

describe('quickSession', function (): void {
    it('plans, fetches, and queues in one call', function (): void {
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

    it('skips tracks that fail to queue', function (): void {
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

describe('queueTracks', function (): void {
    it('queues tracks from curated plan', function (): void {
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

describe('queueTracks edge cases', function (): void {
    it('skips tracks that fail to queue', function (): void {
        $this->spotify->shouldReceive('addToQueue')
            ->with('spotify:track:1')
            ->once();
        $this->spotify->shouldReceive('addToQueue')
            ->with('spotify:track:2')
            ->once()
            ->andThrow(new Exception('Unavailable'));

        $curated = [
            'phases' => [
                [
                    'name' => 'Phase 1',
                    'track_uris' => ['spotify:track:1', 'spotify:track:2'],
                    'dj_note' => 'Test',
                ],
            ],
        ];

        $queued = $this->session->queueTracks($curated);
        expect($queued)->toBe(1);
    });

    it('handles phases with no track_uris key', function (): void {
        $this->spotify->shouldNotReceive('addToQueue');

        $curated = [
            'phases' => [
                ['name' => 'Empty Phase', 'dj_note' => 'Nothing here'],
            ],
        ];

        $queued = $this->session->queueTracks($curated);
        expect($queued)->toBe(0);
    });
});

describe('planFromDescription', function (): void {
    it('parses a valid AI response into a structured plan', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $aiResponse = '{"phases":[{"name":"Focus Time","mood":"flow","duration_minutes":30,"energy":0.6,"valence":0.5,"tempo":120,"description":"Deep work"}],"total_duration":30,"playlist_name":"Deep Focus"}';
        $result = $parseJson->invoke($this->session, $aiResponse);

        expect($result)->toHaveKeys(['phases', 'total_duration', 'playlist_name']);
        expect($result['playlist_name'])->toBe('Deep Focus');
        expect($result['phases'])->toHaveCount(1);
        expect($result['phases'][0]['mood'])->toBe('flow');
    });

    it('parses multi-phase AI responses', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $aiResponse = '{"phases":[{"name":"Warmup","mood":"chill","duration_minutes":10,"energy":0.3,"valence":0.4,"tempo":90,"description":"Ease in"},{"name":"Peak","mood":"hype","duration_minutes":20,"energy":0.9,"valence":0.8,"tempo":140,"description":"Go hard"}],"total_duration":30,"playlist_name":"Morning Ramp"}';
        $result = $parseJson->invoke($this->session, $aiResponse);

        expect($result['phases'])->toHaveCount(2);
        expect($result['phases'][0]['mood'])->toBe('chill');
        expect($result['phases'][1]['mood'])->toBe('hype');
        expect($result['total_duration'])->toBe(30);
    });
});

describe('parseJson', function (): void {
    it('parses clean JSON', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $result = $parseJson->invoke($this->session, '{"key": "value"}');
        expect($result)->toBe(['key' => 'value']);
    });

    it('strips markdown code fences', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $input = "```json\n{\"key\": \"value\"}\n```";
        $result = $parseJson->invoke($this->session, $input);
        expect($result)->toBe(['key' => 'value']);
    });

    it('throws on invalid JSON', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $parseJson->invoke($this->session, 'not json at all');
    })->throws(RuntimeException::class, 'AI response was not valid JSON');
});

describe('curate response parsing', function (): void {
    it('parses a valid curator response', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $curatorResponse = '{"playlist_name":"Vibes","playlist_description":"Chill","phases":[{"name":"Chill","track_uris":["spotify:track:aaa"],"dj_note":"Smooth"}]}';
        $result = $parseJson->invoke($this->session, $curatorResponse);

        expect($result)->toHaveKeys(['playlist_name', 'playlist_description', 'phases']);
        expect($result['phases'][0]['track_uris'])->toBe(['spotify:track:aaa']);
        expect($result['phases'][0]['dj_note'])->toBe('Smooth');
    });

    it('parses multi-phase curated response', function (): void {
        $parseJson = new ReflectionMethod(\App\Services\SessionService::class, 'parseJson');
        $parseJson->setAccessible(true);

        $curatorResponse = '{"playlist_name":"Night Drive","playlist_description":"Late night vibes","phases":[{"name":"Sunset","track_uris":["spotify:track:1","spotify:track:2"],"dj_note":"Ease into it"},{"name":"Midnight","track_uris":["spotify:track:3"],"dj_note":"Deep cuts only"}]}';
        $result = $parseJson->invoke($this->session, $curatorResponse);

        expect($result['phases'])->toHaveCount(2);
        expect($result['phases'][0]['track_uris'])->toHaveCount(2);
        expect($result['phases'][1]['track_uris'])->toHaveCount(1);
    });
});

describe('phaseToAudioFeatures', function (): void {
    it('maps phase values to audio feature targets', function (): void {
        $method = new ReflectionMethod(\App\Services\SessionService::class, 'phaseToAudioFeatures');
        $method->setAccessible(true);

        $phase = [
            'energy' => 0.8,
            'valence' => 0.7,
            'tempo' => 130,
            'mood' => 'hype',
        ];

        $features = $method->invoke($this->session, $phase);

        expect($features['target_energy'])->toBe(0.8);
        expect($features['target_valence'])->toBe(0.7);
        expect($features['target_tempo'])->toBe(130);
    });

    it('merges preset features for known moods', function (): void {
        $method = new ReflectionMethod(\App\Services\SessionService::class, 'phaseToAudioFeatures');
        $method->setAccessible(true);

        // Phase with mood but missing some features — should pull from preset
        $phase = [
            'mood' => 'chill',
            'energy' => 0.3,
        ];

        $features = $method->invoke($this->session, $phase);

        expect($features['target_energy'])->toBe(0.3); // explicit wins
        expect($features)->toHaveKey('target_valence'); // pulled from preset
    });

    it('handles phase with no mood gracefully', function (): void {
        $method = new ReflectionMethod(\App\Services\SessionService::class, 'phaseToAudioFeatures');
        $method->setAccessible(true);

        $phase = ['energy' => 0.5];
        $features = $method->invoke($this->session, $phase);

        expect($features['target_energy'])->toBe(0.5);
    });
});

describe('getters', function (): void {
    it('returns phases after planning', function (): void {
        $this->session->planFromMood('ambient', 30);

        expect($this->session->getPhases())->toHaveCount(1);
        expect($this->session->getSessionPlan())->toHaveKey('playlist_name');
    });

    it('returns empty arrays before planning', function (): void {
        expect($this->session->getPhases())->toBe([]);
        expect($this->session->getSessionPlan())->toBe([]);
    });
});
