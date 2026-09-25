<?php
declare(strict_types=1);

// Test nghiệm thu đầu-cuối: chạy web server tích hợp của PHP với một CSDL RIÊNG
// (hpu2_test_acceptance) rồi thao tác qua HTTP như người dùng thật.
// Không đụng vào CSDL thật. Không kiểm tra .htaccess (chỉ Apache đọc).

suite('Nghiệm thu: cài đặt, đăng nhập, học và làm quiz');

const ACC_DB = 'hpu2_test_acceptance';
const ACC_PORT = 8089;
const ACC_ADMIN_EMAIL = 'admin.test@hpu2.edu.vn';
const ACC_ADMIN_PASS = 'MatKhau-Quan-Tri-12345';
const ACC_LEARNER_EMAIL = 'hocvien.test@hpu2.edu.vn';
const ACC_LEARNER_PASS = 'MatKhau-Hoc-Vien-12345';

final class Http {
    public string $jar;
    public function __construct(public string $base) { $this->jar = tempnam(sys_get_temp_dir(), 'hpu2jar'); }
    public function __destruct() { @unlink($this->jar); }

    /** @return array{status:int,headers:array<string,string>,location:string,body:string} */
    public function req(string $method, string $path, array $post = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 30,
        ]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $raw = curl_exec($ch);
        if ($raw === false) fail('Lỗi kết nối: ' . curl_error($ch));
        $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
        }
        return ['status' => $status, 'headers' => $headers, 'location' => $headers['location'] ?? '', 'body' => substr($raw, $size)];
    }
    public function get(string $path): array { return $this->req('GET', $path); }
    public function post(string $path, array $data): array { return $this->req('POST', $path, $data); }

    public function csrf(string $path): string {
        $r = $this->get($path);
        if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $r['body'], $m)) fail("Không tìm thấy token CSRF tại {$path} (HTTP {$r['status']})");
        return $m[1];
    }
    public function login(string $email, string $password): array {
        return $this->post('/index.php?page=login', ['csrf' => $this->csrf('/index.php?page=login'), 'email' => $email, 'password' => $password]);
    }
}

function acc_pdo(bool $withDb): PDO {
    $c = cfg('db');
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ($withDb ? ';dbname=' . ACC_DB : '') . ';charset=utf8mb4';
    return new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

// ---- Chuẩn bị môi trường -------------------------------------------------
$root = dirname(__DIR__);
$serverProc = null;
$ready = false;
$setupError = '';
try {
    if (!str_starts_with(ACC_DB, 'hpu2_test')) throw new RuntimeException('Tên CSDL test không an toàn.');
    $server = acc_pdo(false);
    $server->exec('DROP DATABASE IF EXISTS `' . ACC_DB . '`');
    $env = array_merge(getenv(), ['HPU2_DB_NAME' => ACC_DB]);
    $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . ACC_PORT, '-t', $root];
    $serverProc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/hpu2-server.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/hpu2-server.log', 'a']], $pipes, $root, $env);
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(200000);
        $s = @fsockopen('127.0.0.1', ACC_PORT, $en, $es, 0.2);
        if ($s) { fclose($s); $ready = true; }
    }
    if (!$ready) throw new RuntimeException('Không khởi động được web server thử nghiệm trên cổng ' . ACC_PORT);
} catch (Throwable $e) {
    $setupError = $e->getMessage();
}

t('Môi trường nghiệm thu sẵn sàng (MySQL + web server thử nghiệm)', function () use ($setupError) {
    assert_same('', $setupError, 'Hãy bật MySQL trong XAMPP');
});

if ($setupError === '') {
    $web = new Http('http://127.0.0.1:' . ACC_PORT);
    $ctx = [];

    // ---- Cài đặt -----------------------------------------------------------
    t('Truy cập trang chủ khi chưa cài đặt sẽ chuyển tới install.php', function () use ($web) {
        $r = $web->get('/index.php');
        assert_same(302, $r['status']);
        assert_contains('install.php', $r['location']);
    });

    t('install.php từ chối POST không có token CSRF (HTTP 419)', function () use ($web) {
        $r = $web->post('/install.php', ['email' => ACC_ADMIN_EMAIL, 'full_name' => 'Quản trị', 'password' => ACC_ADMIN_PASS]);
        assert_same(419, $r['status']);
    });

    t('install.php từ chối mật khẩu ngắn NHƯNG vẫn cho phép cài lại sau đó', function () use ($web) {
        $bad = $web->post('/install.php', ['csrf' => $web->csrf('/install.php'), 'email' => ACC_ADMIN_EMAIL, 'full_name' => 'Quản trị', 'password' => 'ngan']);
        assert_contains('ít nhất 12 ký tự', $bad['body']);
        $again = $web->get('/install.php');
        assert_same(200, $again['status'], 'sau lần nhập sai, trang cài đặt phải còn dùng được (không bị chuyển hướng)');
    });

    t('Cài đặt hoàn tất với dữ liệu mẫu', function () use ($web) {
        $r = $web->post('/install.php', ['csrf' => $web->csrf('/install.php'), 'email' => ACC_ADMIN_EMAIL, 'full_name' => 'Quản trị Test', 'password' => ACC_ADMIN_PASS, 'seed' => '1']);
        assert_same(200, $r['status']);
        assert_contains('Cài đặt hoàn tất', $r['body']);
    });

    t('Sau khi cài, install.php chuyển hướng tới trang đăng nhập (không cài lại được)', function () use ($web) {
        $r = $web->get('/install.php');
        assert_same(302, $r['status']);
        assert_contains('page=login', $r['location']);
    });

    t('Dữ liệu mẫu: 12 khóa, 6 lộ trình, 6 miền; mỗi bài có 2 quiz, mỗi quiz đúng 1 đáp án đúng', function () {
        $db = acc_pdo(true);
        assert_same(12, (int)$db->query('SELECT COUNT(*) FROM courses')->fetchColumn());
        assert_same(6, (int)$db->query('SELECT COUNT(*) FROM learning_paths')->fetchColumn());
        assert_same(6, (int)$db->query('SELECT COUNT(*) FROM competency_domains')->fetchColumn());
        $lessons = (int)$db->query('SELECT COUNT(*) FROM lessons')->fetchColumn();
        assert_true($lessons >= 12, 'quá ít bài học');
        assert_same($lessons * 2, (int)$db->query('SELECT COUNT(*) FROM quizzes')->fetchColumn());
        $bad = $db->query('SELECT q.id FROM quizzes q LEFT JOIN quiz_options o ON o.quiz_id=q.id GROUP BY q.id HAVING SUM(o.is_correct)<>1 OR COUNT(o.id)<>3')->fetchAll();
        assert_same([], $bad, 'có quiz không đúng 1 đáp án đúng/3 lựa chọn');
    });

    t('Mật khẩu quản trị được băm, không lưu dạng rõ', function () {
        $hash = (string)acc_pdo(true)->query("SELECT password_hash FROM users WHERE email='" . ACC_ADMIN_EMAIL . "'")->fetchColumn();
        assert_true($hash !== ACC_ADMIN_PASS && password_verify(ACC_ADMIN_PASS, $hash));
    });

    // ---- Trang công khai và tiêu đề bảo mật ------------------------------
    t('Trang chủ hiển thị 12 khóa học và có đủ tiêu đề bảo mật', function () use ($web) {
        $r = $web->get('/index.php');
        assert_same(200, $r['status']);
        assert_contains('Năng lực số để thích ứng', $r['body']);
        assert_same("default-src 'self'", explode(';', $r['headers']['content-security-policy'] ?? '')[0]);
        assert_contains("script-src 'self'", $r['headers']['content-security-policy'] ?? '');
        assert_same('nosniff', $r['headers']['x-content-type-options'] ?? '');
        assert_same('SAMEORIGIN', $r['headers']['x-frame-options'] ?? '');
    });

    t('Trang lộ trình và từng lộ trình đều mở được', function () use ($web) {
        $list = $web->get('/index.php?page=paths');
        assert_same(200, $list['status']);
        $paths = json_decode((string)file_get_contents(dirname(__DIR__) . '/database/paths.json'), true)['paths'];
        foreach ($paths as $p) {
            $r = $web->get('/index.php?page=path&slug=' . $p['slug']);
            assert_same(200, $r['status'], 'lộ trình ' . $p['slug']);
            assert_contains($p['title'], html_entity_decode($r['body'], ENT_QUOTES, 'UTF-8'));
        }
        assert_same(404, $web->get('/index.php?page=path&slug=khong-ton-tai')['status']);
    });

    t('Trang quyền riêng tư mở được', function () use ($web) {
        assert_same(200, $web->get('/index.php?page=privacy')['status']);
    });

    // ---- Đăng ký ---------------------------------------------------------
    t('Tự đăng ký mặc định bị tắt (HTTP 403)', function () use ($web) {
        $r = $web->post('/index.php?page=register', ['csrf' => $web->csrf('/index.php?page=login'), 'email' => 'x@hpu2.edu.vn', 'full_name' => 'X Y', 'password' => 'MatKhau-Dai-12345']);
        assert_same(403, $r['status']);
    });

    // ---- Xác thực --------------------------------------------------------
    t('Đăng nhập không có token CSRF bị từ chối (HTTP 419)', function () {
        $anon = new Http('http://127.0.0.1:' . ACC_PORT);
        $r = $anon->post('/index.php?page=login', ['email' => ACC_ADMIN_EMAIL, 'password' => ACC_ADMIN_PASS]);
        assert_same(419, $r['status']);
    });

    t('Đăng nhập sai mật khẩu không tạo phiên và ghi lại lần thất bại', function () {
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        $r = $c->login(ACC_ADMIN_EMAIL, 'sai-mat-khau');
        assert_same(302, $r['status']);
        assert_contains('page=login', $r['location']);
        assert_true(in_array($c->get('/admin.php')['status'], [302, 403], true), 'vẫn không vào được trang quản trị');
        assert_true((int)acc_pdo(true)->query('SELECT COUNT(*) FROM login_attempts WHERE succeeded=0')->fetchColumn() >= 1);
    });

    t('Khách chưa đăng nhập không xem được trang quản trị', function () {
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        $r = $c->get('/admin.php');
        assert_true($r['status'] === 403 || $r['status'] === 302, 'HTTP ' . $r['status']);
        assert_not_contains('Quản trị hệ thống', $r['body']);
    });

    t('Quản trị đăng nhập đúng được chuyển tới admin.php và vào được trang quản trị', function () use (&$ctx) {
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        $r = $c->login(ACC_ADMIN_EMAIL, ACC_ADMIN_PASS);
        assert_same(302, $r['status']);
        assert_contains('admin.php', $r['location']);
        assert_same(200, $c->get('/admin.php')['status']);
        $ctx['admin'] = $c;
    });

    t('Phiên đăng nhập được cấp lại sau khi đăng nhập (chống session fixation)', function () {
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        $c->get('/index.php?page=login');
        preg_match('/HPU2_DIGITAL_SESSION\s+(\S+)/', (string)file_get_contents($c->jar), $m1);
        $c->login(ACC_ADMIN_EMAIL, ACC_ADMIN_PASS);
        preg_match('/HPU2_DIGITAL_SESSION\s+(\S+)/', (string)file_get_contents($c->jar), $m2);
        assert_true(isset($m1[1], $m2[1]) && $m1[1] !== $m2[1], 'mã phiên phải đổi sau khi đăng nhập');
    });

    // ---- Học viên: mở khóa bài, video, quiz ---------------------------------
    t('Tạo học viên và đăng nhập được, chuyển tới "Học của tôi"', function () use (&$ctx) {
        $db = acc_pdo(true);
        $db->prepare('INSERT INTO users(email,password_hash,full_name,role,status) VALUES(?,?,?,"learner","active")')->execute([ACC_LEARNER_EMAIL, password_hash(ACC_LEARNER_PASS, PASSWORD_DEFAULT), 'Học viên Test']);
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        $r = $c->login(ACC_LEARNER_EMAIL, ACC_LEARNER_PASS);
        assert_same(302, $r['status']);
        assert_contains('my-learning', $r['location']);
        assert_same(200, $c->get('/index.php?page=my-learning')['status']);
        $ctx['learner'] = $c;
    });

    t('Học viên không vào được khu vực quản trị (HTTP 403)', function () use (&$ctx) {
        assert_same(403, $ctx['learner']->get('/admin.php')['status']);
    });

    t('Bài 2 bị khóa cho đến khi hoàn thành bài 1; làm sai không mở khóa, làm đúng mới mở', function () use (&$ctx) {
        $c = $ctx['learner'];
        $db = acc_pdo(true);
        $courseId = (int)$db->query('SELECT id FROM courses ORDER BY sort_order,id LIMIT 1')->fetchColumn();
        $lessons = $db->query("SELECT id FROM lessons WHERE course_id={$courseId} ORDER BY sort_order,id")->fetchAll(PDO::FETCH_COLUMN);
        assert_true(count($lessons) >= 2, 'khóa đầu tiên cần ít nhất 2 bài');
        [$l1, $l2] = [(int)$lessons[0], (int)$lessons[1]];
        $ctx['l1'] = $l1; $ctx['l2'] = $l2;

        $lessonPage = $c->get('/index.php?page=lesson&id=' . $l1);
        assert_same(200, $lessonPage['status'], 'bài 1 phải mở sẵn');
        preg_match('/name="csrf" value="([a-f0-9]{64})"/', $lessonPage['body'], $m);
        assert_true(isset($m[1]), 'trang bài học phải có form quiz với token CSRF');
        $token = $m[1];
        $ctx['token'] = $token;

        // Bài 2 chưa mở: nộp quiz bị chặn.
        assert_same(403, $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l2])['status'], 'bài 2 phải bị khóa');

        $answers = static function (int $lessonId, bool $correct) use ($db): array {
            $out = [];
            foreach ($db->query("SELECT id FROM quizzes WHERE lesson_id={$lessonId}")->fetchAll(PDO::FETCH_COLUMN) as $qid) {
                $flag = $correct ? 1 : 0;
                $out[(int)$qid] = (int)$db->query("SELECT id FROM quiz_options WHERE quiz_id={$qid} AND is_correct={$flag} LIMIT 1")->fetchColumn();
            }
            return $out;
        };

        // Làm sai toàn bộ.
        $r = $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l1, 'answer' => $answers($l1, false)]);
        assert_same(302, $r['status']);
        $row = $db->query("SELECT status,best_score,attempts FROM progress WHERE lesson_id={$l1}")->fetch();
        assert_same('started', $row['status']);
        assert_same(0.0, (float)$row['best_score']);
        assert_same(403, $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l2])['status'], 'làm sai thì bài 2 vẫn khóa');

        // Làm đúng toàn bộ.
        $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l1, 'answer' => $answers($l1, true)]);
        $row = $db->query("SELECT status,best_score,attempts FROM progress WHERE lesson_id={$l1}")->fetch();
        assert_same('completed', $row['status']);
        assert_same(100.0, (float)$row['best_score']);
        assert_same(2, (int)$row['attempts']);
        assert_same(2, (int)$db->query("SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id={$l1}")->fetchColumn(), 'mọi lần làm đều được lưu');
        assert_same(200, $c->get('/index.php?page=lesson&id=' . $l2)['status'], 'bài 2 phải được mở');

        // Làm lại sai: điểm cao nhất và trạng thái hoàn thành được giữ nguyên.
        $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l1, 'answer' => $answers($l1, false)]);
        $row = $db->query("SELECT status,best_score,attempts FROM progress WHERE lesson_id={$l1}")->fetch();
        assert_same('completed', $row['status'], 'không được tụt trạng thái hoàn thành');
        assert_same(100.0, (float)$row['best_score']);
    });

    t('Nộp quiz với token CSRF sai bị từ chối (HTTP 419) và không ghi điểm', function () use (&$ctx) {
        $db = acc_pdo(true);
        $before = (int)$db->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn();
        $r = $ctx['learner']->post('/index.php?page=quiz', ['csrf' => str_repeat('0', 64), 'lesson_id' => $ctx['l2']]);
        assert_same(419, $r['status']);
        assert_same($before, (int)$db->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn());
    });

    t('Video bắt buộc: chặn quiz khi chưa đủ tỷ lệ xem; tiến độ chỉ tăng, giới hạn 0–100', function () use (&$ctx) {
        $c = $ctx['learner']; $db = acc_pdo(true); $l2 = $ctx['l2']; $token = $ctx['token'];
        $db->prepare('UPDATE lessons SET video_url=?, video_required_percent=50 WHERE id=?')->execute(['https://youtu.be/dQw4w9WgXcQ', $l2]);
        $attempts = fn() => (int)$db->query("SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id={$l2}")->fetchColumn();
        $answers = [];
        foreach ($db->query("SELECT id FROM quizzes WHERE lesson_id={$l2}")->fetchAll(PDO::FETCH_COLUMN) as $qid) $answers[(int)$qid] = (int)$db->query("SELECT id FROM quiz_options WHERE quiz_id={$qid} AND is_correct=1 LIMIT 1")->fetchColumn();

        $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l2, 'answer' => $answers]);
        assert_same(0, $attempts(), 'chưa xem video thì không được nộp quiz');

        $r = $c->post('/index.php?page=video-progress', ['csrf' => $token, 'lesson_id' => $l2, 'percent' => '25']);
        assert_same(['ok' => true, 'percent' => 25], json_decode($r['body'], true));
        $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l2, 'answer' => $answers]);
        assert_same(0, $attempts(), 'mới xem 25% < 50% thì vẫn bị chặn');

        assert_same(100, json_decode($c->post('/index.php?page=video-progress', ['csrf' => $token, 'lesson_id' => $l2, 'percent' => '999'])['body'], true)['percent'], 'giới hạn trên 100');
        $c->post('/index.php?page=video-progress', ['csrf' => $token, 'lesson_id' => $l2, 'percent' => '10']);
        assert_same(100, (int)$db->query("SELECT watched_percent FROM video_progress WHERE lesson_id={$l2}")->fetchColumn(), 'tiến độ xem không được giảm');

        $c->post('/index.php?page=quiz', ['csrf' => $token, 'lesson_id' => $l2, 'answer' => $answers]);
        assert_same(1, $attempts(), 'đủ tỷ lệ xem thì nộp được quiz');
    });

    t('Trang bài học chỉ nhúng video từ youtube-nocookie', function () use (&$ctx) {
        $r = $ctx['learner']->get('/index.php?page=lesson&id=' . $ctx['l2']);
        assert_contains('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $r['body']);
    });

    t('Đăng xuất hủy phiên: sau đó không vào được "Học của tôi"', function () use (&$ctx) {
        $c = $ctx['learner'];
        assert_same(302, $c->get('/index.php?page=logout')['status']);
        $r = $c->get('/index.php?page=my-learning');
        assert_same(302, $r['status']);
        assert_contains('page=login', $r['location']);
    });

    // ---- Giới hạn đăng nhập (chạy cuối vì khóa IP 127.0.0.1) ------------------
    t('Khóa đăng nhập sau 8 lần sai liên tiếp, kể cả khi lần sau nhập đúng mật khẩu', function () {
        $db = acc_pdo(true);
        $db->exec('DELETE FROM login_attempts');
        $c = new Http('http://127.0.0.1:' . ACC_PORT);
        for ($i = 0; $i < 8; $i++) $c->login(ACC_LEARNER_EMAIL, 'sai-mat-khau-' . $i);
        $r = $c->login(ACC_LEARNER_EMAIL, ACC_LEARNER_PASS);
        assert_same(302, $r['status']);
        assert_contains('page=login', $r['location'], 'đăng nhập đúng vẫn bị chặn khi đang bị khóa');
        $check = $c->get('/index.php?page=my-learning');
        assert_contains('page=login', $check['location'], 'không được tạo phiên khi đang bị khóa');
        assert_true((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='login_throttled'")->fetchColumn() >= 1, 'phải ghi nhật ký login_throttled');
        $db->exec('DELETE FROM login_attempts');
    });
}

// ---- Dọn dẹp -------------------------------------------------------------
if (is_resource($serverProc)) { proc_terminate($serverProc); proc_close($serverProc); }
if ($setupError === '') { try { acc_pdo(false)->exec('DROP DATABASE IF EXISTS `' . ACC_DB . '`'); } catch (Throwable $e) {} }
