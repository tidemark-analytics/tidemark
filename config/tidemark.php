<?php

return [

    // GitHub repository checked for new releases when an administrator opens Account settings → Updates.
    // Look for new releases daily (a cron task) and every few hours while an administrator is using the app so the dashboard can
    // show an "update available" dot. Only the latest version number is requested; nothing about your sites or visitors is sent.
    'update_check' => (bool) env('TIDEMARK_UPDATE_CHECK', true),

    'update_repo' => env('TIDEMARK_UPDATE_REPO', 'tidemark-analytics/tidemark'),

    // Installed release, stamped into the VERSION file by scripts/build-release.sh.
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev',

    /*
    | Deployment mode.
    |   self-hosted : one installation, one operator (shared hosting friendly)
    |   cloud       : multi-tenant cell; open registration, plan limits & quotas
    */
    'mode' => env('TIDEMARK_MODE', 'self-hosted'),

    'registration' => (bool) env('TIDEMARK_REGISTRATION', env('TIDEMARK_MODE', 'self-hosted') === 'cloud'),

    /*
    | Database connection that holds the high-volume tables (visits, events,
    | event_props). Defaults to the application connection. Point it
    | at a different server to split analytics from metadata, or at a read
    | replica/shard in a cloud cell.
    */
    'analytics_connection' => env('TIDEMARK_ANALYTICS_DB', null),

    /*
    | Event ingestion strategy.
    |   sync  : write straight to the database inside the request (default)
    |   queue : push to the queue; a worker (or the scheduler on shared hosting)
    |           inserts events in batches
    */
    'ingest' => env('TIDEMARK_INGEST', 'sync'),
    'ingest_queue' => env('TIDEMARK_INGEST_QUEUE', 'ingest'),

    // Headers (in order) checked for the client IP when behind a proxy/CDN.
    'ip_headers' => ['CF-Connecting-IP', 'X-Client-IP', 'X-Forwarded-For', 'X-Real-IP', 'X-Appengine-User-Ip', 'True-Client-IP'],

    // Optional MaxMind GeoLite2/GeoIP2 City database (needs `composer require geoip2/geoip2`).
    'geoip_database' => env('TIDEMARK_GEOIP_DATABASE', storage_path('app/geo/GeoLite2-City.mmdb')),

    'ignore_ips' => array_filter(array_map('trim', explode(',', env('TIDEMARK_IGNORE_IP', '')))),
    'allowed_hostnames' => array_filter(array_map('trim', explode(',', env('TIDEMARK_HOSTNAMES', '')))),
    'disable_bot_filter' => (bool) env('TIDEMARK_DISABLE_BOT_FILTER', false),

    // Delete raw events older than N days (0 = keep forever). Run by the scheduler.
    'retention_days' => (int) env('TIDEMARK_RETENTION_DAYS', 0),

    // Visitor ids are derived from a salted hash; the salt changes every day, so visitors can't be followed across days.
    'salt' => env('TIDEMARK_SALT'),

    // Cloud-mode plan limits (0 = unlimited).
    'limits' => [
        'sites_per_user' => (int) env('TIDEMARK_MAX_SITES', 0),
        'events_per_month' => (int) env('TIDEMARK_MAX_EVENTS', 0),
    ],

];
