<?php
/**
 * 소매 회원등급 4단계 확장 마이그레이션 — 일반/할인/우수(3단계) → 일반/우수/VIP/플래티넘(4단계)
 * 접속: http://서버주소/sunset/sql/run_update_mall_retail_tiers_migration.php
 *
 * 매핑 규칙(사용자 확정):
 *   - 기존 'discount'(할인) 등급 회원 → 'good'(우수)로 이동
 *   - 기존 'vip'(당시 명칭 "우수") 등급 회원 → 'good'(우수)로 이동 — 키 이름만 바뀜, 등급 자체는 동일
 *   - 새 'vip'(신규 명칭 VIP) 키는 이제 더 높은 신규 등급을 의미 — 기존 회원은 아무도 자동 배정되지 않음
 *   - 신규 'platinum'(플래티넘) 등급도 마찬가지로 아무도 자동 배정되지 않음
 *   - mall_retail_discount_rules: 'vip' 행을 'good'으로 개명(할인율 그대로 유지), 'discount' 행은 삭제,
 *     신규 'vip'/'platinum' 행은 'good' 행과 동일한 할인율로 생성(관리자가 화면에서 바로 조정 가능)
 *
 * SHOW COLUMNS로 이미 적용됐는지 확인해 재실행해도 안전하다(이미 'good'이 있으면 SKIP).
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

$col_check = $conn->query("SHOW COLUMNS FROM mall_members LIKE 'retail_tier'");
$col_row = $col_check ? $col_check->fetch_assoc() : null;
$already_migrated = $col_row && strpos($col_row['Type'], "'good'") !== false;

if ($already_migrated) {
    $steps[] = ['SKIP', '이미 4단계(일반/우수/VIP/플래티넘) 체계로 마이그레이션되어 있습니다.'];
} else {
    // 1) 두 테이블의 enum을 우선 넓혀서 기존 값(discount/vip)과 신규 값(good/platinum)이 공존 가능하게 한다.
    if ($conn->query("ALTER TABLE `mall_members` MODIFY COLUMN `retail_tier` enum('general','discount','good','vip','platinum') NOT NULL DEFAULT 'general'")) {
        $steps[] = ['OK', 'mall_members.retail_tier enum 확장 완료'];
    } else {
        $steps[] = ['ERROR', 'mall_members.retail_tier enum 확장 실패: ' . $conn->error];
    }

    if ($conn->query("ALTER TABLE `mall_retail_discount_rules` MODIFY COLUMN `tier` enum('general','discount','good','vip','platinum') NOT NULL")) {
        $steps[] = ['OK', 'mall_retail_discount_rules.tier enum 확장 완료'];
    } else {
        $steps[] = ['ERROR', 'mall_retail_discount_rules.tier enum 확장 실패: ' . $conn->error];
    }

    // 2) 회원 데이터 이동: 기존 discount/vip → good
    if ($conn->query("UPDATE `mall_members` SET `retail_tier` = 'good' WHERE `retail_tier` IN ('discount', 'vip')")) {
        $steps[] = ['OK', '회원 등급 데이터 이동 완료(할인/우수 → 우수): ' . $conn->affected_rows . '명'];
    } else {
        $steps[] = ['ERROR', '회원 등급 데이터 이동 실패: ' . $conn->error];
    }

    // 3) 할인 규칙 테이블: 'vip' 행을 'good'으로 개명, 'discount' 행 삭제
    $vip_rule = $conn->query("SELECT discount_rate, min_cumulative_amount FROM mall_retail_discount_rules WHERE tier = 'vip'")->fetch_assoc();

    if ($conn->query("UPDATE `mall_retail_discount_rules` SET `tier` = 'good' WHERE `tier` = 'vip'")) {
        $steps[] = ['OK', "할인 규칙 'vip'(우수) → 'good'으로 개명 완료"];
    } else {
        $steps[] = ['ERROR', "할인 규칙 개명 실패: " . $conn->error];
    }

    if ($conn->query("DELETE FROM `mall_retail_discount_rules` WHERE `tier` = 'discount'")) {
        $steps[] = ['OK', "할인 규칙 'discount'(할인) 행 삭제 완료"];
    } else {
        $steps[] = ['ERROR', "할인 규칙 'discount' 삭제 실패: " . $conn->error];
    }

    // 4) 신규 등급(VIP/플래티넘) 할인 규칙 행 생성 — 우수와 동일한 할인율로 시작(관리자가 화면에서 조정)
    $seed_rate = $vip_rule ? (float)$vip_rule['discount_rate'] : 0.0;
    $seed_min = $vip_rule ? (float)$vip_rule['min_cumulative_amount'] : 0.0;

    $seed_stmt = $conn->prepare(
        "INSERT INTO mall_retail_discount_rules (tier, discount_rate, min_cumulative_amount)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE discount_rate = discount_rate"
    );
    foreach (['vip', 'platinum'] as $new_tier) {
        $seed_stmt->bind_param('sdd', $new_tier, $seed_rate, $seed_min);
        $seed_stmt->execute();
    }
    $seed_stmt->close();
    $steps[] = ['OK', "신규 등급(VIP/플래티넘) 할인 규칙 행 생성 완료(할인율 {$seed_rate}%로 시작, 화면에서 조정 가능)"];

    // 5) 이제 'discount'를 쓰는 행이 없으므로 enum에서 제거해 최종 4단계로 정리한다.
    if ($conn->query("ALTER TABLE `mall_members` MODIFY COLUMN `retail_tier` enum('general','good','vip','platinum') NOT NULL DEFAULT 'general'")) {
        $steps[] = ['OK', 'mall_members.retail_tier enum 최종 정리 완료(4단계)'];
    } else {
        $steps[] = ['ERROR', 'mall_members.retail_tier enum 최종 정리 실패: ' . $conn->error];
    }

    if ($conn->query("ALTER TABLE `mall_retail_discount_rules` MODIFY COLUMN `tier` enum('general','good','vip','platinum') NOT NULL")) {
        $steps[] = ['OK', 'mall_retail_discount_rules.tier enum 최종 정리 완료(4단계)'];
    } else {
        $steps[] = ['ERROR', 'mall_retail_discount_rules.tier enum 최종 정리 실패: ' . $conn->error];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회원등급 4단계 확장 DB 마이그레이션</title>
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
    <h1>🚀 회원등급 4단계 확장 DB 마이그레이션</h1>
    <p class="sub">일반/할인/우수 → 일반/우수/VIP/플래티넘 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요. (일부만 적용됐을 수 있으니 재실행 전 DB 상태를 확인하세요)</div>
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
