<?php

namespace App\Support;

/**
 * Maps AdaptAgent phase output (energy/valence/tempo) to Spotify
 * target_* audio feature parameters. Shared by SessionAdjustTool
 * and AutopilotCommand so the mapping lives in exactly one place.
 */
class AudioFeatureTargets
{
    /**
     * @param  array<string, mixed>  $phase
     * @return array<string, int|float>
     */
    public static function fromPhase(array $phase): array
    {
        $features = [];

        if (isset($phase['energy']) && is_numeric($phase['energy'])) {
            $features['target_energy'] = $phase['energy'];
        }

        if (isset($phase['valence']) && is_numeric($phase['valence'])) {
            $features['target_valence'] = $phase['valence'];
        }

        if (isset($phase['tempo']) && is_numeric($phase['tempo'])) {
            $features['target_tempo'] = $phase['tempo'];
        }

        return $features;
    }
}
