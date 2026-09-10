<?php
/**
 * Foodpang 외부 도매 매핑(fpwx_*) 테이블 생성 마이그레이션.
 * create_fpwx_tables.sql을 적용한다. 이미 존재하는 테이블은 SKIP되므로
 * 여러 번 실행해도 안전하다(idempotent). FK 의존 순서대로 하나씩 생성한다.
 *
 * 접속: http://서버주소/sql/run_create_fpwx_tables_migration.php
 * 주의: 실행 후 이 파일을 삭제하거나 접근을 제한하세요.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../mall/lib/csrf.php';

ensure_logged_in();
require_permission('foodpang_wholesale_management', '/admin/index.php');
$csrf_token = mall_csrf_token();

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

// FK 의존 순서 (부모 테이블 먼저)
$table_order = [
    'fpwx_import_batches',
    'fpwx_raw_barcode_rows',
    'fpwx_raw_pms_rows',
    'fpwx_base_products',
    'fpwx_sales_products',
    'fpwx_hkm_mappings',
    'fpwx_match_candidates',
    'fpwx_mapping_history',
    'fpwx_export_logs',
];

$sql_file = __DIR__ . '/create_fpwx_tables.sql';
$sql_content = file_get_contents($sql_file);

function fpwx_migration_extract_create_table($sql_content, $table_name) {
    $pattern = '/CREATE TABLE `' . preg_quote($table_name, '/') . '`.*?;/s';
    if (preg_match($pattern, $sql_content, $m)) {
        return rtrim($m[0], ';');
    }
    return null;
}

// 실행 전 상태 확인
$exists_before = [];
foreach ($table_order as $table_name) {
    $safe_table = $conn->real_escape_string($table_name);
    $check = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    $exists_before[$table_name] = ($check && $check->num_rows > 0);
}
$missing_before = array_keys(array_filter($exists_before, fn($v) => !$v));
$installation_complete = empty($missing_before);

$ran = false;
$results = []; // table_name => ['status' => OK|SKIP|ERROR, 'message' => string]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        $results['_csrf'] = ['status' => 'ERROR', 'message' => '요청이 만료되었습니다. 새로고침 후 다시 시도해주세요.'];
    } else {
        foreach ($table_order as $table_name) {
            if ($exists_before[$table_name]) {
                $results[$table_name] = ['status' => 'SKIP', 'message' => "{$table_name} 테이블이 이미 존재합니다."];
                continue;
            }
            $create_sql = fpwx_migration_extract_create_table($sql_content, $table_name);
            if (!$create_sql) {
                $results[$table_name] = ['status' => 'ERROR', 'message' => "create_fpwx_tables.sql에서 {$table_name} CREATE TABLE 문을 찾지 못했습니다."];
                continue;
            }
            if ($conn->query($create_sql)) {
                $results[$table_name] = ['status' => 'OK', 'message' => "{$table_name} 테이블 생성 완료"];
                $exists_before[$table_name] = true; // 다음 테이블의 FK 참조를 위해 즉시 반영
            } else {
                $results[$table_name] = ['status' => 'ERROR', 'message' => "{$table_name} 생성 실패: " . $conn->error];
                // 부모 테이블 생성 실패 시 이후 자식 테이블도 계속 실패할 것이므로 중단
                break;
            }
        }
    }
}

// 실행 후 상태 재확인
$exists_after = [];
foreach ($table_order as $table_name) {
    $safe_table = $conn->real_escape_string($table_name);
    $check = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    $exists_after[$table_name] = ($check && $check->num_rows > 0);
}
$missing_after = array_keys(array_filter($exists_after, fn($v) => !$v));
$installation_complete_after = empty($missing_after);

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Foodpang 도매 매핑(fpwx) DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:720px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .row{display:flex;align-items:center;justify-content:space-between;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.85rem}
        .row:last-child{border-bottom:none}
        .st-OK{color:#065f46;font-weight:700}
        .st-SKIP{color:#6b7280;font-weight:700}
        .st-ERROR{color:#991b1b;font-weight:700}
        .badge{display:inline-block;padding:2px 8px;border-radius:9999px;font-size:.75rem;background:#fce7f3;color:#9d174d;margin:2px}
        .badge-missing{background:#fef2f2;color:#991b1b}
        button{width:100%;padding:.75rem;background:#db2777;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#be185d}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🛵 Foodpang 도매 매핑(fpwx) DB 마이그레이션</h1>
    <p class="sub">create_fpwx_tables.sql 적용 · DB: <strong><?php echo htmlspecialchars(DB_NAME); ?></strong></p>
</div>

<?php if ($ran): ?>
<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">실행 결과</p>
    <?php foreach ($results as $table => $r): ?>
    <div class="row">
        <span><?php echo htmlspecialchars($table); ?></span>
        <span class="st-<?php echo $r['status']; ?>"><?php echo htmlspecialchars($r['message']); ?></span>
    </div>
    <?php endforeach; ?>
    <?php if ($installation_complete_after): ?>
    <p class="sub" style="margin-top:.75rem">⚠️ 보안을 위해 이 파일을 삭제하거나 접근을 제한하세요.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">현재 상태</p>
    <?php foreach ($table_order as $table_name): ?>
        <span class="badge <?php echo $exists_after[$table_name] ? '' : 'badge-missing'; ?>">
            <?php echo htmlspecialchars($table_name); ?> <?php echo $exists_after[$table_name] ? '✓' : '✗'; ?>
        </span>
    <?php endforeach; ?>
</div>

<?php if (!$installation_complete_after): ?>
<div class="card">
    <div class="warn">
        ⚠️ <strong>주의:</strong> 누락된 <code>fpwx_*</code> 테이블을 생성합니다.
        <code>products</code>/<code>users</code> 테이블을 FK로 참조하므로 해당 테이블이 먼저 존재해야 합니다.
        기존 Foodpang 상품 큐레이션(foodpang_products) 및 Mall/상품 테이블은 전혀 건드리지 않습니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button type="submit" onclick="return confirm('누락된 fpwx_* 테이블을 생성하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php else: ?>
<div class="card">
    <a href="../foodpang/admin/wholesale_dashboard.php">Foodpang 도매 매핑 대시보드로 이동 →</a>
</div>
<?php endif; ?>

</body>
</html>
