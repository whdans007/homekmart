<?php
/**
 * mall_orders 배송지 스냅샷 컬럼 추가 DB 마이그레이션
 * 접속: http://서버주소/sunset/sql/run_add_mall_orders_shipping_snapshot_migration.php
 *
 * 배경: 지금까지 mall_orders는 주문 시점의 배송지를 저장하지 않고, 화면에 표시할 때마다
 * 회원의 "현재" 기본 배송지(mall_addresses)를 그때그때 조회해왔다. 그래서 고객이 주문 이후
 * 기본 배송지를 바꾸면 과거 주문의 배송지 표시도 함께 바뀌는 버그가 있었다.
 * mall_order_items가 가격을 스냅샷으로 저장하는 것과 같은 방식으로, 주문 확정 시점의
 * 배송지 값을 mall_orders에 그대로 복사해 저장한다(이후 mall_addresses가 바뀌어도 불변).
 *
 * SHOW COLUMNS로 이미 적용됐는지 확인해 재실행해도 안전하다.
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('DB 연결 실패: ' . $conn->connect_error);
}
$conn->set_charset(DB_CHARSET);

$steps = [];

$col_check = $conn->query("SHOW COLUMNS FROM mall_orders LIKE 'ship_recipient_name'");
if ($col_check && $col_check->num_rows > 0) {
    $steps[] = ['SKIP', '배송지 스냅샷 컬럼이 이미 존재합니다.'];
} else {
    $sql = "ALTER TABLE `mall_orders`
        ADD COLUMN `ship_recipient_name` varchar(100) DEFAULT NULL COMMENT '주문 시점 배송지 스냅샷',
        ADD COLUMN `ship_phone` varchar(50) DEFAULT NULL,
        ADD COLUMN `ship_region` varchar(100) DEFAULT NULL,
        ADD COLUMN `ship_city` varchar(100) DEFAULT NULL,
        ADD COLUMN `ship_barangay` varchar(100) DEFAULT NULL,
        ADD COLUMN `ship_detail_address` varchar(255) DEFAULT NULL,
        ADD COLUMN `ship_landmark` varchar(255) DEFAULT NULL,
        ADD COLUMN `ship_lat` decimal(10,7) DEFAULT NULL,
        ADD COLUMN `ship_lng` decimal(10,7) DEFAULT NULL";
    if ($conn->query($sql)) {
        $steps[] = ['OK', '배송지 스냅샷 컬럼 9개 추가 완료 (기존 주문은 전부 NULL — 과거 주문은 소급 적용 불가)'];
    } else {
        $steps[] = ['ERROR', '컬럼 추가 실패: ' . $conn->error];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>배송지 스냅샷 컬럼 DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:720px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🚀 배송지 스냅샷 컬럼 DB 마이그레이션</h1>
    <p class="sub">mall_orders에 ship_* 컬럼 9개 추가 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 완료!</div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>단계</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($steps as [$status, $msg]): ?>
        <tr><td colspan="2" class="<?php echo strtolower($status); ?>">[<?php echo $status; ?>] <?php echo htmlspecialchars($msg); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
