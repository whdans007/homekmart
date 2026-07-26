<?php
/**
 * KIM'S MALL 창고 DB 마이그레이션 v2
 * 상품 마스터 개편: 영문/한글명, 브랜드, 카테고리, 바코드 4종
 * 접속: http://서버주소/sunset/kimsmall_wherehouse/sql/run_migration_v2.php
 * 주의: 실행 후 이 파일을 삭제하세요.
 */
require_once __DIR__ . '/../../config/db_config.php';

$steps = [
    'kw_brands 생성' => "CREATE TABLE IF NOT EXISTS kw_brands (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name_en VARCHAR(100) NOT NULL COMMENT '브랜드 영문명',
        name_ko VARCHAR(100) DEFAULT NULL COMMENT '브랜드 한글명',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 브랜드'",

    'kw_categories 생성' => "CREATE TABLE IF NOT EXISTS kw_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name_en VARCHAR(100) NOT NULL COMMENT '카테고리 영문명',
        name_ko VARCHAR(100) DEFAULT NULL COMMENT '카테고리 한글명',
        parent_id INT DEFAULT NULL COMMENT '상위 카테고리',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (parent_id) REFERENCES kw_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='물류 카테고리'",

    'name → name_en 변경' => "ALTER TABLE kw_products
        CHANGE COLUMN name name_en VARCHAR(200) NOT NULL COMMENT '영문 상품명 (필수)'",

    'name_ko 추가' => "ALTER TABLE kw_products
        ADD COLUMN name_ko VARCHAR(200) DEFAULT NULL COMMENT '한글 상품명 (선택)' AFTER name_en",

    'brand_id, category_id 추가' => "ALTER TABLE kw_products
        ADD COLUMN brand_id    INT DEFAULT NULL COMMENT 'kw_brands.id'    AFTER name_ko,
        ADD COLUMN category_id INT DEFAULT NULL COMMENT 'kw_categories.id' AFTER brand_id",

    '바코드 4종 컬럼 추가' => "ALTER TABLE kw_products
        ADD COLUMN barcode_unit      VARCHAR(100) DEFAULT NULL COMMENT '바코드'      AFTER category_id,
        ADD COLUMN barcode_box       VARCHAR(100) DEFAULT NULL COMMENT '박스 바코드' AFTER barcode_unit,
        ADD COLUMN barcode_logistics VARCHAR(100) DEFAULT NULL COMMENT '물류코드'      AFTER barcode_box",

    '기존 barcode → barcode_unit 복사' => "UPDATE kw_products SET barcode_unit = barcode WHERE barcode IS NOT NULL AND barcode != ''",

    'barcode 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN barcode",

    'sku 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN sku",

    'supplier_id 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN supplier_id",

    'cost_price 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN cost_price",

    'selling_price 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN selling_price",

    'category 텍스트 컬럼 제거' => "ALTER TABLE kw_products DROP COLUMN category",

    'brand FK 추가' => "ALTER TABLE kw_products
        ADD CONSTRAINT fk_kw_products_brand FOREIGN KEY (brand_id) REFERENCES kw_brands(id) ON DELETE SET NULL",

    'category FK 추가' => "ALTER TABLE kw_products
        ADD CONSTRAINT fk_kw_products_category FOREIGN KEY (category_id) REFERENCES kw_categories(id) ON DELETE SET NULL",
];

// 현재 컬럼 상태 조회
$conn_check = null;
$current_cols = [];
$db_ver = '';
try {
    $conn_check = get_db_connection();
    $row = $conn_check->query("SELECT VERSION() AS v")->fetch_assoc();
    $db_ver = $row['v'] ?? '';
    $res = $conn_check->query("SHOW COLUMNS FROM kw_products");
    if ($res) {
        while ($r = $res->fetch_assoc()) $current_cols[] = $r['Field'];
    }
    $conn_check->close();
} catch (Exception $e) {
    $connect_error = $e->getMessage();
}

$results = [];
$has_error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    try {
        $conn = get_db_connection();
        foreach ($steps as $label => $sql) {
            try {
                $conn->query($sql);
                $results[$label] = ['status' => 'ok', 'msg' => '완료'];
            } catch (Exception $e) {
                $msg = $e->getMessage();
                // 이미 존재하거나 없는 컬럼이면 skip
                if (str_contains($msg, 'Duplicate') || str_contains($msg, "Can't DROP") ||
                    str_contains($msg, 'already exists') || str_contains($msg, "check that column/key exists") ||
                    str_contains($msg, 'Unknown column')) {
                    $results[$label] = ['status' => 'skip', 'msg' => '이미 처리됨 (건너뜀)'];
                } else {
                    $results[$label] = ['status' => 'error', 'msg' => $msg];
                    $has_error = true;
                }
            }
        }
        // 변경 후 컬럼 목록 갱신
        $res = $conn->query("SHOW COLUMNS FROM kw_products");
        $current_cols = [];
        if ($res) while ($r = $res->fetch_assoc()) $current_cols[] = $r['Field'];
        $conn->close();
    } catch (Exception $e) {
        $has_error = true;
        $results['_연결오류'] = ['status' => 'error', 'msg' => $e->getMessage()];
    }
}

$already_done = in_array('name_en', $current_cols) && !in_array('name', $current_cols);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KIM'S MALL 창고 DB 마이그레이션 v2</title>
    <style>
        body{font-family:-apple-system,sans-serif;background:#f9fafb;margin:0;padding:2rem}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:700px;margin:0 auto 1rem}
        h1{font-size:1.2rem;font-weight:700;margin:0 0 .25rem;color:#111}
        .sub{color:#6b7280;font-size:.875rem}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        td,th{padding:.5rem .75rem;border-bottom:1px solid #f3f4f6;text-align:left}
        th{color:#6b7280;font-weight:500;font-size:.8rem}
        .ok{color:#059669}.skip{color:#9ca3af}.err{color:#dc2626}
        .warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:1rem;font-size:.875rem;color:#92400e;margin-bottom:1rem}
        .success-box{background:#ecfdf5;border:1px solid #6ee7b7;border-radius:6px;padding:1rem;font-size:.875rem;color:#065f46;margin-bottom:1rem}
        .err-box{background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:1rem;font-size:.875rem;color:#991b1b;margin-bottom:1rem}
        button{width:100%;padding:.75rem;background:#0d9488;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer}
        button:hover{background:#0f766e}
        a{color:#0d9488}
        code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.8rem}
        .mono{font-family:monospace;font-size:.8rem}
        .badge{display:inline-block;padding:1px 6px;border-radius:9999px;font-size:.75rem}
        .badge-green{background:#d1fae5;color:#065f46}
        .badge-gray{background:#f3f4f6;color:#6b7280}
    </style>
</head>
<body>
<div class="card">
    <h1>🏭 KIM'S MALL 창고 DB 마이그레이션 v2</h1>
    <p class="sub">상품 마스터 개편 · DB: <strong><?php echo DB_NAME; ?></strong> · <?php echo htmlspecialchars($db_ver); ?></p>
</div>

<?php if (isset($connect_error)): ?>
<div class="card"><div class="err-box">❌ <?php echo htmlspecialchars($connect_error); ?></div></div>
<?php else: ?>

<?php if (!empty($results)): ?>
<div class="card">
    <?php if ($has_error): ?>
    <div class="err-box">⚠️ 일부 단계 실패. 아래 오류를 확인하세요.</div>
    <?php else: ?>
    <div class="success-box">✅ 마이그레이션 v2 완료! <a href="<?php echo '/sunset/kimsmall_wherehouse/login.php'; ?>">KIM'S MALL 창고 →</a></div>
    <p class="sub">⚠️ 보안을 위해 이 파일을 삭제하세요.</p>
    <?php endif; ?>
    <table>
        <thead><tr><th>단계</th><th>결과</th></tr></thead>
        <tbody>
        <?php foreach ($results as $label => $r): ?>
        <tr>
            <td><?php echo htmlspecialchars($label); ?></td>
            <td class="<?php echo $r['status']; ?>">
                <?php echo $r['status'] === 'ok' ? '✅' : ($r['status'] === 'skip' ? '➖' : '❌'); ?>
                <?php echo htmlspecialchars($r['msg']); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- 현재 kw_products 컬럼 상태 -->
<div class="card">
    <p style="font-size:.875rem;font-weight:600;color:#374151;margin:0 0 .75rem">kw_products 현재 컬럼</p>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
        <?php foreach ($current_cols as $col): ?>
        <span class="badge <?php echo in_array($col, ['name_en','name_ko','brand_id','category_id','barcode_unit','barcode_box','barcode_logistics']) ? 'badge-green' : 'badge-gray'; ?>">
            <?php echo $col; ?>
        </span>
        <?php endforeach; ?>
    </div>
    <p class="sub" style="margin-top:.75rem">
        초록: 새 컬럼 · 회색: 기존 컬럼
    </p>
</div>

<?php if ($already_done && empty($results)): ?>
<div class="card">
    <div class="success-box">✅ 이미 v2 마이그레이션이 완료된 상태입니다. <a href="/sunset/kimsmall_wherehouse/login.php">KIM'S MALL 창고 →</a></div>
</div>
<?php elseif (empty($results)): ?>
<div class="card">
    <div class="warn">
        ⚠️ <strong>주의:</strong> 기존 컬럼(<code>name→name_en</code>, <code>barcode→barcode_unit</code>) 변환이 포함됩니다.
        기존 데이터가 있는 경우 자동으로 새 컬럼으로 복사됩니다.
        <code>cost_price</code>, <code>selling_price</code>, <code>supplier_id</code>, <code>sku</code>는 <strong>삭제</strong>됩니다.
    </div>
    <form method="post">
        <input type="hidden" name="action" value="run">
        <button type="submit" onclick="return confirm('kw_products v2 마이그레이션을 실행하시겠습니까?\n\n주의: cost_price, selling_price, sku, supplier_id 컬럼이 삭제됩니다.')">
            마이그레이션 v2 실행
        </button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
