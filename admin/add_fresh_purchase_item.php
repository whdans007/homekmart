<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.purchase_link_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';
require_once __DIR__ . '/../lib/fresh_margin_helper.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();

$stmt = $conn->prepare("SELECT id, code, name_ko, name_en, sale_type, pkg_weight_kg, pkg_pieces_per_box FROM mall_fresh_products WHERE status = 'active' ORDER BY name_ko");
$stmt->execute();
$masters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storeId = (int)($current_store_id ?? 0);
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $itemsJson = $_POST['items_json'] ?? '';
    $items = json_decode(is_string($itemsJson) ? $itemsJson : '', true);
    $inTransaction = false;

    try {
        if (!$storeId) {
            throw new InvalidArgumentException('현재 점포 정보를 확인할 수 없습니다. 헤더에서 점포를 선택해 주세요.');
        }
        if (!$supplierId) {
            throw new InvalidArgumentException(t('mall_fresh_products.select_supplier_first'));
        }
        if ($purchaseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $purchaseDate)) {
            throw new InvalidArgumentException('매입일자를 올바르게 입력해 주세요.');
        }
        if (!is_array($items) || !$items) {
            throw new InvalidArgumentException(t('mall_fresh_products.no_items_added'));
        }

        $rawMasterIds = array_map(static fn($it) => (int)($it['fresh_product_id'] ?? 0), $items);
        $masterIds = array_values(array_unique(array_filter($rawMasterIds)));
        if (!$masterIds) {
            throw new InvalidArgumentException(t('mall_fresh_products.no_items_added'));
        }
        if (count(array_filter($rawMasterIds)) !== count($masterIds)) {
            throw new InvalidArgumentException('같은 신선상품을 한 매입 전표에 두 번 이상 입력할 수 없습니다. 수량을 합쳐서 한 줄로 입력해 주세요.');
        }
        $placeholders = implode(',', array_fill(0, count($masterIds), '?'));
        $typeStr = str_repeat('i', count($masterIds));
        $masterStmt = $conn->prepare("SELECT id, sale_type, fresh_category FROM mall_fresh_products WHERE id IN ($placeholders)");
        $masterStmt->bind_param($typeStr, ...$masterIds);
        $masterStmt->execute();
        $saleTypeById = [];
        $freshCategoryById = [];
        foreach ($masterStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $saleTypeById[(int)$row['id']] = $row['sale_type'];
            $freshCategoryById[(int)$row['id']] = $row['fresh_category'];
        }
        $masterStmt->close();

        // 1단계: 전표(배치) 저장 전에 모든 행을 검증하고 합계를 미리 계산한다.
        $validatedRows = [];
        foreach ($items as $idx => $item) {
            $lineNo = $idx + 1;
            $masterId = (int)($item['fresh_product_id'] ?? 0);

            if (!$masterId || !isset($saleTypeById[$masterId])) {
                throw new InvalidArgumentException($lineNo . '번째 항목: 신선상품 정보를 확인해 주세요.');
            }

            $qtyRaw = $item['quantity'] ?? '';
            if ($qtyRaw === '' || !ctype_digit((string)$qtyRaw) || (int)$qtyRaw <= 0) {
                throw new InvalidArgumentException($lineNo . '번째 항목: 수량을 확인해 주세요.');
            }
            $qty = (int)$qtyRaw;

            $boxCostRaw = $item['box_cost'] ?? '';
            if ($boxCostRaw === '' || !is_numeric($boxCostRaw) || (float)$boxCostRaw < 0) {
                throw new InvalidArgumentException($lineNo . '번째 항목: 박스당 원가를 확인해 주세요.');
            }
            $boxCost = (float)$boxCostRaw;
            $totalCost = round($boxCost * $qty, 2);

            $weightKg = null;
            $piecesPerBox = null;
            $unitCostPer100g = null;
            $unitCostPerPiece = null;
            $boxWeightKg = null;
            $boxPiecesPerBox = null;

            if ($saleTypeById[$masterId] === 'piece') {
                $boxPiecesRaw = $item['box_pieces'] ?? '';
                if ($boxPiecesRaw === '' || !ctype_digit((string)$boxPiecesRaw) || (int)$boxPiecesRaw <= 0) {
                    throw new InvalidArgumentException($lineNo . '번째 항목: 박스당 개수를 확인해 주세요.');
                }
                $boxPiecesPerBox = (int)$boxPiecesRaw;
                $piecesPerBox = $boxPiecesPerBox * $qty;
                $unitCostPerPiece = round($totalCost / $piecesPerBox, 2);
            } else {
                $boxWeightRaw = $item['box_weight_kg'] ?? '';
                if ($boxWeightRaw === '' || !is_numeric($boxWeightRaw) || (float)$boxWeightRaw <= 0) {
                    throw new InvalidArgumentException($lineNo . '번째 항목: 박스당 무게를 확인해 주세요.');
                }
                $boxWeightKg = (float)$boxWeightRaw;
                $weightKg = round($boxWeightKg * $qty, 3);
                $unitCostPer100g = round($totalCost / ($weightKg * 10), 2);
            }

            $validatedRows[] = [
                'master_id' => $masterId,
                'quantity_boxes' => $qty,
                'box_weight_kg' => $boxWeightKg,
                'box_pieces_per_box' => $boxPiecesPerBox,
                'box_cost' => $boxCost,
                'weight_kg' => $weightKg,
                'pieces_per_box' => $piecesPerBox,
                'total_cost' => $totalCost,
                'unit_cost_per_100g' => $unitCostPer100g,
                'unit_cost_per_piece' => $unitCostPerPiece,
            ];
        }

        $batchTotalAmount = round(array_sum(array_column($validatedRows, 'total_cost')), 2);
        $batchTotalItems = count($validatedRows);
        $userId = (int)$_SESSION['user_id'];

        // 2단계: 전표(배치) 헤더를 먼저 저장하고, 반환된 batch_id로 품목 행을 저장한다.
        $conn->begin_transaction();
        $inTransaction = true;

        $batchStmt = $conn->prepare(
            'INSERT INTO fresh_purchase_batches (store_id, supplier_id, purchase_date, total_amount, total_items, registered_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $batchStmt->bind_param('iisdii', $storeId, $supplierId, $purchaseDate, $batchTotalAmount, $batchTotalItems, $userId);
        $batchStmt->execute();
        $batchId = $batchStmt->insert_id;
        $batchStmt->close();

        $insertStmt = $conn->prepare(
            'INSERT INTO fresh_purchase_items
                (batch_id, sort_order, store_id, supplier_id, mall_fresh_product_id, purchase_date,
                 quantity_boxes, box_weight_kg, box_pieces_per_box, box_cost,
                 weight_kg, pieces_per_box, total_cost, unit_cost_per_100g, unit_cost_per_piece, registered_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($validatedRows as $sortOrder => $row) {
            $sortOrder++;
            $insertStmt->bind_param(
                'iiiiisididdidddi',
                $batchId, $sortOrder, $storeId, $supplierId, $row['master_id'], $purchaseDate,
                $row['quantity_boxes'], $row['box_weight_kg'], $row['box_pieces_per_box'], $row['box_cost'],
                $row['weight_kg'], $row['pieces_per_box'], $row['total_cost'], $row['unit_cost_per_100g'], $row['unit_cost_per_piece'],
                $userId
            );
            $insertStmt->execute();
        }
        $insertStmt->close();

        // 3단계: 방금 입력한 값이 아니라, 상품별 실제 최신 매입 기록(날짜 역전 방지)을 기준으로
        // 마진율을 적용해 박스판매가/낱개판매가를 자동 계산해 마스터 상품에 반영한다.
        $latestCostStmt = $conn->prepare(
            'SELECT box_cost, unit_cost_per_100g, unit_cost_per_piece
             FROM fresh_purchase_items
             WHERE mall_fresh_product_id = ?
             ORDER BY purchase_date DESC, id DESC
             LIMIT 1'
        );
        $priceUpdateStmt = $conn->prepare(
            'UPDATE mall_fresh_products SET box_sale_price = ?, price_per_100g = ? WHERE id = ?'
        );
        foreach ($masterIds as $masterId) {
            $latestCostStmt->bind_param('i', $masterId);
            $latestCostStmt->execute();
            $latestRow = $latestCostStmt->get_result()->fetch_assoc();
            if (!$latestRow) {
                continue;
            }

            $marginRate = get_fresh_margin_rate($freshCategoryById[$masterId] ?? 'fruit', $conn);
            $boxSalePrice = calculate_fresh_sale_price((float)$latestRow['box_cost'], $marginRate);
            $unitCost = $saleTypeById[$masterId] === 'piece' ? $latestRow['unit_cost_per_piece'] : $latestRow['unit_cost_per_100g'];
            $unitSalePrice = $unitCost !== null ? calculate_fresh_sale_price((float)$unitCost, $marginRate) : 0.0;

            $priceUpdateStmt->bind_param('ddi', $boxSalePrice, $unitSalePrice, $masterId);
            $priceUpdateStmt->execute();
        }
        $latestCostStmt->close();
        $priceUpdateStmt->close();

        $conn->commit();

        fresh_admin_flash('success', t('mall_fresh_products.purchase_save_success', ['count' => $batchTotalItems]));
        $conn->close();
        fresh_admin_redirect('fresh_purchase_items.php');
    } catch (Throwable $e) {
        if ($inTransaction) {
            $conn->rollback();
        }
        if (!($e instanceof InvalidArgumentException)) {
            error_log('add_fresh_purchase_item.php: ' . $e->getMessage());
        }
        fresh_admin_flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : t('mall_fresh_products.save_failed'));
        fresh_save_purchase_draft($supplierId, $purchaseDate, is_string($itemsJson) ? $itemsJson : '[]');
        $conn->close();
        fresh_admin_redirect('add_fresh_purchase_item.php');
    }
}

$draft = fresh_take_purchase_draft();
$selectedSupplierForDraft = null;
if ($draft && !empty($draft['supplier_id'])) {
    $supStmt = $conn->prepare('SELECT id, name FROM suppliers WHERE id = ?');
    $supStmt->bind_param('i', $draft['supplier_id']);
    $supStmt->execute();
    $selectedSupplierForDraft = $supStmt->get_result()->fetch_assoc() ?: null;
    $supStmt->close();
}
$preselectMasterId = (int)($_GET['fresh_product_id'] ?? 0);
$conn->close();
$flash = fresh_admin_take_flash();

function fph($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-lg font-bold"><i class="fas fa-truck-ramp-box mr-2"></i><?php echo fph(t('mall_fresh_products.purchase_link_title')); ?></h1>
        <div class="flex items-center gap-3">
            <?php if ($masters): ?>
                <button type="submit" form="fresh-purchase-form" class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo fph(t('common.save')); ?></button>
            <?php endif; ?>
            <a href="fresh_purchase_items.php" class="text-blue-700 text-sm"><i class="fas fa-arrow-left mr-1"></i><?php echo fph(t('mall_fresh_products.purchase_history_title')); ?></a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'; ?>"><?php echo fph($flash['message']); ?></div>
    <?php endif; ?>

    <?php if (!$masters): ?>
        <div class="p-3 bg-amber-50 rounded max-w-3xl"><?php echo fph(t('mall_fresh_products.masters_missing_notice')); ?></div>
    <?php else: ?>
    <form method="post" id="fresh-purchase-form" class="bg-white shadow rounded-lg p-6">
        <input type="hidden" name="supplier_id" id="supplier_id_input" value="">
        <input type="hidden" name="items_json" id="items_json_input" value="">

        <div class="mb-4">
            <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fph(t('mall_fresh_products.select_supplier_label')); ?> *</label>
            <div id="supplier-search-section" class="relative max-w-xl">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="supplier-search" autocomplete="off" placeholder="<?php echo fph(t('mall_fresh_products.search_supplier_placeholder')); ?>"
                           class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
                <div id="supplier-results" class="hidden absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <div id="supplier-chip" class="hidden max-w-xl flex items-center justify-between bg-emerald-50 border border-emerald-200 rounded-md px-3 py-2 text-sm">
                <b id="supplier-chip-name"></b>
                <button type="button" id="supplier-clear-btn" class="text-red-600 hover:text-red-800"><i class="fas fa-times"></i> <?php echo fph(t('mall_fresh_products.change_selection')); ?></button>
            </div>
            <p id="supplier-hint" class="text-xs text-gray-400 mt-1"><?php echo fph(t('mall_fresh_products.select_supplier_first_hint')); ?></p>
        </div>

        <div class="mb-4 max-w-xs">
            <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fph(t('mall_fresh_products.purchase_date_label')); ?> *</label>
            <input type="date" name="purchase_date" id="purchase-date" required value="<?php echo date('Y-m-d'); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
        </div>

        <div id="line-entry-section" class="hidden border-t border-gray-100 pt-4">
            <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fph(t('mall_fresh_products.fresh_product_label')); ?></label>
            <div id="master-search-section" class="relative">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="master-search" autocomplete="off" placeholder="<?php echo fph(t('mall_fresh_products.search_master_placeholder')); ?>"
                           class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                </div>
                <div id="master-results" class="hidden absolute z-20 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-60 overflow-y-auto"></div>
            </div>
            <p class="text-xs text-gray-400 mt-1"><?php echo fph(t('mall_fresh_products.search_product_to_add_hint')); ?></p>
        </div>

        <div class="mt-6 border-t border-gray-100 pt-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2"><?php echo fph(t('mall_fresh_products.add_item_section_title')); ?></h3>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.fresh_product_label')); ?></th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.quantity_boxes_label')); ?></th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.box_composition_label')); ?></th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.total_cost_label')); ?></th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.unit_cost_label')); ?></th>
                            <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700"><?php echo fph(t('mall_fresh_products.line_total_cost_label')); ?></th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="items-tbody">
                        <tr id="no-items-row"><td colspan="7" class="px-3 py-8 text-center text-gray-500"><?php echo fph(t('mall_fresh_products.no_items_added')); ?></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end gap-6 mt-3 text-sm font-semibold text-gray-700">
                <span><?php echo fph(t('mall_fresh_products.total_items_count_label')); ?>: <span id="totals-count">0</span></span>
                <span><?php echo fph(t('mall_fresh_products.batch_total_cost_label')); ?>: <span id="totals-cost">0.00</span></span>
            </div>
        </div>

        <div class="mt-6"><button type="submit" class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-md hover:bg-blue-700"><?php echo fph(t('common.save')); ?></button></div>
    </form>
    <?php endif; ?>
</div>
<script>
(function () {
    const MASTERS = <?php echo json_encode($masters, JSON_UNESCAPED_UNICODE); ?>;
    const DRAFT = <?php echo json_encode($draft, JSON_UNESCAPED_UNICODE); ?>;
    const DRAFT_SUPPLIER = <?php echo json_encode($selectedSupplierForDraft, JSON_UNESCAPED_UNICODE); ?>;
    const PRESELECT_MASTER_ID = <?php echo (int)$preselectMasterId; ?>;
    const I18N = {
        saleTypePiece: <?php echo json_encode(t('mall_fresh_products.sale_type_piece')); ?>,
        saleTypeWeight: <?php echo json_encode(t('mall_fresh_products.sale_type_weight')); ?>,
        noSearchResults: <?php echo json_encode(t('mall_fresh_products.no_search_results')); ?>,
        selectSupplierFirst: <?php echo json_encode(t('mall_fresh_products.select_supplier_first')); ?>,
        selectMasterForRow: <?php echo json_encode(t('mall_fresh_products.select_master_for_row')); ?>,
        noItemsAdded: <?php echo json_encode(t('mall_fresh_products.no_items_added')); ?>
    };

    const supplierIdInput = document.getElementById('supplier_id_input');
    const itemsJsonInput = document.getElementById('items_json_input');
    const supplierSearchSection = document.getElementById('supplier-search-section');
    const supplierSearchInput = document.getElementById('supplier-search');
    const supplierResults = document.getElementById('supplier-results');
    const supplierChip = document.getElementById('supplier-chip');
    const supplierChipName = document.getElementById('supplier-chip-name');
    const supplierClearBtn = document.getElementById('supplier-clear-btn');
    const supplierHint = document.getElementById('supplier-hint');
    const lineEntrySection = document.getElementById('line-entry-section');

    const itemsTbody = document.getElementById('items-tbody');
    const noItemsRow = document.getElementById('no-items-row');
    const totalsCount = document.getElementById('totals-count');
    const totalsCost = document.getElementById('totals-cost');
    const purchaseForm = document.getElementById('fresh-purchase-form');

    let selectedSupplier = null;
    let items = [];
    let uidSeq = 0;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }

    function findMaster(id) {
        return MASTERS.find(function (m) { return String(m.id) === String(id); }) || null;
    }

    // ---- 실시간 검색 결과 목록 키보드(방향키/Enter/Esc) 탐색 공통 헬퍼 ----
    function createResultKeyboardNav(inputEl, resultsEl, onSelect) {
        let highlightIndex = -1;

        function items() {
            return Array.prototype.slice.call(resultsEl.querySelectorAll('.client-result'));
        }

        function applyHighlight() {
            items().forEach(function (el, idx) {
                el.classList.toggle('bg-indigo-100', idx === highlightIndex);
            });
        }

        function reset() {
            const list = items();
            highlightIndex = list.length ? 0 : -1;
            applyHighlight();
        }

        function move(delta) {
            const list = items();
            if (!list.length) { return; }
            highlightIndex = (highlightIndex + delta + list.length) % list.length;
            applyHighlight();
        }

        function setHighlightIndex(idx) {
            highlightIndex = idx;
            applyHighlight();
        }

        inputEl.addEventListener('keydown', function (event) {
            if (resultsEl.classList.contains('hidden')) { return; }
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                move(-1);
            } else if (event.key === 'Enter') {
                if (highlightIndex < 0 || highlightIndex >= items().length) { return; }
                event.preventDefault();
                onSelect(highlightIndex);
            } else if (event.key === 'Escape') {
                resultsEl.classList.add('hidden');
                resultsEl.innerHTML = '';
            }
        });

        return { reset: reset, setHighlightIndex: setHighlightIndex };
    }

    // ---- 거래처 검색 (AJAX) ----
    let supplierDebounce = null;
    supplierSearchInput.addEventListener('input', function () {
        const term = this.value.trim();
        clearTimeout(supplierDebounce);
        if (!term) { supplierResults.classList.add('hidden'); supplierResults.innerHTML = ''; return; }
        supplierDebounce = setTimeout(function () {
            fetch('ajax_search_suppliers.php?term=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(function (data) { renderSupplierResults(Array.isArray(data) ? data : []); })
                .catch(function () { supplierResults.classList.add('hidden'); });
        }, 300);
    });

    let supplierResultList = [];
    const supplierNav = createResultKeyboardNav(supplierSearchInput, supplierResults, function (idx) {
        selectSupplier(supplierResultList[idx]);
    });

    function renderSupplierResults(list) {
        supplierResultList = list;
        if (!list.length) {
            supplierResults.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">' + escapeHtml(I18N.noSearchResults) + '</div>';
            supplierResults.classList.remove('hidden');
            return;
        }
        supplierResults.innerHTML = list.map(function (s, idx) {
            return '<div class="client-result px-3 py-2 hover:bg-indigo-50 cursor-pointer border-b border-gray-100 last:border-b-0" data-idx="' + idx + '">' +
                '<div class="font-medium text-gray-900">' + escapeHtml(s.name) + '</div>' +
                (s.phone ? '<div class="text-xs text-gray-500">' + escapeHtml(s.phone) + '</div>' : '') +
                '</div>';
        }).join('');
        supplierResults.classList.remove('hidden');
        supplierResults.querySelectorAll('.client-result').forEach(function (el, idx) {
            el.addEventListener('click', function () { selectSupplier(list[idx]); });
            el.addEventListener('mouseenter', function () { supplierNav.setHighlightIndex(idx); });
        });
        supplierNav.reset();
    }
    document.addEventListener('click', function (e) {
        if (!supplierSearchSection.contains(e.target)) { supplierResults.classList.add('hidden'); }
    });

    function selectSupplier(supplier) {
        selectedSupplier = supplier;
        supplierIdInput.value = supplier.id;
        supplierChipName.textContent = supplier.name;
        supplierSearchSection.classList.add('hidden');
        supplierChip.classList.remove('hidden');
        supplierHint.classList.add('hidden');
        lineEntrySection.classList.remove('hidden');
    }

    supplierClearBtn.addEventListener('click', function () {
        selectedSupplier = null;
        supplierIdInput.value = '';
        supplierChip.classList.add('hidden');
        supplierSearchSection.classList.remove('hidden');
        supplierSearchInput.value = '';
        supplierSearchInput.focus();
        supplierHint.classList.remove('hidden');
        lineEntrySection.classList.add('hidden');
    });

    // ---- 신선상품 마스터 검색 (클라이언트 필터, 한글/영문/코드) → 선택 시 표에 바로 행 추가 ----
    const masterSearchSection = document.getElementById('master-search-section');
    const masterSearchInput = document.getElementById('master-search');
    const masterResults = document.getElementById('master-results');

    let masterMatches = [];
    const masterNav = createResultKeyboardNav(masterSearchInput, masterResults, function (idx) {
        addMasterRow(masterMatches[idx]);
    });

    masterSearchInput.addEventListener('input', function () {
        const term = this.value.trim().toLowerCase();
        if (!term) { masterResults.classList.add('hidden'); masterResults.innerHTML = ''; return; }
        const matches = MASTERS.filter(function (m) {
            return (m.code + ' ' + m.name_ko + ' ' + (m.name_en || '')).toLowerCase().includes(term);
        }).slice(0, 20);
        masterMatches = matches;
        if (!matches.length) {
            masterResults.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">' + escapeHtml(I18N.noSearchResults) + '</div>';
            masterResults.classList.remove('hidden');
            return;
        }
        masterResults.innerHTML = matches.map(function (m, idx) {
            return '<div class="client-result px-3 py-2 hover:bg-indigo-50 cursor-pointer border-b border-gray-100 last:border-b-0" data-idx="' + idx + '">' +
                '<div class="font-medium text-gray-900">[' + escapeHtml(m.code) + '] ' + escapeHtml(m.name_ko) + '</div>' +
                '<div class="text-xs text-gray-500">' + escapeHtml(m.name_en || '') + '</div>' +
                '</div>';
        }).join('');
        masterResults.classList.remove('hidden');
        masterResults.querySelectorAll('.client-result').forEach(function (el, idx) {
            el.addEventListener('click', function () { addMasterRow(matches[idx]); });
            el.addEventListener('mouseenter', function () { masterNav.setHighlightIndex(idx); });
        });
        masterNav.reset();
    });
    document.addEventListener('click', function (e) {
        if (!masterSearchSection.contains(e.target)) { masterResults.classList.add('hidden'); }
    });

    function addMasterRow(master) {
        const item = {
            uid: 'r' + (++uidSeq),
            masterId: master.id,
            quantity: 1,
            boxWeightKg: master.pkg_weight_kg !== null ? parseFloat(master.pkg_weight_kg) : null,
            boxPieces: master.pkg_pieces_per_box !== null ? parseInt(master.pkg_pieces_per_box, 10) : null,
            boxCost: 0
        };
        items.push(item);
        appendItemRow(item);
        updateFooterTotals();
        masterSearchInput.value = '';
        masterResults.classList.add('hidden');
        masterResults.innerHTML = '';
    }

    function computeItemTotals(item) {
        const master = findMaster(item.masterId);
        const qty = parseInt(item.quantity, 10) || 0;
        const boxCost = parseFloat(item.boxCost);
        const totalCost = (qty > 0 && boxCost >= 0) ? qty * boxCost : 0;
        let unitCost = null;
        if (master) {
            if (master.sale_type === 'piece') {
                const totalPieces = qty * (parseInt(item.boxPieces, 10) || 0);
                unitCost = totalPieces > 0 ? totalCost / totalPieces : null;
            } else {
                const totalWeight = qty * (parseFloat(item.boxWeightKg) || 0);
                unitCost = totalWeight > 0 ? totalCost / (totalWeight * 10) : null;
            }
        }
        return { master: master, totalCost: totalCost, unitCost: unitCost };
    }

    function updateFooterTotals() {
        totalsCount.textContent = items.length;
        totalsCost.textContent = items.reduce(function (sum, it) { return sum + computeItemTotals(it).totalCost; }, 0).toFixed(2);
        if (!items.length) {
            itemsTbody.innerHTML = '';
            itemsTbody.appendChild(noItemsRow);
        }
    }

    function appendItemRow(item) {
        if (itemsTbody.contains(noItemsRow)) { noItemsRow.remove(); }
        const master = findMaster(item.masterId);

        const tr = document.createElement('tr');
        tr.className = 'border-t border-gray-100 align-top';
        tr.dataset.uid = item.uid;

        const tdMaster = document.createElement('td');
        tdMaster.className = 'px-3 py-2';
        tdMaster.innerHTML = master
            ? ('<div class="font-medium">[' + escapeHtml(master.code) + '] ' + escapeHtml(master.name_ko) + '</div>' +
               '<div class="text-xs text-gray-500">' + escapeHtml(master.name_en || '') + '</div>' +
               '<span class="inline-block mt-1 px-2 py-0.5 rounded-full text-xs ' + (master.sale_type === 'piece' ? 'bg-purple-100 text-purple-700' : 'bg-sky-100 text-sky-700') + '">' + escapeHtml(master.sale_type === 'piece' ? I18N.saleTypePiece : I18N.saleTypeWeight) + '</span>')
            : '-';
        tr.appendChild(tdMaster);

        const tdQty = document.createElement('td');
        tdQty.className = 'px-3 py-2 text-right';
        const qtyInput = document.createElement('input');
        qtyInput.type = 'number'; qtyInput.step = '1'; qtyInput.min = '1';
        qtyInput.value = item.quantity;
        qtyInput.className = 'w-20 border border-gray-300 rounded-md px-2 py-1.5 text-sm text-right';
        tdQty.appendChild(qtyInput);
        tr.appendChild(tdQty);

        const tdComposition = document.createElement('td');
        tdComposition.className = 'px-3 py-2 text-right';
        const weightInput = document.createElement('input');
        weightInput.type = 'number'; weightInput.step = '0.001'; weightInput.min = '0.001';
        weightInput.placeholder = <?php echo json_encode(t('mall_fresh_products.box_weight_kg_label')); ?>;
        weightInput.className = 'w-28 border border-gray-300 rounded-md px-2 py-1.5 text-sm text-right';
        const piecesInput = document.createElement('input');
        piecesInput.type = 'number'; piecesInput.step = '1'; piecesInput.min = '1';
        piecesInput.placeholder = <?php echo json_encode(t('mall_fresh_products.pieces_per_box_label')); ?>;
        piecesInput.className = 'w-28 border border-gray-300 rounded-md px-2 py-1.5 text-sm text-right';
        weightInput.value = item.boxWeightKg || '';
        piecesInput.value = item.boxPieces || '';
        weightInput.classList.toggle('hidden', !!master && master.sale_type === 'piece');
        piecesInput.classList.toggle('hidden', !master || master.sale_type !== 'piece');
        tdComposition.appendChild(weightInput);
        tdComposition.appendChild(piecesInput);
        tr.appendChild(tdComposition);

        const tdCost = document.createElement('td');
        tdCost.className = 'px-3 py-2 text-right';
        const costInput = document.createElement('input');
        costInput.type = 'number'; costInput.step = '0.01'; costInput.min = '0';
        costInput.value = item.boxCost || '';
        costInput.className = 'w-24 border border-gray-300 rounded-md px-2 py-1.5 text-sm text-right';
        tdCost.appendChild(costInput);
        tr.appendChild(tdCost);

        const tdUnitCost = document.createElement('td');
        tdUnitCost.className = 'px-3 py-2 text-right text-gray-600';
        tr.appendChild(tdUnitCost);

        const tdLineTotal = document.createElement('td');
        tdLineTotal.className = 'px-3 py-2 text-right font-semibold';
        tr.appendChild(tdLineTotal);

        const tdDelete = document.createElement('td');
        tdDelete.className = 'px-3 py-2 text-right';
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'text-red-600 hover:text-red-800';
        delBtn.innerHTML = '<i class="fas fa-trash"></i>';
        delBtn.addEventListener('click', function () {
            items = items.filter(function (it) { return it.uid !== item.uid; });
            tr.remove();
            updateFooterTotals();
        });
        tdDelete.appendChild(delBtn);
        tr.appendChild(tdDelete);

        function refreshRow() {
            const result = computeItemTotals(item);
            tdUnitCost.textContent = result.unitCost !== null ? result.unitCost.toFixed(2) : '-';
            tdLineTotal.textContent = result.totalCost.toFixed(2);
            updateFooterTotals();
        }

        qtyInput.addEventListener('input', function () { item.quantity = parseInt(this.value, 10) || 0; refreshRow(); });
        weightInput.addEventListener('input', function () { item.boxWeightKg = parseFloat(this.value) || null; refreshRow(); });
        piecesInput.addEventListener('input', function () { item.boxPieces = parseInt(this.value, 10) || null; refreshRow(); });
        costInput.addEventListener('input', function () { item.boxCost = parseFloat(this.value) || 0; refreshRow(); });

        qtyInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                const compositionInput = weightInput.classList.contains('hidden') ? piecesInput : weightInput;
                compositionInput.focus();
                compositionInput.select();
            }
        });
        [weightInput, piecesInput].forEach(function (input) {
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    costInput.focus();
                    costInput.select();
                }
            });
        });
        costInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                masterSearchInput.value = '';
                masterSearchInput.focus();
            }
        });

        itemsTbody.appendChild(tr);
        refreshRow();
        qtyInput.focus();
        qtyInput.select();
    }

    purchaseForm.addEventListener('submit', function (e) {
        if (!selectedSupplier) { e.preventDefault(); alert(I18N.selectSupplierFirst); return; }
        if (!items.length) { e.preventDefault(); alert(I18N.noItemsAdded); return; }

        const payload = [];
        for (let i = 0; i < items.length; i++) {
            const it = items[i];
            const master = findMaster(it.masterId);
            if (!master) { e.preventDefault(); alert((i + 1) + '번째 항목: ' + I18N.selectMasterForRow); return; }
            const qty = parseInt(it.quantity, 10);
            if (!(qty > 0)) { e.preventDefault(); alert((i + 1) + '번째 항목: 수량을 올바르게 입력해 주세요.'); return; }
            if (!(parseFloat(it.boxCost) >= 0)) { e.preventDefault(); alert((i + 1) + '번째 항목: 박스당 원가를 올바르게 입력해 주세요.'); return; }

            const row = {
                fresh_product_id: master.id,
                quantity: qty,
                box_cost: parseFloat(it.boxCost)
            };
            if (master.sale_type === 'piece') {
                if (!(parseInt(it.boxPieces, 10) > 0)) { e.preventDefault(); alert((i + 1) + '번째 항목: 박스당 개수를 올바르게 입력해 주세요.'); return; }
                row.box_pieces = parseInt(it.boxPieces, 10);
            } else {
                if (!(parseFloat(it.boxWeightKg) > 0)) { e.preventDefault(); alert((i + 1) + '번째 항목: 박스당 무게를 올바르게 입력해 주세요.'); return; }
                row.box_weight_kg = parseFloat(it.boxWeightKg);
            }
            payload.push(row);
        }
        itemsJsonInput.value = JSON.stringify(payload);
    });

    // ---- 저장 실패 후 복원(draft) ----
    if (DRAFT) {
        if (DRAFT_SUPPLIER) { selectSupplier(DRAFT_SUPPLIER); }
        if (DRAFT.purchase_date) { document.getElementById('purchase-date').value = DRAFT.purchase_date; }
        try {
            const restored = JSON.parse(DRAFT.items_json);
            if (Array.isArray(restored)) {
                restored.forEach(function (row) {
                    const item = {
                        uid: 'r' + (++uidSeq),
                        masterId: row.fresh_product_id || null,
                        quantity: row.quantity || 1,
                        boxWeightKg: row.box_weight_kg || null,
                        boxPieces: row.box_pieces || null,
                        boxCost: row.box_cost || 0
                    };
                    items.push(item);
                    appendItemRow(item);
                });
                updateFooterTotals();
            }
        } catch (e) { /* ignore malformed draft */ }
    } else if (PRESELECT_MASTER_ID) {
        // 목록 화면의 "매입" 바로가기 링크로 들어온 경우, 해당 신선상품을 미리 목록에 추가해 둔다
        const preMaster = findMaster(PRESELECT_MASTER_ID);
        if (preMaster) { addMasterRow(preMaster); }
    }
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
