<?php

return [
    'cloud_staging' => (bool) env('ESTACION_CLOUD_STAGING', false),
    'setup_code' => env('ESTACION_SETUP_CODE'),
    'open_24_hours' => env('RESTAURANT_OPEN_24_HOURS', true),
    // Paused until the restaurant resumes daily production planning.
    'daily_availability' => false,
];
