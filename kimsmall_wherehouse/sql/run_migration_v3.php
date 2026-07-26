<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v3
 * kw_brands, kw_categories: name → name_en / name_ko 분리
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v3.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

$steps = [
    'kw_brands name_en 변경' => "ALTER TABLE kw_brands
        CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '브랜드 영문명'",

    'kw_brands name_ko 추가' => "ALTER TABLE kw_brands
        ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '브랜드 한글명' AFTER name_en",

    'kw_categories name_en 변경' => "ALTER TABLE kw_categories
        CHANGE COLUMN name name_en VARCHAR(100) NOT NULL COMMENT '카테고리 영문명'",

    'kw_categories name_ko 추가' => "ALTER TABLE kw_categories
        ADD COLUMN name_ko VARCHAR(100) DEFAULT NULL COMMENT '카테고리 한글명' AFTER name_en",
];

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');

$results = [];
foreach ($steps as $label => $sql) {
    try {
        $conn->query($sql);
        $results[] = ['step' => $label, 'status' => 'OK'];
    } catch (mysqli_sql_exception $e) {
        $msg = $e->getMessage();
        // v2에서 kw_brands/kw_categories를 name_en/name_ko로 직접 생성한 경우
        // 이 단계는 이미 적용된 것으로 보고 건너뜀 (재실행 안전)
        if (str_contains($msg, 'Unknown column') || str_contains($msg, 'Duplicate column') ||
            str_contains($msg, 'check that column/key exists')) {
            $results[] = ['step' => $label, 'status' => 'SKIP', 'msg' => '이미 처리됨 (건너뜀)'];
        } else {
            $results[] = ['step' => $label, 'status' => 'ERROR', 'msg' => $msg];
        }
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head><meta charset="UTF-8"><title>Migration v3</title>
<style>body{font-family:monospace;padding:2em;} .ok{color:green;} .err{color:red;} .skip{color:#9ca3af;}</style>
</head>
<body>
<h2>Migration v3 결과</h2>
<ul>
<?php foreach ($results as $r): ?>
<li class="<?php echo $r['status']==='OK'?'ok':($r['status']==='SKIP'?'skip':'err'); ?>">
    [<?php echo $r['status']; ?>] <?php echo htmlspecialchars($r['step']); ?>
    <?php if (!empty($r['msg'])): ?> — <?php echo htmlspecialchars($r['msg']); ?><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<p><strong>완료 후 이 파일을 삭제하세요.</strong></p>
</body>
</html>
