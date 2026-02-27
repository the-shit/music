<?php

use App\Commands\AutopilotCommand;
use Illuminate\Support\Facades\Config;

describe('AutopilotCommand', function () {

    beforeEach(function () {
        Config::set('autopilot.mood_presets', config('autopilot.mood_presets'));
        $this->command = $this->app->make(AutopilotCommand::class);
    });

    it('uses mood presets from config', function () {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('moodPresets');
        $method->setAccessible(true);

        $presets = $method->invoke($this->command);

        expect($presets)->toHaveCount(10);
        expect($presets)->toHaveKeys(['flow', 'focus', 'sleep']);
        expect($presets['flow'])->toHaveKey('target_energy');
    });

    it('builds track and artist seeds from current and recent playback', function () {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('buildRecommendationSeeds');
        $method->setAccessible(true);

        $current = [
            'uri' => 'spotify:track:current123',
            'artist_id' => 'artist_current',
        ];

        $recent = [
            ['uri' => 'spotify:track:recent1', 'artist_id' => 'artist_recent_1'],
            ['uri' => 'spotify:track:recent2', 'artist_id' => 'artist_recent_2'],
            ['uri' => 'spotify:track:recent3', 'artist_id' => 'artist_recent_3'],
            ['uri' => 'spotify:track:recent4', 'artist_id' => 'artist_recent_4'],
            ['uri' => 'spotify:track:recent5', 'artist_id' => 'artist_recent_5'],
        ];

        [$trackSeeds, $artistSeeds] = $method->invoke($this->command, $current, $recent);

        expect($trackSeeds)->toBe(['current123', 'recent1', 'recent2']);
        expect($artistSeeds)->toBe([
            'artist_current',
            'artist_recent_1',
            'artist_recent_2',
            'artist_recent_3',
            'artist_recent_4',
        ]);
    });

    it('parses launchctl pid output robustly', function () {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('parseLaunchctlPid');
        $method->setAccessible(true);

        $valid = $method->invoke($this->command, '"PID" = 12345;');
        $invalid = $method->invoke($this->command, 'no pid here');
        $zero = $method->invoke($this->command, '"PID" = 0;');

        expect($valid)->toBe(12345);
        expect($invalid)->toBeNull();
        expect($zero)->toBeNull();
    });
});
