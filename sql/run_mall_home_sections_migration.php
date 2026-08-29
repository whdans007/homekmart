<?php
/**
 * mall-home-layout 기능 DB 마이그레이션 (mall_home_sections.sql 적용)
 * Design Ref: docs/02-design/features/mall-home-layout.design.md §3.3, §11.2 1단계
 * 접속: http://서버주소/sunset/sql/run_mall_home_sections_migration.php
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

// 사전 점검: 폐기된 이전 레이아웃 빌더 테이블명과의 충돌 여부 확인
$legacy_names = ['layout_rows', 'layout_columns', 'layout_presets', 'display_sections', 'product_displays'];
$legacy_found = [];
foreach ($legacy_names as $name) {
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($name) . "'");
    if ($r && $r->num_rows > 0) {
        $legacy_found[] = $name;
    }
}

$check = $conn->query("SHOW TABLES LIKE 'mall_home_sections'");
if ($check && $check->num_rows > 0) {
    $steps[] = ['SKIP', 'mall_home_sections 테이블이 이미 존재합니다.'];
} elseif ($conn->query("
    CREATE TABLE `mall_home_sections` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `store_id` int(11) UNSIGNED NOT NULL,
      `section_type` enum('banner','category_shortcut','product_list') NOT NULL,
      `title` varchar(255) DEFAULT NULL,
      `subtitle` varchar(255) DEFAULT NULL,
      `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
      `sort_order` int(11) NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `store_id` (`store_id`),
      KEY `store_sort` (`store_id`, `sort_order`),
      CONSTRAINT `mall_home_sections_ibfk_1` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 홈 화면 섹션(드래그앤드롭 레이아웃)'
")) {
    $steps[] = ['OK', 'mall_home_sections 테이블 생성 완료'];
} else {
    $steps[] = ['ERROR', 'mall_home_sections 생성 실패: ' . $conn->error];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>mall-home-layout DB 마이그레이션</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:700px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        .ok{color:#059669}.skip{color:#9ca3af}.error{color:#dc2626}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
    </style>
</head>
<body>
<div class="card">
    <h1>🧩 mall-home-layout DB 마이그레이션</h1>
    <p class="sub">mall_home_sections.sql 적용 · DB: <strong><?php echo DB_NAME; ?></strong></p>
</div>

<?php if (!empty($legacy_found)): ?>
<div class="card">
    <div class="warn">⚠️ 폐기된 이전 레이아웃 빌더 테이블이 라이브 DB에 남아있습니다: <?php echo htmlspecialchars(implode(', ', $legacy_found)); ?>. mall_home_sections는 이름이 겹치지 않지만, 잔여 테이블 정리를 검토하세요.</div>
</div>
<?php endif; ?>

<div class="card">
    <?php $has_error = false; foreach ($steps as [$s]) { if ($s === 'ERROR') $has_error = true; } ?>
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 실패. 아래 오류를 확인하세요.</div>
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
