<?php
// 업로드 진단 페이지 - 문제 해결 후 삭제하세요
session_start();

$upload_max = ini_get('upload_max_filesize');
$post_max   = ini_get('post_max_size');
$memory     = ini_get('memory_limit');
$max_exec   = ini_get('max_execution_time');
$php_ver    = phpversion();
$user_ini   = php_ini_loaded_file();

// .user.ini 읽혔는지 확인
$scanned = php_ini_scanned_files();

// POST 진단
$post_info = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content_length = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_info['content_length_MB'] = round($content_length / 1024 / 1024, 2);
    $post_info['_FILES']    = empty($_FILES)  ? '비어있음 (post_max_size 초과 의심)' : print_r($_FILES, true);
    $post_info['_POST keys'] = empty($_POST)  ? '비어있음' : implode(', ', array_keys($_POST));
    if (!empty($_FILES['test_file'])) {
        $post_info['upload_error'] = $_FILES['test_file']['error'];
        $post_info['upload_size_MB'] = round($_FILES['test_file']['size'] / 1024 / 1024, 2);
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>업로드 진단</title>
<style>
body { font-family: sans-serif; padding: 20px; background: #f5f5f5; }
.card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
table { width: 100%; border-collapse: collapse; }
td, th { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
th { background: #f0f0f0; }
.ok { color: green; font-weight: bold; }
.warn { color: orange; font-weight: bold; }
.err { color: red; font-weight: bold; }
pre { background: #f0f0f0; padding: 10px; border-radius: 4px; overflow: auto; }
</style>
</head>
<body>
<h1>📊 PHP 업로드 진단</h1>

<div class="card">
<h2>현재 PHP 설정</h2>
<table>
<tr><th>설정</th><th>값</th><th>상태</th></tr>
<tr>
    <td>upload_max_filesize</td>
    <td><?= htmlspecialchars($upload_max) ?></td>
    <td class="<?= intval($upload_max) >= 50 ? 'ok' : 'err' ?>">
        <?= intval($upload_max) >= 50 ? '✅ 충분' : '❌ 너무 작음 (50M 이상 필요)' ?>
    </td>
</tr>
<tr>
    <td>post_max_size</td>
    <td><?= htmlspecialchars($post_max) ?></td>
    <td class="<?= intval($post_max) >= 50 ? 'ok' : 'err' ?>">
        <?= intval($post_max) >= 50 ? '✅ 충분' : '❌ 너무 작음 (50M 이상 필요)' ?>
    </td>
</tr>
<tr>
    <td>memory_limit</td>
    <td><?= htmlspecialchars($memory) ?></td>
    <td class="<?= intval($memory) >= 256 ? 'ok' : 'warn' ?>">
        <?= intval($memory) >= 256 ? '✅ 충분' : '⚠️ 부족할 수 있음' ?>
    </td>
</tr>
<tr>
    <td>max_execution_time</td>
    <td><?= htmlspecialchars($max_exec) ?>초</td>
    <td><?= intval($max_exec) >= 120 ? '<span class="ok">✅ 충분</span>' : '<span class="warn">⚠️ 짧을 수 있음</span>' ?></td>
</tr>
<tr>
    <td>PHP 버전</td>
    <td><?= htmlspecialchars($php_ver) ?></td>
    <td>-</td>
</tr>
<tr>
    <td>php.ini 경로</td>
    <td><?= htmlspecialchars($user_ini ?: '(없음)') ?></td>
    <td>-</td>
</tr>
<tr>
    <td>스캔된 ini 파일</td>
    <td style="max-width:400px; word-break:break-all;"><?= htmlspecialchars($scanned ?: '(없음)') ?></td>
    <td><?= strpos($scanned, '.user.ini') !== false ? '<span class="ok">✅ .user.ini 읽힘</span>' : '<span class="warn">⚠️ .user.ini 미적용</span>' ?></td>
</tr>
</table>
</div>

<?php if (!empty($post_info)): ?>
<div class="card">
<h2>POST 수신 진단</h2>
<table>
<?php foreach ($post_info as $k => $v): ?>
<tr><th><?= htmlspecialchars($k) ?></th><td><pre><?= htmlspecialchars($v) ?></pre></td></tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<div class="card">
<h2>테스트 파일 업로드</h2>
<p>아래에서 실제 POS 파일을 선택하고 "테스트 업로드"를 눌러보세요. 업로드 결과가 바로 위에 표시됩니다.</p>
<form method="POST" enctype="multipart/form-data">
    <input type="file" name="test_file" accept=".xlsx,.csv,.xls" style="margin-right:10px;">
    <button type="submit" style="padding:8px 16px; background:#3b82f6; color:white; border:none; border-radius:4px; cursor:pointer;">
        테스트 업로드
    </button>
</form>
</div>

<p style="color:#999; font-size:12px;">⚠️ 이 진단 페이지는 문제 해결 후 삭제하세요: admin/upload_diag.php</p>
</body>
</html>
