<?php
/**
 * 창고 관리 기능 취소 — 서버 정리 도구
 *
 * 배경: 창고 입고/매장출고(내부소모) 기능을 테스트하는 동안, 그 코드는 매장의 실제
 * 판매 재고(inventory) 테이블을 직접 증감시켰다. 기능 자체를 취소하기로 했으므로,
 * 테스트로 인해 틀어진 매장 재고 수량을 원래대로 되돌려야 한다.
 *
 * 계산 방식: warehouse_inbound_logs(입고 합) - warehouse_outbound_logs(내부소모 합)
 *          = 테스트 중 inventory에 순수하게 더해진 양(net)
 * 이 도구는 inventory.quantity에서 net만큼 빼서 테스트 이전 상태로 되돌린다.
 *
 * 주의: 유통기한별 로트(inventory_expirations)는 자동으로 되돌리지 않는다 — 그 사이
 * 실제 매입/판매/폐기 등으로 로트가 계속 바뀌었을 수 있어 자동 역산이 위험하기 때문이다.
 * warehouse_inventory_expirations 테이블에 남은 기록을 참고해 필요시 수동으로 확인하세요.
 *
 * 접속: http://서버주소/admin/warehouse_cleanup_revert.php
 * 실행 후 이 파일은 반드시 삭제하세요.
 */
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
ensure_logged_in();

if (current_user_level() < LEVEL_BRANCH_MANAGER) {
    http_response_code(403);
    exit('실제 매장 재고 수량을 변경하는 도구입니다. 점장 이상만 접근할 수 있습니다.');
}

$conn = get_db_connection();

function table_exists($conn, $table) {
    $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    return $r && $r->num_rows > 0;
}

$missing_tables = [];
foreach (['warehouse_inbound_logs', 'warehouse_outbound_logs'] as $t) {
    if (!table_exists($conn, $t)) $missing_tables[] = $t;
}

// 이미 되돌렸는지 확인하는 마커
$conn->query("CREATE TABLE IF NOT EXISTS `warehouse_cleanup_status` (
    `id` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
    `reverted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reverted_by` int(11) UNSIGNED DEFAULT NULL,
    `row_count` int(11) DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$already_reverted = null;
$marker = $conn->query("SELECT reverted_at, row_count FROM warehouse_cleanup_status WHERE id = 1 LIMIT 1");
if ($marker && $marker->num_rows > 0) {
    $already_reverted = $marker->fetch_assoc();
}

$preview = [];
$expiration_rows = [];
$error = '';
$applied = false;
$apply_error = '';

if (empty($missing_tables) && !$already_reverted) {
    $sql = "SELECT
                p.id AS product_id, p.sku, p.name_ko,
                inb.store_id,
                COALESCE(inb.total_in, 0) AS total_in,
                COALESCE(outb.total_out, 0) AS total_out,
                (COALESCE(inb.total_in, 0) - COALESCE(outb.total_out, 0)) AS net,
                COALESCE(inv.quantity, 0) AS current_inventory_quantity
            FROM (
                SELECT store_id, product_id, SUM(quantity) AS total_in
                FROM warehouse_inbound_logs GROUP BY store_id, product_id
            ) inb
            LEFT JOIN (
                SELECT store_id, product_id, SUM(quantity) AS total_out
                FROM warehouse_outbound_logs GROUP BY store_id, product_id
            ) outb ON outb.store_id = inb.store_id AND outb.product_id = inb.product_id
            LEFT JOIN products p ON p.id = inb.product_id
            LEFT JOIN inventory inv ON inv.product_id = inb.product_id AND inv.store_id = inb.store_id
            HAVING net <> 0
            ORDER BY p.name_ko";
    $result = $conn->query($sql);
    if ($result) {
        $preview = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error = 'DB 오류: ' . $conn->error;
    }

    if (table_exists($conn, 'warehouse_inventory_expirations')) {
        $exp_result = $conn->query("SELECT wie.store_id, p.sku, p.name_ko, wie.expiration_date, wie.quantity
                                     FROM warehouse_inventory_expirations wie
                                     LEFT JOIN products p ON p.id = wie.product_id
                                     ORDER BY wie.store_id, p.name_ko");
        if ($exp_result) {
            $expiration_rows = $exp_result->fetch_all(MYSQLI_ASSOC);
        }
    }
}

// 되돌리기 적용
if (empty($missing_tables) && !$already_reverted && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    $conn->begin_transaction();
    try {
        foreach ($preview as $row) {
            $net = (int)$row['net'];
            if ($net === 0) continue;

            $upd = $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE product_id = ? AND store_id = ?");
            $upd->bind_param("iii", $net, $row['product_id'], $row['store_id']);
            $upd->execute();
            $upd->close();
        }

        $mark = $conn->prepare("INSERT INTO warehouse_cleanup_status (id, reverted_by, row_count) VALUES (1, ?, ?)");
        $count = count($preview);
        $mark->bind_param("ii", $_SESSION['user_id'], $count);
        $mark->execute();
        $mark->close();

        $conn->commit();
        $applied = true;
    } catch (Exception $e) {
        $conn->rollback();
        $apply_error = $e->getMessage();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>창고 기능 취소 — 재고 되돌리기</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f9fafb; margin: 0; padding: 2rem; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.5rem; max-width: 900px; margin: 0 auto 1rem; }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 .25rem; color: #111; }
        .sub { color: #6b7280; font-size: .875rem; }
        table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        td, th { padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; text-align: left; }
        th { color: #6b7280; font-weight: 500; }
        .warn { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #92400e; margin-bottom: 1rem; }
        .success-box { background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #065f46; margin-bottom: 1rem; }
        .err-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 1rem; font-size: .875rem; color: #991b1b; margin-bottom: 1rem; }
        button { padding: .75rem 1.5rem; background: #dc2626; color: #fff; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        button:hover { background: #b91c1c; }
        code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: .8rem; }
        .num { text-align: right; }
        ol li { margin-bottom: .5rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>창고 기능 취소 — 매장 재고 되돌리기</h1>
    <p class="sub">창고 입고/매장출고 테스트로 변경된 매장의 실제 재고(inventory) 수량을 원래대로 되돌립니다.</p>
</div>

<?php if (!empty($missing_tables)): ?>
<div class="card">
    <div class="warn">⚠️ 필요한 테이블(<?php echo implode(', ', $missing_tables); ?>)이 없습니다. 이미 삭제되었다면 되돌릴 데이터가 없는 것입니다.</div>
</div>

<?php elseif ($already_reverted): ?>
<div class="card">
    <div class="success-box">✅ 이미 <?php echo htmlspecialchars($already_reverted['reverted_at']); ?>에 <?php echo (int)$already_reverted['row_count']; ?>건을 되돌렸습니다. 중복 적용 방지를 위해 다시 실행되지 않습니다.</div>
    <p class="sub">아래 "완료 후 정리" 순서대로 남은 테이블/파일을 삭제하세요.</p>
</div>

<?php elseif ($applied): ?>
<div class="card">
    <div class="success-box">✅ 되돌리기가 완료되었습니다. <?php echo count($preview); ?>개 상품의 매장 재고 수량을 원래대로 복원했습니다.</div>
</div>

<?php elseif ($apply_error): ?>
<div class="card">
    <div class="err-box">❌ 처리 중 오류가 발생했습니다: <?php echo htmlspecialchars($apply_error); ?> (변경 사항은 모두 롤백되었습니다)</div>
</div>

<?php elseif ($error): ?>
<div class="card">
    <div class="err-box">❌ <?php echo htmlspecialchars($error); ?></div>
</div>

<?php elseif (empty($preview)): ?>
<div class="card">
    <div class="success-box">✅ 되돌릴 항목이 없습니다 (창고 입출고로 인한 재고 변동 기록이 없습니다).</div>
</div>

<?php else: ?>
<div class="card">
    <div class="warn">
        ⚠️ 아래 <?php echo count($preview); ?>개 상품의 매장 실제 재고(inventory) 수량이 변경됩니다. 내용을 확인 후 진행하세요.
    </div>
    <table>
        <thead>
            <tr>
                <th>매장 ID</th>
                <th>바코드</th>
                <th>상품명</th>
                <th class="num">창고 입고 합</th>
                <th class="num">창고 내부소모 합</th>
                <th class="num">되돌릴 양</th>
                <th class="num">현재 매장 재고</th>
                <th class="num">되돌린 후 재고</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($preview as $row): ?>
            <tr>
                <td><?php echo (int)$row['store_id']; ?></td>
                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                <td><?php echo htmlspecialchars($row['name_ko']); ?></td>
                <td class="num"><?php echo (int)$row['total_in']; ?></td>
                <td class="num"><?php echo (int)$row['total_out']; ?></td>
                <td class="num"><strong><?php echo (int)$row['net']; ?></strong></td>
                <td class="num"><?php echo (int)$row['current_inventory_quantity']; ?></td>
                <td class="num"><?php echo (int)$row['current_inventory_quantity'] - (int)$row['net']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form method="post" style="margin-top:1rem;">
        <input type="hidden" name="action" value="apply">
        <button type="submit" onclick="return confirm('매장의 실제 재고 수량을 변경합니다. 위 내용을 확인했으며 계속 진행하시겠습니까?');">
            되돌리기 적용
        </button>
    </form>
</div>
<?php endif; ?>

<?php if (!empty($expiration_rows)): ?>
<div class="card">
    <div class="warn">
        ⚠️ 아래는 창고 입고 시 유통기한을 입력했던 기록입니다 (warehouse_inventory_expirations, 자동으로 되돌리지 않음).
        해당 상품의 실제 유통기한 로트(inventory_expirations)가 이 수량만큼 부풀려져 있을 수 있으니, 유통기한 관리 화면(점검기록)에서 직접 확인해주세요.
    </div>
    <table>
        <thead><tr><th>매장 ID</th><th>바코드</th><th>상품명</th><th>유통기한</th><th class="num">수량</th></tr></thead>
        <tbody>
        <?php foreach ($expiration_rows as $row): ?>
            <tr>
                <td><?php echo (int)$row['store_id']; ?></td>
                <td><?php echo htmlspecialchars($row['sku']); ?></td>
                <td><?php echo htmlspecialchars($row['name_ko']); ?></td>
                <td><?php echo htmlspecialchars($row['expiration_date']); ?></td>
                <td class="num"><?php echo (int)$row['quantity']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <p style="font-weight:600;color:#374151;margin:0 0 .5rem;">완료 후 정리 순서</p>
    <ol class="sub">
        <li>위 되돌리기를 적용했거나 대상이 없음을 확인했다면, phpMyAdmin 등에서 다음 테이블을 삭제하세요:
            <code>DROP TABLE IF EXISTS warehouse_inbound_logs, warehouse_outbound_logs, warehouse_inventory, warehouse_inventory_expirations, warehouse_transfer_logs, warehouse_cleanup_status;</code>
        </li>
        <li>서버에서 다음 파일들을 삭제하세요: <code>admin/warehouse_inbound.php</code>, <code>admin/warehouse_outbound.php</code>, <code>admin/ajax_warehouse_*.php</code> (전체), <code>admin/partials/warehouse_nav.php</code>, <code>admin/warehouse_run_migration.php</code>, <code>admin/warehouse_migrate_legacy_inventory.php</code>(업로드했다면), <code>admin/warehouse_cleanup_revert.php</code>(이 파일 자신), <code>lib/warehouse_helper.php</code></li>
        <li><code>admin/partials/header.php</code>에 "창고 관리" 메뉴가 남아있다면 로컬의 원복된 버전으로 다시 업로드하세요.</li>
        <li><code>lang/ko.json</code>, <code>lang/en.json</code>도 로컬의 원복된 버전으로 다시 업로드하세요.</li>
    </ol>
</div>

</body>
</html>
