<?php
declare(strict_types=1);

// Chạy: php tests/run.php [unit|data|acceptance|all]   (mặc định: all)
// Với XAMPP: C:\xampp\php\php.exe tests\run.php
// Cần bật MySQL trong XAMPP cho phần "acceptance".

$which = $argv[1] ?? 'all';
if (!in_array($which, ['unit', 'data', 'acceptance', 'all'], true)) { fwrite(STDERR, "Dùng: php tests/run.php [unit|data|acceptance|all]\n"); exit(2); }

require __DIR__ . '/lib.php';

// app.php khởi động phiên và gửi header; đệm đầu ra để CLI không cảnh báo.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require dirname(__DIR__) . '/app.php';
ob_end_clean();

if ($which === 'unit' || $which === 'all') require __DIR__ . '/unit.php';
if ($which === 'data' || $which === 'all') require __DIR__ . '/data.php';
if ($which === 'acceptance' || $which === 'all') require __DIR__ . '/acceptance.php';

exit(test_summary());
