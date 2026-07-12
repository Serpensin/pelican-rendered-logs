<?php

return [
    'max_log_bytes' => (int) env('SERPENSIN_RENDERED_LOGS_MAX_LOG_BYTES', 500_000),
    'link_ttl_minutes' => (int) env('SERPENSIN_RENDERED_LOGS_LINK_TTL_MINUTES', 60),
    'storage_prefix' => 'serpensin-rendered-logs',
];
