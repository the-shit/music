<?php

use App\Agents\AdaptAgent;
use App\Commands\AutopilotCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function autopilotSetPrivate(object $object, string $property, mixed $value): void
{
    $prop = new ReflectionProperty($object, $property);
    $prop->setAccessible(true);
    $prop->setValue($object, $value);
}

describe('AutopilotCommand', function (): void {

    beforeEach(function (): void {
        Config::set('autopilot.mood_presets', config('autopilot.mood_presets'));
        $this->command = $this->app->make(AutopilotCommand::class);
    });

    it('uses mood presets from config', function (): void {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('moodPresets');
        $method->setAccessible(true);

        $presets = $method->invoke($this->command);

        expect($presets)->toHaveCount(10);
        expect($presets)->toHaveKeys(['flow', 'focus', 'sleep']);
        expect($presets['flow'])->toHaveKey('target_energy');
    });

    it('builds track and artist seeds from current and recent playback', function (): void {
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

    it('classifies skip vs complete from track-change dwell time', function (): void {
        $reflection = new ReflectionClass($this->command);
        $record = $reflection->getMethod('recordObservation');
        $record->setAccessible(true);

        $first = $record->invoke($this->command, ['track' => 'A', 'artist' => 'X', 'timestamp' => '2026-01-01T00:00:00+00:00']);
        $second = $record->invoke($this->command, ['track' => 'B', 'artist' => 'Y', 'timestamp' => '2026-01-01T00:00:10+00:00']);
        $third = $record->invoke($this->command, ['track' => 'C', 'artist' => 'Z', 'timestamp' => '2026-01-01T00:03:30+00:00']);

        expect($first)->toBeNull();
        expect($second)->toBe('skip');      // A lasted 10s before B arrived
        expect($third)->toBe('complete');   // B lasted 200s before C arrived

        $ledgerProp = $reflection->getProperty('eventLedger');
        $ledgerProp->setAccessible(true);
        $ledger = $ledgerProp->getValue($this->command);

        expect($ledger)->toHaveCount(2);
        expect($ledger[0]['track'])->toBe('A');
        expect($ledger[0]['signal'])->toBe('skip');
        expect($ledger[0]['dwell_seconds'])->toBe(10);
        expect($ledger[1]['track'])->toBe('B');
        expect($ledger[1]['signal'])->toBe('complete');
    });

    it('uses the static mood preset by default (no adapt)', function (): void {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod('activeTargets');
        $method->setAccessible(true);

        expect($method->invoke($this->command, 'flow'))
            ->toBe(config('autopilot.mood_presets.flow'));
    });

    it('resolves adapted targets via AdaptAgent and emits an adapt decision event', function (): void {
        AdaptAgent::fake([
            '{"should_adjust":true,"reasoning":"too many skips","adjusted_phases":[{"name":"Recover","energy":0.4,"valence":0.7,"tempo":100}]}',
        ]);

        $reflection = new ReflectionClass($this->command);
        autopilotSetPrivate($this->command, 'jsonMode', true);
        autopilotSetPrivate($this->command, 'eventLedger', [
            ['track' => 'A', 'artist' => 'X', 'signal' => 'skip', 'dwell_seconds' => 5],
            ['track' => 'B', 'artist' => 'Y', 'signal' => 'skip', 'dwell_seconds' => 8],
        ]);

        $buffer = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = $reflection->getMethod('adaptTargets');
        $method->setAccessible(true);
        $method->invoke($this->command, 'flow');

        $targetsProp = $reflection->getProperty('adaptedTargets');
        $targetsProp->setAccessible(true);
        $targets = $targetsProp->getValue($this->command);

        expect($targets)->toBe([
            'target_energy' => 0.4,
            'target_valence' => 0.7,
            'target_tempo' => 100,
        ]);

        $event = json_decode(trim($buffer->fetch()), true);
        expect($event['type'])->toBe('adapt');
        expect($event['reasoning'])->toBe('too many skips');
        expect($event['targets']['target_energy'])->toBe(0.4);

        // Refill now resolves the adapted targets instead of the static preset
        $active = $reflection->getMethod('activeTargets');
        $active->setAccessible(true);
        expect($active->invoke($this->command, 'flow'))->toBe($targets);
    });

    it('holds current targets when AdaptAgent says no adjustment is needed', function (): void {
        AdaptAgent::fake([
            '{"should_adjust":false,"reasoning":"listening looks healthy"}',
        ]);

        $reflection = new ReflectionClass($this->command);
        autopilotSetPrivate($this->command, 'jsonMode', true);

        $buffer = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = $reflection->getMethod('adaptTargets');
        $method->setAccessible(true);
        $method->invoke($this->command, 'flow');

        $targetsProp = $reflection->getProperty('adaptedTargets');
        $targetsProp->setAccessible(true);
        expect($targetsProp->getValue($this->command))->toBeNull();

        $event = json_decode(trim($buffer->fetch()), true);
        expect($event['type'])->toBe('hold');
        expect($event['stage'])->toBe('adapt');
    });

    it('degrades gracefully when AdaptAgent returns unparseable output', function (): void {
        AdaptAgent::fake(['definitely not json']);

        $reflection = new ReflectionClass($this->command);
        autopilotSetPrivate($this->command, 'jsonMode', true);

        $buffer = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = $reflection->getMethod('adaptTargets');
        $method->setAccessible(true);
        $method->invoke($this->command, 'flow');

        $targetsProp = $reflection->getProperty('adaptedTargets');
        $targetsProp->setAccessible(true);
        expect($targetsProp->getValue($this->command))->toBeNull();

        $event = json_decode(trim($buffer->fetch()), true);
        expect($event['type'])->toBe('error');
        expect($event['stage'])->toBe('adapt');
    });

    it('emits decision events as one JSON object per line', function (): void {
        $reflection = new ReflectionClass($this->command);
        autopilotSetPrivate($this->command, 'jsonMode', true);

        $buffer = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        $method = $reflection->getMethod('emitDecision');
        $method->setAccessible(true);
        $method->invoke($this->command, 'observe', ['track' => 'A', 'artist' => 'X', 'previous_signal' => 'skip']);
        $method->invoke($this->command, 'hold', ['queue_depth' => 5, 'threshold' => 3]);

        $lines = array_values(array_filter(explode("\n", trim($buffer->fetch()))));

        expect($lines)->toHaveCount(2);

        $observe = json_decode($lines[0], true);
        expect($observe['type'])->toBe('observe');
        expect($observe['previous_signal'])->toBe('skip');
        expect($observe)->toHaveKey('timestamp');

        $hold = json_decode($lines[1], true);
        expect($hold['type'])->toBe('hold');
        expect($hold['queue_depth'])->toBe(5);
    });

    it('parses launchctl pid output robustly', function (): void {
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
