<?php

/*
|--------------------------------------------------------------------------
| Upload & image-fetch limits
|--------------------------------------------------------------------------
|
| Central home for every size / count / time / rate limit the app enforces
| on album creation. Each value is env-backed (see .env.example) so it can
| be tuned without a code change; the empty-or-unset form falls back to the
| default shown here.
|
| The Docker entrypoint DERIVES the transport-layer caps (nginx
| client_max_body_size and PHP upload_max_filesize / post_max_size) from
| MAX_IMAGES and max(MAX_IMAGE_BYTES, ADMIN_MAX_IMAGE_BYTES), so they always
| stay at/above the app caps and editing the caps resizes the whole chain.
|
*/

return [
    // How many images an album may contain.
    'min_images' => (int) (env('UPLOAD_MIN_IMAGES') ?: 2),
    'max_images' => (int) (env('UPLOAD_MAX_IMAGES') ?: 8),

    // Per-image size cap in bytes, applied identically to file uploads and
    // to server-side URL fetches. Drives the derived nginx/PHP transport caps.
    'max_image_bytes' => (int) (env('UPLOAD_MAX_IMAGE_BYTES') ?: 10 * 1024 * 1024),

    // Per-image size cap for admin users (bytes). Admins are exempt from the
    // regular cap: their effective cap is max(max_image_bytes, this value),
    // so a value below the regular cap is a no-op. The Docker entrypoint
    // derives the transport caps from max(max_image_bytes, this value) so
    // admin file uploads make it through PHP/nginx as well.
    'admin_max_image_bytes' => (int) (env('UPLOAD_ADMIN_MAX_IMAGE_BYTES') ?: 50 * 1024 * 1024),

    // URL source: max length of the pasted URL string.
    'max_url_length' => (int) (env('UPLOAD_MAX_URL_LENGTH') ?: 2048),

    // Server-side URL fetching.
    'max_redirects' => (int) (env('UPLOAD_MAX_REDIRECTS') ?: 3),  // max hops followed
    'hop_timeout' => (int) (env('UPLOAD_HOP_TIMEOUT') ?: 30),     // seconds, per connection
    'store_deadline' => (int) (env('UPLOAD_STORE_DEADLINE') ?: 90), // seconds, all fetches in one request

    // Rate limit for POST /api/albums (per user): "times,decayMinutes".
    'store_rate' => (string) (env('UPLOAD_STORE_RATE') ?: '10,1'),
];
