<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/mobile_detect.php';
redirect_if_mobile('mobile_main.php', true);

$page_title = '행사상품 관리 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('store_transfer_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$is_super = ($_SESSION['role'] === 'super_admin');
$session_store_id = (int)($current_store_id ?? $_SESSION['store_id'] ?? 0);

// 필터 파라미터
$filter_status   = $_GET['status'] ?? 'all';
$filter_store_id = $is_super ? (int)($_GET['store_id'] ?? 0) : $session_store_id;

// 수정 모드
$edit_id   = isset($_GET['edit']) && is_numeric($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_data = null;

$events  = [];
$stores  = [];
$errors  = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 점포 목록 (super_admin용)
    if ($is_super) {
        $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }

    // 수정 데이터 로드
    if ($edit_id) {
        $edit_stmt = $pdo->prepare("
            SELECT ep.*, p.name_ko, p.name_en, p.sku
            FROM event_products ep
            JOIN products p ON ep.product_id = p.id
            WHERE ep.id = ?
        ");
        $edit_stmt->execute([$edit_id]);
        $edit_data = $edit_stmt->fetch(PDO::FETCH_ASSOC);

        if ($edit_data && !$is_super && (int)$edit_data['store_id'] !== $session_store_id) {
            $edit_data = null;
        }
    }

    // 목록 조회
    $conditions = ['1=1'];
    $params = [];

    if (!$is_super) {
        $conditions[] = 'ep.store_id = ?';
        $params[] = $session_store_id;
    } elseif ($filter_store_id) {
        $conditions[] = 'ep.store_id = ?';
        $params[] = $filter_store_id;
    }

    if ($filter_status === 'active') {
        $conditions[] = 'ep.start_date <= CURDATE() AND ep.end_date >= CURDATE()';
    } elseif ($filter_status === 'ended') {
        $conditions[] = 'ep.end_date < CURDATE()';
    } elseif ($filter_status === 'upcoming') {
        $conditions[] = 'ep.start_date > CURDATE()';
    }

    $where = implode(' AND ', $conditions);

    $sql = "
        SELECT
            ep.id, ep.event_price, ep.original_cost, ep.original_selling,
            ep.start_date, ep.end_date, ep.remarks, ep.is_active, ep.created_at,
            p.id AS product_id, p.name_ko, p.name_en, p.sku,
            s.name AS store_name,
            CASE
                WHEN ep.end_date < CURDATE() THEN 'ended'
                WHEN ep.start_date > CURDATE() THEN 'upcoming'
                ELSE 'active'
            END AS event_status
        FROM event_products ep
        JOIN products p ON ep.product_id = p.id
        JOIN stores s ON ep.store_id = s.id
        WHERE {$where}
        ORDER BY ep.created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("event_products_list error: " . $e->getMessage());
    $errors[] = '데이터를 불러오는 중 오류가 발생했습니다.';
}

// 플래시 메시지
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$today = date('Y-m-d');
$form_store_id = $edit_data ? (int)$edit_data['store_id'] : ($is_super ? 0 : $session_store_id);
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
<div class="w-full mx-auto">

<?php if ($flash): ?>
<div class="mb-6 p-4 rounded-md <?= $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200' ?>">
    <div class="flex">
        <i class="fas <?= $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400' ?> mt-0.5 mr-3"></i>
        <p class="text-sm <?= $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700' ?>"><?= htmlspecialchars($flash['message']) ?></p>
    </div>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
    <ul class="text-sm text-red-700 list-disc list-inside">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- 페이지 헤더 -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">행사상품 관리</h1>
        <p class="mt-1 text-sm text-gray-500">점포별 행사 할인상품을 등록하고 관리합니다.</p>
    </div>
    <button id="btn-toggle-form" type="button"
        class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md shadow-sm transition-colors duration-200">
        <i class="fas fa-plus mr-2"></i>
        <?= $edit_data ? '수정 중' : '새 행사상품 등록' ?>
    </button>
</div>

<!-- 등록/수정 폼 (기본: 수정 모드면 펼침, 아니면 접힘) -->
<div id="form-panel" class="<?= $edit_data ? '' : 'hidden' ?> mb-6 bg-white rounded-lg shadow-sm ring-1 ring-purple-200 overflow-hidden">
    <div class="px-5 py-4 bg-gradient-to-r from-purple-50 to-purple-100 border-b border-purple-200 flex items-center justify-between">
        <h2 class="text-base font-semibold text-purple-900">
            <i class="fas fa-tag mr-2 text-purple-600"></i>
            <span id="form-title"><?= $edit_data ? '행사상품 수정' : '행사상품 등록' ?></span>
        </h2>
        <button type="button" id="btn-close-form" class="text-purple-400 hover:text-purple-600">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <div class="p-5">
        <form id="event-form" class="space-y-4">
            <input type="hidden" id="f-id" value="<?= $edit_data ? $edit_data['id'] : '' ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- 점포 선택 (super_admin만) -->
                <?php if ($is_super): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">점포 <span class="text-red-500">*</span></label>
                    <select id="f-store-id" class="w-full border-2 border-gray-300 rounded-md px-3 py-2 text-sm focus:border-purple-500 focus:outline-none">
                        <option value="">점포 선택</option>
                        <?php foreach ($stores as $store): ?>
                        <option value="<?= $store['id'] ?>" <?= $form_store_id == $store['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($store['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" id="f-store-id" value="<?= $session_store_id ?>">
                <div></div>
                <?php endif; ?>

                <!-- 상품 검색 -->
                <div class="md:col-span-<?= $is_super ? '1' : '2' ?>">
                    <label class="block text-sm font-medium text-gray-700 mb-1">상품 검색 <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="text" id="f-product-search"
                            value="<?= $edit_data ? htmlspecialchars($edit_data['name_ko'] . ' (' . $edit_data['sku'] . ')') : '' ?>"
                            placeholder="상품명 또는 SKU 입력..."
                            class="w-full border-2 border-gray-300 rounded-md px-3 py-2 pr-10 text-sm focus:border-purple-500 focus:outline-none">
                        <i class="fas fa-search absolute right-3 top-2.5 text-gray-400 text-sm"></i>
                        <div id="product-dropdown" class="hidden absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto"></div>
                    </div>
                    <input type="hidden" id="f-product-id" value="<?= $edit_data ? $edit_data['product_id'] : '' ?>">
                </div>
            </div>

            <!-- 가격 정보 표시 -->
            <div id="price-info" class="<?= $edit_data ? '' : 'hidden' ?> grid grid-cols-3 gap-3 p-3 bg-gray-50 rounded-md text-sm">
                <div class="text-center">
                    <p class="text-xs text-gray-500 mb-1">원가</p>
                    <p class="font-semibold text-gray-700" id="display-cost">
                        <?= $edit_data && $edit_data['original_cost'] ? '₩' . number_format($edit_data['original_cost']) : '-' ?>
                    </p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500 mb-1">정상 판매가</p>
                    <p class="font-semibold text-gray-700" id="display-selling">
                        <?= $edit_data && $edit_data['original_selling'] ? '₩' . number_format($edit_data['original_selling']) : '-' ?>
                    </p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500 mb-1">할인율</p>
                    <p class="font-semibold text-green-600" id="display-discount">-</p>
                </div>
            </div>
            <input type="hidden" id="f-original-cost" value="<?= $edit_data ? $edit_data['original_cost'] : '' ?>">
            <input type="hidden" id="f-original-selling" value="<?= $edit_data ? $edit_data['original_selling'] : '' ?>">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- 행사가 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">행사가 (원) <span class="text-red-500">*</span></label>
                    <input type="number" id="f-event-price" min="0" step="100"
                        value="<?= $edit_data ? $edit_data['event_price'] : '' ?>"
                        placeholder="0"
                        class="w-full border-2 border-gray-300 rounded-md px-3 py-2 text-sm focus:border-purple-500 focus:outline-none">
                </div>
                <!-- 시작일 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">행사 시작일 <span class="text-red-500">*</span></label>
                    <input type="date" id="f-start-date"
                        value="<?= $edit_data ? $edit_data['start_date'] : $today ?>"
                        class="w-full border-2 border-gray-300 rounded-md px-3 py-2 text-sm focus:border-purple-500 focus:outline-none">
                </div>
                <!-- 종료일 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">행사 종료일 <span class="text-red-500">*</span></label>
                    <input type="date" id="f-end-date"
                        value="<?= $edit_data ? $edit_data['end_date'] : '' ?>"
                        class="w-full border-2 border-gray-300 rounded-md px-3 py-2 text-sm focus:border-purple-500 focus:outline-none">
                </div>
            </div>

            <!-- 비고 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">비고</label>
                <input type="text" id="f-remarks" maxlength="255"
                    value="<?= $edit_data ? htmlspecialchars($edit_data['remarks'] ?? '') : '' ?>"
                    placeholder="행사 내용이나 메모 (선택사항)"
                    class="w-full border-2 border-gray-300 rounded-md px-3 py-2 text-sm focus:border-purple-500 focus:outline-none">
            </div>

            <!-- 폼 버튼 -->
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="btn-cancel-form"
                    class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-700 text-sm font-medium rounded-md transition-colors duration-200">
                    취소
                </button>
                <button type="submit"
                    class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md shadow-sm transition-colors duration-200">
                    <i class="fas fa-save mr-2"></i>
                    <span id="btn-submit-label"><?= $edit_data ? '저장' : '등록' ?></span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 필터 -->
<div class="mb-4 flex flex-wrap items-center gap-3">
    <?php if ($is_super): ?>
    <select id="filter-store" onchange="applyFilter()"
        class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:border-purple-500 focus:outline-none">
        <option value="0" <?= !$filter_store_id ? 'selected' : '' ?>>전체 점포</option>
        <?php foreach ($stores as $store): ?>
        <option value="<?= $store['id'] ?>" <?= $filter_store_id == $store['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($store['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="flex gap-1">
        <?php foreach (['all' => '전체', 'active' => '진행중', 'upcoming' => '예정', 'ended' => '종료'] as $val => $label): ?>
        <a href="?status=<?= $val ?><?= $filter_store_id ? '&store_id=' . $filter_store_id : '' ?>"
            class="px-3 py-1.5 text-sm rounded-md <?= $filter_status === $val ? 'bg-purple-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <span class="text-sm text-gray-500 ml-auto">총 <?= count($events) ?>건</span>
</div>

<!-- 목록 테이블 -->
<div class="bg-white shadow-sm rounded-lg overflow-hidden ring-1 ring-gray-200">
    <?php if (empty($events)): ?>
    <div class="p-12 text-center text-gray-500">
        <i class="fas fa-tag text-3xl text-gray-300 mb-3 block"></i>
        <p class="text-sm">등록된 행사상품이 없습니다.</p>
        <button type="button" onclick="document.getElementById('btn-toggle-form').click()"
            class="mt-4 text-sm text-purple-600 hover:text-purple-800 font-medium">
            + 첫 번째 행사상품 등록하기
        </button>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr class="bg-gray-50">
                    <?php if ($is_super): ?>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">점포</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">상품명</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">원가</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">판매가</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">행사가</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">할인율</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">기간</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">상태</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">관리</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="events-tbody">
            <?php foreach ($events as $ev): ?>
                <?php
                $discount = '';
                if ($ev['original_selling'] > 0) {
                    $pct = round((1 - $ev['event_price'] / $ev['original_selling']) * 100);
                    $discount = $pct . '%';
                }
                $status_map = [
                    'active'   => ['bg-green-100 text-green-800', '진행중'],
                    'upcoming' => ['bg-blue-100 text-blue-800',  '예정'],
                    'ended'    => ['bg-gray-100 text-gray-600',  '종료'],
                ];
                [$badge_cls, $badge_lbl] = $status_map[$ev['event_status']] ?? ['bg-gray-100 text-gray-600', '?'];
                ?>
            <tr class="hover:bg-gray-50 transition-colors" id="row-<?= $ev['id'] ?>">
                <?php if ($is_super): ?>
                <td class="px-4 py-3 text-sm text-gray-600 whitespace-nowrap"><?= htmlspecialchars($ev['store_name']) ?></td>
                <?php endif; ?>
                <td class="px-4 py-3">
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($ev['name_ko']) ?></p>
                    <p class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($ev['sku'] ?? '') ?></p>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 text-right whitespace-nowrap">
                    <?= $ev['original_cost'] ? '₩' . number_format($ev['original_cost']) : '<span class="text-gray-300">-</span>' ?>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 text-right whitespace-nowrap">
                    <?= $ev['original_selling'] ? '₩' . number_format($ev['original_selling']) : '<span class="text-gray-300">-</span>' ?>
                </td>
                <td class="px-4 py-3 text-sm font-semibold text-purple-700 text-right whitespace-nowrap">
                    ₩<?= number_format($ev['event_price']) ?>
                </td>
                <td class="px-4 py-3 text-sm text-green-600 font-medium text-right whitespace-nowrap">
                    <?= $discount ?: '<span class="text-gray-300">-</span>' ?>
                </td>
                <td class="px-4 py-3 text-xs text-gray-600 text-center whitespace-nowrap">
                    <?= htmlspecialchars($ev['start_date']) ?> ~<br><?= htmlspecialchars($ev['end_date']) ?>
                </td>
                <td class="px-4 py-3 text-center whitespace-nowrap">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badge_cls ?>">
                        <?= $badge_lbl ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center whitespace-nowrap">
                    <a href="?edit=<?= $ev['id'] ?><?= $filter_status !== 'all' ? '&status=' . $filter_status : '' ?>"
                        class="text-purple-600 hover:text-purple-800 text-sm font-medium mr-3">수정</a>
                    <button type="button" onclick="deleteEvent(<?= $ev['id'] ?>, '<?= htmlspecialchars(addslashes($ev['name_ko'])) ?>')"
                        class="text-red-500 hover:text-red-700 text-sm font-medium">삭제</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div>
</div>

<script>
// 폼 열기/닫기
const formPanel    = document.getElementById('form-panel');
const btnToggle    = document.getElementById('btn-toggle-form');
const btnClose     = document.getElementById('btn-close-form');
const btnCancel    = document.getElementById('btn-cancel-form');

btnToggle.addEventListener('click', () => {
    formPanel.classList.toggle('hidden');
    if (!formPanel.classList.contains('hidden')) {
        document.getElementById('f-product-search').focus();
    }
});
btnClose.addEventListener('click', () => formPanel.classList.add('hidden'));
btnCancel.addEventListener('click', () => {
    formPanel.classList.add('hidden');
    resetForm();
    <?php if ($edit_id): ?>
    window.location.href = 'event_products_list.php';
    <?php endif; ?>
});

// 상품 검색 자동완성
let searchTimer = null;
const searchInput    = document.getElementById('f-product-search');
const productDropdown = document.getElementById('product-dropdown');
const storeIdInput   = document.getElementById('f-store-id');

searchInput.addEventListener('input', () => {
    clearTimeout(searchTimer);
    const q = searchInput.value.trim();
    if (q.length < 1) { productDropdown.classList.add('hidden'); return; }
    searchTimer = setTimeout(() => searchProducts(q), 250);
});

searchInput.addEventListener('focus', () => {
    if (searchInput.value.trim().length >= 1) searchProducts(searchInput.value.trim());
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#f-product-search') && !e.target.closest('#product-dropdown')) {
        productDropdown.classList.add('hidden');
    }
});

function getStoreId() {
    const el = document.getElementById('f-store-id');
    if (!el) return 0;
    return el.tagName === 'SELECT' ? parseInt(el.value) : parseInt(el.value);
}

function searchProducts(q) {
    const store_id = getStoreId();
    if (!store_id) {
        productDropdown.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">점포를 먼저 선택해주세요.</div>';
        productDropdown.classList.remove('hidden');
        return;
    }

    const fd = new FormData();
    fd.append('q', q);
    fd.append('store_id', store_id);

    fetch('ajax_search_event_products.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.products.length) {
                productDropdown.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">검색 결과가 없습니다.</div>';
            } else {
                productDropdown.innerHTML = data.products.map(p => `
                    <div class="px-3 py-2 hover:bg-purple-50 cursor-pointer text-sm border-b border-gray-100 last:border-0"
                         onclick="selectProduct(${p.id}, '${escapeHtml(p.name_ko)}', '${escapeHtml(p.sku)}', ${p.cost_price}, ${p.selling_price || 'null'})">
                        <span class="font-medium text-gray-800">${escapeHtml(p.name_ko)}</span>
                        <span class="text-xs text-gray-400 font-mono ml-2">${escapeHtml(p.sku)}</span>
                        <span class="text-xs text-gray-500 float-right">
                            원가 ₩${numberFormat(p.cost_price)}
                            ${p.selling_price ? ' / 판매가 ₩' + numberFormat(p.selling_price) : ''}
                        </span>
                    </div>
                `).join('');
            }
            productDropdown.classList.remove('hidden');
        })
        .catch(() => {
            productDropdown.innerHTML = '<div class="px-3 py-2 text-sm text-red-500">검색 오류가 발생했습니다.</div>';
            productDropdown.classList.remove('hidden');
        });
}

function selectProduct(id, name_ko, sku, cost_price, selling_price) {
    document.getElementById('f-product-id').value = id;
    document.getElementById('f-product-search').value = name_ko + ' (' + sku + ')';
    document.getElementById('f-original-cost').value = cost_price || '';
    document.getElementById('f-original-selling').value = selling_price || '';
    productDropdown.classList.add('hidden');

    // 가격 정보 표시
    const priceInfo = document.getElementById('price-info');
    priceInfo.classList.remove('hidden');
    document.getElementById('display-cost').textContent = cost_price > 0 ? '₩' + numberFormat(cost_price) : '-';
    document.getElementById('display-selling').textContent = selling_price > 0 ? '₩' + numberFormat(selling_price) : '정보 없음';
    updateDiscount();
}

function updateDiscount() {
    const selling = parseFloat(document.getElementById('f-original-selling').value) || 0;
    const event   = parseFloat(document.getElementById('f-event-price').value) || 0;
    const el = document.getElementById('display-discount');
    if (selling > 0 && event > 0) {
        const pct = Math.round((1 - event / selling) * 100);
        el.textContent = pct + '%';
        el.className = 'font-semibold ' + (pct > 0 ? 'text-green-600' : 'text-red-500');
    } else {
        el.textContent = '-';
    }
}

document.getElementById('f-event-price').addEventListener('input', updateDiscount);

// 수정 모드 초기 가격 표시
<?php if ($edit_data && $edit_data['original_selling']): ?>
document.addEventListener('DOMContentLoaded', () => updateDiscount());
<?php endif; ?>

// 폼 제출
document.getElementById('event-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveEvent();
});

function saveEvent() {
    const id          = document.getElementById('f-id').value;
    const store_id    = getStoreId();
    const product_id  = parseInt(document.getElementById('f-product-id').value) || 0;
    const event_price = parseFloat(document.getElementById('f-event-price').value) || 0;
    const start_date  = document.getElementById('f-start-date').value;
    const end_date    = document.getElementById('f-end-date').value;
    const remarks     = document.getElementById('f-remarks').value.trim();
    const original_cost    = parseFloat(document.getElementById('f-original-cost').value) || null;
    const original_selling = parseFloat(document.getElementById('f-original-selling').value) || null;

    if (!store_id)   { alert('점포를 선택해주세요.'); return; }
    if (!product_id) { alert('상품을 선택해주세요.'); return; }
    if (!event_price || event_price <= 0) { alert('행사가를 입력해주세요.'); return; }
    if (!start_date || !end_date) { alert('행사 기간을 설정해주세요.'); return; }
    if (start_date > end_date) { alert('종료일은 시작일 이후여야 합니다.'); return; }

    const payload = {
        action: 'save',
        id: id || null,
        store_id, product_id, event_price,
        original_cost, original_selling,
        start_date, end_date, remarks
    };

    fetch('ajax_save_event_product.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            sessionStorage.setItem('flash_success', data.message);
            window.location.href = 'event_products_list.php';
        } else {
            alert(data.message || '저장 중 오류가 발생했습니다.');
        }
    })
    .catch(() => alert('서버 통신 오류가 발생했습니다.'));
}

function deleteEvent(id, name) {
    if (!confirm(`"${name}" 행사상품을 삭제하시겠습니까?`)) return;

    fetch('ajax_save_event_product.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('row-' + id);
            if (row) row.remove();

            const tbody = document.getElementById('events-tbody');
            if (tbody && tbody.children.length === 0) window.location.reload();
        } else {
            alert(data.message || '삭제 중 오류가 발생했습니다.');
        }
    })
    .catch(() => alert('서버 통신 오류가 발생했습니다.'));
}

function resetForm() {
    document.getElementById('f-id').value = '';
    document.getElementById('f-product-id').value = '';
    document.getElementById('f-product-search').value = '';
    document.getElementById('f-original-cost').value = '';
    document.getElementById('f-original-selling').value = '';
    document.getElementById('f-event-price').value = '';
    document.getElementById('f-start-date').value = '<?= $today ?>';
    document.getElementById('f-end-date').value = '';
    document.getElementById('f-remarks').value = '';
    document.getElementById('price-info').classList.add('hidden');
    document.getElementById('form-title').textContent = '행사상품 등록';
    document.getElementById('btn-submit-label').textContent = '등록';
}

function applyFilter() {
    const store = document.getElementById('filter-store')?.value || 0;
    const status = new URLSearchParams(window.location.search).get('status') || 'all';
    let url = 'event_products_list.php?status=' + status;
    if (store) url += '&store_id=' + store;
    window.location.href = url;
}

// sessionStorage 플래시 메시지 처리
const flashMsg = sessionStorage.getItem('flash_success');
if (flashMsg) {
    sessionStorage.removeItem('flash_success');
    const div = document.createElement('div');
    div.className = 'mb-6 p-4 rounded-md bg-green-50 border border-green-200 flex items-center';
    div.innerHTML = `<i class="fas fa-check-circle text-green-400 mr-3"></i><p class="text-sm text-green-700">${escapeHtml(flashMsg)}</p>`;
    document.querySelector('.w-full.mx-auto').prepend(div);
    setTimeout(() => div.remove(), 4000);
}

function numberFormat(n) {
    return parseFloat(n).toLocaleString('ko-KR');
}
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
