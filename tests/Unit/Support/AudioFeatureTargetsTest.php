<?php

declare(strict_types=1);

use App\Support\AudioFeatureTargets;

it('maps energy, valence and tempo to target_* audio features', function (): void {
    $features = AudioFeatureTargets::fromPhase([
        'name' => 'Wind down',
        'energy' => 0.4,
        'valence' => 0.7,
        'tempo' => 100,
    ]);

    expect($features)->toBe([
        'target_energy' => 0.4,
        'target_valence' => 0.7,
        'target_tempo' => 100,
    ]);
});

it('maps partial phases without inventing missing features', function (): void {
    $features = AudioFeatureTargets::fromPhase(['energy' => 0.9]);

    expect($features)->toBe(['target_energy' => 0.9]);
});

it('ignores non-numeric values', function (): void {
    $features = AudioFeatureTargets::fromPhase([
        'energy' => 'high',
        'valence' => null,
        'tempo' => 128,
    ]);

    expect($features)->toBe(['target_tempo' => 128]);
});

it('returns an empty array for an empty phase', function (): void {
    expect(AudioFeatureTargets::fromPhase([]))->toBe([]);
});
