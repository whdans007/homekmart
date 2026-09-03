<?php
/**
 * 수수료 매장 수수료율(%) 컬럼 DB 마이그레이션
 * 접속: http://서버주소/sunset/sql/run_add_commission_rate_migration.php
 *
 * daily_report_commission_companies에 commission_rate 컬럼을 추가한다.
 * office/product_purchase/monthly_closing.php의 "수수료 매장" 표에서 업체별 고정 수수료율(%)로
 * 수수료/지급액을 계산하는 데 사용한다 (Daily Report의 수수료 코너 업체 등록 테이블을 그대로 공유).
 * SHOW COLUMNS로 이미 적용됐는지 확인해 재실행해도 안전하다.
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

$steps = [];

$tbl_check = $conn->query("SHOW TABLES LIKE 'daily_report_commission_companies'");
if (!$tbl_check || $tbl_check->num_rows === 0) {
    $steps[] = ['ERROR', 'daily_report_commission_companies 테이블이 없습니다. Daily Report 화면에서 수수료 코너 업체를 먼저 하나 이상 등록하세요.'];
} else {
    $col_check = $conn->query("SHOW COLUMNS FROM daily_report_commission_companies LIKE 'commission_rate'");
    if ($col_check && $col_check->num_rows > 0) {
        $steps[] = ['SKIP', 'commission_rate 컬럼이 이미 존재합니다.'];
    } else {
        if ($conn->query("ALTER TABLE `daily_report_commission_companies` ADD COLUMN `commission_rate` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `supplier_name`")) {
            $steps[] = ['OK', 'commission_rate 컬럼 추가 완료'];
        } else {
            $steps[] = ['ERROR', '컬럼 추가 실패: ' . $conn->error];
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>수수료율 컬럼 DB 마이그레이션</title>
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
    <h1>🚀 수수료율(%) 컬럼 DB 마이그레이션</h1>
    <p class="sub">daily_report_commission_companies에 commission_rate 추가 · DB: <strong><?php echo DB_NAME; ?></strong></p>
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
