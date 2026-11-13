<?php

return [
    'auth_challenge_ttl_minutes' => (int) env('BALE_AUTH_CHALLENGE_TTL_MINUTES', 10),
    'max_upload_kilobytes' => (int) env('BALE_MAX_UPLOAD_KB', 51200),
    'webhook_max_attempts' => (int) env('BALE_WEBHOOK_MAX_ATTEMPTS', 5),
    'webhook_timeout_seconds' => (int) env('BALE_WEBHOOK_TIMEOUT_SECONDS', 8),
    'events' => [
        'message',
        'message_deleted',
        'chat_cleared',
        'chat_deleted',
        'username_changed',
        'message_sent',
        'message_edited',
        'about_changed',
        'user_blocked',
        'user_unblocked',
        'group_message_pinned',
        'group_pin_removed',
    ],
];
