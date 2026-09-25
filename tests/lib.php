<?php
declare(strict_types=1);

// Bộ khung test tối giản, không cần Composer/PHPUnit: chỉ cần PHP CLI của XAMPP.

final class AssertionFailure extends Exception {}

$GLOBALS['__tests'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function suite(string $name): void { echo "\n== {$name} ==\n"; }

function t(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__tests']['pass']++;
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $GLOBALS['__tests']['fail']++;
        $GLOBALS['__tests']['failures'][] = $name . ' -> ' . $e->getMessage();
        echo "  FAIL  {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
    }
}

function fail(string $message): never { throw new AssertionFailure($message); }

function assert_true($cond, string $message = 'Điều kiện phải đúng'): void {
    if ($cond !== true) fail($message);
}

function assert_same($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        fail(($message !== '' ? $message . ': ' : '') . 'mong đợi ' . var_export($expected, true) . ', nhận được ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void {
    if (!str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . 'không tìm thấy ' . var_export($needle, true) . ' trong ' . var_export(mb_substr($haystack, 0, 300), true));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void {
    if (str_contains($haystack, $needle)) {
        fail(($message !== '' ? $message . ': ' : '') . 'không được chứa ' . var_export($needle, true));
    }
}

function test_summary(): int {
    $r = $GLOBALS['__tests'];
    echo "\n----------------------------------------\n";
    echo "Đạt: {$r['pass']}   Lỗi: {$r['fail']}\n";
    foreach ($r['failures'] as $f) echo "  - {$f}\n";
    return $r['fail'] === 0 ? 0 : 1;
}
