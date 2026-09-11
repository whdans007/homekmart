<?php
/**
 * fresh_purchase_items.quantity_boxes를 DECIMAL(10,2)로 변경합니다.
 * 무게 상품의 8.40kg 같은 소수 수량을 저장하기 위한 마이그레이션입니다.
 *
 * 여러 번 실행해도 이미 적용된 경우 SKIP 처리됩니다.
 * 적용 완료 후에는 보안을 위해 이 파일을 서버에서 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . htmlspecialchars($conn->connect_error, ENT_QUOTES, 'UTF-8'));
}
$conn->set_charset(DB_CHARSET);

$columnResult = $conn->query(
    "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'fresh_purchase_items'
        AND COLUMN_NAME = 'quantity_boxes'
      LIMIT 1"
);
$column = $columnResult ? $columnResult->fetch_assoc() : null;
$alreadyDone = $column
    && strtolower((string)$column['DATA_TYPE']) === 'decimal'
    && (int)$column['NUMERIC_PRECISION'] === 10
    && (int)$column['NUMERIC_SCALE'] === 2;

$ran = false;
$status = $alreadyDone ? 'SKIP' : 'READY';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    if ($alreadyDone) {
        $status = 'SKIP';
    } elseif (!$column) {
        $status = 'ERROR';
        $error = 'fresh_purchase_items.quantity_boxes 컬럼을 찾을 수 없습니다.';
    } else {
        $sql = "ALTER TABLE `fresh_purchase_items`
                MODIFY COLUMN `quantity_boxes` DECIMAL(10,2) NULL
                COMMENT '매입 수량(BOX 또는 kg, 원 입력값 보존)'";
        if ($conn->query($sql)) {
            $status = 'OK';
        } else {
            $status = 'ERROR';
            $error = $conn->error;
        }
    }
}

$conn->close();

function migration_badge(string $status): string
{
    $styles = [
        'OK' => 'color:#065f46;background:#ecfdf5;border-color:#6ee7b7',
        'SKIP' => 'color:#374151;background:#f3f4f6;border-color:#d1d5db',
        'ERROR' => 'color:#991b1b;background:#fef2f2;border-color:#fca5a5',
        'READY' => 'color:#1e40af;background:#eff6ff;border-color:#93c5fd',
    ];
    $style = $styles[$status] ?? $styles['ERROR'];
    return '<span style="' . $style . ';border-width:1px;border-style:solid;border-radius:4px;padding:2px 8px;font-size:.75rem">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>신선상품 매입 수량 소수점 마이그레이션</title>
    <style>
        body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f9fafb;margin:0;padding:2rem;color:#111827}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px;margin:0 auto}
        h1{font-size:1.25rem;margin:0 0 .5rem}.sub{color:#6b7280;font-size:.875rem}.row{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;border-top:1px solid #f3f4f6}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;color:#92400e;font-size:.875rem;margin:1rem 0}
        .error{color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:.75rem;font-size:.875rem}
        button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:0;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}button:hover{background:#1d4ed8}
    </style>
</head>
<body>
<div class="card">
    <h1>신선상품 매입 수량 소수점 마이그레이션</h1>
    <p class="sub">quantity_boxes 컬럼을 DECIMAL(10,2)로 변경합니다.</p>
    <div class="row"><span>fresh_purchase_items.quantity_boxes</span><?php echo migration_badge($status); ?></div>

    <?php if ($error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif ($ran): ?>
        <div class="warn">OK 또는 SKIP이면 적용이 완료되었습니다. 이 파일을 서버에서 삭제해 주세요.</div>
    <?php else: ?>
        <div class="warn">실행 전 데이터베이스 백업을 권장합니다.</div>
        <form method="post">
            <input type="hidden" name="action" value="run">
            <button type="submit" onclick="return confirm('마이그레이션을 실행하시겠습니까?')">마이그레이션 실행</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
