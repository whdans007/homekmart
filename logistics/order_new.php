<?php
require_once __DIR__ . '/lib/auth.php';
$page_title = t('logistics.order_new.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';
require_once __DIR__ . '/lib/unit_helper.php';

lc_require_login();

// 점포 담당자 또는 관리자만 주문 가능
$store_id = lc_current_store_id();
if (!$store_id) {
    lc_set_flash('error', t('logistics.order_new.no_store'));
    header('Location: ' . LC_BASE . '/index.php');
    exit;
}

// 물류센터(CENTER) 소속 계정은 자기 자신에게 주문할 수 없음 — branch_outbound.php 사용
$conn_center_check = get_lc_db();
$is_center_account = lc_is_center_store($conn_center_check, $store_id);
$conn_center_check->close();
if ($is_center_account) {
    lc_set_flash('error', t('logistics.order_new.center_cannot_order'));
    header('Location: ' . LC_BASE . '/index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();

    $product_ids = $_POST['product_id'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $order_units = $_POST['order_unit'] ?? [];
    $promotion_ids = $_POST['promotion_id'] ?? [];
    $notes       = trim($_POST['notes'] ?? '');

    // 유효한 주문 항목 추출 (Design Ref: box-pcs-unit §5.4 — 단위는 서버에서 재검증)
    $items = [];
    foreach ($product_ids as $i => $pid) {
        $pid = (int)$pid;
        $qty = (int)($quantities[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $items[] = [
                'product_id' => $pid,
                'quantity'   => $qty,
                'unit'       => lc_valid_unit($order_units[$i] ?? '', LC_UNIT_PCS),
                'promotion_id' => (int)($promotion_ids[$i] ?? 0) ?: null,
            ];
        }
    }

    if (empty($items)) {
        $errors[] = t('logistics.order_new.items_required');
    }

    if (empty($errors)) {
        try {
            $conn = get_lc_db();

            // 재고 확인 및 단가 조회 — 단위별 재고 기준 (Design Ref: box-pcs-unit §5.4)
            // 프로모션 섹션과 일반 상품 섹션에 같은 상품+단위가 각각 별도 행으로 나뉠 수 있으므로
            // 합산 수량으로 재고를 검증한다(각 행을 독립적으로 검증하면 총합이 재고를 넘어도 통과될 수 있음)
            $combined_qty = [];
            foreach ($items as $it) {
                $key = $it['product_id'] . '|' . $it['unit'];
                $combined_qty[$key] = ($combined_qty[$key] ?? 0) + $it['quantity'];
            }
            $checked_keys = [];

            $total_amount = 0;
            foreach ($items as &$item) {
                $key = $item['product_id'] . '|' . $item['unit'];
                if (!isset($checked_keys[$key])) {
                    $checked_keys[$key] = true;
                    $stock_by_unit = lc_get_stock_by_unit($conn, $item['product_id']);
                    $stock = $stock_by_unit[$item['unit']];
                    if ($stock < $combined_qty[$key]) {
                        $st = $conn->prepare("SELECT CONCAT(name_en, IFNULL(CONCAT(' (', name_ko, ')'), '')) FROM lc_products WHERE id = ?");
                        $st->bind_param('i', $item['product_id']);
                        $st->execute();
                        $pname = $st->get_result()->fetch_row()[0] ?? "#{$item['product_id']}";
                        $st->close();
                        $errors[] = t('logistics.order_new.insufficient_stock', ['name' => $pname, 'unit' => $item['unit'], 'stock' => $stock]);
                    }
                }
                $price = 0.00; // 단가는 입고 시 결정
                $item['unit_price']   = $price;
                $total_amount += $price * $item['quantity'];
            }
            unset($item);

            if (empty($errors)) {
                $conn->autocommit(false);

                $uid = lc_current_user_id();
                $today = date('Y-m-d');

                // 주문 헤더
                $st = $conn->prepare(
                    "INSERT INTO lc_orders (order_date, store_id, status, total_amount, notes, created_by)
                     VALUES (?, ?, 'pending', ?, ?, ?)"
                );
                $st->bind_param('ssdsi', $today, $store_id, $total_amount, $notes, $uid);
                $st->execute();
                $order_id = $conn->insert_id;
                $st->close();

                // 주문 상세 — order_unit + ppb 스냅샷 기록 (Design Ref: box-pcs-unit §3.1, FR-09)
                $ppb_map = [];
                $pid_in = implode(',', array_unique(array_map(fn($it) => (int)$it['product_id'], $items)));
                $res = $conn->query("SELECT id, GREATEST(1, IFNULL(pieces_per_box, 1)) AS ppb FROM lc_products WHERE id IN ($pid_in)");
                foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) $ppb_map[(int)$r['id']] = (int)$r['ppb'];

                $st2 = $conn->prepare(
                    "INSERT INTO lc_order_items (order_id, product_id, quantity, order_unit, pieces_per_box, unit_price, promotion_id) VALUES (?,?,?,?,?,?,?)"
                );
                foreach ($items as $item) {
                    $ppb = $ppb_map[$item['product_id']] ?? 1;
                    $promotion_id = $item['promotion_id'];
                    $st2->bind_param('iiisidi', $order_id, $item['product_id'], $item['quantity'], $item['unit'], $ppb, $item['unit_price'], $promotion_id);
                    $st2->execute();
                }
                $st2->close();

                $conn->commit();
                $conn->close();

                lc_set_flash('success', t('logistics.order_new.placed', ['id' => str_pad($order_id, 4, '0', STR_PAD_LEFT)]));
                header('Location: ' . LC_BASE . '/orders.php');
                exit;
            }
            $conn->close();
        } catch (Exception $e) {
            if (isset($conn)) { $conn->rollback(); $conn->close(); }
            $errors[] = t('logistics.order_new.db_error', ['message' => $e->getMessage()]);
        }
    }
}

// 재고 있는 상품 목록
try {
    $conn = get_lc_db();
    // Design Ref: pack-unit §5.1 — 단위별(BOX/PACK/PCS) 재고 분리 집계
    $available = $conn->query(
        "SELECT p.id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name, p.unit, 0 AS selling_price,
                c.name_en AS category,
                SUM(CASE WHEN i.unit = 'BOX'  THEN i.quantity_remain ELSE 0 END) AS box_stock,
                SUM(CASE WHEN i.unit = 'PACK' THEN i.quantity_remain ELSE 0 END) AS pack_stock,
                SUM(CASE WHEN i.unit = 'PCS'  THEN i.quantity_remain ELSE 0 END) AS pcs_stock
         FROM lc_inventory i
         JOIN lc_products p ON i.product_id = p.id
         JOIN lc_categories c ON p.category_id = c.id
         WHERE i.quantity_remain > 0 AND p.is_active = 1
         GROUP BY p.id
         ORDER BY c.name_en ASC, p.name_en ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    $available = [];
}

$promo_items = [];
try {
    $conn2 = get_lc_db();
    $promo_items = $conn2->query(
        "SELECT lp.id AS promotion_id, lp.discount_rate, lp.base_price, lp.discounted_price, lp.unit,
                i.lot_number, i.expiry_date, i.quantity_remain,
                p.id AS product_id, CONCAT(p.name_en, IFNULL(CONCAT(' (', p.name_ko, ')'), '')) AS name
         FROM lc_lot_promotions lp
         JOIN lc_inventory i ON lp.inventory_id = i.id
         JOIN lc_products p ON lp.product_id = p.id
         WHERE lp.status = 'active' AND i.quantity_remain > 0 AND p.is_active = 1
         ORDER BY i.expiry_date ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $conn2->close();
} catch (Exception $e) {
    $promo_items = [];
}
?>

<div class="flex items-center gap-3 mb-6">
    <a href="<?php echo LC_BASE; ?>/orders.php" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.order_new.title')); ?></h2>
</div>

<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($e); ?></p><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (empty($available) && empty($promo_items)): ?>
<div class="bg-white rounded-lg border border-gray-200 p-8 text-center text-gray-400">
    <i class="fas fa-box-open text-4xl mb-3 block"></i>
    <p><?php echo htmlspecialchars(t('logistics.order_new.no_stock')); ?></p>
</div>
<?php else: ?>

<form method="post" id="order-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

    <?php if (!empty($promo_items)): ?>
    <div class="bg-amber-50 rounded-lg border border-amber-200 overflow-hidden mb-4">
        <div class="px-4 py-3 border-b border-amber-100">
            <h3 class="text-sm font-semibold text-amber-800"><?php echo htmlspecialchars(t('logistics.order_new.promo_title')); ?></h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-amber-100"><tr>
                    <th class="px-4 py-3 text-left text-xs text-amber-800 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.product_name')); ?></th>
                    <th class="px-4 py-3 text-right text-xs text-amber-800 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.current_stock')); ?></th>
                    <th class="px-4 py-3 text-right text-xs text-amber-800 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.selling_price')); ?></th>
                    <th class="px-4 py-3 text-center text-xs text-amber-800 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.order_quantity')); ?></th>
                </tr></thead>
                <tbody class="divide-y divide-amber-100">
                <?php foreach ($promo_items as $promo):
                    $promoUnit = lc_normalize_unit($promo['unit']);
                    $promoStock = 0;
                    foreach ($available as $availableProduct) {
                        if ((int)$availableProduct['id'] !== (int)$promo['product_id']) continue;
                        $promoStock = (int)($availableProduct[strtolower($promoUnit) . '_stock'] ?? 0);
                        break;
                    }
                ?>
                <tr class="hover:bg-amber-100/50">
                    <td class="px-4 py-3 font-medium text-gray-900">
                        <?php echo htmlspecialchars($promo['name']); ?>
                        <div class="text-xs text-amber-700 mt-1"><?php echo htmlspecialchars(t('logistics.order_new.promo_detail', ['lot' => $promo['lot_number'] ?: '-', 'expiry' => $promo['expiry_date'] ?: '-', 'quantity' => $promo['quantity_remain'], 'unit' => $promo['unit'], 'rate' => $promo['discount_rate']])); ?></div>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold text-teal-700"><?php echo htmlspecialchars($promoStock . ' ' . $promoUnit); ?></td>
                    <td class="px-4 py-3 text-right text-gray-700">
                        <div class="text-gray-400 text-xs leading-tight" style="text-decoration:line-through;"><?php echo number_format((float)$promo['base_price'], 2); ?></div>
                        <div class="font-semibold text-amber-700"><?php echo number_format((float)$promo['discounted_price'], 2); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <input type="hidden" name="product_id[]" value="<?php echo (int)$promo['product_id']; ?>">
                        <input type="hidden" name="promotion_id[]" value="<?php echo (int)$promo['promotion_id']; ?>">
                        <input type="hidden" name="order_unit[]" value="<?php echo htmlspecialchars($promoUnit); ?>">
                        <input type="number" name="quantity[]" min="0" max="<?php echo $promoStock; ?>" value="0" data-price="0"
                               oninput="updateTotal()"
                               class="w-20 border border-amber-300 rounded-md px-2 py-1 text-sm text-center focus:outline-none focus:ring-2 focus:ring-amber-500">
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Product List -->
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden mb-4">
        <div class="px-4 py-3 border-b border-gray-100">
            <h3 class="text-sm font-semibold text-gray-700"><?php echo htmlspecialchars(t('logistics.order_new.select_items')); ?></h3>
            <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(t('logistics.order_new.stock_hint')); ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50"><tr>
                    <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.product_name')); ?></th>
                    <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.category')); ?></th>
                    <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.current_stock')); ?></th>
                    <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.selling_price')); ?></th>
                    <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.order_quantity')); ?></th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php foreach ($available as $idx => $p):
                    // Design Ref: pack-unit §5.1 — 단위 select + 단위별 max (BOX/PACK/PCS)
                    $boxStock  = (int)$p['box_stock'];
                    $packStock = (int)$p['pack_stock'];
                    $pcsStock  = (int)$p['pcs_stock'];
                    $stockByUnit = [LC_UNIT_BOX => $boxStock, LC_UNIT_PACK => $packStock, LC_UNIT_PCS => $pcsStock];
                    $unitOpts = [];
                    foreach (LC_ALL_UNITS as $u) { if ($stockByUnit[$u] > 0) $unitOpts[] = $u; }
                    if (empty($unitOpts)) continue;
                    $defaultUnit = in_array(lc_normalize_unit($p['unit']), $unitOpts, true)
                        ? lc_normalize_unit($p['unit']) : $unitOpts[0];
                    $defaultMax = $stockByUnit[$defaultUnit];
                ?>
                <tr class="hover:bg-gray-50" id="row-<?php echo $idx; ?>">
                    <td class="px-4 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($p['name']); ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars($p['category'] ?? '-'); ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-teal-700">
                        <?php echo htmlspecialchars(lc_format_stock($stockByUnit)); ?>
                    </td>
                    <td class="px-4 py-3 text-right text-gray-700"><?php echo "-"; ?></td>
                    <td class="px-4 py-3 text-center">
                        <input type="hidden" name="product_id[]" value="<?php echo $p['id']; ?>">
                        <input type="hidden" name="promotion_id[]" value="">
                        <div class="inline-flex items-center gap-1.5">
                            <select name="order_unit[]"
                                    data-box-stock="<?php echo $boxStock; ?>" data-pack-stock="<?php echo $packStock; ?>" data-pcs-stock="<?php echo $pcsStock; ?>"
                                    onchange="onUnitChange(this)"
                                    class="border border-gray-300 rounded-md px-1.5 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-teal-500 <?php echo count($unitOpts) === 1 ? 'bg-gray-50 text-gray-500' : ''; ?>">
                                <?php foreach ($unitOpts as $u): ?>
                                <option value="<?php echo $u; ?>" <?php echo $u === $defaultUnit ? 'selected' : ''; ?>><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantity[]" min="0" max="<?php echo $defaultMax; ?>"
                                   value="0" data-price="0"
                                   oninput="updateTotal()"
                                   class="w-20 border border-gray-300 rounded-md px-2 py-1 text-sm text-center focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Notes and Total -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo htmlspecialchars(t('logistics.order_new.notes')); ?></label>
            <textarea name="notes" rows="3" placeholder="<?php echo htmlspecialchars(t('logistics.order_new.notes_placeholder')); ?>"
                      class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"></textarea>
        </div>
        <div class="bg-teal-50 rounded-lg border border-teal-200 p-4 flex flex-col justify-between">
            <div>
                <p class="text-sm text-teal-700 font-medium"><?php echo htmlspecialchars(t('logistics.order_new.order_total')); ?></p>
                <p class="text-3xl font-bold text-teal-800 mt-2" id="total-display">0.00</p>
            </div>
            <button type="submit"
                    onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.order_new.place_confirm')), ENT_QUOTES); ?>)"
                    class="w-full py-3 bg-teal-600 text-white font-medium rounded-lg hover:bg-teal-700 transition-colors mt-4">
                <i class="fas fa-paper-plane mr-2"></i><?php echo htmlspecialchars(t('logistics.order_new.place_order')); ?>
            </button>
        </div>
    </div>
</form>

<script>
// Design Ref: pack-unit §5.1 — 단위 변경 시 max를 해당 단위(BOX/PACK/PCS) 재고로 갱신
function onUnitChange(sel) {
    var input = sel.parentElement.querySelector('input[name="quantity[]"]');
    var stockMap = { BOX: parseInt(sel.dataset.boxStock), PACK: parseInt(sel.dataset.packStock), PCS: parseInt(sel.dataset.pcsStock) };
    var max = stockMap[sel.value] || 0;
    input.max = max;
    if ((parseInt(input.value) || 0) > max) input.value = max;
    updateTotal();
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('input[name="quantity[]"]').forEach(input => {
        const qty = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        total += qty * price;
    });
    document.getElementById('total-display').textContent = total.toLocaleString('ko-KR', {minimumFractionDigits:2, maximumFractionDigits:2});
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
