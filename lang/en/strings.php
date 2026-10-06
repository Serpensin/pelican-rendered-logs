<?php

return [
    'settings' => [
        'section' => 'Rendered Logs',
        'description' => 'Logs stay on this Panel. A recipient receives a random, single-use link that starts an HTML download immediately.',
        'link_ttl_minutes' => 'Link expiry in minutes',
        'link_ttl_minutes_help' => 'Unused links are invalid after this period. Used links are invalid immediately.',
        'max_log_bytes' => 'Maximum log size in bytes',
        'max_log_bytes_help' => 'When a log is larger, only its final bytes are rendered.',
        'saved_title' => 'Rendered Logs settings saved',
        'saved_body' => 'New links use the saved expiry and size limits.',
    ],
    'action' => [
        'label' => 'Download Logs',
        'tooltip' => 'Render the current terminal log internally and create a one-time download link.',
    ],
    'create' => [
        'no_logs_title' => 'No logs available',
        'success_title' => 'One-time download link created',
        'failed_title' => 'Could not render logs',
        'document_title' => ':server terminal log :timestamp',
        'truncated' => '[Log truncated to the last :limit bytes]',
    ],
    'command' => [
        'description' => 'Remove expired or orphaned rendered log downloads.',
        'purged' => 'Removed :count expired rendered-log downloads.',
    ],
];
