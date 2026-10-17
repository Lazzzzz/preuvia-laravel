<?php

return [

    /*
    | Base URL of the API, version prefix included.
    */
    'base_url' => env('PREUVIA_API_BASE_URL', 'https://preuvia.eliteinspection.fr/api/v1'),

    /*
    | Plain-text API key, sent as a Bearer token. Create it in Preuvia under API > Applications.
    */
    'api_key' => env('PREUVIA_API_KEY'),

    /*
    | Optional token for GET /health: without it the endpoint only answers a minimal status.
    */
    'health_token' => env('PREUVIA_HEALTH_TOKEN'),

    'timeout' => (int) env('PREUVIA_API_TIMEOUT', 30),

    'connect_timeout' => (int) env('PREUVIA_API_CONNECT_TIMEOUT', 10),

    /*
    | Retries on connection errors, 429, 502, 503 and 504. Only safe requests are retried:
    | reads, and document uploads that carry a client_capture_id (Preuvia deduplicates them).
    | A Retry-After longer than max_retry_after is not waited for: the exception is thrown.
    */
    'retry' => [
        'times' => (int) env('PREUVIA_API_RETRY_TIMES', 2),
        'sleep_ms' => (int) env('PREUVIA_API_RETRY_SLEEP_MS', 500),
        'max_retry_after' => (int) env('PREUVIA_API_MAX_RETRY_AFTER', 10),
    ],

    'documents' => [
        // Sends client_sha256 with every upload so Preuvia refuses a file altered in transit.
        'send_hash' => (bool) env('PREUVIA_DOCUMENTS_SEND_HASH', true),
    ],

    'webhooks' => [
        // Callback secret shown once when the API application is created.
        'secret' => env('PREUVIA_WEBHOOK_SECRET'),

        // Callbacks signed longer ago than this (seconds) are rejected as replays.
        'tolerance' => (int) env('PREUVIA_WEBHOOK_TOLERANCE', 300),

        // Path of the built-in receiver, e.g. "webhooks/preuvia". null: no route registered.
        'path' => env('PREUVIA_WEBHOOK_PATH'),

        // Extra middleware for the built-in receiver. Signature verification is always applied.
        'middleware' => [],
    ],

];
