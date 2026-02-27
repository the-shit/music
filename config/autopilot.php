<?php

return [
    'mood_presets' => [
        'chill' => [
            'target_energy' => 0.3,
            'target_valence' => 0.5,
            'target_tempo' => 90,
            'target_danceability' => 0.5,
            'target_acousticness' => 0.6,
        ],
        'flow' => [
            'target_energy' => 0.6,
            'target_valence' => 0.6,
            'target_tempo' => 120,
            'target_danceability' => 0.6,
            'target_instrumentalness' => 0.2,
        ],
        'hype' => [
            'target_energy' => 0.9,
            'target_valence' => 0.8,
            'target_tempo' => 140,
            'target_danceability' => 0.85,
        ],
        'focus' => [
            'target_energy' => 0.45,
            'target_valence' => 0.4,
            'target_tempo' => 105,
            'target_instrumentalness' => 0.7,
            'target_speechiness' => 0.08,
        ],
        'party' => [
            'target_energy' => 0.92,
            'target_valence' => 0.85,
            'target_tempo' => 128,
            'target_danceability' => 0.9,
        ],
        'upbeat' => [
            'target_energy' => 0.78,
            'target_valence' => 0.82,
            'target_tempo' => 124,
            'target_danceability' => 0.78,
        ],
        'melancholy' => [
            'target_energy' => 0.4,
            'target_valence' => 0.2,
            'target_tempo' => 95,
            'target_acousticness' => 0.45,
        ],
        'ambient' => [
            'target_energy' => 0.2,
            'target_valence' => 0.4,
            'target_tempo' => 78,
            'target_acousticness' => 0.7,
            'target_instrumentalness' => 0.85,
        ],
        'workout' => [
            'target_energy' => 0.95,
            'target_valence' => 0.7,
            'target_tempo' => 150,
            'target_danceability' => 0.82,
        ],
        'sleep' => [
            'target_energy' => 0.12,
            'target_valence' => 0.28,
            'target_tempo' => 65,
            'target_acousticness' => 0.82,
            'target_instrumentalness' => 0.9,
        ],
    ],
];
