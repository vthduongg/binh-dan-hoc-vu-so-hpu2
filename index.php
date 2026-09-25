<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

if (!installed()) redirect('install.php');
$page = (string)($_GET['page'] ?? 'home');

if ($page === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
    session_destroy(); redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'login') {
    require_csrf();
    $email=mb_strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
    $limit=db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE (email=? OR ip_address=?) AND succeeded=0 AND attempted_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE)');$limit->execute([$email,client_ip()]);
    if((int)$limit->fetchColumn()>=8){audit_log('login_throttled','user',null,['email'=>$email]);flash('danger','Có quá nhiều lần đăng nhập không thành công. Vui lòng thử lại sau 15 phút.');redirect('index.php?page=login');}
    $st=db()->prepare('SELECT * FROM users WHERE email=?'); $st->execute([$email]); $account=$st->fetch();
    if ($account && $account['status']==='active' && password_verify($password,$account['password_hash'])) {
        db()->prepare('INSERT INTO login_attempts(email,ip_address,succeeded) VALUES(?,?,1)')->execute([$email,client_ip()]);
        if(random_int(1,100)===1) db()->exec('DELETE FROM login_attempts WHERE attempted_at<DATE_SUB(NOW(),INTERVAL 30 DAY)');
        session_regenerate_id(true); $_SESSION['user_id']=(int)$account['id']; $_SESSION['login_at']=time(); $_SESSION['last_seen']=time();
        db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$account['id']]);
        audit_log('login_success','user',(int)$account['id']);
        redirect($account['role']==='admin' ? 'admin.php' : 'index.php?page=my-learning');
    }
    db()->prepare('INSERT INTO login_attempts(email,ip_address,succeeded) VALUES(?,?,0)')->execute([$email,client_ip()]);
    flash('danger','Email hoặc mật khẩu không đúng.'); redirect('index.php?page=login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'register') {
    if (setting('allow_registration','0')!=='1') { http_response_code(403); exit('Đăng ký đang tạm khóa.'); }
    require_csrf();
    $email=mb_strtolower(trim((string)($_POST['email']??''))); $name=trim((string)($_POST['full_name']??'')); $unit=trim((string)($_POST['unit_name']??'')); $password=(string)($_POST['password']??'');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($name)<2 || strlen($password)<12) { flash('danger','Vui lòng nhập đủ thông tin; mật khẩu tối thiểu 12 ký tự.'); redirect('index.php?page=register'); }
    $allowedDomain=trim((string)setting('allowed_email_domain',''));
    if ($allowedDomain!=='' && !str_ends_with($email,'@'.$allowedDomain)) { flash('danger','Chỉ chấp nhận địa chỉ email @'.$allowedDomain.'.'); redirect('index.php?page=register'); }
    try {
        $st=db()->prepare('INSERT INTO users(email,password_hash,full_name,unit_name,role,status,email_verified_at) VALUES(?,?,?,?,"learner","locked",NULL)');
        $st->execute([$email,password_hash($password,PASSWORD_DEFAULT),$name,$unit]);
        flash('success','Đã gửi yêu cầu tạo tài khoản. Quản trị viên cần xác minh và kích hoạt trước khi đăng nhập.'); redirect('index.php?page=login');
    } catch (PDOException $e) { flash('danger','Email này đã được sử dụng.'); redirect('index.php?page=register'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'quiz') {
    require_login(); require_csrf();
    $lessonId=(int)($_POST['lesson_id']??0);
    $st=db()->prepare('SELECT l.*,c.slug course_slug FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=?'); $st->execute([$lessonId]); $lesson=$st->fetch();
    if (!$lesson) { http_response_code(404); exit('Không tìm thấy bài học.'); }
    if(!is_lesson_unlocked($lessonId,(int)user()['id'])){http_response_code(403);exit('Bài học chưa được mở khóa.');}
    $qst=db()->prepare('SELECT * FROM quizzes WHERE lesson_id=? ORDER BY sort_order,id'); $qst->execute([$lessonId]); $questions=$qst->fetchAll();
    if(!$questions){flash('warning','Bài học chưa có quiz hợp lệ. Vui lòng báo quản trị viên.');redirect('index.php?page=lesson&id='.$lessonId);}
    if(!empty($lesson['video_url']) && (int)$lesson['video_required_percent']>0){$vp=db()->prepare('SELECT watched_percent FROM video_progress WHERE user_id=? AND lesson_id=?');$vp->execute([user()['id'],$lessonId]);if((int)$vp->fetchColumn()<(int)$lesson['video_required_percent']){flash('warning','Hãy xem đủ phần video bắt buộc trước khi làm quiz.');redirect('index.php?page=lesson&id='.$lessonId);}}
    $ids=array_map(fn($q)=>(int)$q['id'],$questions);$marks=[];
    if($ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));$ost=db()->prepare('SELECT id,quiz_id,is_correct FROM quiz_options WHERE quiz_id IN ('.$placeholders.')');$ost->execute($ids);foreach($ost as $o)$marks[(int)$o['quiz_id']][(int)$o['id']]=(int)$o['is_correct'];}
    $correct=0; $answers=[];
    foreach ($questions as $q) {
        $selected=(int)($_POST['answer'][$q['id']]??0); $answers[$q['id']]=$selected;
        if (($marks[(int)$q['id']][$selected]??0)===1) $correct++;
    }
    $score=round($correct*100/count($questions),2);
    $pass=(float)setting('quiz_pass_score','70'); $completed=$score>=$pass;
    $pdo=db(); $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO quiz_attempts(user_id,lesson_id,score,answers_json) VALUES(?,?,?,?)')->execute([user()['id'],$lessonId,$score,json_encode($answers)]);
    $pdo->prepare('INSERT INTO progress(user_id,lesson_id,status,best_score,attempts,started_at,completed_at) VALUES(?,?,?, ?,1,NOW(),?) ON DUPLICATE KEY UPDATE status=IF(VALUES(best_score)>=?,"completed",status),best_score=GREATEST(best_score,VALUES(best_score)),attempts=attempts+1,completed_at=IF(VALUES(best_score)>=?,NOW(),completed_at)')
        ->execute([user()['id'],$lessonId,$completed?'completed':'started',$score,$completed?date('Y-m-d H:i:s'):null,$pass,$pass]);
    $pdo->commit();
    flash($completed?'success':'warning',$completed?'Đạt '.$score.' điểm. Bài tiếp theo đã được mở khóa.':'Bạn đạt '.$score.' điểm. Cần tối thiểu '.$pass.' điểm; hãy xem lại bài và thử lại.');
    redirect('index.php?page=lesson&id='.$lessonId);
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $page==='video-progress') {
    require_login();require_csrf();$lessonId=(int)($_POST['lesson_id']??0);$percent=max(0,min(100,(int)($_POST['percent']??0)));
    if(!is_lesson_unlocked($lessonId,(int)user()['id'])){http_response_code(403);exit('Bài học chưa mở.');}
    db()->prepare('INSERT INTO video_progress(user_id,lesson_id,watched_percent) VALUES(?,?,?) ON DUPLICATE KEY UPDATE watched_percent=GREATEST(watched_percent,VALUES(watched_percent))')->execute([user()['id'],$lessonId,$percent]);
    header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>true,'percent'=>$percent]);exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $page==='diagnostic') {
    require_login();require_csrf();$pathSlug=(string)($_POST['path_slug']??'');
    $st=db()->prepare('SELECT id,slug,title FROM learning_paths WHERE slug=? AND status="published"');$st->execute([$pathSlug]);$path=$st->fetch();
    if(!$path){flash('danger','Vui lòng chọn đúng nhóm người học.');redirect('index.php?page=diagnostic');}
    $data=json_decode((string)file_get_contents(__DIR__.'/database/paths.json'),true);$scores=[];
    foreach(($data['diagnostic']??[]) as $i=>$q){$choice=(int)($_POST['answer'][$i]??-1);$scores[$q['domain']]=(int)($q['options'][$choice][1]??0);}
    db()->prepare('INSERT INTO diagnostic_attempts(user_id,scores_json,recommended_path_id) VALUES(?,?,?)')->execute([user()['id'],json_encode($scores,JSON_UNESCAPED_UNICODE),$path['id']]);
    $_SESSION['diagnostic_result']=['scores'=>$scores,'path_slug'=>$path['slug'],'path_title'=>$path['title']];redirect('index.php?page=diagnostic&result=1');
}

if($page==='paths'){
    page_header('Lộ trình học');$paths=db()->query('SELECT p.*,COUNT(pc.course_id) course_count FROM learning_paths p LEFT JOIN learning_path_courses pc ON pc.path_id=p.id WHERE p.status="published" GROUP BY p.id ORDER BY p.sort_order,p.id')->fetchAll();
    echo '<main class="container"><section class="page-head"><div><p class="eyebrow">Học đúng nhu cầu</p><h1>Chọn lộ trình theo nhóm người học</h1><p>Mỗi lộ trình kết nối các khóa học với sáu miền năng lực số. Bạn có thể làm đánh giá đầu vào để biết nội dung nên ưu tiên.</p></div><a class="btn" href="'.url('index.php?page=diagnostic').'">Đánh giá đầu vào</a></section><div class="path-grid">';
    foreach($paths as $p)echo '<article class="path-card"><span class="pill">'.e($p['level']).'</span><h2>'.e($p['title']).'</h2><p>'.e($p['summary']).'</p><div class="path-meta"><span>'.(int)$p['course_count'].' khóa học</span><span>'.e($p['audience']).'</span></div><a class="card-link" href="'.url('index.php?page=path&slug='.$p['slug']).'">Xem lộ trình '.icon('arrow').'</a></article>';
    echo '</div><section class="framework-note"><h2>Khung tham chiếu</h2><p>Người học được đối chiếu theo Thông tư 02/2025/TT-BGDĐT; giảng viên và cán bộ quản lý giáo dục theo Thông tư 18/2026/TT-BGDĐT. Sáu miền gồm dữ liệu và thông tin, giao tiếp và hợp tác, sáng tạo nội dung, an toàn, giải quyết vấn đề và ứng dụng AI.</p></section></main>';page_footer();exit;
}

if($page==='path'){
    $slug=(string)($_GET['slug']??'');$st=db()->prepare('SELECT * FROM learning_paths WHERE slug=? AND status="published"');$st->execute([$slug]);$path=$st->fetch();if(!$path){http_response_code(404);exit('Không tìm thấy lộ trình.');}
    $st=db()->prepare('SELECT c.*,COUNT(l.id) lesson_count FROM learning_path_courses pc JOIN courses c ON c.id=pc.course_id AND c.status="published" LEFT JOIN lessons l ON l.course_id=c.id AND l.status="published" WHERE pc.path_id=? GROUP BY c.id,pc.sort_order ORDER BY pc.sort_order,c.sort_order');$st->execute([$path['id']]);$courses=$st->fetchAll();$progress=user()?all_course_progress((int)user()['id']):[];
    page_header($path['title']);echo '<main class="container"><a class="back" href="'.url('index.php?page=paths').'">← Tất cả lộ trình</a><section class="path-hero"><p class="eyebrow">'.e($path['audience']).' · '.e($path['level']).'</p><h1>'.e($path['title']).'</h1><p>'.e($path['summary']).'</p><small>Căn cứ: '.e($path['legal_basis']).'</small></section><div class="toolbar"><div><h2>Thứ tự học đề xuất</h2><p>Hoàn thành từng bài và quiz để ghi nhận tiến độ.</p></div><a class="btn btn-ghost" href="'.url('index.php?page=diagnostic').'">Làm đánh giá đầu vào</a></div><div class="course-grid">';foreach($courses as $c)echo course_card($c,$progress[(int)$c['id']]??null);echo '</div></main>';page_footer();exit;
}

if($page==='diagnostic'){
    require_login();$data=json_decode((string)file_get_contents(__DIR__.'/database/paths.json'),true);$paths=db()->query('SELECT slug,title,audience FROM learning_paths WHERE status="published" ORDER BY sort_order,id')->fetchAll();$result=$_SESSION['diagnostic_result']??null;unset($_SESSION['diagnostic_result']);page_header('Đánh giá đầu vào');
    echo '<main class="container diagnostic-shell"><a class="back" href="'.url('index.php?page=paths').'">← Lộ trình học</a><section class="page-head"><div><p class="eyebrow">6 miền năng lực số</p><h1>Đánh giá nhanh năng lực đầu vào</h1><p>Chọn phương án gần nhất với cách bạn đang làm. Kết quả dùng để gợi ý nội dung ưu tiên, không phải bài thi xếp loại.</p></div></section>';
    if($result){echo '<section class="result-card"><h2>Lộ trình phù hợp: '.e($result['path_title']).'</h2><div class="domain-score-grid">';foreach($data['domains'] as $d){$score=(int)($result['scores'][$d['code']]??0);echo '<div><strong>'.e($d['code']).' · '.e($d['title']).'</strong><span class="score '.($score>=2?'good':'needs').'">'.($score>=2?'Đã có nền tảng':'Nên ưu tiên').'</span></div>';}echo '</div><a class="btn" href="'.url('index.php?page=path&slug='.$result['path_slug']).'">Bắt đầu lộ trình</a></section>';}
    echo '<form method="post" class="diagnostic-form">'.csrf_field().'<label>Nhóm của bạn<select name="path_slug" required><option value="">Chọn nhóm người học</option>';foreach($paths as $p)echo '<option value="'.e($p['slug']).'">'.e($p['title']).'</option>';echo '</select></label>';
    foreach(($data['diagnostic']??[]) as $i=>$q){echo '<fieldset><legend>'.($i+1).'. '.e($q['question']).'</legend>';foreach($q['options'] as $oi=>$o)echo '<label class="option"><input type="radio" name="answer['.$i.']" value="'.$oi.'" required><span>'.e($o[0]).'</span></label>';echo '</fieldset>';}echo '<button class="btn">Xem gợi ý lộ trình</button></form></main>';page_footer();exit;
}

if ($page==='privacy') {
    page_header('Quyền riêng tư');
    echo '<main class="container legal-page"><p class="eyebrow">Minh bạch dữ liệu</p><h1>Thông báo quyền riêng tư</h1><p>Hệ thống chỉ xử lý thông tin tài khoản, đơn vị, tiến độ học, điểm quiz, nhật ký bảo mật và dữ liệu cần thiết để tổ chức đào tạo. Không nhập tài liệu mật, dữ liệu cá nhân nhạy cảm hoặc hồ sơ người học vào công cụ AI.</p><h2>Mục đích và thời hạn</h2><p>Dữ liệu dùng để xác thực, theo dõi kết quả, hỗ trợ người học và bảo đảm an toàn hệ thống. Nhật ký và tiến độ được lưu theo quy chế của đơn vị; thời hạn mặc định '.e(setting('privacy_retention_months','24')).' tháng và có thể điều chỉnh theo quyết định chính thức.</p><h2>Quyền của người học</h2><p>Người học có thể đề nghị xem, sửa hoặc xử lý dữ liệu không chính xác qua quản trị viên hệ thống. Khi phát hiện sự cố, hãy đổi mật khẩu và báo ngay đơn vị phụ trách CNTT.</p><p class="source-note">Căn cứ tham chiếu: Luật Bảo vệ dữ liệu cá nhân số 91/2025/QH15 và Nghị định 356/2025/NĐ-CP, có hiệu lực từ 01/01/2026.</p></main>';page_footer();exit;
}

if ($page === 'login') {
    if (user()) redirect('index.php?page=my-learning');
    page_header('Đăng nhập');
    echo '<main class="auth-shell"><section class="auth-card"><p class="eyebrow">Tài khoản người học</p><h1>Đăng nhập</h1><p>Tiến độ và điểm quiz của bạn sẽ được lưu tập trung.</p><form method="post" class="stack">'.csrf_field().'<label>Email<input type="email" name="email" required autofocus autocomplete="email"></label><label>Mật khẩu<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-block">Đăng nhập</button></form>';
    if (setting('allow_registration','0')==='1') echo '<p class="auth-alt">Chưa có tài khoản? <a href="'.url('index.php?page=register').'">Gửi yêu cầu</a></p>';
    echo '</section></main>'; page_footer(); exit;
}

if ($page === 'register') {
    if (setting('allow_registration','0')!=='1') { http_response_code(403); exit('Đăng ký đang tạm khóa.'); }
    page_header('Đăng ký');
    echo '<main class="auth-shell"><section class="auth-card"><p class="eyebrow">Học viên mới</p><h1>Gửi yêu cầu tài khoản</h1><form method="post" class="stack">'.csrf_field().'<label>Họ và tên<input name="full_name" required></label><label>Email HPU2<input type="email" name="email" required placeholder="ten@'.e(setting('allowed_email_domain','hpu2.edu.vn')).'"></label><label>Đơn vị / lớp<input name="unit_name"></label><label>Mật khẩu<input type="password" name="password" minlength="12" required><small>Tối thiểu 12 ký tự.</small></label><button class="btn btn-block">Gửi yêu cầu</button></form><p class="auth-alt">Tài khoản chỉ hoạt động sau khi quản trị viên xác minh. <a href="'.url('index.php?page=login').'">Đăng nhập</a></p></section></main>'; page_footer(); exit;
}

if ($page === 'my-learning') {
    require_login(); page_header('Học của tôi');
    $courses=db()->query('SELECT c.*,COUNT(l.id) lesson_count FROM courses c LEFT JOIN lessons l ON l.course_id=c.id AND l.status="published" WHERE c.status="published" GROUP BY c.id ORDER BY c.sort_order,c.id')->fetchAll();
    echo '<main class="container"><section class="page-head"><div><p class="eyebrow">Tiến độ cá nhân</p><h1>Chào '.e(user()['full_name']).'</h1><p>Học lần lượt từng bài và đạt quiz để mở khóa bài tiếp theo.</p></div></section><div class="course-grid">';
    $allProgress=all_course_progress((int)user()['id']);foreach ($courses as $c) { echo course_card($c,$allProgress[(int)$c['id']]??['total'=>(int)$c['lesson_count'],'done'=>0,'percent'=>0]); }
    echo '</div></main>'; page_footer(); exit;
}

if ($page === 'course') {
    $slug=(string)($_GET['slug']??''); $st=db()->prepare('SELECT * FROM courses WHERE slug=? AND status="published"'); $st->execute([$slug]); $course=$st->fetch();
    if (!$course) { http_response_code(404); exit('Không tìm thấy khóa học.'); }
    $st=db()->prepare('SELECT * FROM lessons WHERE course_id=? AND status="published" ORDER BY sort_order,id'); $st->execute([$course['id']]); $lessons=$st->fetchAll();
    $ds=db()->prepare('SELECT d.domain_code,d.title FROM course_competencies cc JOIN competency_domains d ON d.id=cc.domain_id WHERE cc.course_id=? ORDER BY d.domain_code');$ds->execute([$course['id']]);$domains=$ds->fetchAll();
    $completed=[]; if (user()) { $ps=db()->prepare('SELECT lesson_id,status,best_score FROM progress WHERE user_id=? AND lesson_id IN (SELECT id FROM lessons WHERE course_id=?)'); $ps->execute([user()['id'],$course['id']]); foreach($ps as $row)$completed[$row['lesson_id']]=$row; }
    page_header($course['title']);
    echo '<main class="container"><a class="back" href="'.url('index.php').'">← Tất cả khóa học</a><section class="course-hero tone-'.e($course['tone']).'"><div><span class="pill">'.e($course['label']?:$course['level']).'</span><h1>'.e($course['title']).'</h1><p>'.e($course['summary']).'</p><div class="meta-row"><span>'.(int)$course['duration'].' phút</span><span>'.count($lessons).' bài</span><span>'.e($course['level']).'</span></div>';if($domains){echo '<div class="domain-badges">';foreach($domains as $d)echo '<span title="'.e($d['title']).'">'.e($d['domain_code']).' · '.e($d['title']).'</span>';echo '</div>';}echo '</div></section><div class="content-layout"><section><h2>Nội dung khóa học</h2><div class="lesson-list">';
    foreach($lessons as $i=>$l) {
        $isDone=isset($completed[$l['id']])&&$completed[$l['id']]['status']==='completed';
        $unlocked=user() && ($i===0 || (isset($completed[$lessons[$i-1]['id']])&&$completed[$lessons[$i-1]['id']]['status']==='completed'));
        echo '<article class="lesson-row '.($isDone?'done':'').' '.(!$unlocked?'locked':'').'"><span class="lesson-no">'.($isDone?icon('check'):($i+1)).'</span><div><h3>'.e($l['title']).'</h3><p>'.e($l['objective']).'</p><span>'.(int)$l['duration'].' phút'.($isDone?' · Đã hoàn thành':'').'</span></div>';
        echo $unlocked?'<a class="btn btn-sm" href="'.url('index.php?page=lesson&id='.$l['id']).'">'.($isDone?'Học lại':'Bắt đầu').'</a>':'<span class="lock-label">'.icon('lock').' Chưa mở</span>';
        echo '</article>';
    }
    echo '</div></section><aside class="side-card"><h3>Kết quả cần đạt</h3><ul>'; foreach((array)json_decode($course['outcomes']?:'[]',true) as $o) echo '<li>'.e((string)$o).'</li>'; echo '</ul>';$src=db()->prepare('SELECT * FROM course_sources WHERE course_id=? ORDER BY id');$src->execute([$course['id']]);$sources=$src->fetchAll();if($sources){echo '<h3>Nguồn chính thức</h3><ul class="sources">';foreach($sources as $s)echo '<li><a href="'.e($s['source_url']).'" target="_blank" rel="noopener noreferrer">'.e($s['title']).'</a></li>';echo '</ul><small>Rà soát '.e($sources[0]['reviewed_at']?:cfg('content_review_date')).'</small>';}echo '</aside></div></main>'; page_footer(); exit;
}

if ($page === 'lesson') {
    require_login(); $id=(int)($_GET['id']??0);
    $st=db()->prepare('SELECT l.*,c.title course_title,c.slug course_slug,c.id course_id FROM lessons l JOIN courses c ON c.id=l.course_id WHERE l.id=? AND l.status="published" AND c.status="published"'); $st->execute([$id]); $lesson=$st->fetch();
    if (!$lesson) { http_response_code(404); exit('Không tìm thấy bài học.'); }
    $all=db()->prepare('SELECT id,title FROM lessons WHERE course_id=? AND status="published" ORDER BY sort_order,id'); $all->execute([$lesson['course_id']]); $list=$all->fetchAll(); $pos=array_search($id,array_column($list,'id'));
    if(!is_lesson_unlocked($id,(int)user()['id'])){flash('warning','Hãy hoàn thành bài trước để mở khóa bài này.');redirect('index.php?page=course&slug='.$lesson['course_slug']);}
    db()->prepare('INSERT INTO progress(user_id,lesson_id,status) VALUES(?,?,"started") ON DUPLICATE KEY UPDATE updated_at=NOW()')->execute([user()['id'],$id]);
    $qst=db()->prepare('SELECT * FROM quizzes WHERE lesson_id=? ORDER BY sort_order,id'); $qst->execute([$id]); $questions=$qst->fetchAll();$byId=[];foreach($questions as $i=>$q){$questions[$i]['options']=[];$byId[(int)$q['id']]=$i;}if($byId){$os=db()->prepare('SELECT qo.* FROM quiz_options qo JOIN quizzes q ON q.id=qo.quiz_id WHERE q.lesson_id=? ORDER BY q.sort_order,q.id,qo.sort_order,qo.id');$os->execute([$id]);foreach($os as $o)$questions[$byId[(int)$o['quiz_id']]]['options'][]=$o;}
    $ps=db()->prepare('SELECT * FROM progress WHERE user_id=? AND lesson_id=?');$ps->execute([user()['id'],$id]);$progress=$ps->fetch();$vp=db()->prepare('SELECT watched_percent FROM video_progress WHERE user_id=? AND lesson_id=?');$vp->execute([user()['id'],$id]);$watched=(int)$vp->fetchColumn();
    page_header($lesson['title']);
    echo '<main class="learn-shell"><aside class="lesson-nav"><a class="back" href="'.url('index.php?page=course&slug='.$lesson['course_slug']).'">← '.e($lesson['course_title']).'</a><ol>'; foreach($list as $i=>$item)echo '<li class="'.($item['id']==$id?'active':'').'"><span>'.($i+1).'</span>'.e($item['title']).'</li>'; echo '</ol></aside><article class="lesson-content"><p class="eyebrow">Bài '.($pos+1).' / '.count($list).' · '.(int)$lesson['duration'].' phút</p><h1>'.e($lesson['title']).'</h1><div class="objective"><strong>Mục tiêu</strong><p>'.e($lesson['objective']).'</p></div><section class="prose"><h2>Nội dung chính</h2>';
    foreach(preg_split('/\r\n|\r|\n/',(string)$lesson['content']) as $line){$line=trim($line," \t•-");if($line!=='')echo '<p class="point">'.icon('check').'<span>'.e($line).'</span></p>';}
    echo '</section>';$embed=video_embed_url($lesson['video_url']??'');if($embed){echo '<section class="video-card" data-video-lesson="'.$id.'" data-video-required="'.(int)$lesson['video_required_percent'].'" data-video-endpoint="'.url('index.php?page=video-progress').'" data-csrf="'.e(csrf_token()).'"><div class="video-head"><div><p class="eyebrow">Video tương tác</p><h2>Xem – dừng – tự kiểm tra</h2></div><span data-video-status>Đã ghi nhận '.$watched.'%</span></div><div class="video-frame"><iframe src="'.e($embed).'" title="Video bài học" loading="lazy" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div><p class="muted">Xem video và bấm xác nhận sau mỗi chặng. Cần đạt tối thiểu '.(int)$lesson['video_required_percent'].'% trước khi nộp quiz.</p><div class="video-checkpoints"><button type="button" class="btn btn-ghost" data-video-progress="25">Đã xem 25%</button><button type="button" class="btn btn-ghost" data-video-progress="50">Đã xem 50%</button><button type="button" class="btn btn-ghost" data-video-progress="80">Đã xem 80%</button><button type="button" class="btn btn-ghost" data-video-progress="100">Đã xem hết</button></div></section>';}if($lesson['scenario'])echo '<section class="callout"><h2>Tình huống</h2><p>'.nl2br(e($lesson['scenario'])).'</p></section>'; if($lesson['practice'])echo '<section class="practice"><h2>Thực hành</h2><p>'.nl2br(e($lesson['practice'])).'</p></section>';if(!empty($lesson['source_note']))echo '<p class="source-note"><strong>Nguồn/ghi chú:</strong> '.nl2br(e($lesson['source_note'])).' · Rà soát '.e($lesson['reviewed_at']?:cfg('content_review_date')).'</p>';
    echo '<section class="quiz-box" id="quiz"><div class="quiz-title"><div><p class="eyebrow">Điều kiện mở bài tiếp theo</p><h2>Quiz cuối bài</h2></div>'.($progress&&$progress['status']==='completed'?'<span class="badge-success">Đã đạt '.e((string)$progress['best_score']).' điểm</span>':'').'</div>';
    if(!$questions) echo '<p>Bài học chưa có câu hỏi. Quản trị viên cần bổ sung quiz.</p>'; else { echo '<form method="post" action="'.url('index.php?page=quiz').'">'.csrf_field().'<input type="hidden" name="lesson_id" value="'.$id.'">'; foreach($questions as $qi=>$q){echo '<fieldset><legend>'.($qi+1).'. '.e($q['question']).'</legend>';foreach($q['options'] as $o)echo '<label class="option"><input type="radio" name="answer['.$q['id'].']" value="'.$o['id'].'" required><span>'.e($o['option_text']).'</span></label>';echo '</fieldset>';}echo '<button class="btn" type="submit">Nộp bài và xem kết quả</button></form>'; }
    echo '</section></article></main>'; page_footer(); exit;
}

function course_card(array $c, ?array $p=null): string {
    $pct=$p['percent']??0; $lessons=(int)($c['lesson_count']??0);
    return '<article class="course-card tone-'.e($c['tone']).'"><div class="course-top"><span class="pill">'.e($c['label']?:$c['level']).'</span><span>'.(int)$c['duration'].' phút</span></div><h2>'.e($c['title']).'</h2><p>'.e($c['summary']).'</p>'.($p?'<div class="progress"><span style="width:'.$pct.'%"></span></div><div class="progress-label"><span>'.$p['done'].'/'.$p['total'].' bài</span><strong>'.$pct.'%</strong></div>':'<div class="meta-row"><span>'.$lessons.' bài</span><span>'.e($c['level']).'</span></div>').'<a class="card-link" href="'.url('index.php?page=course&slug='.$c['slug']).'">Xem khóa học '.icon('arrow').'</a></article>';
}

page_header('Khóa học kỹ năng số');
$notice=setting('site_notice',''); if($notice)echo '<div class="site-notice">'.e($notice).'</div>';
$courses=db()->query('SELECT c.*,COUNT(l.id) lesson_count FROM courses c LEFT JOIN lessons l ON l.course_id=c.id AND l.status="published" WHERE c.status="published" GROUP BY c.id ORDER BY c.sort_order,c.id')->fetchAll();
    $paths=db()->query('SELECT p.*,COUNT(pc.course_id) course_count FROM learning_paths p LEFT JOIN learning_path_courses pc ON pc.path_id=p.id WHERE p.status="published" GROUP BY p.id ORDER BY p.sort_order,p.id LIMIT 6')->fetchAll();
    echo '<main class="container"><section class="home-head"><div><p class="eyebrow">Chương trình học tập trực tuyến HPU2</p><h1>Năng lực số để thích ứng<br>và thành công</h1><p>Bài học ngắn, tình huống đúng thực tiễn giáo dục Việt Nam, video tương tác và quiz bắt buộc để mở khóa bài tiếp theo.</p><div class="hero-actions"><a class="btn btn-light" href="'.url('index.php?page=paths').'">Chọn lộ trình</a><a class="btn btn-outline-light" href="'.url('index.php?page=diagnostic').'">Đánh giá đầu vào</a></div><div class="hero-values">UY TÍN <span>·</span> TRÍ TUỆ <span>·</span> CỐNG HIẾN</div></div><div class="head-stats"><strong>'.count($courses).'</strong><span>khóa học</span><strong>'.array_sum(array_column($courses,'lesson_count')).'</strong><span>bài thực hành</span></div></section><section class="trust-strip"><strong>Học liệu có căn cứ</strong><span>Nguồn chính thức · Ngày rà soát · Quản trị cập nhật được</span><strong>Học thật – đạt thật</strong><span>Video · Quiz · Tiến độ tập trung</span></section><section class="toolbar"><div><h2>Lộ trình theo nhóm người học</h2><p>Chọn đúng nhóm để học đúng nội dung cần thiết.</p></div><a class="card-link" href="'.url('index.php?page=paths').'">Xem tất cả '.icon('arrow').'</a></section><div class="path-grid compact">';foreach($paths as $p)echo '<article class="path-card"><span class="pill">'.e($p['level']).'</span><h3>'.e($p['title']).'</h3><p>'.e($p['summary']).'</p><a class="card-link" href="'.url('index.php?page=path&slug='.$p['slug']).'">'.(int)$p['course_count'].' khóa học '.icon('arrow').'</a></article>';echo '</div><section class="toolbar"><div><h2>Danh mục khóa học</h2><p>Hoặc chọn trực tiếp một nội dung cần học.</p></div><label class="search">Tìm khóa học<input type="search" placeholder="Nhập từ khóa..." data-course-search></label></section><div class="course-grid" data-course-grid>';
foreach($courses as $c)echo course_card($c);
echo '</div></main>'; page_footer();
