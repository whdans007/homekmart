<?php
/**
 * mall_orders 단계별 시각 컬럼 추가 DB 마이그레이션
 * 접속: http://서버주소/sunset/sql/run_add_mall_orders_stage_timestamps_migration.php
 *
 * 관리자 주문 상세 모달의 "진행 상황" 스테퍼에 단계별 시각을 표시하기 위해 추가한다.
 * - confirmed_at: 접수확인 처리 시각(pending → preparing)
 * - ready_at: 준비완료 처리 시각(preparing → ready)
 * 배정/배송시작/도착/완료 시각은 이미 mall_order_driver_assignments(assigned_at/delivering_at/
 * arrived_at/completed_at)에 저장되어 있어 별도 컬럼이 필요 없다.
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

$col_check = $conn->query("SHOW COLUMNS FROM mall_orders LIKE 'confirmed_at'");
if ($col_check && $col_check->num_rows > 0) {
    $steps[] = ['SKIP', '단계별 시각 컬럼이 이미 존재합니다.'];
} else {
    $sql = "ALTER TABLE `mall_orders`
        ADD COLUMN `confirmed_at` datetime DEFAULT NULL COMMENT '접수확인 처리 시각(pending->preparing)' AFTER `estimated_ready_at`,
        ADD COLUMN `ready_at` datetime DEFAULT NULL COMMENT '준비완료 처리 시각(preparing->ready)' AFTER `confirmed_at`";
    if ($conn->query($sql)) {
        $steps[] = ['OK', 'confirmed_at, ready_at 컬럼 추가 완료'];
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
    <title>단계별 시각 컬럼 DB 마이그레이션</title>
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
    <h1>🚀 단계별 시각 컬럼 DB 마이그레이션</h1>
    <p class="sub">mall_orders에 confirmed_at, ready_at 추가 · DB: <strong><?php echo DB_NAME; ?></strong></p>
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
