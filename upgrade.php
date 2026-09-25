<?php
declare(strict_types=1);
require __DIR__.'/app.php';
if(!installed()) redirect('install.php');
require_admin();
$done=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    try{
        $pdo=db();$column=function(string $table,string $name)use($pdo):bool{$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$st->execute([$table,$name]);return (bool)$st->fetchColumn();};
        $changes=[
            ['users','email_verified_at','ALTER TABLE users ADD email_verified_at DATETIME DEFAULT NULL AFTER status'],
            ['lessons','video_url','ALTER TABLE lessons ADD video_url VARCHAR(500) DEFAULT NULL AFTER practice'],
            ['lessons','video_required_percent','ALTER TABLE lessons ADD video_required_percent TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER video_url'],
            ['lessons','source_note','ALTER TABLE lessons ADD source_note TEXT AFTER video_required_percent'],
            ['lessons','reviewed_at','ALTER TABLE lessons ADD reviewed_at DATE DEFAULT NULL AFTER source_note'],
        ];foreach($changes as [$table,$name,$sql])if(!$column($table,$name))$pdo->exec($sql);
        $schema=(string)file_get_contents(__DIR__.'/database/schema.sql');$newTables=['learning_paths','learning_path_courses','competency_domains','course_competencies','diagnostic_attempts','login_attempts','audit_logs','course_sources','video_progress'];foreach(preg_split('/;\s*(?:\r?\n|$)/',$schema) as $sql){$sql=trim($sql);foreach($newTables as $table)if(str_starts_with($sql,'CREATE TABLE IF NOT EXISTS '.$table)){$pdo->exec($sql);break;}}
        $seed=json_decode((string)file_get_contents(__DIR__.'/database/seed.json'),true,512,JSON_THROW_ON_ERROR);$find=$pdo->prepare('SELECT id FROM courses WHERE slug=?');$exists=$pdo->prepare('SELECT COUNT(*) FROM course_sources WHERE course_id=?');$add=$pdo->prepare('INSERT INTO course_sources(course_id,title,source_url,reviewed_at) VALUES(?,?,?,?)');foreach($seed as $course){$find->execute([$course['id']]);$courseId=(int)$find->fetchColumn();if(!$courseId)continue;$exists->execute([$courseId]);if((int)$exists->fetchColumn()>0)continue;foreach(($course['sources']??[]) as $source)$add->execute([$courseId,$source['title'],$source['url'],date('Y-m-d')]);}
        $pathData=json_decode((string)file_get_contents(__DIR__.'/database/paths.json'),true,512,JSON_THROW_ON_ERROR);$addDomain=$pdo->prepare('INSERT INTO competency_domains(domain_code,title,description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description)');foreach($pathData['domains'] as $domain)$addDomain->execute([$domain['code'],$domain['title'],$domain['description']]);$addPath=$pdo->prepare('INSERT INTO learning_paths(slug,title,audience,level,summary,legal_basis,status,sort_order) VALUES(?,?,?,?,?,?,"published",?) ON DUPLICATE KEY UPDATE title=VALUES(title),audience=VALUES(audience),level=VALUES(level),summary=VALUES(summary),legal_basis=VALUES(legal_basis),sort_order=VALUES(sort_order)');$findPath=$pdo->prepare('SELECT id FROM learning_paths WHERE slug=?');$clearPath=$pdo->prepare('DELETE FROM learning_path_courses WHERE path_id=?');$linkPath=$pdo->prepare('INSERT INTO learning_path_courses(path_id,course_id,sort_order) SELECT ?,id,? FROM courses WHERE slug=?');foreach($pathData['paths'] as $pi=>$path){$addPath->execute([$path['slug'],$path['title'],$path['audience'],$path['level'],$path['summary'],$path['basis'],$pi+1]);$findPath->execute([$path['slug']]);$pathId=(int)$findPath->fetchColumn();$clearPath->execute([$pathId]);foreach($path['courses'] as $ci=>$slug)$linkPath->execute([$pathId,$ci+1,$slug]);}$pdo->exec('DELETE FROM course_competencies');$linkDomain=$pdo->prepare('INSERT INTO course_competencies(course_id,domain_id) SELECT c.id,d.id FROM courses c,competency_domains d WHERE c.slug=? AND d.domain_code=?');foreach($pathData['course_domains'] as $slug=>$codes)foreach($codes as $code)$linkDomain->execute([$slug,$code]);
        $st=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)');$st->execute(['privacy_retention_months','24']);
        audit_log('upgrade_v2_1','system');$done=true;
    }catch(Throwable $e){$error=$e->getMessage();}
}
page_header('Nâng cấp dữ liệu');echo '<main class="auth-shell"><section class="auth-card"><p class="eyebrow">HPU2 v2.1</p><h1>Nâng cấp cơ sở dữ liệu</h1>';
if($done)echo '<div class="alert alert-success">Nâng cấp hoàn tất. Hãy đổi tên hoặc xóa <code>upgrade.php</code>.</div><a class="btn btn-block" href="'.url('admin.php').'">Về trang quản trị</a>';
else{if($error)echo '<div class="alert alert-danger">'.e($error).'</div>';echo '<p>Dùng một lần khi nâng cấp từ bản 1.x hoặc 2.0. Hãy sao lưu database trước khi thực hiện.</p><form method="post">'.csrf_field().'<button class="btn btn-block">Thực hiện nâng cấp</button></form>';}
echo '</section></main>';page_footer();
