<?php
declare(strict_types=1);

// Test đơn vị cho các hàm thuần trong app.php (không cần cơ sở dữ liệu).

suite('Đơn vị: app.php');

t('e() escape ký tự HTML và chấp nhận null', function () {
    assert_same('&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;', e('<script>"x" & \'y\''));
    assert_same('', e(null));
});

t('url() ghép base_url và bỏ dấu / thừa', function () {
    $old = $GLOBALS['config']['base_url'];
    $GLOBALS['config']['base_url'] = '/binh-dan-hoc-vu-so/';
    assert_same('/binh-dan-hoc-vu-so/index.php?page=paths', url('/index.php?page=paths'));
    assert_same('/binh-dan-hoc-vu-so/', url());
    $GLOBALS['config']['base_url'] = $old;
});

t('url() khi base_url rỗng dùng thư mục của SCRIPT_NAME', function () {
    $old = $GLOBALS['config']['base_url'];
    $oldScript = $_SERVER['SCRIPT_NAME'] ?? null;
    $GLOBALS['config']['base_url'] = '';
    $_SERVER['SCRIPT_NAME'] = '/abc/index.php';
    assert_same('/abc/admin.php', url('admin.php'));
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    assert_same('/admin.php', url('admin.php'));
    $GLOBALS['config']['base_url'] = $old;
    if ($oldScript === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $oldScript;
});

t('csv_safe() vô hiệu hóa công thức Excel', function () {
    foreach (['=1+1', '+SUM(A1)', '-2+3', '@cmd', "\tx", "\rx"] as $bad) {
        assert_same("'" . $bad, csv_safe($bad), 'đầu vào ' . json_encode($bad));
    }
    assert_same('Nguyễn Văn A', csv_safe('Nguyễn Văn A'));
    assert_same('123', csv_safe(123));
    assert_same('', csv_safe(null));
});

t('video_embed_url() nhận các dạng liên kết YouTube hợp lệ', function () {
    $expected = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0';
    assert_same($expected, video_embed_url('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
    assert_same($expected, video_embed_url('https://youtu.be/dQw4w9WgXcQ'));
    assert_same($expected, video_embed_url('  https://youtu.be/dQw4w9WgXcQ  '));
});

t('video_embed_url() từ chối liên kết không phải YouTube hoặc rỗng', function () {
    assert_same(null, video_embed_url(null));
    assert_same(null, video_embed_url(''));
    assert_same(null, video_embed_url('https://example.com/video.mp4'));
    assert_same(null, video_embed_url('javascript:alert(1)'));
});

t('video_embed_url() luôn trả về địa chỉ youtube-nocookie an toàn, không mang ký tự nguy hiểm', function () {
    $inputs = [
        'https://www.youtube-nocookie.com/embed/abcDEF123',
        'https://www.youtube-nocookie.com/embed/abcDEF123"><script>alert(1)</script>',
        'https://www.youtube-nocookie.com/embed/abcDEF123" onload="x()',
        "https://www.youtube-nocookie.com/embed/abcDEF123 onmouseover=x",
        'https://evil.example/?u=youtube.com/watch?v=abcDEF123',
    ];
    foreach ($inputs as $in) {
        $out = video_embed_url($in);
        assert_true($out !== null && str_starts_with($out, 'https://www.youtube-nocookie.com/embed/'), 'đầu vào ' . $in);
        assert_true(preg_match('~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]+(\?[A-Za-z0-9_=&-]*)?$~', $out) === 1, 'kết quả không an toàn: ' . $out);
    }
});

t('slugify() bỏ dấu tiếng Việt và ký tự lạ', function () {
    assert_same('nhap-mon-cong-dan-so', slugify('Nhập môn công dân số'));
    assert_same('duong-dai-hoc', slugify('ĐƯỜNG Đại Học!!'));
    assert_same('an-toan-tai-khoan-2fa', slugify('  An toàn tài khoản (2FA) '));
});

t('slugify() có giá trị dự phòng khi không còn ký tự hợp lệ', function () {
    assert_true(str_starts_with(slugify('***'), 'noi-dung-'));
});

t('csrf_token() ổn định trong phiên, dài 64 ký tự hex; csrf_field() nhúng đúng token', function () {
    unset($_SESSION['csrf']);
    $a = csrf_token();
    assert_true(preg_match('/^[a-f0-9]{64}$/', $a) === 1);
    assert_same($a, csrf_token());
    assert_contains('value="' . $a . '"', csrf_field());
    assert_contains('name="csrf"', csrf_field());
});

t('flash() thêm thông báo và flashes() đọc xong thì xóa', function () {
    unset($_SESSION['flash']);
    flash('success', 'A');
    flash('danger', 'B');
    assert_same([['type' => 'success', 'message' => 'A'], ['type' => 'danger', 'message' => 'B']], flashes());
    assert_same([], flashes());
});

t('icon() trả về SVG và dùng biểu tượng mặc định khi tên lạ', function () {
    assert_contains('<svg', icon('check'));
    assert_same(icon('book'), icon('khong-ton-tai'));
});

t('client_ip() cắt tối đa 45 ký tự và có giá trị mặc định', function () {
    $old = $_SERVER['REMOTE_ADDR'] ?? null;
    unset($_SERVER['REMOTE_ADDR']);
    assert_same('unknown', client_ip());
    $_SERVER['REMOTE_ADDR'] = str_repeat('a', 80);
    assert_same(45, strlen(client_ip()));
    if ($old === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $old;
});

t('is_admin() là false khi chưa đăng nhập', function () {
    unset($_SESSION['user_id']);
    assert_same(false, is_admin());
});

t('Thư viện PHP bắt buộc đã được bật', function () {
    foreach (['pdo_mysql', 'mbstring', 'json'] as $ext) assert_true(extension_loaded($ext), "thiếu extension {$ext}");
    assert_true(version_compare(PHP_VERSION, '8.2.0', '>='), 'cần PHP 8.2 trở lên');
});

t('Mọi tệp PHP không có lỗi cú pháp (php -l)', function () {
    $root = dirname(__DIR__);
    $files = array_merge(glob($root . '/*.php'), glob($root . '/tests/*.php'));
    assert_true(count($files) >= 5, 'không tìm thấy tệp PHP');
    foreach ($files as $file) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        assert_same(0, $code, basename($file) . ': ' . implode(' ', $out));
    }
});
