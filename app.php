<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (cfg_https_proxy($config) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
session_name($config['session_name']);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
    'path' => '/',
]);
if (!empty($config['redis_session_dsn']) && extension_loaded('redis')) {
    ini_set('session.save_handler', 'redis');
    ini_set('session.save_path', (string)$config['redis_session_dsn']);
}
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://www.hpu2.edu.vn https://hpu2.edu.vn; frame-src https://www.youtube-nocookie.com https://www.youtube.com; media-src 'self' https:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
if ($isHttps) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

function cfg_https_proxy(array $c): bool { return !empty($c['trusted_proxy_https']); }

$now=time();
$idle=(int)($config['session_idle_minutes']??30)*60;
$absolute=(int)($config['session_absolute_hours']??8)*3600;
if (!empty($_SESSION['user_id']) && ((!empty($_SESSION['last_seen']) && $now-(int)$_SESSION['last_seen']>$idle) || (!empty($_SESSION['login_at']) && $now-(int)$_SESSION['login_at']>$absolute))) {
    $_SESSION=[]; session_regenerate_id(true); $_SESSION['flash'][]=['type'=>'warning','message'=>'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.'];
}
$_SESSION['last_seen']=$now;

function cfg(?string $key = null) {
    global $config;
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(bool $withoutDatabase = false): PDO {
    static $pdo = null;
    static $serverPdo = null;
    $key = $withoutDatabase ? 'serverPdo' : 'pdo';
    if ($withoutDatabase && $serverPdo instanceof PDO) return $serverPdo;
    if (!$withoutDatabase && $pdo instanceof PDO) return $pdo;
    $c = cfg('db');
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ($withoutDatabase ? '' : ';dbname=' . $c['name']) . ';charset=' . $c['charset'];
    $conn = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($withoutDatabase) $serverPdo = $conn; else $pdo = $conn;
    return $conn;
}

function installed(): bool {
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Throwable $e) { return false; }
}

function url(string $path = ''): string {
    $base = rtrim((string) cfg('base_url'), '/');
    if ($base === '') {
        $scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $base = $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function e(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }

function flash(string $type, string $message): void { $_SESSION['flash'][] = compact('type', 'message'); }
function flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function require_csrf(): void {
    $expected = (string) ($_SESSION['csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('Phiên làm việc đã hết hạn. Vui lòng quay lại và thử lại.');
    }
}

function user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $id = (int) ($_SESSION['user_id'] ?? 0);
    if (!$id) return $cached = null;
    $st = db()->prepare('SELECT * FROM users WHERE id=? AND status="active"');
    $st->execute([$id]);
    return $cached = ($st->fetch() ?: null);
}
function is_admin(): bool { return (user()['role'] ?? '') === 'admin'; }
function require_login(): void { if (!user()) { flash('warning', 'Vui lòng đăng nhập để tiếp tục học.'); redirect('index.php?page=login'); } }
function require_admin(): void { if (!is_admin()) { http_response_code(403); exit('Bạn không có quyền truy cập khu vực quản trị.'); } }

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),0,45); }
function audit_log(string $action, string $objectType='', ?int $objectId=null, array $details=[]): void {
    try { $st=db()->prepare('INSERT INTO audit_logs(user_id,action,object_type,object_id,ip_address,details_json) VALUES(?,?,?,?,?,?)'); $st->execute([user()['id']??null,$action,$objectType,$objectId,client_ip(),json_encode($details,JSON_UNESCAPED_UNICODE)]); } catch(Throwable $e) {}
}
function is_lesson_unlocked(int $lessonId,int $userId): bool {
    $st=db()->prepare('SELECT l.id,l.course_id,l.sort_order FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=? AND l.status="published" AND c.status="published"');$st->execute([$lessonId]);$l=$st->fetch();if(!$l)return false;
    $p=db()->prepare('SELECT id FROM lessons WHERE course_id=? AND status="published" AND (sort_order<? OR (sort_order=? AND id<?)) ORDER BY sort_order DESC,id DESC LIMIT 1');$p->execute([$l['course_id'],$l['sort_order'],$l['sort_order'],$l['id']]);$prev=$p->fetchColumn();if(!$prev)return true;
    $q=db()->prepare('SELECT 1 FROM progress WHERE user_id=? AND lesson_id=? AND status="completed"');$q->execute([$userId,$prev]);return (bool)$q->fetchColumn();
}
function csv_safe($value): string { $v=(string)$value; return preg_match('/^[=+\-@\t\r]/u',$v) ? "'".$v : $v; }
function video_embed_url(?string $url): ?string {
    $url=trim((string)$url); if($url==='')return null;
    if(preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([A-Za-z0-9_-]{6,})~',$url,$m))return 'https://www.youtube-nocookie.com/embed/'.$m[1].'?rel=0';
    if(preg_match('~^https://www\.youtube-nocookie\.com/embed/([A-Za-z0-9_-]+)~',$url,$m))return 'https://www.youtube-nocookie.com/embed/'.$m[1].'?rel=0';
    return null;
}

function setting(string $key, ?string $default = null): ?string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $cache[$key] = ($value === false ? $default : (string) $value);
    } catch (Throwable $e) { return $cache[$key] = $default; }
}

function slugify(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $map = ['à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a','è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e','ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i','ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o','ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u','ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'noi-dung-' . time();
}

function course_progress(int $courseId, int $userId): array {
    $st = db()->prepare('SELECT COUNT(*) total, SUM(CASE WHEN p.status="completed" THEN 1 ELSE 0 END) done FROM lessons l LEFT JOIN progress p ON p.lesson_id=l.id AND p.user_id=? WHERE l.course_id=? AND l.status="published"');
    $st->execute([$userId, $courseId]);
    $r = $st->fetch() ?: ['total'=>0,'done'=>0];
    $total=(int)$r['total']; $done=(int)$r['done'];
    return ['total'=>$total,'done'=>$done,'percent'=>$total ? (int) round($done*100/$total) : 0];
}

function all_course_progress(int $userId): array {
    $st=db()->prepare('SELECT l.course_id,COUNT(*) total,SUM(CASE WHEN p.status="completed" THEN 1 ELSE 0 END) done FROM lessons l LEFT JOIN progress p ON p.lesson_id=l.id AND p.user_id=? WHERE l.status="published" GROUP BY l.course_id');
    $st->execute([$userId]);$out=[];
    foreach($st as $r){$total=(int)$r['total'];$done=(int)$r['done'];$out[(int)$r['course_id']]=['total'=>$total,'done'=>$done,'percent'=>$total?(int)round($done*100/$total):0];}
    return $out;
}

function icon(string $name): string {
    $icons = [
        'book'=>'M4 19.5A2.5 2.5 0 016.5 17H20V5H6.5A2.5 2.5 0 004 7.5v12z M4 19.5V7.5A2.5 2.5 0 016.5 5',
        'user'=>'M20 21a8 8 0 00-16 0 M12 13a4 4 0 100-8 4 4 0 000 8z',
        'chart'=>'M4 20V10 M10 20V4 M16 20v-7 M22 20H2',
        'edit'=>'M12 20h9 M16.5 3.5a2.12 2.12 0 013 3L7 19l-4 1 1-4L16.5 3.5z',
        'check'=>'M20 6L9 17l-5-5',
        'lock'=>'M5 11h14v10H5z M8 11V7a4 4 0 018 0v4',
        'menu'=>'M4 6h16 M4 12h16 M4 18h16',
        'arrow'=>'M5 12h14 M13 6l6 6-6 6',
    ];
    $d=$icons[$name] ?? $icons['book'];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$d.'"/></svg>';
}

function page_header(string $title, bool $admin = false): void {
    $app=e((string)cfg('app_name')); $me=installed() ? user() : null;
    echo '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' · '.$app.'</title><meta name="description" content="Nền tảng học tập kỹ năng số HPU2"><link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 64 64%27%3E%3Crect width=%2764%27 height=%2764%27 rx=%2714%27 fill=%27%230c4a8a%27/%3E%3Cpath d=%27M16 17h12c5 0 9 4 9 9v23H25c-5 0-9-4-9-9V17zm32 0H36v32h3c5 0 9-4 9-9V17z%27 fill=%27white%27/%3E%3C/svg%3E"><link rel="stylesheet" href="'.url('assets/style.css').'">';
    echo '</head><body class="'.($admin?'admin-body':'').'">';
    echo '<header class="topbar"><a class="brand" href="'.url('index.php').'"><span class="brand-seal" aria-hidden="true">HPU2</span><span class="brand-copy"><strong>TRƯỜNG ĐHSP HÀ NỘI 2</strong><small>Bình dân học vụ số</small></span></a><button class="nav-toggle" type="button" data-nav-toggle aria-label="Mở menu">'.icon('menu').'</button><nav class="topnav" data-nav>';
    echo '<a href="'.url('index.php').'">Khóa học</a><a href="'.url('index.php?page=paths').'">Lộ trình</a>';
    if ($me) { echo '<a href="'.url('index.php?page=my-learning').'">Học của tôi</a>'; if (is_admin()) echo '<a href="'.url('admin.php').'">Quản trị</a>'; echo '<span class="nav-user">'.e($me['full_name']).'</span><a href="'.url('index.php?page=logout').'">Đăng xuất</a>'; }
    else echo '<a class="btn btn-sm" href="'.url('index.php?page=login').'">Đăng nhập</a>';
    echo '</nav></header>';
    foreach (flashes() as $f) echo '<div class="flash flash-'.e($f['type']).'">'.e($f['message']).'</div>';
}

function page_footer(): void {
    echo '<footer class="footer"><div><strong>Bình dân học vụ số HPU2</strong><p>Uy tín · Trí tuệ · Cống hiến</p></div><div><p>Học liệu rà soát: '.e((string)cfg('content_review_date')).'</p><p><a href="'.url('index.php?page=privacy').'">Quyền riêng tư</a> · © '.date('Y').' Trường Đại học Sư phạm Hà Nội 2</p></div></footer><script src="'.url('assets/app.js').'" defer></script></body></html>';
}
