<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.batch_detail_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';
require_once __DIR__ . '/../lib/fresh_margin_helper.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$batchId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
        echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
        require_once __DIR__ . '/partials/footer.php';
        exit;
    }

    $checkStmt = $conn->prepare('SELECT id FROM fresh_purchase_batches WHERE id = ? AND deleted_at IS NULL');
    $checkStmt->bind_param('i', $batchId);
    $checkStmt->execute();
    $batchExists = (bool)$checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($batchId <= 0 || !$batchExists) {
        $conn->close();
        fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
        fresh_admin_redirect('fresh_purchase_items.php');
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $cancelStmt = $conn->prepare(
        'UPDATE fresh_purchase_batches
         SET deleted_at = NOW(), deleted_by_user_id = ?
         WHERE id = ? AND deleted_at IS NULL'
    );
    $cancelStmt->bind_param('ii', $userId, $batchId);
    $cancelStmt->execute();
    $cancelled = $cancelStmt->affected_rows === 1;
    $cancelStmt->close();
    $conn->close();

    if (!$cancelled) {
        fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
        fresh_admin_redirect('fresh_purchase_items.php');
    }

    fresh_admin_flash('success', t('mall_fresh_products.batch_cancel_success'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

if ($batchId <= 0) {
    $conn->close();
    fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'delete_item' || isset($_POST['delete_item_id']))) {
    $itemId = (int)($_POST['delete_item_id'] ?? $_POST['item_id'] ?? 0);
    $inTransaction = false;
    try {
        $itemStmt = $conn->prepare(
            'SELECT fpi.mall_fresh_product_id, fp.sale_type, fp.fresh_category
             FROM fresh_purchase_items fpi
             JOIN mall_fresh_products fp ON fp.id = fpi.mall_fresh_product_id
             JOIN fresh_purchase_batches b ON b.id = fpi.batch_id AND b.deleted_at IS NULL
             WHERE fpi.id = ? AND fpi.batch_id = ?'
        );
        $itemStmt->bind_param('ii', $itemId, $batchId);
        $itemStmt->execute();
        $deletedItem = $itemStmt->get_result()->fetch_assoc();
        $itemStmt->close();
        if (!$deletedItem) { throw new InvalidArgumentException('삭제할 매입 품목을 찾을 수 없습니다.'); }

        $conn->begin_transaction();
        $inTransaction = true;
        $deleteStmt = $conn->prepare('DELETE FROM fresh_purchase_items WHERE id = ? AND batch_id = ?');
        $deleteStmt->bind_param('ii', $itemId, $batchId);
        $deleteStmt->execute();
        if ($deleteStmt->affected_rows !== 1) { throw new RuntimeException('매입 품목을 삭제하지 못했습니다.'); }
        $deleteStmt->close();

        $totalsStmt = $conn->prepare(
            'UPDATE fresh_purchase_batches
             SET total_amount = COALESCE((SELECT SUM(total_cost) FROM fresh_purchase_items WHERE batch_id = ?), 0),
                 total_items = (SELECT COUNT(*) FROM fresh_purchase_items WHERE batch_id = ?)
             WHERE id = ? AND deleted_at IS NULL'
        );
        $totalsStmt->bind_param('iii', $batchId, $batchId, $batchId);
        $totalsStmt->execute();
        $totalsStmt->close();

        $productId = (int)$deletedItem['mall_fresh_product_id'];
        $latestStmt = $conn->prepare('SELECT box_cost, unit_cost_per_100g, unit_cost_per_piece FROM fresh_purchase_items WHERE mall_fresh_product_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 1');
        $latestStmt->bind_param('i', $productId);
        $latestStmt->execute();
        $latest = $latestStmt->get_result()->fetch_assoc();
        $latestStmt->close();
        if ($latest) {
            $marginRate = get_fresh_margin_rate($deletedItem['fresh_category'], $conn);
            $boxSalePrice = calculate_fresh_sale_price((float)$latest['box_cost'], $marginRate);
            $unitCost = $deletedItem['sale_type'] === 'piece' ? $latest['unit_cost_per_piece'] : $latest['unit_cost_per_100g'];
            $unitSalePrice = $unitCost !== null ? calculate_fresh_sale_price((float)$unitCost, $marginRate) : 0.0;
            $priceStmt = $conn->prepare('UPDATE mall_fresh_products SET box_sale_price = ?, price_per_100g = ? WHERE id = ?');
            $priceStmt->bind_param('ddi', $boxSalePrice, $unitSalePrice, $productId);
        } else {
            $priceStmt = $conn->prepare('UPDATE mall_fresh_products SET box_sale_price = NULL, price_per_100g = 0 WHERE id = ?');
            $priceStmt->bind_param('i', $productId);
        }
        $priceStmt->execute();
        $priceStmt->close();
        $conn->commit();
        fresh_admin_flash('success', '매입 품목을 삭제했습니다.');
    } catch (Throwable $e) {
        if ($inTransaction) { $conn->rollback(); }
        if (!($e instanceof InvalidArgumentException)) { error_log('fresh_purchase_batch_detail.php delete item: ' . $e->getMessage()); }
        fresh_admin_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : '매입 품목을 삭제하지 못했습니다.');
    }
    $conn->close();
    fresh_admin_redirect('fresh_purchase_batch_detail.php?id=' . $batchId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_item') {
    $productId = (int)($_POST['fresh_product_id'] ?? 0);
    $newItemId = 0;
    try {
        $headerStmt = $conn->prepare('SELECT store_id, supplier_id, purchase_date FROM fresh_purchase_batches WHERE id = ? AND deleted_at IS NULL');
        $headerStmt->bind_param('i', $batchId);
        $headerStmt->execute();
        $header = $headerStmt->get_result()->fetch_assoc();
        $headerStmt->close();
        if (!$header || $productId <= 0) { throw new InvalidArgumentException('추가할 상품을 선택해 주세요.'); }

        $productStmt = $conn->prepare("SELECT id, sale_type, unit_type, pkg_weight_kg, pkg_pieces_per_box FROM mall_fresh_products WHERE id = ? AND status = 'active'");
        $productStmt->bind_param('i', $productId);
        $productStmt->execute();
        $product = $productStmt->get_result()->fetch_assoc();
        $productStmt->close();
        if (!$product) { throw new InvalidArgumentException('추가할 상품 정보를 확인해 주세요.'); }

        $duplicateStmt = $conn->prepare('SELECT 1 FROM fresh_purchase_items WHERE batch_id = ? AND mall_fresh_product_id = ? LIMIT 1');
        $duplicateStmt->bind_param('ii', $batchId, $productId);
        $duplicateStmt->execute();
        $duplicate = (bool)$duplicateStmt->get_result()->fetch_assoc();
        $duplicateStmt->close();
        if ($duplicate) { throw new InvalidArgumentException('이미 전표에 추가된 상품입니다. 기존 행을 수정해 주세요.'); }

        $sortStmt = $conn->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_sort FROM fresh_purchase_items WHERE batch_id = ?');
        $sortStmt->bind_param('i', $batchId);
        $sortStmt->execute();
        $sortOrder = (int)$sortStmt->get_result()->fetch_assoc()['next_sort'];
        $sortStmt->close();

        $quantity = 1.0;
        $boxCost = 0.0;
        $isBoxWeight = $product['sale_type'] === 'weight' && (float)($product['pkg_weight_kg'] ?? 0) > 0;
        $boxWeight = $isBoxWeight ? (float)$product['pkg_weight_kg'] : null;
        $boxPieces = $product['sale_type'] === 'piece' ? max(1, (int)($product['pkg_pieces_per_box'] ?? 1)) : null;
        $weight = $product['sale_type'] === 'weight' ? ($isBoxWeight ? $boxWeight : 1.0) : null;
        $pieces = $product['sale_type'] === 'piece' ? $boxPieces : null;
        $total = 0.0;
        $unitWeight = $product['sale_type'] === 'weight' ? 0.0 : null;
        $unitPiece = $product['sale_type'] === 'piece' ? 0.0 : null;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $insertStmt = $conn->prepare(
            'INSERT INTO fresh_purchase_items
             (batch_id, sort_order, store_id, supplier_id, mall_fresh_product_id, purchase_date, quantity_boxes,
              box_weight_kg, box_pieces_per_box, box_cost, weight_kg, pieces_per_box, total_cost,
              unit_cost_per_100g, unit_cost_per_piece, registered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertStmt->bind_param(
            'iiiiisddiddidddi',
            $batchId, $sortOrder, $header['store_id'], $header['supplier_id'], $productId, $header['purchase_date'],
            $quantity, $boxWeight, $boxPieces, $boxCost, $weight, $pieces, $total, $unitWeight, $unitPiece, $userId
        );
        $insertStmt->execute();
        $newItemId = (int)$insertStmt->insert_id;
        $insertStmt->close();
        $countStmt = $conn->prepare('UPDATE fresh_purchase_batches SET total_items = (SELECT COUNT(*) FROM fresh_purchase_items WHERE batch_id = ?) WHERE id = ?');
        $countStmt->bind_param('ii', $batchId, $batchId);
        $countStmt->execute();
        $countStmt->close();
        fresh_admin_flash('success', '전표에 상품을 추가했습니다. 수량과 원가를 확인한 후 수정 저장해 주세요.');
    } catch (Throwable $e) {
        if (!($e instanceof InvalidArgumentException)) { error_log('fresh_purchase_batch_detail.php add item: ' . $e->getMessage()); }
        fresh_admin_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : t('mall_fresh_products.save_failed'));
    }
    $conn->close();
    fresh_admin_redirect('fresh_purchase_batch_detail.php?id=' . $batchId . ($newItemId > 0 ? '&focus_item=' . $newItemId : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $postedItems = $_POST['items'] ?? [];
    $inTransaction = false;

    try {
        if ($purchaseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
            throw new InvalidArgumentException('매입일자를 올바르게 입력해 주세요.');
        }

        $editStmt = $conn->prepare(
            'SELECT fpi.id, fpi.mall_fresh_product_id, fp.sale_type, fp.fresh_category, fp.unit_type, fp.pkg_weight_kg
             FROM fresh_purchase_items fpi
             JOIN mall_fresh_products fp ON fp.id = fpi.mall_fresh_product_id
             JOIN fresh_purchase_batches b ON b.id = fpi.batch_id AND b.deleted_at IS NULL
             WHERE fpi.batch_id = ?'
        );
        $editStmt->bind_param('i', $batchId);
        $editStmt->execute();
        $editableItems = $editStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $editStmt->close();
        if (!$editableItems) {
            throw new InvalidArgumentException(t('mall_fresh_products.batch_not_found'));
        }

        $validated = [];
        foreach ($editableItems as $index => $dbItem) {
            $itemId = (int)$dbItem['id'];
            $input = is_array($postedItems[$itemId] ?? null) ? $postedItems[$itemId] : [];
            $qtyRaw = trim((string)($input['quantity'] ?? ''));
            $costRaw = trim((string)($input['cost'] ?? ''));
            $lineNo = $index + 1;

            if ($qtyRaw === '' || !is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                throw new InvalidArgumentException($lineNo . '번째 항목: 수량을 올바르게 입력해 주세요.');
            }
            if ($costRaw === '' || !is_numeric($costRaw) || (float)$costRaw < 0) {
                throw new InvalidArgumentException($lineNo . '번째 항목: 원가를 올바르게 입력해 주세요.');
            }

            $isPiece = $dbItem['sale_type'] === 'piece';
            $isBoxWeight = !$isPiece && (float)($dbItem['pkg_weight_kg'] ?? 0) > 0;
            $quantity = round((float)$qtyRaw, 2);
            $cost = round((float)$costRaw, 2);
            $total = round($quantity * $cost, 2);
            $boxPieces = null;
            $boxWeight = null;
            $pieces = null;
            $weight = null;
            $unitPer100g = null;
            $unitPerPiece = null;

            if ($isPiece) {
                $piecesRaw = trim((string)($input['box_pieces'] ?? ''));
                if ($piecesRaw === '' || !ctype_digit($piecesRaw) || (int)$piecesRaw <= 0) {
                    throw new InvalidArgumentException($lineNo . '번째 항목: 박스당 개수를 올바르게 입력해 주세요.');
                }
                $boxPieces = (int)$piecesRaw;
                $pieces = (int)round($quantity * $boxPieces);
                $unitPerPiece = round($cost / $boxPieces, 2);
            } elseif ($isBoxWeight) {
                $weightRaw = trim((string)($input['box_weight'] ?? ''));
                if ($weightRaw === '' || !is_numeric($weightRaw) || (float)$weightRaw <= 0) {
                    throw new InvalidArgumentException($lineNo . '번째 항목: BOX당 무게(kg)를 올바르게 입력해 주세요.');
                }
                $boxWeight = round((float)$weightRaw, 3);
                $weight = round($quantity * $boxWeight, 3);
                $unitPer100g = $weight > 0 ? round($total / $weight, 2) : null;
            } else {
                $weight = $quantity;
                $unitPer100g = $cost;
            }

            $validated[] = compact('itemId', 'quantity', 'cost', 'total', 'boxWeight', 'boxPieces', 'pieces', 'weight', 'unitPer100g', 'unitPerPiece') + [
                'productId' => (int)$dbItem['mall_fresh_product_id'],
                'saleType' => $dbItem['sale_type'],
                'freshCategory' => $dbItem['fresh_category'],
            ];
        }

        $conn->begin_transaction();
        $inTransaction = true;
        $updateItemStmt = $conn->prepare(
            'UPDATE fresh_purchase_items
             SET purchase_date = ?, quantity_boxes = ?, box_weight_kg = ?, box_pieces_per_box = ?, box_cost = ?,
                 weight_kg = ?, pieces_per_box = ?, total_cost = ?, unit_cost_per_100g = ?, unit_cost_per_piece = ?
             WHERE id = ? AND batch_id = ?'
        );
        foreach ($validated as $row) {
            $updateItemStmt->bind_param(
                'sddiddidddii',
                $purchaseDate, $row['quantity'], $row['boxWeight'], $row['boxPieces'], $row['cost'], $row['weight'],
                $row['pieces'], $row['total'], $row['unitPer100g'], $row['unitPerPiece'], $row['itemId'], $batchId
            );
            $updateItemStmt->execute();
        }
        $updateItemStmt->close();

        $batchTotal = round(array_sum(array_column($validated, 'total')), 2);
        $itemCount = count($validated);
        $updateBatchStmt = $conn->prepare('UPDATE fresh_purchase_batches SET purchase_date = ?, total_amount = ?, total_items = ? WHERE id = ? AND deleted_at IS NULL');
        $updateBatchStmt->bind_param('sdii', $purchaseDate, $batchTotal, $itemCount, $batchId);
        $updateBatchStmt->execute();
        $updateBatchStmt->close();

        $latestStmt = $conn->prepare('SELECT box_cost, unit_cost_per_100g, unit_cost_per_piece FROM fresh_purchase_items WHERE mall_fresh_product_id = ? ORDER BY purchase_date DESC, id DESC LIMIT 1');
        $priceStmt = $conn->prepare('UPDATE mall_fresh_products SET box_sale_price = ?, price_per_100g = ? WHERE id = ?');
        foreach ($validated as $row) {
            $latestStmt->bind_param('i', $row['productId']);
            $latestStmt->execute();
            $latest = $latestStmt->get_result()->fetch_assoc();
            if (!$latest) { continue; }
            $marginRate = get_fresh_margin_rate($row['freshCategory'], $conn);
            $boxSalePrice = calculate_fresh_sale_price((float)$latest['box_cost'], $marginRate);
            $unitCost = $row['saleType'] === 'piece' ? $latest['unit_cost_per_piece'] : $latest['unit_cost_per_100g'];
            $unitSalePrice = $unitCost !== null ? calculate_fresh_sale_price((float)$unitCost, $marginRate) : 0.0;
            $priceStmt->bind_param('ddi', $boxSalePrice, $unitSalePrice, $row['productId']);
            $priceStmt->execute();
        }
        $latestStmt->close();
        $priceStmt->close();
        $conn->commit();
        fresh_admin_flash('success', '매입 전표를 수정했습니다.');
        $conn->close();
        fresh_admin_redirect('fresh_purchase_items.php');
    } catch (Throwable $e) {
        if ($inTransaction) { $conn->rollback(); }
        if (!($e instanceof InvalidArgumentException)) { error_log('fresh_purchase_batch_detail.php update: ' . $e->getMessage()); }
        fresh_admin_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : t('mall_fresh_products.save_failed'));
        $conn->close();
        fresh_admin_redirect('fresh_purchase_batch_detail.php?id=' . $batchId);
    }
}

$batchStmt = $conn->prepare(
    'SELECT b.id, b.purchase_date, b.total_amount, b.total_items,
            s.name AS store_name, sup.name AS supplier_name
     FROM fresh_purchase_batches b
     JOIN stores s ON s.id = b.store_id
     JOIN suppliers sup ON sup.id = b.supplier_id
     WHERE b.id = ? AND b.deleted_at IS NULL'
);
$batchStmt->bind_param('i', $batchId);
$batchStmt->execute();
$batch = $batchStmt->get_result()->fetch_assoc();
$batchStmt->close();

if (!$batch) {
    $conn->close();
    fresh_admin_flash('error', t('mall_fresh_products.batch_not_found'));
    fresh_admin_redirect('fresh_purchase_items.php');
}

$itemsStmt = $conn->prepare(
    'SELECT fpi.id, fpi.quantity_boxes, fpi.box_cost, fpi.box_weight_kg, fpi.box_pieces_per_box, fpi.weight_kg, fpi.pieces_per_box,
            fpi.unit_cost_per_100g, fpi.unit_cost_per_piece, fpi.total_cost,
            fp.code AS fresh_code, fp.name_ko AS fresh_name_ko, fp.sale_type, fp.unit_type,
            fp.pkg_weight_kg AS master_pkg_weight_kg
     FROM fresh_purchase_items fpi
     JOIN mall_fresh_products fp ON fp.id = fpi.mall_fresh_product_id
     WHERE fpi.batch_id = ?
     ORDER BY fpi.sort_order ASC, fpi.id ASC'
);
$itemsStmt->bind_param('i', $batchId);
$itemsStmt->execute();
$items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();
$mastersStmt = $conn->prepare(
    "SELECT fp.id, fp.code, fp.name_ko, fp.name_en
     FROM mall_fresh_products fp
     WHERE fp.status = 'active'
     ORDER BY fp.name_ko"
);
$mastersStmt->execute();
$availableMasters = $mastersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$mastersStmt->close();
$conn->close();
$flash = fresh_admin_take_flash();
$focusItemId = (int)($_GET['focus_item'] ?? 0);

function fpbdh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<style>
    .no-number-spinner::-webkit-outer-spin-button,
    .no-number-spinner::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .no-number-spinner { -moz-appearance: textfield; appearance: textfield; }
</style>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <?php if ($flash): ?>
        <div class="mb-4 rounded-md border px-4 py-3 text-sm <?php echo $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-700'; ?>"><?php echo fpbdh($flash['message']); ?></div>
    <?php endif; ?>
    <section class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400 mb-6">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-900"><i class="fas fa-file-invoice mr-2"></i><?php echo fpbdh(t('mall_fresh_products.batch_detail_title')); ?></h1>
                <p class="mt-1 text-sm text-gray-500"><?php echo fpbdh(t('mall_fresh_products.batch_id_label')); ?>: #<?php echo (int)$batch['id']; ?></p>
            </div>
            <form method="post" onsubmit="return confirm('<?php echo fpbdh(t('mall_fresh_products.batch_cancel_confirm')); ?>');">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="id" value="<?php echo (int)$batch['id']; ?>">
                <button type="submit" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-md">
                    <i class="fas fa-ban mr-2"></i><?php echo fpbdh(t('mall_fresh_products.batch_cancel_button')); ?>
                </button>
            </form>
        </div>
        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 px-6 py-5">
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.purchase_date_label')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['purchase_date']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('common.store')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['store_name']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('purchase.supplier')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo fpbdh($batch['supplier_name']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.total_items_count_label')); ?></dt><dd class="mt-1 text-sm text-gray-900"><?php echo number_format((int)$batch['total_items']); ?></dd></div>
            <div><dt class="text-xs font-semibold text-gray-500"><?php echo fpbdh(t('mall_fresh_products.batch_total_cost_label')); ?></dt><dd class="mt-1 text-sm font-semibold text-gray-900"><?php echo number_format((float)$batch['total_amount'], 2); ?></dd></div>
        </dl>
    </section>

    <section class="relative z-20 mb-6 bg-white shadow-lg rounded-lg ring-1 ring-gray-400">
        <div class="border-b border-gray-200 px-4 py-3">
            <form method="post" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="id" value="<?php echo (int)$batch['id']; ?>">
                <div id="add-product-search-section" class="relative min-w-0 flex-1">
                    <label class="mb-1 block text-xs font-semibold text-gray-600">전표에 상품 추가</label>
                    <input type="hidden" name="fresh_product_id" id="add-fresh-product-id" required>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400"></i>
                        <input type="text" id="add-product-search" autocomplete="off" placeholder="상품명 또는 코드 검색" class="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div id="add-product-results" class="hidden mt-1 max-h-60 w-full overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg"></div>
                </div>
                <div class="flex flex-none gap-2">
                    <button type="submit" id="add-product-button" disabled class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"><i class="fas fa-plus mr-1"></i>상품 추가</button>
                </div>
            </form>
        </div>
    </section>

    <form method="post" id="batch-edit-form">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$batch['id']; ?>">
        <section class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="flex flex-col gap-3 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <label for="edit-purchase-date" class="mb-1 block text-xs font-semibold text-gray-600"><?php echo fpbdh(t('mall_fresh_products.purchase_date_label')); ?></label>
                <input id="edit-purchase-date" type="date" name="purchase_date" required value="<?php echo fpbdh($batch['purchase_date']); ?>" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div class="flex gap-2">
                <a href="fresh_purchase_items.php" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"><i class="fas fa-times mr-1"></i>취소</a>
                <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"><i class="fas fa-save mr-1"></i>수정 저장</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-gray-50 border-b border-gray-200"><tr>
                    <th class="w-14 px-4 py-3 text-center text-xs font-semibold text-gray-700">순번</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.fresh_product_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.quantity_boxes_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.total_cost_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.composition_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.unit_cost_label')); ?></th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700"><?php echo fpbdh(t('mall_fresh_products.line_total_cost_label')); ?></th>
                    <th class="w-16 px-4 py-3 text-center text-xs font-semibold text-gray-700"><?php echo fpbdh(t('common.delete')); ?></th>
                </tr></thead>
                <tbody class="bg-white">
                    <?php if (!$items): ?><tr><td colspan="8" class="px-4 py-10 text-center text-sm text-gray-500"><?php echo fpbdh(t('mall_fresh_products.no_purchase_history')); ?></td></tr><?php endif; ?>
                    <?php foreach ($items as $itemIndex => $item):
                        $display = fresh_purchase_row_display($item);
                        $isPiece = $item['sale_type'] === 'piece';
                        $isBoxWeight = !$isPiece && (float)($item['master_pkg_weight_kg'] ?? 0) > 0;
                        $rowMode = $isPiece ? 'piece' : ($isBoxWeight ? 'box_weight' : 'weight');
                        $unitType = $isPiece && in_array($item['unit_type'] ?? '', ['pcs', 'pack'], true) ? $item['unit_type'] : 'kg';
                    ?>
                        <tr class="batch-edit-row border-b border-gray-100 hover:bg-gray-50" data-row-mode="<?php echo fpbdh($rowMode); ?>" data-unit-type="<?php echo fpbdh($unitType); ?>">
                            <td class="px-4 py-3 text-center text-sm font-semibold text-gray-500"><?php echo $itemIndex + 1; ?></td>
                            <td class="px-4 py-3 text-sm"><div class="font-medium text-gray-900"><?php echo fpbdh($item['fresh_code']); ?></div><div class="text-xs text-gray-500"><?php echo fpbdh($item['fresh_name_ko']); ?></div></td>
                            <td class="px-4 py-3 text-right"><input type="number" name="items[<?php echo (int)$item['id']; ?>][quantity]" required min="0.01" step="0.01" value="<?php echo fpbdh($item['quantity_boxes']); ?>" <?php echo $focusItemId === (int)$item['id'] ? 'autofocus data-focus-new-item' : ''; ?> class="edit-quantity no-number-spinner w-24 rounded-md border border-gray-300 px-2 py-1.5 text-right text-sm"></td>
                            <td class="px-4 py-3 text-right"><input type="number" name="items[<?php echo (int)$item['id']; ?>][cost]" required min="0" step="0.01" value="<?php echo fpbdh($item['box_cost']); ?>" class="edit-cost no-number-spinner w-24 rounded-md border border-gray-300 px-2 py-1.5 text-right text-sm"></td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($isPiece): ?>
                                    <input type="number" name="items[<?php echo (int)$item['id']; ?>][box_pieces]" required min="1" step="1" value="<?php echo (int)$item['box_pieces_per_box']; ?>" class="edit-pieces w-24 rounded-md border border-gray-300 px-2 py-1.5 text-right text-sm" title="BOX당 <?php echo fpbdh($unitType); ?> 수">
                                <?php elseif ($isBoxWeight): ?>
                                    <input type="number" name="items[<?php echo (int)$item['id']; ?>][box_weight]" required min="0.001" step="0.001" value="<?php echo fpbdh($item['box_weight_kg'] ?? $item['master_pkg_weight_kg']); ?>" class="edit-box-weight w-24 rounded-md border border-gray-300 px-2 py-1.5 text-right text-sm" title="BOX당 kg">
                                <?php else: ?><span class="text-gray-400">-</span><?php endif; ?>
                            </td>
                            <td class="edit-unit-cost px-4 py-3 text-sm text-gray-500 text-right"><?php echo fpbdh($display['unit_cost']); ?></td>
                            <td class="edit-line-total px-4 py-3 text-sm font-medium text-gray-900 text-right"><?php echo number_format((float)$item['total_cost'], 2); ?></td>
                            <td class="px-4 py-3 text-center"><button type="submit" name="delete_item_id" value="<?php echo (int)$item['id']; ?>" formnovalidate onclick="return confirm('이 매입 품목을 삭제하시겠습니까?');" class="text-red-600 hover:text-red-800" aria-label="<?php echo fpbdh(t('common.delete')); ?>"><i class="fas fa-trash"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </section>
    </form>

</div>
<div id="new-product-modal" class="hidden fixed inset-0 z-50 bg-gray-900 bg-opacity-60 p-4" role="dialog" aria-modal="true" aria-label="새로운 상품 등록">
    <div class="mx-auto flex h-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
            <h2 class="text-lg font-bold text-gray-800"><i class="fas fa-plus-circle mr-2"></i>새로운 상품 등록</h2>
            <button type="button" id="close-new-product-modal" class="text-gray-500 hover:text-gray-800" aria-label="닫기"><i class="fas fa-times text-xl"></i></button>
        </div>
        <iframe id="new-product-frame" title="새로운 상품 등록" class="min-h-0 flex-1 w-full border-0" src="about:blank"></iframe>
    </div>
</div>
<script>
(function () {
    const availableMasters = <?php echo json_encode($availableMasters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    const searchSection = document.getElementById('add-product-search-section');
    const searchInput = document.getElementById('add-product-search');
    const productIdInput = document.getElementById('add-fresh-product-id');
    const searchResults = document.getElementById('add-product-results');
    const addButton = document.getElementById('add-product-button');
    const addProductForm = searchInput.closest('form');
    const batchEditForm = document.getElementById('batch-edit-form');
    let productMatches = [];
    let productHighlightIndex = -1;
    let addingProduct = false;

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function newProductRegisterHtml() {
        return '<div class="sticky bottom-0 border-t border-gray-200 bg-white p-2">' +
            '<button type="button" class="open-new-product-modal flex w-full items-center justify-center rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700" style="background-color:#2563eb !important;color:#ffffff !important;">' +
            '<i class="fas fa-plus-circle mr-1"></i>새로운 상품 등록</button></div>';
    }

    const newProductModal = document.getElementById('new-product-modal');
    const newProductFrame = document.getElementById('new-product-frame');
    const closeNewProductModal = document.getElementById('close-new-product-modal');
    let newProductRegistered = false;

    function openNewProductModal() {
        if (!batchEditForm.reportValidity()) { return; }
        fetch(window.location.href, { method: 'POST', body: new FormData(batchEditForm), credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) { throw new Error('기존 입력값을 저장하지 못했습니다.'); }
                newProductRegistered = false;
                newProductFrame.src = 'add_fresh_product.php?modal=1&name_ko=' + encodeURIComponent(searchInput.value.trim());
                newProductModal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            })
            .catch(function (error) { alert(error.message || '기존 입력값을 저장하지 못했습니다.'); });
    }

    function hideNewProductModal() {
        newProductModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        newProductFrame.src = 'about:blank';
        if (newProductRegistered) { window.location.reload(); }
    }

    closeNewProductModal.addEventListener('click', hideNewProductModal);
    newProductModal.addEventListener('click', function (event) { if (event.target === newProductModal) { hideNewProductModal(); } });
    newProductFrame.addEventListener('load', function () {
        try {
            if (newProductFrame.contentWindow.location.pathname.endsWith('/fresh_products.php')) {
                const frameUrl = new URL(newProductFrame.contentWindow.location.href);
                const createdProductId = parseInt(frameUrl.searchParams.get('modal_created_id'), 10);
                if (createdProductId > 0) {
                    newProductRegistered = false;
                    newProductModal.classList.add('hidden');
                    document.body.classList.remove('overflow-hidden');
                    newProductFrame.src = 'about:blank';
                    productIdInput.value = String(createdProductId);
                    addButton.disabled = false;
                    addProductForm.requestSubmit();
                } else {
                    newProductRegistered = true;
                    hideNewProductModal();
                }
            }
        } catch (error) { /* same-origin page expected */ }
    });

    function bindNewProductRegisterButton() {
        const button = searchResults.querySelector('.open-new-product-modal');
        if (button) { button.addEventListener('click', openNewProductModal); }
    }

    function highlightProductResult(index) {
        const resultButtons = searchResults.querySelectorAll('.product-search-result');
        if (!resultButtons.length) { productHighlightIndex = -1; return; }
        productHighlightIndex = Math.max(0, Math.min(index, resultButtons.length - 1));
        resultButtons.forEach(function (button, buttonIndex) {
            button.classList.toggle('bg-blue-100', buttonIndex === productHighlightIndex);
        });
        resultButtons[productHighlightIndex].scrollIntoView({ block: 'nearest' });
    }

    function selectProductResult(index, submitImmediately) {
        const product = productMatches[index];
        if (!product) { return; }
        productIdInput.value = product.id;
        searchInput.value = '[' + product.code + '] ' + product.name_ko;
        searchResults.classList.add('hidden');
        addButton.disabled = false;
        if (submitImmediately) { addProductForm.requestSubmit(); }
    }

    searchInput.addEventListener('input', function () {
        const term = this.value.trim().toLowerCase();
        productIdInput.value = '';
        addButton.disabled = true;
        if (!term) {
            searchResults.classList.add('hidden');
            searchResults.innerHTML = '';
            return;
        }
        const matches = availableMasters.filter(function (product) {
            return (product.code + ' ' + product.name_ko + ' ' + (product.name_en || '')).toLowerCase().includes(term);
        }).slice(0, 20);
        productMatches = matches;
        productHighlightIndex = matches.length ? 0 : -1;
        searchResults.innerHTML = (matches.length ? matches.map(function (product, index) {
            return '<button type="button" class="product-search-result block w-full border-b border-gray-100 px-3 py-2 text-left hover:bg-blue-50 last:border-b-0" data-index="' + index + '">' +
                '<span class="block text-sm font-medium text-gray-900">[' + escapeHtml(product.code) + '] ' + escapeHtml(product.name_ko) + '</span>' +
                '<span class="block text-xs text-gray-500">' + escapeHtml(product.name_en || '') + '</span></button>';
        }).join('') : '<div class="px-3 py-3 text-sm text-gray-500">검색 결과가 없습니다.</div>') + newProductRegisterHtml();
        searchResults.classList.remove('hidden');
        searchResults.querySelectorAll('.product-search-result').forEach(function (button) {
            button.addEventListener('click', function () { selectProductResult(parseInt(this.dataset.index, 10), true); });
            button.addEventListener('mouseenter', function () { highlightProductResult(parseInt(this.dataset.index, 10)); });
        });
        bindNewProductRegisterButton();
        if (matches.length) { highlightProductResult(0); }
    });
    searchInput.addEventListener('keydown', function (event) {
        if (searchResults.classList.contains('hidden')) { return; }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            highlightProductResult(productHighlightIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            highlightProductResult(productHighlightIndex - 1);
        } else if (event.key === 'Enter' && productHighlightIndex >= 0) {
            event.preventDefault();
            selectProductResult(productHighlightIndex, true);
        } else if (event.key === 'Escape') {
            searchResults.classList.add('hidden');
        }
    });
    document.addEventListener('click', function (event) {
        if (!searchSection.contains(event.target)) { searchResults.classList.add('hidden'); }
    });

    addProductForm.addEventListener('submit', function (event) {
        if (addingProduct) { return; }
        event.preventDefault();
        if (!productIdInput.value || !batchEditForm.reportValidity()) { return; }

        addingProduct = true;
        addButton.disabled = true;
        fetch(window.location.href, {
            method: 'POST',
            body: new FormData(batchEditForm),
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('기존 입력값을 저장하지 못했습니다.'); }
                HTMLFormElement.prototype.submit.call(addProductForm);
            })
            .catch(function (error) {
                addingProduct = false;
                addButton.disabled = false;
                alert(error.message || '기존 입력값을 저장하지 못했습니다.');
            });
    });

    const newItemQuantity = document.querySelector('[data-focus-new-item]');
    if (newItemQuantity) {
        newItemQuantity.focus();
        newItemQuantity.select();
        newItemQuantity.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    document.querySelectorAll('.batch-edit-row').forEach(function (row) {
        const quantity = row.querySelector('.edit-quantity');
        const cost = row.querySelector('.edit-cost');
        const pieces = row.querySelector('.edit-pieces');
        const boxWeight = row.querySelector('.edit-box-weight');
        const unit = row.querySelector('.edit-unit-cost');
        const total = row.querySelector('.edit-line-total');
        function refresh() {
            const qtyValue = parseFloat(quantity.value) || 0;
            const costValue = parseFloat(cost.value) || 0;
            total.textContent = (qtyValue * costValue).toFixed(2);
            if (row.dataset.rowMode === 'piece') {
                const piecesValue = parseInt(pieces.value, 10) || 0;
                unit.textContent = piecesValue > 0 ? (costValue / piecesValue).toFixed(2) + ' / ' + row.dataset.unitType : '-';
            } else if (row.dataset.rowMode === 'box_weight') {
                const weightValue = parseFloat(boxWeight.value) || 0;
                unit.textContent = weightValue > 0 ? (costValue / weightValue).toFixed(2) + ' / kg' : '-';
            } else {
                unit.textContent = costValue.toFixed(2) + ' / kg';
            }
        }
        [quantity, cost, pieces, boxWeight].filter(Boolean).forEach(function (input) { input.addEventListener('input', refresh); });
        quantity.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                cost.focus();
                cost.select();
            }
        });
        cost.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });
        refresh();
    });
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
