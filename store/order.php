<?php
// 편집 모드: 센터 승인 전(pending) 주문의 품목 추가/삭제/수량변경
$edit_order_id = (int)($_GET['edit'] ?? $_POST['edit_order_id'] ?? 0);
$page_title    = $edit_order_id ? 'Edit Order' : 'Place Order';
require_once __DIR__ . '/partials/header.php';

$store_id   = store_current_store_id();
$errors     = [];
$edit_notes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    store_verify_csrf();

    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity']   ?? [];
    $order_units = $_POST['order_unit'] ?? [];
    $notes       = trim($_POST['notes'] ?? '');

    $items = [];
    foreach ($product_ids as $i => $pid) {
        $pid  = (int)$pid;
        $qty  = (int)($quantities[$i] ?? 0);
        $unit = strtoupper(trim($order_units[$i] ?? ''));
        $unit = in_array($unit, ['BOX', 'PACK', 'PCS'], true) ? $unit : 'PCS';
        if ($pid > 0 && $qty > 0) {
            $items[] = [
                'product_id' => $pid,
                'quantity'   => $qty,
                'order_unit' => $unit,
            ];
        }
    }

    if (empty($items)) {
        $errors[] = 'Enter a quantity of 1 or more for each product.';
    }

    if (empty($errors)) {
        try {
            $conn = get_store_db();

            // 상품 정보 + 단위별 재고/단가 검증
            // 다른 점포가 먼저 주문/승인되어 재고가 모자란 경우 주문 전체를 막지 않고
            // 남은 재고 수량만큼만 자동으로 조정해서 주문을 진행한다 (완전 품절 품목만 제외).
            $stock_notices = [];
            $checked_items = [];
            foreach ($items as $item) {
                $st = $conn->prepare(
                    "SELECT CONCAT(name_en, IFNULL(CONCAT(' (',name_ko,')'),'')) AS pname,
                            GREATEST(1, IFNULL(pieces_per_box,1)) AS ppb
                     FROM lc_products WHERE id = ?"
                );
                $st->bind_param('i', $item['product_id']); $st->execute();
                $pr = $st->get_result()->fetch_assoc(); $st->close();
                $item['pname']          = $pr['pname'] ?? "#{$item['product_id']}";
                $item['pieces_per_box'] = (int)($pr['ppb'] ?? 1);

                $st = $conn->prepare(
                    "SELECT COALESCE(SUM(quantity_remain),0) FROM lc_inventory
                     WHERE product_id = ? AND unit = ?"
                );
                $st->bind_param('is', $item['product_id'], $item['order_unit']); $st->execute();
                $stock = (int)$st->get_result()->fetch_row()[0]; $st->close();

                if ($stock <= 0) {
                    $stock_notices[] = "'{$item['pname']}' is out of {$item['order_unit']} stock and was removed from the order.";
                    continue;
                }
                if ($stock < $item['quantity']) {
                    $stock_notices[] = "'{$item['pname']}' quantity adjusted from {$item['quantity']} to {$stock} {$item['order_unit']} (limited stock).";
                    $item['quantity'] = $stock;
                }

                if ($item['order_unit'] === 'PCS') {
                    $price_sql =
                        "SELECT COALESCE(SUM(inv.quantity_remain * IF(ib.inbound_unit IN ('BOX','PACK'), ib.cost_price_pcs, ib.cost_price)) / NULLIF(SUM(inv.quantity_remain),0), 0)
                         FROM lc_inventory inv JOIN lc_inbound ib ON inv.inbound_id = ib.id
                         WHERE inv.product_id = ? AND inv.unit = 'PCS' AND inv.quantity_remain > 0";
                    $st = $conn->prepare($price_sql);
                    $st->bind_param('i', $item['product_id']);
                } else {
                    // BOX/PACK: 묶음 단위는 입고 원가를 그대로 사용
                    $price_sql =
                        "SELECT COALESCE(SUM(inv.quantity_remain * ib.cost_price) / NULLIF(SUM(inv.quantity_remain),0), 0)
                         FROM lc_inventory inv JOIN lc_inbound ib ON inv.inbound_id = ib.id
                         WHERE inv.product_id = ? AND inv.unit = ? AND inv.quantity_remain > 0 AND ib.cost_price > 0";
                    $st = $conn->prepare($price_sql);
                    $st->bind_param('is', $item['product_id'], $item['order_unit']);
                }
                $st->execute();
                $item['unit_price'] = (float)$st->get_result()->fetch_row()[0]; $st->close();

                $checked_items[] = $item;
            }
            $items = $checked_items;

            if (empty($items)) {
                $errors[] = 'All selected products are currently out of stock. Please refresh the page and try again.';
            }

            if (empty($errors) && $edit_order_id > 0) {
                // ── 편집 모드: pending 주문 품목 교체 ──────────────────
                $conn->autocommit(false);
                $total_amount = array_sum(array_map(fn($it) => $it['quantity'] * $it['unit_price'], $items));

                // 잠금 후 본인 점포 + pending 상태 재확인 (승인 사이 변경 방지)
                $st = $conn->prepare("SELECT status FROM lc_orders WHERE id = ? AND store_id = ? FOR UPDATE");
                $st->bind_param('ii', $edit_order_id, $store_id);
                $st->execute();
                $cur = $st->get_result()->fetch_assoc();
                $st->close();

                if (!$cur) {
                    $conn->rollback(); $conn->close();
                    $errors[] = 'Order not found.';
                } elseif ($cur['status'] !== 'pending') {
                    $conn->rollback(); $conn->close();
                    $errors[] = 'This order has already been approved by the center and can no longer be edited.';
                } else {
                    $st = $conn->prepare("UPDATE lc_orders SET total_amount = ?, notes = ? WHERE id = ?");
                    $st->bind_param('dsi', $total_amount, $notes, $edit_order_id);
                    $st->execute();
                    $st->close();

                    $st = $conn->prepare("DELETE FROM lc_order_items WHERE order_id = ?");
                    $st->bind_param('i', $edit_order_id);
                    $st->execute();
                    $st->close();

                    $st2 = $conn->prepare(
                        "INSERT INTO lc_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price) VALUES (?,?,?,?,?,?)"
                    );
                    foreach ($items as $item) {
                        $st2->bind_param('iiisid', $edit_order_id, $item['product_id'], $item['quantity'], $item['order_unit'], $item['pieces_per_box'], $item['unit_price']);
                        $st2->execute();
                    }
                    $st2->close();

                    $conn->commit(); $conn->close();
                    $flash_msg = 'Order #' . str_pad($edit_order_id, 4, '0', STR_PAD_LEFT) . ' has been updated.';
                    if (!empty($stock_notices)) $flash_msg .= "\n" . implode("\n", $stock_notices);
                    store_set_flash(empty($stock_notices) ? 'success' : 'warning', $flash_msg);
                    header('Location: ' . STORE_BASE . '/order_detail.php?id=' . $edit_order_id);
                    exit;
                }
            } elseif (empty($errors)) {
                $conn->autocommit(false);
                $uid   = store_current_user_id();
                $today = date('Y-m-d');

                $total_amount = array_sum(array_map(fn($it) => $it['quantity'] * $it['unit_price'], $items));

                $st = $conn->prepare(
                    "INSERT INTO lc_orders (order_date, store_id, status, total_amount, notes, created_by)
                     VALUES (?, ?, 'pending', ?, ?, ?)"
                );
                $st->bind_param('ssdsi', $today, $store_id, $total_amount, $notes, $uid);
                $st->execute();
                $order_id = $conn->insert_id;
                $st->close();

                $st2 = $conn->prepare(
                    "INSERT INTO lc_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price) VALUES (?,?,?,?,?,?)"
                );
                foreach ($items as $item) {
                    $st2->bind_param('iiisid', $order_id, $item['product_id'], $item['quantity'], $item['order_unit'], $item['pieces_per_box'], $item['unit_price']);
                    $st2->execute();
                }
                $st2->close();

                $conn->commit(); $conn->close();
                $flash_msg = 'Order #' . str_pad($order_id, 4, '0', STR_PAD_LEFT) . ' has been received.';
                if (!empty($stock_notices)) $flash_msg .= "\n" . implode("\n", $stock_notices);
                store_set_flash(empty($stock_notices) ? 'success' : 'warning', $flash_msg);
                header('Location: ' . STORE_BASE . '/orders.php');
                exit;
            } else {
                $conn->rollback(); $conn->close();
            }
        } catch (Exception $e) {
            if (isset($conn)) { $conn->rollback(); $conn->close(); }
            $errors[] = 'DB Error: ' . $e->getMessage();
        }
    }
}

// 재고 있는 상품 목록 + 카테고리
try {
    $conn = get_store_db();

    // lc_products.image_path 컬럼 존재 감지 (마이그레이션 v21 미적용 환경 호환)
    $has_image_col = false;
    try {
        $rc = $conn->query("SHOW COLUMNS FROM lc_products LIKE 'image_path'");
        $has_image_col = $rc && $rc->num_rows > 0;
    } catch (Exception $e) { /* 감지 실패 시 미지원으로 처리 */ }
    $img_select = $has_image_col ? "p.image_path," : "NULL AS image_path,";

    $categories = $conn->query(
        "SELECT DISTINCT c.id, c.name_en, c.name_ko
         FROM lc_categories c
         JOIN lc_products p ON p.category_id = c.id
         JOIN lc_inventory i ON i.product_id = p.id
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         JOIN lc_inbound_batches bat ON ib.batch_id = bat.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         ORDER BY c.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);

    // 재고 확정 상태 상관없이 모든 재고 노출 (재고 등록 후 바로 주문 가능하도록)
    // 단위(BOX/PCS)별 재고와 단가를 분리 집계
    $products = $conn->query(
        "SELECT p.id, p.name_en, p.name_ko, p.unit, p.pieces_per_box, p.capacity,
                {$img_select}
                COALESCE(p.barcode_unit, p.barcode_box, p.barcode_logistics) AS barcode,
                p.category_id, c.name_en AS cat_name,
                b.name_en AS brand_name, b.name_ko AS brand_name_ko,
                SUM(CASE WHEN i.unit = 'BOX' THEN i.quantity_remain ELSE 0 END) AS box_stock,
                SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock,
                SUM(CASE WHEN i.unit = 'PCS' THEN i.quantity_remain ELSE 0 END) AS pcs_stock,
                MIN(ib.expiry_date) AS earliest_expiry,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * ib2.cost_price) / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM lc_inventory inv
                    JOIN lc_inbound ib2 ON inv.inbound_id = ib2.id
                    JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'BOX' AND inv.quantity_remain > 0 AND ib2.cost_price > 0
                ), 0) AS box_price,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * ib2.cost_price) / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM lc_inventory inv
                    JOIN lc_inbound ib2 ON inv.inbound_id = ib2.id
                    JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'PACK' AND inv.quantity_remain > 0 AND ib2.cost_price > 0
                ), 0) AS pack_price,
                COALESCE((
                    SELECT SUM(inv.quantity_remain * IF(ib2.inbound_unit IN ('BOX','PACK'), ib2.cost_price_pcs, ib2.cost_price))
                           / NULLIF(SUM(inv.quantity_remain), 0)
                    FROM lc_inventory inv
                    JOIN lc_inbound ib2 ON inv.inbound_id = ib2.id
                    JOIN lc_inbound_batches bat2 ON ib2.batch_id = bat2.id
                    WHERE inv.product_id = p.id AND inv.unit = 'PCS' AND inv.quantity_remain > 0
                ), 0) AS pcs_price,
                MAX(ib.created_at) AS latest_inbound_at
         FROM lc_inventory i
         JOIN lc_products p ON i.product_id = p.id
         JOIN lc_inbound ib ON i.inbound_id = ib.id
         JOIN lc_inbound_batches bat ON ib.batch_id = bat.id
         LEFT JOIN lc_categories c ON p.category_id = c.id
         LEFT JOIN lc_brands b ON p.brand_id = b.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         GROUP BY p.id
         ORDER BY (MIN(ib.expiry_date) IS NULL) ASC, MIN(ib.expiry_date) ASC, latest_inbound_at DESC, c.name_en ASC, p.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);

    $conn->close();
} catch (Exception $e) {
    error_log('store/order.php product list query failed: ' . $e->getMessage());
    $products = []; $categories = [];
}

// 수량/단위 복원: POST 실패 재표시 OR 재주문(from_order) 파라미터
$prev = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['product_id'] ?? []) as $i => $pid) {
        $unit = strtoupper(trim($_POST['order_unit'][$i] ?? ''));
        $unit = in_array($unit, ['BOX', 'PACK', 'PCS'], true) ? $unit : 'PCS';
        $prev[(int)$pid] = ['qty' => (int)($_POST['quantity'][$i] ?? 0), 'unit' => $unit];
    }
} elseif ($edit_order_id > 0) {
    // 편집 모드: pending 주문의 기존 품목/비고 로드 (본인 점포 + pending 만)
    try {
        $conn_ro = get_store_db();
        $st = $conn_ro->prepare("SELECT notes, status FROM lc_orders WHERE id = ? AND store_id = ?");
        $st->bind_param('ii', $edit_order_id, $store_id);
        $st->execute();
        $ord = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$ord || $ord['status'] !== 'pending') {
            // 편집 불가 → 상세로 안내
            $conn_ro->close();
            store_set_flash('error', !$ord ? 'Order not found.' : 'Only orders pending center approval can be edited.');
            header('Location: ' . STORE_BASE . '/order_detail.php?id=' . $edit_order_id);
            exit;
        }
        $edit_notes = $ord['notes'] ?? '';

        $st = $conn_ro->prepare(
            "SELECT product_id, quantity, order_unit FROM lc_order_items WHERE order_id = ?"
        );
        $st->bind_param('i', $edit_order_id);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $unit = in_array($r['order_unit'] ?? '', ['BOX', 'PACK', 'PCS'], true) ? $r['order_unit'] : 'PCS';
            $prev[(int)$r['product_id']] = ['qty' => (int)$r['quantity'], 'unit' => $unit];
        }
        $st->close();
        $conn_ro->close();
    } catch (Exception $e) { /* 복원 실패해도 빈 폼으로 진행 */ }
} elseif ($from_order_id = (int)($_GET['from_order'] ?? 0)) {
    try {
        $conn_ro = get_store_db();
        $st = $conn_ro->prepare(
            "SELECT oi.product_id, oi.quantity, oi.order_unit
             FROM lc_order_items oi
             JOIN lc_orders o ON oi.order_id = o.id
             WHERE o.id = ? AND o.store_id = ? AND o.status = 'cancelled'"
        );
        $st->bind_param('ii', $from_order_id, $store_id);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $unit = in_array($r['order_unit'] ?? '', ['BOX', 'PACK', 'PCS'], true) ? $r['order_unit'] : 'PCS';
            $prev[(int)$r['product_id']] = ['qty' => (int)$r['quantity'], 'unit' => $unit];
        }
        $st->close();
        $conn_ro->close();
    } catch (Exception $e) { /* 복원 실패해도 빈 폼으로 진행 */ }
}
?>

<?php if ($edit_order_id > 0): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-4 text-sm text-amber-800 flex items-center justify-between">
    <span><i class="fas fa-pen-to-square mr-1.5"></i>Editing Order #<?php echo str_pad($edit_order_id, 4, '0', STR_PAD_LEFT); ?> — add items, set quantity to 0 to remove, then save. (Available only before center approval.)</span>
    <a href="<?php echo STORE_BASE; ?>/order_detail.php?id=<?php echo $edit_order_id; ?>" class="text-amber-700 underline whitespace-nowrap ml-3">Cancel edit</a>
</div>
<?php elseif (!empty($prev) && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
<div class="bg-teal-50 border border-teal-200 rounded-lg px-4 py-3 mb-4 text-sm text-teal-800">
    <i class="fas fa-redo mr-1.5"></i>Previous order quantities have been auto-filled. Modify as needed and place your order.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?>
    <p class="text-sm text-red-700"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($e); ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($products)): ?>
<div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-400">
    <i class="fas fa-box-open text-4xl mb-3 block"></i>
    <p>No orderable stock available.</p>
</div>
<?php else: ?>

<style>
.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.qty-input { -moz-appearance:textfield; }
#productBody tr.product-row                 { background-color: #ffffff; }
#productBody tr.product-row:hover           { background-color: #ccfbf1 !important; }
#productBody tr.product-row.row-focused     { background-color: #fef08a !important; outline: 2px solid #eab308; }
</style>

<form method="post" id="orderForm">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(store_csrf_token()); ?>">
<?php if ($edit_order_id > 0): ?>
<input type="hidden" name="edit_order_id" value="<?php echo $edit_order_id; ?>">
<?php endif; ?>

<!-- 검색 -->
<div class="mb-3">
    <div class="flex items-center w-full border border-gray-300 rounded-lg px-3 bg-white focus-within:ring-2 focus-within:ring-teal-500">
        <i class="fas fa-search text-gray-400 text-sm mr-2 flex-shrink-0"></i>
        <input type="text" id="searchInput" placeholder="Search product name..."
               class="flex-1 min-w-0 py-2.5 text-sm border-0 focus:outline-none focus:ring-0 bg-transparent">
    </div>
</div>

<!-- 카테고리 탭 -->
<div class="flex flex-wrap gap-2 pb-2 mb-4">
    <button type="button" data-cat="all"
            class="cat-tab flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                   bg-teal-600 text-white border-teal-600">
        All
    </button>
    <?php foreach ($categories as $cat): ?>
    <button type="button" data-cat="<?php echo $cat['id']; ?>"
            class="cat-tab flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
                   bg-white text-gray-600 border-gray-300 hover:bg-teal-50 hover:border-teal-400">
        <?php echo htmlspecialchars($cat['name_ko'] ?: $cat['name_en']); ?>
    </button>
    <?php endforeach; ?>

    <!-- 내가 체크한 주문만 보기 -->
    <button type="button" id="selectedToggle"
            class="flex-shrink-0 px-4 py-1.5 rounded-full text-sm font-semibold border transition-colors whitespace-nowrap"
            style="background:#fff;color:#d97706;border-color:#fbbf24;">
        <i class="fas fa-cart-shopping mr-1"></i>주문 선택
        <span id="selectedCount"
              style="display:inline-block;min-width:18px;padding:0 5px;margin-left:4px;border-radius:999px;background:#f59e0b;color:#fff;font-size:11px;line-height:18px;text-align:center;">0</span>
    </button>
</div>

<!-- 상품 목록 -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
    <table class="w-full text-sm" id="productTable">
        <thead class="bg-gray-50 border-b border-gray-100">
            <tr>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-16">Image</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-32">Barcode</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium w-28">Brand</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-16">PKG</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-24">Expiry</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">PCS Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">Box Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">Pack Price</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-28">Stock</th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium w-24">Subtotal</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-36">Quantity</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200" id="productBody">
        <?php foreach ($products as $p):
            $boxStock  = (int)$p['box_stock'];
            $packStock = (int)$p['pack_stock'];
            $pcsStock  = (int)$p['pcs_stock'];
            $stockByUnit = ['BOX' => $boxStock, 'PACK' => $packStock, 'PCS' => $pcsStock];
            $priceByUnit = ['BOX' => (float)$p['box_price'], 'PACK' => (float)$p['pack_price'], 'PCS' => (float)$p['pcs_price']];
            $unitOpts = [];
            foreach (['BOX', 'PACK', 'PCS'] as $u) { if ($stockByUnit[$u] > 0) $unitOpts[] = $u; }
            if (empty($unitOpts)) continue;

            $prevItem = $prev[$p['id']] ?? null;
            if ($prevItem && in_array($prevItem['unit'], $unitOpts, true)) {
                $defaultUnit = $prevItem['unit'];
            } else {
                $rawUnit = strtoupper(trim((string)$p['unit']));
                if ($rawUnit === 'BOX' || $rawUnit === '박스')        $norm = 'BOX';
                elseif ($rawUnit === 'PACK' || $rawUnit === '팩')     $norm = 'PACK';
                else                                                  $norm = 'PCS';
                $defaultUnit = in_array($norm, $unitOpts, true) ? $norm : $unitOpts[0];
            }
            $qty          = ($prevItem && $prevItem['unit'] === $defaultUnit) ? $prevItem['qty'] : 0;
            $defaultMax   = $stockByUnit[$defaultUnit];
            $defaultPrice = $priceByUnit[$defaultUnit];
        ?>
        <tr class="product-row hover:bg-teal-50 transition-colors"
            data-cat="<?php echo $p['category_id'] ?? ''; ?>"
            data-name="<?php echo strtolower(($p['name_en'] ?? '') . ' ' . ($p['name_ko'] ?? '') . ' ' . ($p['brand_name'] ?? '') . ' ' . ($p['brand_name_ko'] ?? '') . ' ' . ($p['barcode'] ?? '')); ?>">
            <td class="px-4 py-3 text-center">
                <?php if (!empty($p['image_path'])): $img_url = STORE_WEB_ROOT . '/logistics/' . $p['image_path']; ?>
                <button type="button"
                        onclick="openImageLightbox('<?php echo htmlspecialchars(addslashes($img_url), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($p['name_en'] ?? ''), ENT_QUOTES); ?>')"
                        class="inline-block w-11 h-11 rounded border border-gray-200 overflow-hidden bg-gray-50 hover:ring-2 hover:ring-teal-400 align-middle" title="Click to enlarge">
                    <img src="<?php echo htmlspecialchars($img_url); ?>" alt="" class="w-full h-full object-cover" loading="lazy">
                </button>
                <?php else: ?>
                <span class="inline-flex items-center justify-center w-11 h-11 rounded border border-gray-100 bg-gray-50 text-gray-300 align-middle"><i class="fas fa-image text-xs"></i></span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-xs text-gray-400 font-mono whitespace-nowrap">
                <input type="hidden" name="product_id[]" value="<?php echo $p['id']; ?>">
                <?php if ($p['barcode']): ?>
                <span><i class="fas fa-barcode mr-1 opacity-50"></i><?php echo htmlspecialchars($p['barcode']); ?></span>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-xs">
                <?php if ($p['brand_name'] || !empty($p['brand_name_ko'])): ?>
                <?php if (!empty($p['brand_name_ko'])): ?>
                <div class="text-gray-900 font-semibold leading-tight"><?php echo htmlspecialchars($p['brand_name_ko']); ?></div>
                <?php endif; ?>
                <?php if ($p['brand_name']): ?>
                <div class="text-gray-900 leading-tight"><?php echo htmlspecialchars($p['brand_name']); ?></div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3">
                <?php $cap = !empty($p['capacity']) ? ' ' . htmlspecialchars($p['capacity']) : ''; ?>
                <div class="leading-tight">
                    <?php if ($p['name_ko']): ?>
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['name_ko']) . $cap; ?></div>
                    <div class="text-xs text-gray-900"><?php echo htmlspecialchars($p['name_en']); ?></div>
                    <?php else: ?>
                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['name_en']) . $cap; ?></div>
                    <?php endif; ?>
                </div>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500">
                <?php echo (int)$p['pieces_per_box'] > 1 ? number_format($p['pieces_per_box']) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-center text-xs whitespace-nowrap">
                <?php if (!empty($p['earliest_expiry'])):
                    $daysLeft = (int)floor((strtotime($p['earliest_expiry']) - strtotime(date('Y-m-d'))) / 86400);
                    if ($daysLeft < 0)       $expClass = 'text-red-600 font-semibold';
                    elseif ($daysLeft <= 7)  $expClass = 'text-red-500 font-semibold';
                    elseif ($daysLeft <= 30) $expClass = 'text-amber-600';
                    else                     $expClass = 'text-gray-500';
                ?>
                <span class="<?php echo $expClass; ?>"><?php echo date('Y-m-d', strtotime($p['earliest_expiry'])); ?></span>
                <?php else: ?>
                <span class="text-gray-300">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['pcs_price'] > 0 ? number_format($p['pcs_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['box_price'] > 0 ? number_format($p['box_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs text-gray-500 font-mono">
                <?php echo $p['pack_price'] > 0 ? number_format($p['pack_price'], 2) : '-'; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs">
                <?php if ($boxStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($boxStock); ?></span> <span class="text-gray-400">BOX</span></div>
                <?php endif; ?>
                <?php if ($packStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($packStock); ?></span> <span class="text-gray-400">PACK</span></div>
                <?php endif; ?>
                <?php if ($pcsStock > 0): ?>
                <div><span class="font-semibold text-gray-700"><?php echo number_format($pcsStock); ?></span> <span class="text-gray-400">PCS</span></div>
                <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-right text-xs font-semibold text-teal-700 font-mono subtotal-cell">
                <?php echo ($defaultPrice > 0 && $qty > 0) ? number_format($defaultPrice * $qty, 2) : '-'; ?>
            </td>
            <td class="px-2 py-2 text-center">
                <div class="flex items-center justify-center gap-1.5">
                    <?php if (count($unitOpts) > 1): ?>
                    <select name="order_unit[]" onchange="onUnitChange(this)"
                            data-box-stock="<?php echo $boxStock; ?>" data-pack-stock="<?php echo $packStock; ?>" data-pcs-stock="<?php echo $pcsStock; ?>"
                            data-box-price="<?php echo (float)$p['box_price']; ?>" data-pack-price="<?php echo (float)$p['pack_price']; ?>" data-pcs-price="<?php echo (float)$p['pcs_price']; ?>"
                            class="border border-gray-300 rounded-md px-1 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <?php foreach ($unitOpts as $u): ?>
                        <option value="<?php echo $u; ?>" <?php echo $u === $defaultUnit ? 'selected' : ''; ?>><?php echo $u; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" name="order_unit[]" value="<?php echo $defaultUnit; ?>">
                    <span class="text-xs text-gray-400 w-9 text-center"><?php echo $defaultUnit; ?></span>
                    <?php endif; ?>
                    <div class="inline-flex items-center border border-gray-300 rounded-lg overflow-hidden <?php echo $qty > 0 ? 'border-teal-400' : ''; ?>">
                        <button type="button"
                                onclick="stepQty(this,-1)"
                                class="w-8 h-9 flex items-center justify-center text-gray-500 hover:bg-teal-50 hover:text-teal-700 active:bg-teal-100 text-base font-bold select-none">
                            <i class="fas fa-minus text-xs"></i>
                        </button>
                        <input type="number" name="quantity[]"
                               min="0" max="<?php echo $defaultMax; ?>"
                               value="<?php echo $qty; ?>"
                               data-id="<?php echo $p['id']; ?>"
                               data-price="<?php echo $defaultPrice; ?>"
                               oninput="onQtyChange(this)"
                               class="qty-input w-10 text-sm text-center border-0 focus:outline-none focus:ring-0 bg-transparent font-semibold <?php echo $qty > 0 ? 'text-teal-700' : 'text-gray-700'; ?>">
                        <button type="button"
                                onclick="stepQty(this,1)"
                                class="w-8 h-9 flex items-center justify-center text-gray-500 hover:bg-teal-50 hover:text-teal-700 active:bg-teal-100 text-base font-bold select-none">
                            <i class="fas fa-plus text-xs"></i>
                        </button>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div id="noResult" class="hidden px-4 py-8 text-center text-gray-400 text-sm">
        <i class="fas fa-search mb-2 block text-2xl"></i>No search results.
    </div>
</div>

<!-- 비고 -->
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <label class="block text-sm font-medium text-gray-700 mb-2">Notes <span class="text-xs text-gray-400 font-normal">(Delivery requests, etc.)</span></label>
    <textarea name="notes" rows="2" placeholder="Enter special notes."
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 resize-none"><?php echo htmlspecialchars($_POST['notes'] ?? $edit_notes); ?></textarea>
</div>

</form>

<!-- 임시저장 토스트 -->
<div id="draftToast"
     style="position:fixed;bottom:48px;left:50%;transform:translateX(-50%);background:rgba(15,118,110,0.9);color:#fff;font-size:0.75rem;padding:0.3rem 0.9rem;border-radius:999px;z-index:10000;opacity:0;transition:opacity 0.3s;pointer-events:none;white-space:nowrap;"></div>

<!-- 하단 고정 카트 바 -->
<div id="cartBar" style="position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#fff;border-top:1px solid #e5e7eb;padding:0.2rem 1rem;">
    <div class="flex items-center justify-end gap-3">
        <div class="text-xs text-gray-600">
            <span id="cartCount" class="font-bold text-teal-700">0</span>
            <span class="ml-1">items</span>
            <span class="text-gray-300 mx-2">|</span>
            Total Qty <span id="cartQty" class="font-semibold text-gray-700">0</span>
            <span class="text-gray-300 mx-2">|</span>
            Total <span id="cartAmount" class="font-semibold text-gray-800">0.00</span>
        </div>
        <button type="button" onclick="submitOrder()"
                id="submitBtn"
                style="background:#0d9488;color:#fff;font-weight:700;font-size:0.9rem;padding:0.45rem 1.4rem;border-radius:0.5rem;border:none;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.2);letter-spacing:0.02em;transition:background 0.15s;"
                onmouseover="this.style.background='#0f766e'"
                onmouseout="this.style.background='#0d9488'"
                disabled>
            <i class="fas fa-paper-plane" style="margin-right:0.4rem;"></i><?php echo $edit_order_id ? 'Update Order' : 'Place Order'; ?>
        </button>
    </div>
</div>

<!-- 주문 확인 모달 -->
<div id="confirmModal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40">
    <div class="bg-white rounded-t-2xl sm:rounded-xl shadow-xl w-full sm:max-w-md p-5">
        <h3 class="text-base font-bold text-gray-900 mb-3"><?php echo $edit_order_id ? 'Confirm Order Changes' : 'Order Confirmation'; ?></h3>
        <div id="modalItems" class="divide-y divide-gray-100 max-h-60 overflow-y-auto mb-4 text-sm"></div>
        <div class="flex gap-3">
            <button type="button" onclick="document.getElementById('confirmModal').classList.add('hidden')"
                    class="flex-1 py-2.5 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200">
                Cancel
            </button>
            <button type="button" onclick="finalizeSubmit()"
                    class="flex-1 py-2.5 bg-teal-600 text-white font-semibold rounded-lg hover:bg-teal-700">
                <i class="fas fa-check mr-1"></i><?php echo $edit_order_id ? 'Update Order' : 'Place Order'; ?>
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    var activeCat = 'all';
    var searchVal = '';

    var selectedToggle = document.getElementById('selectedToggle');

    function deactivateSelectedToggle() {
        selectedToggle.style.background  = '#fff';
        selectedToggle.style.color       = '#d97706';
        selectedToggle.style.borderColor = '#fbbf24';
    }

    // ── 카테고리 탭 ───────────────────────────────────────────────
    document.querySelectorAll('.cat-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            activeCat = this.dataset.cat;
            document.querySelectorAll('.cat-tab').forEach(function(b) {
                b.classList.remove('bg-teal-600', 'text-white', 'border-teal-600');
                b.classList.add('bg-white', 'text-gray-600', 'border-gray-300');
            });
            this.classList.add('bg-teal-600', 'text-white', 'border-teal-600');
            this.classList.remove('bg-white', 'text-gray-600', 'border-gray-300');
            deactivateSelectedToggle();
            filterRows();
        });
    });

    // ── "주문 선택" 토글: 수량 입력한 상품만 표시 ────────────────
    selectedToggle.addEventListener('click', function() {
        activeCat = '__selected__';
        document.querySelectorAll('.cat-tab').forEach(function(b) {
            b.classList.remove('bg-teal-600', 'text-white', 'border-teal-600');
            b.classList.add('bg-white', 'text-gray-600', 'border-gray-300');
        });
        // 활성 상태: 주황 배경 + 흰 글씨
        this.style.background  = '#f59e0b';
        this.style.color       = '#fff';
        this.style.borderColor = '#f59e0b';
        filterRows();
    });

    // ── 검색 ──────────────────────────────────────────────────────
    document.getElementById('searchInput').addEventListener('input', function() {
        searchVal = this.value.trim().toLowerCase();
        filterRows();
    });

    function filterRows() {
        var rows    = document.querySelectorAll('.product-row');
        var selMode = activeCat === '__selected__';
        var visible = 0;
        rows.forEach(function(row) {
            var catMatch;
            if (selMode) {
                var qty  = parseInt(row.querySelector('.qty-input').value) || 0;
                catMatch = qty > 0;
            } else {
                catMatch = activeCat === 'all' || row.dataset.cat == activeCat;
            }
            var nameMatch = !searchVal || row.dataset.name.includes(searchVal);
            if (catMatch && nameMatch) { row.classList.remove('hidden'); visible++; }
            else                       { row.classList.add('hidden'); }
        });
        document.getElementById('noResult').classList.toggle('hidden', visible > 0);
    }

    // ── 행에서 현재 선택된 단위(BOX/PCS) 가져오기 ─────────────────
    function getRowUnit(row) {
        var sel = row.querySelector('select[name="order_unit[]"]');
        if (sel) return sel.value;
        var hid = row.querySelector('input[name="order_unit[]"]');
        return hid ? hid.value : 'PCS';
    }

    // ── 단위(BOX/PCS) 변경 ────────────────────────────────────────
    window.onUnitChange = function(sel) {
        var unit  = sel.value;
        var row   = sel.closest('tr');
        var input = row.querySelector('.qty-input');
        var stockMap = { BOX: sel.dataset.boxStock, PACK: sel.dataset.packStock, PCS: sel.dataset.pcsStock };
        var priceMap = { BOX: sel.dataset.boxPrice, PACK: sel.dataset.packPrice, PCS: sel.dataset.pcsPrice };
        var max   = parseInt(stockMap[unit]) || 0;
        var price = parseFloat(priceMap[unit]) || 0;
        input.max = max;
        input.dataset.price = price;
        if ((parseInt(input.value) || 0) > max) input.value = max;
        onQtyChange(input);
    };

    // ── 임시저장 (localStorage) ───────────────────────────────────
    // 편집 모드에서는 임시저장 사용 안 함 (서버에서 불러온 수량을 덮어쓰지 않도록)
    var EDIT_MODE = <?php echo $edit_order_id > 0 ? 'true' : 'false'; ?>;
    var DRAFT_KEY = 'order_draft_<?php echo (int)$_sid; ?>';

    function saveDraft() {
        if (EDIT_MODE) return;
        var draft = {};
        document.querySelectorAll('.product-row').forEach(function(row) {
            var inp = row.querySelector('.qty-input');
            var qty = parseInt(inp.value) || 0;
            if (qty > 0) draft[inp.dataset.id] = { qty: qty, unit: getRowUnit(row) };
        });
        if (Object.keys(draft).length > 0) {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
            showDraftToast('임시저장됨');
        } else {
            localStorage.removeItem(DRAFT_KEY);
        }
    }

    function restoreDraft() {
        if (EDIT_MODE) return;
        var raw = localStorage.getItem(DRAFT_KEY);
        if (!raw) return;
        try {
            var draft = JSON.parse(raw);
            var restored = 0;
            document.querySelectorAll('.product-row').forEach(function(row) {
                var inp = row.querySelector('.qty-input');
                var d   = draft[inp.dataset.id];
                if (d && d.qty > 0) {
                    var sel = row.querySelector('select[name="order_unit[]"]');
                    if (sel && d.unit) {
                        var hasOpt = Array.prototype.some.call(sel.options, function(o) { return o.value === d.unit; });
                        if (hasOpt && sel.value !== d.unit) { sel.value = d.unit; onUnitChange(sel); }
                    }
                    inp.value = d.qty;
                    onQtyChange(inp);
                    restored++;
                }
            });
            if (restored > 0) showDraftToast('이전 수량 복원됨 (' + restored + '개 항목)');
        } catch(e) {}
    }

    window.clearDraft = function() {
        localStorage.removeItem(DRAFT_KEY);
    };

    // ── 최종 제출 ─────────────────────────────────────────────────
    // 수량 0인 행의 input(product_id/quantity/order_unit)을 disabled 처리하여
    // 실제 주문 품목만 전송한다. (상품 수가 많을 때 PHP max_input_vars 초과로
    // 뒤쪽 카테고리 행이 잘려 전송 누락되던 문제 방지)
    window.finalizeSubmit = function() {
        clearDraft();
        document.querySelectorAll('.product-row').forEach(function(row) {
            var inp = row.querySelector('.qty-input');
            if ((parseInt(inp.value) || 0) <= 0) {
                row.querySelectorAll('input[name], select[name]').forEach(function(el) {
                    el.disabled = true;
                });
            }
        });
        document.getElementById('orderForm').submit();
    };

    var _toastTimer;
    function showDraftToast(msg) {
        var t = document.getElementById('draftToast');
        if (!t) return;
        t.textContent = msg;
        t.style.opacity = '1';
        clearTimeout(_toastTimer);
        _toastTimer = setTimeout(function() { t.style.opacity = '0'; }, 2000);
    }

    // 폼 제출 시 draft 삭제
    document.getElementById('orderForm').addEventListener('submit', clearDraft);

    // ── 행 포커스 강조 ────────────────────────────────────────────
    document.querySelectorAll('.qty-input').forEach(function(input) {
        input.addEventListener('focus', function() {
            document.querySelectorAll('.product-row').forEach(function(r) { r.classList.remove('row-focused'); });
            this.closest('.product-row').classList.add('row-focused');
        });
        input.addEventListener('blur', function() {
            this.closest('.product-row').classList.remove('row-focused');
        });
    });

    // ── +/- 버튼 ──────────────────────────────────────────────────
    window.stepQty = function(btn, delta) {
        var input = btn.closest('div').querySelector('.qty-input');
        var val   = parseInt(input.value) || 0;
        var max   = parseInt(input.max) || 99999;
        input.value = Math.min(max, Math.max(0, val + delta));
        onQtyChange(input);
    };

    // ── 수량 변경 ──────────────────────────────────────────────────
    window.onQtyChange = function(input) {
        var qty   = parseInt(input.value) || 0;
        var price = parseFloat(input.dataset.price) || 0;
        var cell  = input.closest('tr').querySelector('.subtotal-cell');
        if (qty > 0) {
            input.classList.add('border-teal-400', 'bg-teal-50', 'font-semibold');
            input.classList.remove('border-gray-300');
            if (cell) cell.textContent = price > 0 ? (qty * price).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) : '-';
        } else {
            input.classList.remove('border-teal-400', 'bg-teal-50', 'font-semibold');
            input.classList.add('border-gray-300');
            if (cell) cell.textContent = '-';
        }
        updateCart();
        saveDraft();
    };

    function updateCart() {
        var count = 0, totalQty = 0, totalAmt = 0;
        document.querySelectorAll('.qty-input').forEach(function(inp) {
            var qty   = parseInt(inp.value) || 0;
            var price = parseFloat(inp.dataset.price) || 0;
            if (qty > 0) { count++; totalQty += qty; totalAmt += qty * price; }
        });
        document.getElementById('cartCount').textContent = count;
        document.getElementById('cartQty').textContent   = totalQty;
        document.getElementById('cartAmount').textContent =
            totalAmt.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
        var selCountEl = document.getElementById('selectedCount');
        if (selCountEl) selCountEl.textContent = count;
        // "주문 선택" 보기 중이면 수량 0이 된 항목이 사라지도록 갱신
        if (activeCat === '__selected__') filterRows();
        var btn = document.getElementById('submitBtn');
        btn.disabled = (count === 0);
        btn.style.background   = count === 0 ? '#9ca3af' : '#0d9488';
        btn.style.cursor       = count === 0 ? 'not-allowed' : 'pointer';
        btn.style.boxShadow    = count === 0 ? 'none' : '0 2px 6px rgba(0,0,0,0.2)';
    }

    // ── Enter / 위아래 화살표 → 행 이동 ──────────────────────────
    document.getElementById('productBody').addEventListener('keydown', function(e) {
        if (!['Enter','ArrowDown','ArrowUp'].includes(e.key)) return;
        e.preventDefault();
        var inputs = Array.from(document.querySelectorAll('.product-row:not(.hidden) .qty-input'));
        var idx    = inputs.indexOf(e.target);
        if (idx < 0) return;
        if (e.key === 'ArrowUp') {
            if (idx > 0) { inputs[idx - 1].focus(); inputs[idx - 1].select(); }
        } else {
            if (idx < inputs.length - 1) { inputs[idx + 1].focus(); inputs[idx + 1].select(); }
            else { document.querySelector('textarea[name="notes"]')?.focus(); }
        }
    });

    // ── 주문 접수 ──────────────────────────────────────────────────
    window.submitOrder = function() {
        var items = [];
        document.querySelectorAll('.product-row').forEach(function(row) {
            var inp = row.querySelector('.qty-input');
            var qty = parseInt(inp.value) || 0;
            if (qty > 0) {
                var name  = row.querySelector('.font-medium').textContent.trim();
                var price = parseFloat(inp.dataset.price) || 0;
                items.push({ name: name, qty: qty, price: price, unit: getRowUnit(row) });
            }
        });

        if (items.length === 0) { alert('Enter quantity.'); return; }

        var html = '';
        var grandTotal = 0;
        items.forEach(function(item) {
            var sub = item.price > 0 ? item.qty * item.price : null;
            if (sub) grandTotal += sub;
            html += '<div class="py-2 flex justify-between items-center">' +
                    '<span class="text-gray-700 flex-1">' + escHtml(item.name) + '</span>' +
                    '<span class="text-xs text-gray-400 mx-3">× ' + item.qty + ' ' + item.unit + '</span>' +
                    '<span class="font-semibold text-teal-700 w-24 text-right">' +
                    (sub ? sub.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) : item.qty + ' units') +
                    '</span></div>';
        });
        if (grandTotal > 0) {
            html += '<div class="pt-2 mt-1 border-t border-gray-200 flex justify-between font-bold text-sm">' +
                    '<span class="text-gray-700">Total</span>' +
                    '<span class="text-teal-700">' +
                    grandTotal.toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}) +
                    '</span></div>';
        }
        document.getElementById('modalItems').innerHTML = html;
        document.getElementById('confirmModal').classList.remove('hidden');
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // 초기 카트 상태 반영 후 draft 복원 (onQtyChange 정의 이후)
    updateCart();
    restoreDraft();

})();
</script>

<!-- 이미지 확대 라이트박스 -->
<div id="imageLightbox" class="hidden fixed inset-0 flex items-center justify-center bg-black/60 p-4" style="z-index:10001;" onclick="closeImageLightbox()">
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden flex flex-col border-2 border-gray-300 ring-1 ring-black/5" style="width:26rem;max-width:92vw;" onclick="event.stopPropagation()">
        <!-- 헤더: 제목 + 닫기 -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
            <span id="lightboxTitle" class="text-sm font-semibold text-gray-800 truncate pr-2"></span>
            <button type="button" onclick="closeImageLightbox()" title="Close"
                    class="text-gray-400 hover:text-gray-700 text-lg flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- 고정 크기 이미지 영역 -->
        <div class="bg-gray-50 flex items-center justify-center" style="height:22rem;">
            <img id="lightboxImg" src="" alt="" class="max-w-full max-h-full object-contain">
        </div>
        <!-- 푸터: 닫기 버튼 -->
        <div class="px-4 py-3 border-t border-gray-100 flex justify-end">
            <button type="button" onclick="closeImageLightbox()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                Close
            </button>
        </div>
    </div>
</div>
<script>
    window.openImageLightbox = function(src, alt) {
        if (!src) return;
        var img = document.getElementById('lightboxImg');
        img.src = src; img.alt = alt || '';
        document.getElementById('lightboxTitle').textContent = alt || '';
        document.getElementById('imageLightbox').classList.remove('hidden');
    };
    window.closeImageLightbox = function() {
        document.getElementById('imageLightbox').classList.add('hidden');
        document.getElementById('lightboxImg').src = '';
    };
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImageLightbox();
    });
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
