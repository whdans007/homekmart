<?php
/**
 * 유통기한 관리(점검기록/폐기등록) DB 마이그레이션 실행기
 * 접속: http://서버주소/admin/expiry_run_migration.php
 * 대상: docs/02-design/features/expiry-management.design.md §3
 * 주의: 실행 후 이 파일을 삭제하거나 이름을 바꾸세요.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
ensure_logged_in();

if (!has_permission('product_management')) {
    http_response_code(403);
    exit('권한이 없습니다.');
}

$error = '';
$results = [];
$has_error = false;

$steps = [
    'inventory_expirations.registered_by' => "ALTER TABLE `inventory_expirations` ADD COLUMN `registered_by` INT(11) UNSIGNED NULL AFTER `quantity`",
    'inventory_expirations.registered_at' => "ALTER TABLE `inventory_expirations` ADD COLUMN `registered_at` DATETIME NULL AFTER `registered_by`",
    'product_disposals' => "CREATE TABLE IF NOT EXISTS `product_disposals` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `store_id` INT(11) UNSIGNED NOT NULL,
        `product_id` INT(11) UNSIGNED NOT NULL,
        `inventory_expiration_id` INT(11) UNSIGNED NULL COMMENT '차감된 로트 (inventory_expirations.id)',
        `expiration_date` DATE NOT NULL,
        `quantity` INT(11) NOT NULL COMMENT '폐기 수량',
        `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '폐기 시점 원가 스냅샷 (통계용)',
        `reason` ENUM('expired','damaged','other') NOT NULL DEFAULT 'expired',
        `reason_note` VARCHAR(255) NULL COMMENT '사유=other일 때 상세 텍스트',
        `disposed_by` INT(11) NULL COMMENT '등록한 사용자 (users.id)',
        `disposed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_disposal_store_product` (`store_id`,`product_id`),
        KEY `idx_disposal_disposed_at` (`disposed_at`),
        KEY `idx_disposal_lot` (`inventory_expiration_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 상품 폐기 이력'",
    'expiry_settings' => "CREATE TABLE IF NOT EXISTS `expiry_settings` (
        `id` TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
        `warning_days` INT(11) NOT NULL DEFAULT 60 COMMENT '관찰 대상 기준일',
        `alert_days` INT(11) NOT NULL DEFAULT 30 COMMENT '긴급 알림(배지) 기준일',
        `updated_by` INT(11) NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='유통기한 관리 임계값 설정 (단일 행, id=1 고정)'",
    'expiry_settings.seed_row' => "INSERT INTO `expiry_settings` (`id`, `warning_days`, `alert_days`) VALUES (1, 60, 30) ON DUPLICATE KEY UPDATE `id` = `id`",
];

// 현재 상태 점검
function check_column_exists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_assoc()['cnt'] > 0;
    $stmt->close();
    return $exists;
}

function check_table_exists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $result && $result->num_rows > 0;
}

function check_seed_row_exists($conn) {
    if (!check_table_exists($conn, 'expiry_settings')) return false;
    $result = $conn->query("SELECT id FROM expiry_settings WHERE id = 1 LIMIT 1");
    return $result && $result->num_rows > 0;
}

$existing = [];
$db_ver = '';
try {
    $conn = get_db_connection();
    $row = $conn->query("SELECT VERSION() AS v")->fetch_assoc();
    $db_ver = $row['v'] ?? '';

    $existing['inventory_expirations.registered_by'] = check_column_exists($conn, 'inventory_expirations', 'registered_by');
    $existing['inventory_expirations.registered_at'] = check_column_exists($conn, 'inventory_expirations', 'registered_at');
    $existing['product_disposals'] = check_table_exists($conn, 'product_disposals');
    $existing['expiry_settings'] = check_table_exists($conn, 'expiry_settings');
    $existing['expiry_settings.seed_row'] = check_seed_row_exists($conn);

    $conn->close();
} catch (Exception $e) {
    $error = 'DB 연결/조회 오류: ' . $e->getMessage();
}

// 마이그레이션 실행
if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $conn = get_db_connection();
        foreach ($steps as $key => $sql) {
            if (!empty($existing[$key])) {
                $results[$key] = ['status' => 'skip', 'msg' => '이미 적용됨 (건너뜀)'];
                continue;
            }
            if ($conn->query($sql) === true) {
                $results[$key] = ['status' => 'ok', 'msg' => '적용 완료'];
                $existing[$key] = true;
            } else {
                $results[$key] = ['status' => 'error', 'msg' => $conn->error];
                $has_error = true;
            }
        }
        $conn->close();
    } catch (Exception $e) {
        $has_error = true;
        $results['_오류'] = ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

$all_exist = !in_array(false, $existing, true);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>유통기한 관리 DB 마이그레이션</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f9fafb; margin: 0; padding: 2rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.5rem; max-width: 640px; margin: 0 auto 1rem; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 .25rem; color: #111; }
        .sub { color: #6b7280; font-size: .875rem; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        td, th { padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; text-align: left; }
        th { color: #6b7280; font-weight: 500; }
        .ok   { color: #059669; }
        .skip { color: #9ca3af; }
        .err  { color: #dc2626; }
        .new  { color: #0d9488; }
        .exist{ color: #9ca3af; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #92400e; margin-bottom: 1rem; }
        .success-box { background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #065f46; margin-bottom: 1rem; }
        .err-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #991b1b; margin-bottom: 1rem; }
        button { width: 100%; padding: .75rem; background: #0d9488; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #0f766e; }
        a { color: #0d9488; }
        code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: .8rem; }
        .mono { font-family: monospace; }
    </style>
</head>
<body>
<div class="card">
    <h1>유통기한 관리 DB 마이그레이션</h1>
    <p class="sub">inventory_expirations 컬럼 추가 2건 + 테이블 생성 2건 · DB: <strong><?php echo DB_NAME; ?></strong> · 버전: <?php echo htmlspecialchars($db_ver ?: '연결 실패'); ?></p>
</div>

<?php if ($error): ?>
<div class="card">
    <div class="err-box">❌ <?php echo htmlspecialchars($error); ?></div>
</div>
<?php else: ?>

<?php if (!empty($results)): ?>
<div class="card">
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 항목 적용에 실패했습니다. 아래 표에서 오류 내용을 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 마이그레이션 완료! <a href="expiry_inspection.php">점검기록 화면으로 이동 →</a></div>
    <p class="sub" style="margin-top:.5rem">⚠️ 보안을 위해 이 파일(<code>expiry_run_migration.php</code>)을 삭제하거나 이름을 바꾸세요.</p>
    <?php endif; ?>

    <table>
        <thead><tr><th>항목</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($results as $t => $r): ?>
        <tr>
            <td class="mono"><?php echo htmlspecialchars($t); ?></td>
            <td class="<?php echo $r['status']; ?>">
                <?php echo $r['status'] === 'ok' ? '✅' : ($r['status'] === 'skip' ? '➖' : '❌'); ?>
                <?php echo htmlspecialchars($r['msg']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">적용 대상 항목</p>
    <table>
        <thead><tr><th>항목명</th><th>상태</th></tr></thead>
        <tbody>
        <?php foreach ($existing as $t => $exists): ?>
        <tr>
            <td class="mono"><?php echo htmlspecialchars($t); ?></td>
            <td class="<?php echo $exists ? 'exist' : 'new'; ?>">
                <?php echo $exists ? '➖ 이미 적용됨' : '➕ 적용 예정'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($all_exist && empty($results)): ?>
<div class="card">
    <div class="success-box">✅ 모든 항목이 이미 적용되어 있습니다. <a href="expiry_inspection.php">점검기록 화면으로 이동 →</a></div>
</div>
<?php elseif (empty($results)): ?>
<div class="card">
    <div class="warn">⚠️ 컬럼/테이블이 이미 있으면 건너뛰므로 여러 번 실행해도 안전합니다.</div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('유통기한 관리 마이그레이션을 실행하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
