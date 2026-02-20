<?php

return [
    'sample_threshold' => env('SENTINEL_SAMPLE_THRESHOLD', 20),
    'drift_severity'   => env('SENTINEL_DRIFT_SEVERITY', 'BREAKING'),
    'reharden'         => env('SENTINEL_REHARDEN', true),
    
    'store' => [
        'driver' => env('SENTINEL_STORE_DRIVER', 'file'),  // file | pdo | redis
        'path'   => storage_path('sentinel'),
    ],
    
    'additive_threshold' => env('SENTINEL_ADDITIVE_THRESHOLD', 0.95),
    'max_stored_samples' => env('SENTINEL_MAX_STORED_SAMPLES', 50),
];
