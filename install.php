<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

if (installed()) redirect('index.php?page=login');

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $c = cfg('db');
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $c['name']);
        if (!$name) throw new RuntimeException('Tên cơ sở dữ liệu không hợp lệ trong config.php.');
        db(true)->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        db()->exec($schema);
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $nameAdmin = trim((string)($_POST['full_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email quản trị không hợp lệ.');
        if (mb_strlen($nameAdmin) < 2) throw new RuntimeException('Vui lòng nhập họ tên quản trị.');
        if (strlen($password) < 12) throw new RuntimeException('Mật khẩu quản trị phải có ít nhất 12 ký tự.');
        $st = db()->prepare('INSERT INTO users(email,password_hash,full_name,role,status) VALUES(?,?,?,?,"active") ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), full_name=VALUES(full_name), role="admin", status="active"');
        $st->execute([$email, password_hash($password, PASSWORD_DEFAULT), $nameAdmin, 'admin']);

        if (isset($_POST['seed']) && is_file(__DIR__.'/database/seed.json')) {
            $courses = json_decode((string)file_get_contents(__DIR__.'/database/seed.json'), true, 512, JSON_THROW_ON_ERROR);
            $pdo=db(); $pdo->beginTransaction();
            $findCourse=$pdo->prepare('SELECT id FROM courses WHERE slug=?');
            $addCourse=$pdo->prepare('INSERT INTO courses(slug,title,summary,outcomes,duration,level,audience,category,tone,label,status,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,"published",?)');
            $addLesson=$pdo->prepare('INSERT INTO lessons(course_id,title,objective,content,scenario,practice,source_note,reviewed_at,duration,sort_order,status) VALUES(?,?,?,?,?,?,?,?,?, ?,"published")');
            $addSource=$pdo->prepare('INSERT INTO course_sources(course_id,title,source_url,reviewed_at) VALUES(?,?,?,?)');
            $addQuiz=$pdo->prepare('INSERT INTO quizzes(lesson_id,question,explanation,sort_order) VALUES(?,?,?,?)');
            $addOption=$pdo->prepare('INSERT INTO quiz_options(quiz_id,option_text,is_correct,sort_order) VALUES(?,?,?,?)');
            foreach ($courses as $ci=>$course) {
                $findCourse->execute([$course['id']]);
                if ($findCourse->fetchColumn()) continue;
                $addCourse->execute([$course['id'],$course['title'],$course['summary']??'',json_encode($course['outcomes']??[],JSON_UNESCAPED_UNICODE),(int)($course['duration']??0),$course['level']??'Cơ bản',implode(',', $course['audience']??['all']),$course['category']??'skills',$course['tone']??'blue',$course['label']??'', $ci+1]);
                $courseId=(int)$pdo->lastInsertId();
                foreach(($course['sources']??[]) as $source){if(!empty($source['title'])&&!empty($source['url']))$addSource->execute([$courseId,$source['title'],$source['url'],date('Y-m-d')]);}
                foreach (($course['modules']??[]) as $li=>$lesson) {
                    $content=implode("\n", array_map(fn($v)=>'• '.$v, $lesson['points']??[]));
                    $sourceNote=implode('; ',array_column($course['sources']??[],'title'));
                    $addLesson->execute([$courseId,$lesson['title'],$lesson['objective']??'',$content,$lesson['scenario']??'',$lesson['practice']??'',$sourceNote,date('Y-m-d'),(int)($lesson['duration']??5),$li+1]);
                    $lessonId=(int)$pdo->lastInsertId();
                    $correct=$lesson['points'][0]??'Thực hiện đúng theo nội dung bài học.';
                    $addQuiz->execute([$lessonId,'Trong tình huống “'.mb_substr($lesson['title'],0,90).'”, lựa chọn nào đúng quy trình và an toàn nhất?','Đáp án bám nguyên tắc cốt lõi của bài; hãy kiểm tra cả tính đúng, thẩm quyền và rủi ro dữ liệu.',1]);
                    $quizId=(int)$pdo->lastInsertId();
                    $options=[['Bỏ qua bước kiểm tra vì cần xử lý nhanh',0],[$correct,1],['Đưa toàn bộ dữ liệu lên công cụ công khai để nhờ xử lý',0]];shuffle($options);
                    foreach ($options as $oi=>$option) $addOption->execute([$quizId,$option[0],$option[1],$oi+1]);

                    $practice=$lesson['practice']??'Áp dụng bảng kiểm của bài học vào một tình huống thực tế.';
                    $addQuiz->execute([$lessonId,'Hành động thực hành phù hợp sau bài học là gì?','Thực hành phải bám mục tiêu bài học và có bước tự kiểm tra kết quả.',2]);
                    $quizId=(int)$pdo->lastInsertId();
                    $options=[[$practice,1],['Chỉ ghi nhớ khái niệm mà không thử áp dụng',0],['Bỏ qua quy định của đơn vị nếu công cụ thuận tiện',0]];shuffle($options);
                    foreach ($options as $oi=>$option) $addOption->execute([$quizId,$option[0],$option[1],$oi+1]);
                }
            }
            $pathData=json_decode((string)file_get_contents(__DIR__.'/database/paths.json'),true,512,JSON_THROW_ON_ERROR);
            $addDomain=$pdo->prepare('INSERT INTO competency_domains(domain_code,title,description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description)');
            foreach($pathData['domains'] as $domain)$addDomain->execute([$domain['code'],$domain['title'],$domain['description']]);
            $addPath=$pdo->prepare('INSERT INTO learning_paths(slug,title,audience,level,summary,legal_basis,status,sort_order) VALUES(?,?,?,?,?,?,"published",?) ON DUPLICATE KEY UPDATE title=VALUES(title),audience=VALUES(audience),level=VALUES(level),summary=VALUES(summary),legal_basis=VALUES(legal_basis),sort_order=VALUES(sort_order)');
            $findPath=$pdo->prepare('SELECT id FROM learning_paths WHERE slug=?');$linkPath=$pdo->prepare('INSERT IGNORE INTO learning_path_courses(path_id,course_id,sort_order) SELECT ?,id,? FROM courses WHERE slug=?');
            foreach($pathData['paths'] as $pi=>$path){$addPath->execute([$path['slug'],$path['title'],$path['audience'],$path['level'],$path['summary'],$path['basis'],$pi+1]);$findPath->execute([$path['slug']]);$pathId=(int)$findPath->fetchColumn();foreach($path['courses'] as $ci=>$slug)$linkPath->execute([$pathId,$ci+1,$slug]);}
            $linkDomain=$pdo->prepare('INSERT IGNORE INTO course_competencies(course_id,domain_id) SELECT c.id,d.id FROM courses c,competency_domains d WHERE c.slug=? AND d.domain_code=?');
            foreach($pathData['course_domains'] as $slug=>$codes)foreach($codes as $code)$linkDomain->execute([$slug,$code]);
            $pdo->commit();
        }
        $done = true;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

page_header('Cài đặt hệ thống');
echo '<main class="auth-shell"><section class="auth-card">';
if ($done) {
    echo '<div class="success-mark">'.icon('check').'</div><h1>Cài đặt hoàn tất</h1><p>Cơ sở dữ liệu, tài khoản quản trị và nội dung mẫu đã sẵn sàng.</p><a class="btn btn-block" href="'.url('index.php?page=login').'">Đăng nhập quản trị</a><p class="muted">Vì an toàn, hãy đổi tên hoặc xóa tệp <code>install.php</code> sau khi kiểm tra hệ thống.</p>';
} else {
    echo '<p class="eyebrow">Thiết lập lần đầu</p><h1>Khởi tạo website HPU2</h1><p>Trước khi tiếp tục, bật Apache và MySQL trong XAMPP. Thông tin kết nối mặc định nằm trong <code>config.php</code>.</p>';
    if ($error) echo '<div class="alert alert-danger">'.e($error).'</div>';
    echo '<form method="post" class="stack">'.csrf_field().'<label>Họ tên quản trị<input name="full_name" required value="'.e($_POST['full_name']??'Quản trị HPU2').'"></label><label>Email quản trị<input type="email" name="email" required value="'.e($_POST['email']??'admin@hpu2.edu.vn').'"></label><label>Mật khẩu ban đầu<input type="password" name="password" required minlength="12" autocomplete="new-password"><small>Tối thiểu 12 ký tự; nên dùng cụm từ mật khẩu riêng.</small></label><label class="check-row"><input type="checkbox" name="seed" value="1" checked> Nhập 12 khóa học, nguồn chính thức và quiz tình huống</label><button class="btn btn-block" type="submit">Cài đặt hệ thống</button></form>';
}
echo '</section></main>';
page_footer();
