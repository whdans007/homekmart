<?php
/**
 * Foodpang 상품 큐레이션 테이블(foodpang_products) 생성 마이그레이션.
 * create_foodpang_products.sql을 적용한다. 이미 테이블이 존재하면 SKIP되어
 * 여러 번 실행해도 안전하다(idempotent).
 *
 * 접속: http://서버주소/sql/run_create_foodpang_products_migration.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../mall/lib/csrf.php';

ensure_logged_in();
require_permission('foodpang_management', '/admin/index.php');
$csrf_token = mall_csrf_token();

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$table_name = 'foodpang_products';
$sql_file = __DIR__ . '/create_foodpang_products.sql';

function foodpang_migration_extract_create_table($sql_file) {
    $content = file_get_contents($sql_file);
    if (preg_match('/CREATE TABLE `foodpang_products`.*?;/s', $content, $m)) {
        return rtrim($m[0], ';');
    }
    return null;
}

$create_sql = foodpang_migration_extract_create_table($sql_file);
$sql_content = file_get_contents($sql_file);
$category_sql = preg_match('/CREATE TABLE `foodpang_categories`.*?;/s', $sql_content, $category_match)
    ? rtrim($category_match[0], ';')
    : null;
$category_check = $conn->query("SHOW TABLES LIKE 'foodpang_categories'");
$category_exists = $category_check && $category_check->num_rows > 0;

$exists_before = false;
$check = $conn->query("SHOW TABLES LIKE '{$table_name}'");
if ($check && $check->num_rows > 0) {
    $exists_before = true;
}
$installation_complete = $exists_before && $category_exists;

$ran = false;
$status = null; // 'OK' | 'SKIP' | 'ERROR'
$message = '';

// Earlier Foodpang deployments may already have the product table but not the
// newly separated category table. Create the missing dependency first.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'run'
    && mall_csrf_verify($_POST['csrf_token'] ?? '')
    && !$category_exists
    && $category_sql) {
    if ($conn->query($category_sql)) {
        $category_exists = true;
    }
}

// Upgrade the first Foodpang schema version, whose category FK still pointed
// at the shared categories table. It is safe to switch automatically while
// the new channel has no curated rows yet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exists_before && $category_exists) {
    $fk_result = $conn->query("SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '" . $conn->real_escape_string(DB_NAME) . "' AND TABLE_NAME = 'foodpang_products' AND COLUMN_NAME = 'category_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
    $fk = $fk_result ? $fk_result->fetch_assoc() : null;
    if ($fk && $fk['REFERENCED_TABLE_NAME'] !== 'foodpang_categories') {
        $row_count_result = $conn->query('SELECT COUNT(*) cnt FROM foodpang_products');
        $row_count = $row_count_result ? (int)$row_count_result->fetch_assoc()['cnt'] : 0;
        if ($row_count === 0) {
            $constraint = str_replace('`', '``', $fk['CONSTRAINT_NAME']);
            $conn->query("ALTER TABLE foodpang_products DROP FOREIGN KEY `{$constraint}`");
            $conn->query('ALTER TABLE foodpang_products ADD CONSTRAINT foodpang_products_ibfk_3 FOREIGN KEY (category_id) REFERENCES foodpang_categories(id)');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        $status = 'ERROR';
        $message = '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요.';
    } elseif ($exists_before) {
        $status = 'SKIP';
        $message = "{$table_name} 테이블이 이미 존재합니다.";
    } elseif (!$create_sql) {
        $status = 'ERROR';
        $message = 'create_foodpang_products.sql에서 CREATE TABLE 문을 찾지 못했습니다.';
    } elseif (($category_exists || ($category_sql && $conn->query($category_sql))) && $conn->query($create_sql)) {
        $status = 'OK';
        $message = "{$table_name} 테이블 생성 완료";
    } else {
        $status = 'ERROR';
        $message = "{$table_name} 생성 실패: " . $conn->error;
    }
}

$exists_after = $exists_before;
if ($ran && $status === 'OK') {
    $exists_after = true;
}
$installation_complete_after = $exists_after && $category_exists;

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foodpang 상품 큐레이션 DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .skip-box{background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;padding:1rem;font-size:.875rem;color:#374151;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
        button{width:100%;padding:.75rem;background:#db2777;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#be185d}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
        .badge{display:inline-block;padding:2px 8px;border-radius:9999px;font-size:.8rem;background:#fce7f3;color:#9d174d}
    </style>
</head>
<body>
<div class="card">
    <h1>🛵 Foodpang 상품 큐레이션 DB 마이그레이션</h1>
    <p class="sub">create_foodpang_products.sql 적용 · DB: <strong><?php echo htmlspecialchars(DB_NAME); ?></strong></p>
</div>

<?php if ($ran): ?>
<div class="card">
    <?php if ($status === 'OK'): ?>
        <div class="success-box">✅ <?php echo htmlspecialchars($message); ?></div>
        <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php elseif ($status === 'SKIP'): ?>
        <div class="skip-box">➖ <?php echo htmlspecialchars($message); ?> (변경 없음)</div>
    <?php else: ?>
        <div class="err-box">❌ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">현재 상태</p>
    <span class="badge"><?php echo $exists_after ? "foodpang_products 존재함" : "foodpang_products 없음"; ?></span>
</div>

<?php if (!$installation_complete_after): ?>
<div class="card">
    <div class="warn">
        ⚠️ <strong>주의:</strong> <code>foodpang_products</code> 테이블을 생성합니다.
        기존 <code>products</code>/<code>stores</code> 테이블을 FK로 참조하므로 해당 테이블이 먼저 존재해야 합니다.
        mall_products 등 기존 쇼핑몰 테이블은 전혀 건드리지 않습니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button type="submit" onclick="return confirm('foodpang_products 테이블을 생성하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <a href="../foodpang/admin/dashboard.php">Foodpang 관리 대시보드로 이동 →</a>
</div>
<?php endif; ?>

</body>
</html>
