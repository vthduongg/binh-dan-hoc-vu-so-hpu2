<?php
declare(strict_types=1);

return [
    'app_name' => 'Bình dân học vụ số HPU2',
    'base_url' => '', // Ví dụ: /binh-dan-hoc-vu-so khi đặt trong htdocs/binh-dan-hoc-vu-so
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'hpu2_digital_learning',
        // XAMPP cục bộ có thể dùng root; khi đưa lên host BẮT BUỘC dùng user riêng.
        'user' => getenv('HPU2_DB_USER') ?: 'root',
        'pass' => getenv('HPU2_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'session_name' => 'HPU2_DIGITAL_SESSION',
    'session_idle_minutes' => 30,
    'session_absolute_hours' => 8,
    // Để trống trên XAMPP. Khi chạy nhiều máy chủ có thể đặt HPU2_REDIS_DSN=redis://127.0.0.1:6379/2.
    'redis_session_dsn' => getenv('HPU2_REDIS_DSN') ?: '',
    'trusted_proxy_https' => false,
    'content_review_date' => '24/09/2026',
];
