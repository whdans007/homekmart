<?php
/**
 * categories 테이블에 sort_order 컬럼 추가 마이그레이션
 * mall/admin/products.php 좌측 카테고리 메뉴의 드래그앤드롭 순서 저장에 필요.
 * 접속: https://homekmart.net/sql/run_add_categories_sort_order_migration.php
 *
 * 이미 컬럼이 존재하면 SKIP 처리되어 여러 번 실행해도 안전하다(idempotent).
 *
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$already_exists = false;
$check = $conn->query("SHOW COLUMNS FROM categories LIKE 'sort_order'");
if ($check && $check->num_rows > 0) {
    $already_exists = true;
}

$ran = false;
$status = null;
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    if ($already_exists) {
        $status = 'SKIP';
    } elseif ($conn->query("ALTER TABLE `categories` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `parent_id`")) {
        $status = 'OK';
    } else {
        $status = 'ERROR';
        $error_msg = $conn->error;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>categories.sort_order 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
        .skip-box{background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;padding:1rem;font-size:.875rem;color:#374151;margin-bottom:1rem}
        button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#1d4ed8}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🛠️ categories.sort_order 컬럼 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!$ran && $already_exists): ?>
<div class="card">
    <div class="skip-box">➖ <code>categories.sort_order</code> 컬럼이 이미 존재합니다. 실행할 필요가 없습니다.</div>
</div>
<?php elseif ($ran): ?>
<div class="card">
    <?php if ($status === 'OK'): ?>
    <div class="success-box">✅ <code>sort_order</code> 컬럼 추가 완료!</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php elseif ($status === 'SKIP'): ?>
    <div class="skip-box">➖ 이미 존재하여 건너뛰었습니다.</div>
    <?php else: ?>
    <div class="err-box">❌ 추가 실패: <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>categories</code> 테이블에 <code>sort_order</code> 컬럼(기본값 0)을 추가합니다. 기존 데이터에는 영향을 주지 않습니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('categories 테이블에 sort_order 컬럼을 추가하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php endif; ?>

</body>
</html>
