<?php
/**
 * 홈 화면 고정 슬롯(slot_key) 도입 DB 마이그레이션
 * 접속: http://서버주소/sunset/sql/run_add_mall_home_sections_slot_key_migration.php
 *
 * 홈 레이아웃을 "관리자가 섹션을 자유롭게 추가/삭제"하는 빌더에서 디자인 목업에 정해진
 * 고정 3슬롯(배너/오늘의특가/새로들어온상품)의 내용만 편집하는 구조로 바꾸면서
 * mall_home_sections에 slot_key 컬럼을 추가한다. SHOW COLUMNS로 이미 적용됐는지 확인해
 * 재실행해도 안전하다.
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

$table_check = $conn->query("SHOW TABLES LIKE 'mall_home_sections'");
if (!$table_check || $table_check->num_rows === 0) {
    $conn->close();
    die('mall_home_sections 테이블이 아직 없습니다. 먼저 sql/run_create_mall_home_sections_migration.php를 실행해주세요.');
}

$col_check = $conn->query("SHOW COLUMNS FROM mall_home_sections LIKE 'slot_key'");
if ($col_check && $col_check->num_rows > 0) {
    $steps[] = ['SKIP', 'slot_key 컬럼이 이미 존재합니다.'];
} else {
    if ($conn->query("ALTER TABLE `mall_home_sections` ADD COLUMN `slot_key` varchar(30) DEFAULT NULL AFTER `section_type`")) {
        $steps[] = ['OK', 'slot_key 컬럼 추가 완료'];
    } else {
        $steps[] = ['ERROR', '컬럼 추가 실패: ' . $conn->error];
    }

    if ($conn->query("ALTER TABLE `mall_home_sections` ADD UNIQUE KEY `store_slot_status` (`store_id`, `slot_key`, `status`)")) {
        $steps[] = ['OK', 'UNIQUE KEY store_slot_status 추가 완료'];
    } else {
        $steps[] = ['SKIP', 'UNIQUE KEY 추가 생략(이미 있거나 기존 데이터와 충돌): ' . $conn->error];
    }
}

// 예전에 만들었던 범용 빌더로 등록된 섹션(카테고리 바로가기 타입, slot_key 없는 것들)은
// 새 고정 슬롯 구조와 맞지 않으므로 정리 대상임을 안내만 하고 자동 삭제는 하지 않는다.
$orphan_result = $conn->query("SELECT COUNT(*) AS cnt FROM mall_home_sections WHERE slot_key IS NULL");
$orphan_count = $orphan_result ? (int)($orphan_result->fetch_assoc()['cnt'] ?? 0) : 0;
if ($orphan_count > 0) {
    $steps[] = ['SKIP', "slot_key가 없는 기존 섹션 {$orphan_count}개가 있습니다(예전 범용 빌더로 만든 것). 새 홈 레이아웃 관리 화면에는 안 보이니, 필요 없으면 나중에 DB에서 직접 정리하세요."];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>홈 화면 고정 슬롯 도입 DB 마이그레이션</title>
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
    <h1>🚀 홈 화면 고정 슬롯 도입 DB 마이그레이션</h1>
    <p class="sub">mall_home_sections에 slot_key 컬럼 추가 · DB: <strong><?php echo DB_NAME; ?></strong></p>
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
