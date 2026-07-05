<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';
ord_require_manager();

try { $conn = get_ord_db(); } catch (Exception $e) { die('DB 연결 실패: ' . htmlspecialchars($e->getMessage())); }

$queries = [
    "ALTER TABLE order_vendor_column_maps ADD COLUMN IF NOT EXISTS sheet_name VARCHAR(100) DEFAULT NULL AFTER sheet_index",
    "ALTER TABLE order_vendor_column_maps ADD COLUMN IF NOT EXISTS order_unit_col VARCHAR(5) DEFAULT NULL AFTER expiry_col",
    "ALTER TABLE order_vendor_column_maps ADD COLUMN IF NOT EXISTS unit_qty_col VARCHAR(5) DEFAULT NULL AFTER order_unit_col",
    "ALTER TABLE order_vendor_column_maps ADD COLUMN IF NOT EXISTS brand_col VARCHAR(5) DEFAULT NULL AFTER unit_qty_col",
    "ALTER TABLE order_vendor_inventory_items ADD COLUMN IF NOT EXISTS order_unit VARCHAR(100) DEFAULT NULL AFTER expiry_date",
    "ALTER TABLE order_vendor_inventory_items ADD COLUMN IF NOT EXISTS unit_qty INT DEFAULT NULL AFTER order_unit",
    "ALTER TABLE order_vendor_inventory_items ADD COLUMN IF NOT EXISTS brand VARCHAR(200) DEFAULT NULL AFTER unit_qty",
    "ALTER TABLE order_vendor_inventory_items MODIFY COLUMN expiry_date VARCHAR(200) DEFAULT NULL",
    "ALTER TABLE order_vendor_inventory_items MODIFY COLUMN remark VARCHAR(1000) DEFAULT NULL",
    "ALTER TABLE order_vendor_inventory_items MODIFY COLUMN order_unit VARCHAR(200) DEFAULT NULL",
];

$results = [];
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        $results[] = ['ok' => true,  'msg' => $sql];
    } else {
        $results[] = ['ok' => false, 'msg' => $sql . ' → ' . $conn->error];
    }
}
$conn->close();
$hasError = array_filter($results, fn($r) => !$r['ok']);
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>컬럼 마이그레이션</title>
<style>body{font-family:sans-serif;max-width:800px;margin:60px auto;padding:0 20px}
.ok{background:#f0fdf4;color:#15803d;padding:8px 12px;border-radius:6px;margin-bottom:6px;font-size:12px;word-break:break-all}
.fail{background:#fef2f2;color:#dc2626;padding:8px 12px;border-radius:6px;margin-bottom:6px;font-size:12px;word-break:break-all}
.sum{margin-top:20px;padding:12px 16px;border-radius:8px;font-weight:bold}
.sum.ok{background:#dcfce7;color:#166534}.sum.fail{background:#fee2e2;color:#991b1b}</style></head><body>
<h2 style="color:#4338ca">발주 시스템 컬럼 마이그레이션</h2>
<p style="color:#6b7280;font-size:13px">발주단위(order_unit) + 입수량(unit_qty) 컬럼 추가</p>
<?php foreach ($results as $r): ?>
<div class="<?php echo $r['ok'] ? 'ok' : 'fail'; ?>"><?php echo $r['ok'] ? '✅' : '❌'; ?> <?php echo htmlspecialchars($r['msg']); ?></div>
<?php endforeach; ?>
<div class="sum <?php echo $hasError ? 'fail' : 'ok'; ?>">
    <?php echo $hasError ? '❌ 일부 실패 — 위 오류를 확인하세요' : '✅ 완료 — 이 파일을 삭제하세요'; ?>
</div>
</body></html>
