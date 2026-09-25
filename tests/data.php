<?php
declare(strict_types=1);

// Test toàn vẹn dữ liệu học liệu: seed.json, paths.json, schema.sql.

suite('Dữ liệu học liệu');

$root = dirname(__DIR__);
$seed = json_decode((string)file_get_contents($root . '/database/seed.json'), true);
$paths = json_decode((string)file_get_contents($root . '/database/paths.json'), true);
$courseIds = is_array($seed) ? array_column($seed, 'id') : [];
$domainCodes = is_array($paths) ? array_column($paths['domains'] ?? [], 'code') : [];

t('seed.json và paths.json là JSON hợp lệ', function () use ($seed, $paths) {
    assert_true(is_array($seed) && count($seed) > 0, 'seed.json không hợp lệ hoặc rỗng');
    assert_true(is_array($paths) && isset($paths['domains'], $paths['paths'], $paths['course_domains'], $paths['diagnostic']), 'paths.json thiếu khóa bắt buộc');
});

t('Có đúng 12 khóa học, mã khóa học duy nhất và an toàn cho URL', function () use ($seed, $courseIds) {
    assert_same(12, count($seed));
    assert_same(count($courseIds), count(array_unique($courseIds)), 'mã khóa học bị trùng');
    foreach ($courseIds as $id) assert_true(preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', (string)$id) === 1, "mã không hợp lệ: {$id}");
});

t('Mỗi khóa học có tiêu đề, kết quả học tập, nguồn chính thức và ít nhất một bài', function () use ($seed) {
    foreach ($seed as $c) {
        assert_true(trim((string)($c['title'] ?? '')) !== '', "khóa {$c['id']} thiếu tiêu đề");
        assert_true(count($c['outcomes'] ?? []) >= 1, "khóa {$c['id']} thiếu kết quả học tập");
        assert_true(count($c['modules'] ?? []) >= 1, "khóa {$c['id']} không có bài học");
        assert_true(count($c['sources'] ?? []) >= 1, "khóa {$c['id']} thiếu nguồn chính thức");
    }
});

t('Mỗi bài học có mục tiêu, nội dung, tình huống, thực hành và thời lượng dương', function () use ($seed) {
    foreach ($seed as $c) foreach ($c['modules'] as $i => $m) {
        $where = "khóa {$c['id']} bài " . ($i + 1);
        foreach (['title', 'objective', 'scenario', 'practice'] as $k) assert_true(trim((string)($m[$k] ?? '')) !== '', "{$where} thiếu {$k}");
        assert_true(count($m['points'] ?? []) >= 1, "{$where} thiếu nội dung chính");
        assert_true((int)($m['duration'] ?? 0) > 0, "{$where} thời lượng không hợp lệ");
    }
});

t('Mọi nguồn tham khảo có tiêu đề và địa chỉ https', function () use ($seed) {
    foreach ($seed as $c) foreach ($c['sources'] as $s) {
        assert_true(trim((string)($s['title'] ?? '')) !== '', "khóa {$c['id']} có nguồn thiếu tiêu đề");
        assert_true(str_starts_with((string)($s['url'] ?? ''), 'https://'), "khóa {$c['id']} có nguồn không dùng https: " . ($s['url'] ?? ''));
    }
});

t('Sáu miền năng lực M1–M6 đầy đủ và duy nhất', function () use ($domainCodes) {
    assert_same(['M1', 'M2', 'M3', 'M4', 'M5', 'M6'], $domainCodes);
});

t('Sáu lộ trình có mã duy nhất và chỉ tham chiếu khóa học tồn tại', function () use ($paths, $courseIds) {
    assert_same(6, count($paths['paths']));
    $slugs = array_column($paths['paths'], 'slug');
    assert_same(count($slugs), count(array_unique($slugs)), 'slug lộ trình bị trùng');
    foreach ($paths['paths'] as $p) {
        foreach (['slug', 'title', 'audience', 'level', 'summary', 'basis'] as $k) assert_true(trim((string)($p[$k] ?? '')) !== '', "lộ trình {$p['slug']} thiếu {$k}");
        assert_true(count($p['courses']) >= 1, "lộ trình {$p['slug']} không có khóa học");
        assert_same(count($p['courses']), count(array_unique($p['courses'])), "lộ trình {$p['slug']} lặp khóa học");
        foreach ($p['courses'] as $slug) assert_true(in_array($slug, $courseIds, true), "lộ trình {$p['slug']} tham chiếu khóa không tồn tại: {$slug}");
    }
});

t('Mỗi khóa học được gắn với ít nhất một miền năng lực hợp lệ', function () use ($paths, $courseIds, $domainCodes) {
    foreach ($courseIds as $id) assert_true(!empty($paths['course_domains'][$id]), "khóa {$id} chưa gắn miền năng lực");
    foreach ($paths['course_domains'] as $slug => $codes) {
        assert_true(in_array($slug, $courseIds, true), "course_domains có khóa không tồn tại: {$slug}");
        foreach ($codes as $code) assert_true(in_array($code, $domainCodes, true), "khóa {$slug} gắn miền không hợp lệ: {$code}");
    }
});

t('Mỗi khóa học thuộc ít nhất một lộ trình (không có khóa "mồ côi")', function () use ($paths, $courseIds) {
    $used = array_unique(array_merge(...array_column($paths['paths'], 'courses')));
    foreach ($courseIds as $id) assert_true(in_array($id, $used, true), "khóa {$id} không thuộc lộ trình nào");
});

t('Đánh giá đầu vào phủ đủ 6 miền, mỗi câu có đúng một đáp án điểm cao nhất', function () use ($paths, $domainCodes) {
    $covered = array_values(array_unique(array_column($paths['diagnostic'], 'domain')));
    sort($covered);
    assert_same($domainCodes, $covered);
    foreach ($paths['diagnostic'] as $i => $q) {
        assert_true(count($q['options']) >= 2, "câu " . ($i + 1) . " thiếu lựa chọn");
        $scores = array_map(fn($o) => (int)$o[1], $q['options']);
        assert_same(1, count(array_keys($scores, max($scores), true)), "câu " . ($i + 1) . " có nhiều đáp án cùng điểm cao nhất");
    }
});

t('schema.sql khai báo đủ các bảng mà mã nguồn sử dụng', function () use ($root) {
    $sql = (string)file_get_contents($root . '/database/schema.sql');
    $needed = ['users', 'courses', 'lessons', 'quizzes', 'quiz_options', 'progress', 'quiz_attempts', 'settings', 'login_attempts', 'audit_logs', 'course_sources', 'video_progress', 'learning_paths', 'learning_path_courses', 'competency_domains', 'course_competencies', 'diagnostic_attempts'];
    foreach ($needed as $table) assert_contains("CREATE TABLE IF NOT EXISTS {$table} (", $sql);
});
