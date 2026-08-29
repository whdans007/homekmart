<?php
/**
 * mall-home-layout v0.3 DB 마이그레이션 (초안/발행 워크플로우)
 * Design Ref: docs/02-design/features/mall-home-layout.design.md §3.3
 * 접속: http://서버주소/sunset/sql/run_mall_home_sections_v2_status_migration.php
 *
 * status/published_at/published_by 컬럼을 추가하고, 기존 행을 1회 부트스트랩 발행한다
 * (마이그레이션 직후 고객 화면이 비어 보이지 않도록). SHOW COLUMNS 사전 점검으로 재실행해도 안전.
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

// 사전 점검: 기반 테이블(module-1)이 먼저 만들어져 있어야 한다. 없으면 이후 모든 쿼리가
// "Unknown column"/"Table doesn't exist" 오류로 연쇄 실패하므로 여기서 명확히 안내하고 중단한다.
$table_check = $conn->query("SHOW TABLES LIKE 'mall_home_sections'");
if (!$table_check || $table_check->num_rows === 0) {
    $conn->close();
    die('mall_home_sections 테이블이 아직 없습니다. 먼저 sql/run_mall_home_sections_migration.php를 실행해주세요.');
}

$check = $conn->query("SHOW COLUMNS FROM mall_home_sections LIKE 'status'");
if ($check && $check->num_rows > 0) {
    $steps[] = ['SKIP', 'status 컬럼이 이미 존재합니다(마이그레이션이 이미 적용된 것으로 판단, 부트스트랩 발행도 건너뜁니다).'];
} else {
    if ($conn->query("
        ALTER TABLE `mall_home_sections`
          ADD COLUMN `status` enum('draft','published') NOT NULL DEFAULT 'draft' AFTER `is_active`,
          ADD COLUMN `published_at` timestamp NULL DEFAULT NULL AFTER `status`,
          ADD COLUMN `published_by` int(11) DEFAULT NULL AFTER `published_at`,
          ADD KEY `store_status` (`store_id`, `status`)
    ")) {
        $steps[] = ['OK', 'status/published_at/published_by 컬럼 추가 완료'];
    } else {
        $steps[] = ['ERROR', '컬럼 추가 실패: ' . $conn->error];
    }

    if ($conn->query("
        ALTER TABLE `mall_home_sections`
          ADD CONSTRAINT `mall_home_sections_ibfk_2` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ")) {
        $steps[] = ['OK', 'published_by -> users FK 추가 완료'];
    } else {
        $steps[] = ['SKIP', 'FK 추가 생략(타입 불일치 등, 기능에는 영향 없음): ' . $conn->error];
    }

    $existing_result = $conn->query("SELECT COUNT(*) AS cnt FROM mall_home_sections WHERE status = 'draft'");
    $draft_count = $existing_result ? (int)($existing_result->fetch_assoc()['cnt'] ?? 0) : 0;

    if (!$existing_result) {
        $steps[] = ['ERROR', '초안 개수 조회 실패(컬럼 추가 단계가 실패했을 수 있습니다): ' . $conn->error];
    } elseif ($draft_count > 0) {
        if ($conn->query("
            INSERT INTO mall_home_sections
              (store_id, section_type, title, subtitle, config, sort_order, is_active, status, published_at, published_by)
            SELECT store_id, section_type, title, subtitle, config, sort_order, is_active, 'published', NOW(), NULL
            FROM mall_home_sections WHERE status = 'draft'
        ")) {
            $steps[] = ['OK', "부트스트랩 발행 완료: 기존 {$draft_count}개 초안 섹션을 published로도 복제했습니다."];
        } else {
            $steps[] = ['ERROR', '부트스트랩 발행 실패: ' . $conn->error];
        }
    } else {
        $steps[] = ['SKIP', '기존 섹션이 없어 부트스트랩 발행을 생략합니다.'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>mall-home-layout v0.3 DB 마이그레이션</title>
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
    <h1>🚀 mall-home-layout v0.3 DB 마이그레이션</h1>
    <p class="sub">초안/발행(status) 컬럼 추가 + 부트스트랩 발행 · DB: <strong><?php echo DB_NAME; ?></strong></p>
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
