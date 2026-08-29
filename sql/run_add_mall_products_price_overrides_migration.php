<?php
/**
 * mall_products 테이블에 가격/할인 제어 컬럼 추가 마이그레이션
 * mall/admin/products.php 큐레이션 테이블에서 원가/기준판매가를 "스페셜 가격"으로
 * 오버라이드하고(매장 재고의 실제 가격은 건드리지 않음), 상품별 할인 허용 여부를
 * 관리할 수 있게 하는 데 필요.
 * 접속: https://homekmart.net/sql/run_add_mall_products_price_overrides_migration.php
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

$columns_to_add = [
    'cost_price_override' => "ALTER TABLE `mall_products` ADD COLUMN `cost_price_override` DECIMAL(10,2) NULL DEFAULT NULL",
    'selling_price_override' => "ALTER TABLE `mall_products` ADD COLUMN `selling_price_override` DECIMAL(10,2) NULL DEFAULT NULL",
    'discount_allowed' => "ALTER TABLE `mall_products` ADD COLUMN `discount_allowed` TINYINT(1) NOT NULL DEFAULT 1",
];

$ran = false;
$steps = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    foreach ($columns_to_add as $col => $sql) {
        $check = $conn->query("SHOW COLUMNS FROM mall_products LIKE '{$col}'");
        if ($check && $check->num_rows > 0) {
            $steps[] = ['SKIP', "{$col} 컬럼이 이미 존재합니다."];
            continue;
        }
        if ($conn->query($sql)) {
            $steps[] = ['OK', "{$col} 컬럼 추가 완료"];
        } else {
            $steps[] = ['ERROR', "{$col} 추가 실패: " . $conn->error];
        }
    }
}

$has_error = false;
foreach ($steps as [$status]) {
    if ($status === 'ERROR') { $has_error = true; break; }
}

$already_all_exist = true;
foreach (array_keys($columns_to_add) as $col) {
    $check = $conn->query("SHOW COLUMNS FROM mall_products LIKE '{$col}'");
    if (!$check || $check->num_rows === 0) { $already_all_exist = false; }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>mall_products 가격 오버라이드 컬럼 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        th{color:#6b7280;font-weight:500;font-size:.8rem}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
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
    <h1>🛠️ mall_products 가격/할인 제어 컬럼 마이그레이션</h1>
    <p class="sub">DB: <strong><?php echo DB_NAME; ?></strong> · cost_price_override, selling_price_override, discount_allowed</p>
</div>

<?php if (!$ran && $already_all_exist): ?>
<div class="card">
    <div class="skip-box">➖ 세 컬럼 모두 이미 존재합니다. 실행할 필요가 없습니다.</div>
</div>
<?php elseif ($ran): ?>
<div class="card">
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 내용을 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 마이그레이션 완료!</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $msg]): ?>
        <tr><td class="<?php echo strtolower($status); ?>">
            <?php echo $status === 'OK' ? '✅' : ($status === 'SKIP' ? '➖' : '❌'); ?>
            <?php echo htmlspecialchars($msg); ?>
        </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="card">
    <div class="warn">
        ⚠️ <code>mall_products</code> 테이블에 <code>cost_price_override</code>, <code>selling_price_override</code>(NULL 허용), <code>discount_allowed</code>(기본값 1) 컬럼을 추가합니다. 기존 데이터·다른 테이블에는 영향을 주지 않습니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('마이그레이션을 실행하시겠습니까?')">
            마이그레이션 실행
        </button>
    </form>
</div>
<?php endif; ?>

</body>
</html>
