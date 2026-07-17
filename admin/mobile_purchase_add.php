<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

$has_purchase_permission = has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
if (!$has_purchase_permission) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

$conn = get_db_connection();

// 현재 사용자 점포 정보 (mobile_main.php와 동일 패턴)
$current_store_name = '본점';
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT s.name AS store_name, s.id AS store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $current_store_name = $row['store_name'] ?? '본점';
        $current_store_id = $row['store_id'];
        if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
            $first = $conn->query("SELECT id, name FROM stores ORDER BY id LIMIT 1")->fetch_assoc();
            if ($first) {
                $current_store_id = $first['id'];
                $current_store_name = $first['name'];
            }
        }
    }
    $stmt->close();
}

$edit_purchase_id = (int)($_GET['edit_purchase_id'] ?? 0);
$existing_purchase = null;
$existing_items = [];

if ($edit_purchase_id) {
    $stmt = $conn->prepare("SELECT p.*, s.name AS supplier_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.purchase_id = ?");
    $stmt->bind_param('i', $edit_purchase_id);
    $stmt->execute();
    $existing_purchase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing_purchase || (int)$existing_purchase['is_confirmed'] === 1) {
        $_SESSION['flash'] = [
            'type' => 'error',
            'message' => !$existing_purchase ? '매입 내역을 찾을 수 없습니다.' : '이미 확정된 매입은 품목을 추가할 수 없습니다.',
        ];
        header('Location: purchase_management.php');
        exit;
    }

    $stmt = $conn->prepare("
        SELECT pi.item_id, pi.product_id, pi.purchase_type, pi.quantity, pi.unit_price,
               pr.name_ko AS product_name, pr.name_en AS product_name_en, pr.sku
        FROM purchase_items pi
        JOIN products pr ON pi.product_id = pr.id
        WHERE pi.purchase_id = ?
        ORDER BY pi.sort_order
    ");
    $stmt->bind_param('i', $edit_purchase_id);
    $stmt->execute();
    $existing_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$errors = [];
$saved_purchase_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_edit_id = (int)($_POST['edit_purchase_id'] ?? 0);
    $supplier_id  = (int)($_POST['supplier_id'] ?? 0);
    $purchase_date = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];

    if (empty($items)) {
        $errors[] = '추가할 상품이 없습니다.';
    }
    if (!$post_edit_id && $supplier_id <= 0) {
        $errors[] = '거래처를 선택하세요.';
    }
    if (empty($current_store_id) && !$post_edit_id) {
        $errors[] = '점포 정보가 없습니다. 관리자에게 문의하세요.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            if ($post_edit_id) {
                $chk = $conn->prepare("SELECT is_confirmed FROM purchases WHERE purchase_id = ? FOR UPDATE");
                $chk->bind_param('i', $post_edit_id);
                $chk->execute();
                $cur = $chk->get_result()->fetch_assoc();
                $chk->close();
                if (!$cur || (int)$cur['is_confirmed'] === 1) {
                    throw new Exception('이미 확정되었거나 존재하지 않는 매입입니다.');
                }
                $purchase_id = $post_edit_id;

                $max_sort_row = $conn->query("SELECT COALESCE(MAX(sort_order), 0) AS m FROM purchase_items WHERE purchase_id = {$purchase_id}")->fetch_assoc();
                $sort_order = (int)$max_sort_row['m'] + 1;
            } else {
                $stmt = $conn->prepare("INSERT INTO purchases (store_id, supplier_id, purchase_date, total_amount, total_items) VALUES (?, ?, ?, 0, 0)");
                $stmt->bind_param('iis', $current_store_id, $supplier_id, $purchase_date);
                $stmt->execute();
                $purchase_id = $stmt->insert_id;
                $stmt->close();
                $sort_order = 1;
            }

            // 상품별 VAT 적용 여부 조회용
            $vat_stmt = $conn->prepare("SELECT is_vat_applicable FROM products WHERE id = ?");
            $item_stmt = $conn->prepare(
                "INSERT INTO purchase_items
                    (purchase_id, product_id, purchase_type, quantity, unit_price,
                     vat_included, original_unit_price, vat_amount, discount_rate, discounted_unit_price, sort_order)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, 0, ?, ?)"
            );

            foreach ($items as $item) {
                $product_id    = (int)($item['product_id'] ?? 0);
                $quantity      = (int)($item['quantity'] ?? 0);
                $unit_cost     = (float)($item['unit_price'] ?? 0);
                $purchase_type = (($item['purchase_type'] ?? 'box') === 'piece') ? 'piece' : 'box';

                if ($product_id <= 0 || $quantity <= 0 || $unit_cost < 0) {
                    continue;
                }

                $vat_stmt->bind_param('i', $product_id);
                $vat_stmt->execute();
                $vat_row = $vat_stmt->get_result()->fetch_assoc();
                $is_vat_applicable = $vat_row ? (int)$vat_row['is_vat_applicable'] : 1;
                $vat_amount = $is_vat_applicable ? ($unit_cost - $unit_cost / 1.12) : 0;

                $item_stmt->bind_param(
                    'iisiddddi',
                    $purchase_id, $product_id, $purchase_type, $quantity, $unit_cost,
                    $unit_cost, $vat_amount, $unit_cost, $sort_order
                );
                $item_stmt->execute();
                $sort_order++;
            }
            $vat_stmt->close();
            $item_stmt->close();

            $conn->query("
                UPDATE purchases SET
                    total_amount = (SELECT SUM(quantity * unit_price) FROM purchase_items WHERE purchase_id = {$purchase_id}),
                    total_items  = (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = {$purchase_id})
                WHERE purchase_id = {$purchase_id}
            ");

            $conn->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => '매입이 저장되었습니다.'];
            header('Location: mobile_purchase_add.php?saved=' . $purchase_id);
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = '저장 실패: ' . $e->getMessage();
        }
    }
}

$suppliers = $conn->query("SELECT id, name FROM suppliers ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$saved_flash = null;
if (isset($_GET['saved'])) {
    $saved_flash = (int)$_GET['saved'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#047857">
<title>모바일 매입 등록 - HOME K MART</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif;
    background: #f3f4f6;
    padding-bottom: 84px;
}
.topbar {
    position: sticky; top: 0; z-index: 20;
    background: linear-gradient(135deg, #047857, #065f46);
    color: #fff;
    padding: 0.9rem 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.topbar-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.topbar h1 { font-size: 1.05rem; font-weight: 700; }
.topbar a.back { color: #d1fae5; font-size: 1.1rem; text-decoration: none; padding: 0.25rem; }
.topbar .store-tag { font-size: 0.75rem; color: #d1fae5; margin-top: 0.15rem; }

.section {
    background: #fff;
    border-radius: 14px;
    margin: 0.75rem;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}
.section-title { font-size: 0.8rem; font-weight: 700; color: #6b7280; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.03em; }

select, input[type="text"], input[type="date"], input[type="number"] {
    width: 100%;
    padding: 0.7rem 0.8rem;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 1rem;
    background: #fff;
}
select:focus, input:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }

.readonly-box {
    padding: 0.7rem 0.8rem;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    font-size: 0.95rem;
    color: #374151;
    font-weight: 600;
}

.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
.field-label { font-size: 0.8rem; font-weight: 600; color: #4b5563; margin-bottom: 0.3rem; display: block; }

.search-row { display: flex; gap: 0.5rem; }
.search-row input { flex: 1; }
.scan-btn {
    flex-shrink: 0;
    width: 48px; height: 48px;
    border-radius: 10px;
    background: #059669;
    color: #fff;
    border: none;
    font-size: 1.2rem;
    display: flex; align-items: center; justify-content: center;
}
.scan-btn:active { background: #047857; }

#searchResults {
    margin-top: 0.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    display: none;
}
.search-result-row {
    padding: 0.65rem 0.8rem;
    border-bottom: 1px solid #f3f4f6;
    display: flex; flex-direction: column;
}
.search-result-row:last-child { border-bottom: none; }
.search-result-row:active { background: #f0fdf4; }
.search-result-name { font-weight: 600; font-size: 0.9rem; color: #111827; }
.search-result-meta { font-size: 0.75rem; color: #9ca3af; margin-top: 0.1rem; }

.entry-card {
    display: none;
    border: 2px solid #059669;
    border-radius: 12px;
    padding: 0.9rem;
    margin-top: 0.75rem;
    background: #f0fdf4;
}
.entry-card .name { font-weight: 700; color: #065f46; margin-bottom: 0.6rem; font-size: 0.95rem; }
.unit-toggle { display: flex; border: 1px solid #d1d5db; border-radius: 10px; overflow: hidden; }
.unit-toggle button {
    flex: 1; padding: 0.65rem; font-size: 0.85rem; font-weight: 700;
    border: none; background: #fff; color: #6b7280;
}
.unit-toggle button.active.box { background: #d97706; color: #fff; }
.unit-toggle button.active.piece { background: #2563eb; color: #fff; }
.entry-actions { display: flex; gap: 0.6rem; margin-top: 0.8rem; }
.btn-cancel, .btn-add {
    flex: 1; padding: 0.75rem; border-radius: 10px; border: none; font-weight: 700; font-size: 0.9rem;
}
.btn-cancel { background: #e5e7eb; color: #4b5563; }
.btn-add { background: #059669; color: #fff; }

.item-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 0.75rem 0.9rem;
    margin-bottom: 0.6rem;
    display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;
}
.item-card .info { flex: 1; min-width: 0; }
.item-card .pname { font-weight: 600; font-size: 0.9rem; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-card .pmeta { font-size: 0.75rem; color: #6b7280; margin-top: 0.15rem; }
.unit-badge { display: inline-block; font-size: 0.7rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 999px; margin-right: 0.3rem; }
.unit-badge.box { background: #fef3c7; color: #92400e; }
.unit-badge.piece { background: #dbeafe; color: #1e40af; }
.item-card .amount { font-weight: 700; color: #059669; font-size: 0.9rem; white-space: nowrap; }
.item-remove { background: none; border: none; color: #ef4444; font-size: 1.1rem; padding: 0.3rem; }

.existing-item { opacity: 0.75; }

.empty-hint { text-align: center; color: #9ca3af; padding: 1.5rem 0; font-size: 0.85rem; }

.bottom-bar {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 30;
    background: #fff; border-top: 1px solid #e5e7eb;
    padding: 0.6rem 1rem; padding-bottom: calc(0.6rem + env(safe-area-inset-bottom));
    display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
    box-shadow: 0 -2px 8px rgba(0,0,0,0.08);
}
.bottom-summary { font-size: 0.8rem; color: #4b5563; }
.bottom-summary b { color: #059669; font-size: 1rem; }
.save-btn {
    background: #059669; color: #fff; font-weight: 700; font-size: 0.95rem;
    padding: 0.75rem 1.5rem; border-radius: 10px; border: none;
}
.save-btn:disabled { background: #9ca3af; }

.alert { margin: 0.75rem; padding: 0.8rem 1rem; border-radius: 10px; font-size: 0.85rem; }
.alert-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

#scannerModal {
    display: none;
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,0.85);
    align-items: center; justify-content: center;
    flex-direction: column;
}
#scannerModal.open { display: flex; }
#scanner-reader { width: 92vw; max-width: 420px; border-radius: 12px; overflow: hidden; }
.scanner-close {
    margin-top: 1.2rem;
    background: #fff; color: #111827; border: none;
    padding: 0.7rem 1.5rem; border-radius: 999px; font-weight: 700;
}
.scanner-hint { color: #d1d5db; font-size: 0.8rem; margin-top: 0.8rem; text-align: center; padding: 0 1rem; }
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-row">
        <a href="mobile_purchase_list.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h1><i class="fas fa-truck-loading mr-1"></i> <?php echo $edit_purchase_id ? '매입 품목 추가 #' . str_pad($edit_purchase_id, 4, '0', STR_PAD_LEFT) : '모바일 매입 등록'; ?></h1>
        <span style="width:1.1rem"></span>
    </div>
    <div class="store-tag"><i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($current_store_name); ?></div>
</div>

<?php if ($saved_flash): ?>
<div class="alert alert-success"><i class="fas fa-check-circle mr-1"></i>매입 #<?php echo str_pad($saved_flash, 4, '0', STR_PAD_LEFT); ?>이(가) 저장되었습니다.</div>
<?php endif; ?>

<?php foreach ($errors as $e): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($e); ?></div>
<?php endforeach; ?>

<form id="purchaseForm" method="post">
<input type="hidden" name="items_json" id="items_json">

<!-- 거래처 -->
<div class="section">
    <div class="section-title">거래처</div>
    <?php if ($existing_purchase): ?>
        <div class="readonly-box"><i class="fas fa-building mr-1 text-gray-400"></i><?php echo htmlspecialchars($existing_purchase['supplier_name']); ?></div>
        <input type="hidden" name="edit_purchase_id" value="<?php echo $edit_purchase_id; ?>">
    <?php else: ?>
        <input type="hidden" name="supplier_id" id="supplier_id" value="" required>
        <div class="readonly-box" id="supplierSelectedBox" style="display:none; cursor:pointer;">
            <i class="fas fa-building mr-1 text-gray-400"></i><span id="supplierSelectedName"></span>
            <span style="float:right; color:#059669; font-weight:700; font-size:0.8rem;">변경</span>
        </div>
        <div id="supplierSearchWrap">
            <input type="text" id="supplierSearchInput" placeholder="거래처명 검색 (예: 홈케이마트)" autocomplete="off">
            <div id="supplierResults" style="margin-top:0.5rem; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; max-height:260px; overflow-y:auto; display:none;"></div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($existing_items)): ?>
<div class="section">
    <div class="section-title">기존 등록 품목 (<?php echo count($existing_items); ?>건)</div>
    <?php foreach ($existing_items as $it): ?>
    <div class="item-card existing-item">
        <div class="info">
            <div class="pname"><?php echo htmlspecialchars($it['product_name']); ?></div>
            <div class="pmeta">
                <span class="unit-badge <?php echo $it['purchase_type']; ?>"><?php echo $it['purchase_type'] === 'box' ? 'BOX' : 'PCS'; ?></span>
                <?php echo number_format($it['quantity']); ?>개 × <?php echo number_format($it['unit_price'], 2); ?>
            </div>
        </div>
        <div class="amount"><?php echo number_format($it['quantity'] * $it['unit_price'], 2); ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 상품 검색/스캔 -->
<div class="section">
    <div class="section-title">상품 검색 / 바코드 스캔</div>
    <div class="search-row">
        <input type="text" id="searchInput" placeholder="상품명 또는 바코드 입력" autocomplete="off">
        <button type="button" class="scan-btn" id="scanBtn"><i class="fas fa-camera"></i></button>
    </div>
    <div id="searchResults"></div>

    <!-- 선택된 상품 입력 카드 -->
    <div class="entry-card" id="entryCard">
        <div class="name" id="entryName"></div>
        <div class="row-2">
            <div>
                <label class="field-label">수량</label>
                <input type="number" id="entryQty" min="1" value="1" inputmode="numeric">
            </div>
            <div id="entryPpbGroup">
                <label class="field-label">박스당 수량</label>
                <input type="number" id="entryPpb" min="1" value="1" inputmode="numeric">
            </div>
        </div>
        <div class="row-2" style="margin-top:0.6rem;">
            <div>
                <label class="field-label">원가</label>
                <input type="number" id="entryPrice" min="0" step="0.01" inputmode="decimal">
            </div>
            <div>
                <label class="field-label">매입 단위</label>
                <div class="unit-toggle">
                    <button type="button" id="unitBoxBtn" class="active box">BOX</button>
                    <button type="button" id="unitPieceBtn" class="piece">PCS</button>
                </div>
            </div>
        </div>
        <div class="entry-actions">
            <button type="button" class="btn-cancel" id="entryCancelBtn">취소</button>
            <button type="button" class="btn-add" id="entryAddBtn"><i class="fas fa-plus mr-1"></i>목록에 추가</button>
        </div>
    </div>
</div>

<!-- 추가할 품목 리스트 -->
<div class="section">
    <div class="section-title">추가할 품목</div>
    <div id="itemList"></div>
    <div class="empty-hint" id="emptyHint">검색 또는 바코드 스캔으로 상품을 추가하세요.</div>
</div>

</form>

<div class="bottom-bar">
    <div class="bottom-summary">
        <span id="itemCount">0</span>건 · 합계 <b id="totalAmount">0.00</b>
    </div>
    <button type="button" class="save-btn" id="saveBtn" disabled onclick="submitPurchase()">
        <i class="fas fa-save mr-1"></i>저장
    </button>
</div>

<!-- 바코드 스캐너 모달 -->
<div id="scannerModal">
    <div id="scanner-reader"></div>
    <div class="scanner-hint">바코드를 화면 중앙에 맞춰주세요.</div>
    <button type="button" class="scanner-close" id="scannerCloseBtn">닫기</button>
</div>

<script>
(function() {
    const editPurchaseId = <?php echo (int)$edit_purchase_id; ?>;
    const supplierSelect = document.getElementById('supplier_id');
    const supplierSearchInput = document.getElementById('supplierSearchInput');
    const supplierResults = document.getElementById('supplierResults');
    const supplierSelectedBox = document.getElementById('supplierSelectedBox');
    const supplierSelectedName = document.getElementById('supplierSelectedName');
    const supplierSearchWrap = document.getElementById('supplierSearchWrap');
    const suppliersData = <?php echo json_encode(array_map(function($s) { return ['id' => (int)$s['id'], 'name' => $s['name']]; }, $suppliers)); ?>;
    const searchInput = document.getElementById('searchInput');
    const searchResults = document.getElementById('searchResults');
    const entryCard = document.getElementById('entryCard');
    const entryName = document.getElementById('entryName');
    const entryQty = document.getElementById('entryQty');
    const entryPpb = document.getElementById('entryPpb');
    const entryPrice = document.getElementById('entryPrice');
    const unitBoxBtn = document.getElementById('unitBoxBtn');
    const unitPieceBtn = document.getElementById('unitPieceBtn');
    const entryPpbGroup = document.getElementById('entryPpbGroup');
    const itemListEl = document.getElementById('itemList');
    const emptyHint = document.getElementById('emptyHint');

    let selectedProduct = null;
    let currentUnit = 'box';
    let priceManuallyEdited = false;
    let items = []; // { product_id, name, sku, quantity, pieces_per_box, unit_price, purchase_type }
    let searchTimeout;

    entryPrice.addEventListener('input', () => { priceManuallyEdited = true; });

    function supplierSelected() {
        return editPurchaseId > 0 || (supplierSelect && supplierSelect.value);
    }

    // ── 거래처 검색 선택 (드롭다운 대신 검색 방식) ──────────
    if (supplierSearchInput) {
        function renderSupplierResults(list) {
            if (!list.length) {
                supplierResults.innerHTML = '<div class="search-result-row">검색 결과가 없습니다.</div>';
                supplierResults.style.display = 'block';
                return;
            }
            supplierResults.innerHTML = '';
            list.forEach(s => {
                const row = document.createElement('div');
                row.className = 'search-result-row';
                row.innerHTML = '<div class="search-result-name">' + escHtml(s.name) + '</div>';
                row.addEventListener('click', () => selectSupplier(s));
                supplierResults.appendChild(row);
            });
            supplierResults.style.display = 'block';
        }

        function selectSupplier(s) {
            supplierSelect.value = s.id;
            supplierSelectedName.textContent = s.name;
            supplierSelectedBox.style.display = 'block';
            supplierSearchWrap.style.display = 'none';
            supplierResults.style.display = 'none';
            supplierSearchInput.value = '';
        }

        supplierSearchInput.addEventListener('input', function() {
            const term = this.value.trim().toLowerCase();
            if (term.length < 1) {
                supplierResults.style.display = 'none';
                supplierResults.innerHTML = '';
                return;
            }
            const matches = suppliersData.filter(s => s.name.toLowerCase().includes(term)).slice(0, 30);
            renderSupplierResults(matches);
        });

        supplierSelectedBox.addEventListener('click', function() {
            supplierSelect.value = '';
            supplierSelectedBox.style.display = 'none';
            supplierSearchWrap.style.display = 'block';
            supplierSearchInput.focus();
        });

        document.addEventListener('click', function(e) {
            if (!supplierSearchInput.contains(e.target) && !supplierResults.contains(e.target)) {
                supplierResults.style.display = 'none';
            }
        });
    }

    // ── 상품 검색 (텍스트 입력) ─────────────────────────────
    searchInput.addEventListener('input', function() {
        const term = this.value.trim();
        clearTimeout(searchTimeout);
        if (term.length < 2) {
            searchResults.style.display = 'none';
            searchResults.innerHTML = '';
            return;
        }
        if (!supplierSelected()) {
            alert('먼저 거래처를 선택하세요.');
            this.blur();
            return;
        }
        searchTimeout = setTimeout(() => runSearch(term), 300);
    });

    function runSearch(term) {
        fetch('ajax_search_products.php?term=' + encodeURIComponent(term))
            .then(r => r.json())
            .then(data => {
                if (!Array.isArray(data)) { data = []; }
                if (data.length === 1 && data[0].exact_match) {
                    selectProduct(data[0]);
                    searchResults.style.display = 'none';
                    searchInput.value = '';
                    return;
                }
                renderResults(data);
            })
            .catch(() => {
                searchResults.innerHTML = '<div class="search-result-row">검색 중 오류가 발생했습니다.</div>';
                searchResults.style.display = 'block';
            });
    }

    function renderResults(list) {
        if (!list.length) {
            searchResults.innerHTML = '<div class="search-result-row">검색 결과가 없습니다.</div>';
            searchResults.style.display = 'block';
            return;
        }
        searchResults.innerHTML = '';
        list.forEach(p => {
            const row = document.createElement('div');
            row.className = 'search-result-row';
            row.innerHTML = '<div class="search-result-name">' + escHtml(p.name_ko || p.name_en || ('#' + p.id)) + '</div>' +
                             '<div class="search-result-meta">SKU: ' + escHtml(p.sku || '-') + '</div>';
            row.addEventListener('click', () => {
                selectProduct(p);
                searchResults.style.display = 'none';
                searchInput.value = '';
            });
            searchResults.appendChild(row);
        });
        searchResults.style.display = 'block';
    }

    function selectProduct(p) {
        selectedProduct = p;
        entryName.textContent = p.name_ko || p.name_en || ('#' + p.id);
        entryQty.value = 1;
        entryPpb.value = p.pieces_per_box || 1;
        priceManuallyEdited = false;
        applyUnitPrefill();
        entryCard.style.display = 'block';
        entryQty.focus();
    }

    function applyUnitPrefill() {
        if (!selectedProduct || priceManuallyEdited) return;
        const prefillPrice = currentUnit === 'box'
            ? (selectedProduct.box_price || selectedProduct.cost_price || '')
            : (selectedProduct.cost_price || '');
        entryPrice.value = prefillPrice || '';
    }

    // ── BOX/PCS 토글 ─────────────────────────────────────
    function setUnit(unit) {
        currentUnit = unit;
        unitBoxBtn.classList.toggle('active', unit === 'box');
        unitPieceBtn.classList.toggle('active', unit === 'piece');
        entryPpbGroup.style.display = unit === 'piece' ? 'none' : 'block';
        applyUnitPrefill();
    }
    unitBoxBtn.addEventListener('click', () => setUnit('box'));
    unitPieceBtn.addEventListener('click', () => setUnit('piece'));

    document.getElementById('entryCancelBtn').addEventListener('click', () => {
        selectedProduct = null;
        entryCard.style.display = 'none';
    });

    // ── 목록에 추가 ──────────────────────────────────────
    document.getElementById('entryAddBtn').addEventListener('click', function() {
        if (!selectedProduct) return;
        const qty = parseInt(entryQty.value) || 0;
        const ppb = parseInt(entryPpb.value) || 1;
        const price = parseFloat(entryPrice.value);

        if (qty <= 0) { alert('수량을 입력하세요.'); return; }
        if (isNaN(price) || price < 0) { alert('원가를 입력하세요.'); return; }

        // 박스당수량이 상품 마스터 값과 다르면 공용 상품 정보 업데이트 (기존 확인 후 진행)
        const originalPpb = parseInt(selectedProduct.pieces_per_box) || 1;
        if (currentUnit === 'box' && ppb !== originalPpb) {
            if (confirm('박스당 수량을 ' + originalPpb + ' → ' + ppb + '(으)로 변경하면 이 상품의 다른 매입/재고 계산에도 함께 반영됩니다. 변경할까요?')) {
                fetch('ajax_update_pieces_per_box.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: selectedProduct.id, pieces_per_box: ppb })
                }).catch(() => {});
            }
        }

        addOrMergeItem({
            product_id: selectedProduct.id,
            name: selectedProduct.name_ko || selectedProduct.name_en || ('#' + selectedProduct.id),
            sku: selectedProduct.sku || '',
            quantity: qty,
            unit_price: price,
            purchase_type: currentUnit
        });

        selectedProduct = null;
        entryCard.style.display = 'none';
    });

    function addOrMergeItem(newItem) {
        const existing = items.find(it => it.product_id === newItem.product_id && it.purchase_type === newItem.purchase_type);
        if (existing) {
            existing.quantity += newItem.quantity;
            existing.unit_price = newItem.unit_price;
        } else {
            items.push(newItem);
        }
        renderItemList();
    }

    function renderItemList() {
        itemListEl.innerHTML = '';
        emptyHint.style.display = items.length ? 'none' : 'block';

        let totalQty = 0, totalAmount = 0;
        items.forEach((it, idx) => {
            totalAmount += it.quantity * it.unit_price;
            const card = document.createElement('div');
            card.className = 'item-card';
            card.innerHTML =
                '<div class="info">' +
                    '<div class="pname">' + escHtml(it.name) + '</div>' +
                    '<div class="pmeta"><span class="unit-badge ' + it.purchase_type + '">' + (it.purchase_type === 'box' ? 'BOX' : 'PCS') + '</span>' +
                    it.quantity + '개 × ' + it.unit_price.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</div>' +
                '</div>' +
                '<div class="amount">' + (it.quantity * it.unit_price).toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</div>' +
                '<button type="button" class="item-remove" data-idx="' + idx + '"><i class="fas fa-trash"></i></button>';
            card.querySelector('.item-remove').addEventListener('click', function() {
                items.splice(parseInt(this.dataset.idx), 1);
                renderItemList();
            });
            itemListEl.appendChild(card);
        });

        document.getElementById('itemCount').textContent = items.length;
        document.getElementById('totalAmount').textContent = totalAmount.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById('saveBtn').disabled = items.length === 0;
    }

    window.submitPurchase = function() {
        if (!items.length) return;
        if (!supplierSelected()) { alert('거래처를 선택하세요.'); return; }
        document.getElementById('items_json').value = JSON.stringify(items);
        document.getElementById('saveBtn').disabled = true;
        document.getElementById('purchaseForm').submit();
    };

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // ── 카메라 바코드 스캔 (html5-qrcode) ─────────────────
    let html5QrCode = null;
    const scannerModal = document.getElementById('scannerModal');
    let lastDecodedText = null;
    let decodedMatchCount = 0;

    document.getElementById('scanBtn').addEventListener('click', function() {
        if (!supplierSelected()) { alert('먼저 거래처를 선택하세요.'); return; }
        openScanner();
    });
    document.getElementById('scannerCloseBtn').addEventListener('click', closeScanner);

    function onScanSuccess(decodedText) {
        // 같은 값이 연속 3프레임 이상 읽혀야 확정 (오포맷 혼동/흔들림에 의한 오독 방지)
        if (decodedText === lastDecodedText) {
            decodedMatchCount++;
        } else {
            lastDecodedText = decodedText;
            decodedMatchCount = 1;
        }
        if (decodedMatchCount < 3) return;

        if (navigator.vibrate) navigator.vibrate(80);
        closeScanner();

        // 기존에 검색창에 입력되어 있던 내용과 대기 중인 검색을 지우고 스캔값으로 갱신
        clearTimeout(searchTimeout);
        searchResults.style.display = 'none';
        searchResults.innerHTML = '';
        searchInput.value = '';
        searchInput.value = decodedText;
        runSearch(decodedText);
    }

    function startScannerWith(cameraIdOrConfig) {
        return html5QrCode.start(
            cameraIdOrConfig,
            {
                fps: 10,
                qrbox: { width: 320, height: 140 } // 1D 바코드 비율에 맞춘 가로로 넓은 스캔박스
            },
            onScanSuccess,
            function() { /* 프레임별 인식 실패는 무시 */ }
        );
    }

    function createScanner() {
        // 한국(880) 상품은 EAN-13/EAN-8, 필리핀 유통 상품은 미국계 수입품이 많아
        // UPC-A/UPC-E도 그대로 쓰인다. 모두 GS1 계열 숫자 바코드라 서로 혼동될
        // 위험 없이 함께 제한하면, Code39/Codabar 등 완전히 다른 심볼로지와의
        // 오인식만 걸러낼 수 있다. 생성 자체가 실패하면(구버전 등) 제한 없는
        // 기본 설정으로 안전하게 폴백한다.
        try {
            if (typeof Html5QrcodeSupportedFormats !== 'undefined') {
                return new Html5Qrcode('scanner-reader', {
                    formatsToSupport: [
                        Html5QrcodeSupportedFormats.EAN_13,
                        Html5QrcodeSupportedFormats.EAN_8,
                        Html5QrcodeSupportedFormats.UPC_A,
                        Html5QrcodeSupportedFormats.UPC_E
                    ],
                    verbose: false
                });
            }
        } catch (e) {
            console.warn('포맷 제한 스캐너 생성 실패, 기본 설정으로 폴백:', e);
        }
        return new Html5Qrcode('scanner-reader');
    }

    function openScanner() {
        scannerModal.classList.add('open');
        lastDecodedText = null;
        decodedMatchCount = 0;

        // 스캔 시작 시 기존 검색창 내용/대기 중인 검색 초기화
        clearTimeout(searchTimeout);
        searchInput.value = '';
        searchResults.style.display = 'none';
        searchResults.innerHTML = '';

        html5QrCode = createScanner();

        // 1차 시도: 표준 facingMode 제약. 일부 기종(특히 후면 카메라가 여러 개인
        // 중국 브랜드 단말)에서는 이 제약 조건 매칭 자체가 실패하는 경우가 있어,
        // 실패 시 실제 카메라 장치 목록에서 후면 카메라를 직접 지정해 재시도한다.
        startScannerWith({ facingMode: 'environment' }).catch(function(err) {
            console.warn('facingMode 방식 카메라 시작 실패, 장치 목록으로 재시도:', err);
            return Html5Qrcode.getCameras().then(function(devices) {
                if (!devices || !devices.length) {
                    throw err;
                }
                const backCamera = devices.find(d => /back|rear|environment|후면/i.test(d.label)) || devices[devices.length - 1];
                return startScannerWith(backCamera.id);
            });
        }).catch(function(err) {
            console.error('카메라 시작 실패:', err);
            const name = (err && err.name) || '';
            let msg = '카메라를 시작할 수 없습니다.';
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                msg = '카메라 접근 권한이 거부되었습니다. 브라우저 설정에서 카메라 권한을 허용해주세요.';
            } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                msg = '사용 가능한 카메라를 찾을 수 없습니다.';
            } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                msg = '카메라를 다른 앱이 사용 중이거나 하드웨어 오류가 발생했습니다.';
            } else if (err && err.message) {
                msg = '카메라를 시작할 수 없습니다: ' + err.message;
            }
            alert(msg);
            scannerModal.classList.remove('open');
        });
    }

    function closeScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => html5QrCode.clear()).catch(() => {});
        }
        scannerModal.classList.remove('open');
    }

    // 초기 단위 표시
    setUnit('box');
})();
</script>

</body>
</html>
