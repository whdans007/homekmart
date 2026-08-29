<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/home_layout.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'products.php';
$search = trim($_GET['q'] ?? '');
$selected_category_id = isset($_GET['cat_id']) && $_GET['cat_id'] !== '' ? (int)$_GET['cat_id'] : null;
$selected_sub_id = isset($_GET['sub_id']) && $_GET['sub_id'] !== '' ? (int)$_GET['sub_id'] : null;
$can_manage_categories = has_permission('category_management');

// 카테고리 목록(특히 "전체")은 상품이 많으면 한 번에 다 그리기엔 너무 커서 페이지네이션한다.
// 홈 노출(오늘의특가 등) 목록은 보통 몇 개 안 되니 페이지네이션 없이 그대로 둔다.
$curated_page = max(1, (int)($_GET['page'] ?? 1));
$curated_per_page = 50;

// 카테고리 아랫쪽에 "홈 노출" 가상 카테고리(오늘의특가/기획전/새상품)를 둔다 — 실제 categories 테이블과는
// 무관하고, mall_home_sections의 해당 슬롯 draft product_ids로만 관리한다.
$home_slot_labels = ['today_deals' => '오늘의특가', 'promo_products' => '기획전', 'new_arrivals' => '새상품'];
$selected_home_slot = $_GET['home_slot'] ?? null;
if (!isset($home_slot_labels[$selected_home_slot])) {
    $selected_home_slot = null;
}
// 카테고리 선택과 홈 노출 선택은 상호배타적이다 — 홈 노출을 고르면 카테고리 필터는 무시한다.
if ($selected_home_slot) {
    $selected_category_id = null;
    $selected_sub_id = null;
}

// 오늘의특가/기획전/새상품 각각 지금 몇 개가 초안(draft)에 들어있는지 — 사이드바 카운트 + 필터링에 쓴다.
// 실제 발행은 홈 레이아웃 화면의 "적용" 버튼을 눌러야 반영된다(ajax/toggle_home_section_product.php).
// (프로모 정보는 더 이상 여기서 안 읽는다 — mall_products.promo_type/promo_value에 직접 저장되어
// 아래 큐레이션 조회 쿼리에서 바로 가져온다. 가격처럼 저장 즉시 반영되게 하기 위함.)
$home_slot_membership = ['today_deals' => [], 'new_arrivals' => [], 'promo_products' => []];
foreach (array_keys($home_slot_membership) as $__slot_key) {
    $__slot = mall_get_home_slot($__slot_key);
    if ($__slot && !empty($__slot['config'])) {
        $__decoded = json_decode($__slot['config'], true);
        if (is_array($__decoded) && !empty($__decoded['product_ids'])) {
            $home_slot_membership[$__slot_key] = array_map('intval', $__decoded['product_ids']);
        }
    }
}

$conn = get_db_connection();

// 기준 점포 선택 (기본값: MALL_STORE_ID). 목록에 없는 값이면 기본값으로 되돌린다.
$stores = $conn->query('SELECT id, name FROM stores ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$store_ids = array_column($stores, 'id');
$selected_store_id = isset($_GET['store_id']) ? (int)$_GET['store_id'] : MALL_STORE_ID;
if (!in_array($selected_store_id, $store_ids, true)) {
    $selected_store_id = MALL_STORE_ID;
}

// 좌측 카테고리 메뉴 (대분류만 표시, 드래그앤드롭으로 정한 sort_order 순)
// product_count: 대분류 + 그 하위 소분류에 걸린 "실제 몰에 큐레이션된(mall_products)" 상품 수.
// products.category_id만으로 세면 큐레이션에서 삭제된 상품(category_id는 안 지워짐)까지 잡혀서
// 실제 큐레이션 목록과 숫자가 안 맞았기 때문에, mall_products와 LEFT JOIN해서 실제 등록 건만 센다.
$cat_count_stmt = $conn->prepare(
    "SELECT c.id, c.name, c.name_en,
            COUNT(DISTINCT mp.id) AS product_count
     FROM categories c
     LEFT JOIN categories sub ON sub.parent_id = c.id
     LEFT JOIN products p ON (p.category_id = c.id OR p.category_id = sub.id) AND p.is_active = 1
     LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
     WHERE c.parent_id IS NULL
     GROUP BY c.id, c.name, c.name_en
     ORDER BY c.sort_order, c.name"
);
$cat_count_stmt->bind_param('i', $selected_store_id);
$cat_count_stmt->execute();
$mall_categories = $cat_count_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cat_count_stmt->close();

// 전체 소분류(모든 대분류의 하위)를 대분류별로 묶어서 한 번에 가져온다.
// 사이드바(선택된 대분류의 소분류 필터 목록)와 카테고리 관리 모달(전체 트리) 양쪽에서 공용으로 쓴다.
$all_sub_stmt = $conn->prepare(
    "SELECT c.id, c.name, c.name_en, c.parent_id, COUNT(DISTINCT mp.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.is_active = 1
     LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
     WHERE c.parent_id IS NOT NULL
     GROUP BY c.id, c.name, c.name_en, c.parent_id
     ORDER BY c.parent_id, c.sort_order, c.name"
);
$all_sub_stmt->bind_param('i', $selected_store_id);
$all_sub_stmt->execute();
$sub_categories_by_parent = [];
foreach ($all_sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $sub_categories_by_parent[(int)$row['parent_id']][] = $row;
}
$all_sub_stmt->close();

$sub_categories = $selected_category_id ? ($sub_categories_by_parent[$selected_category_id] ?? []) : [];
// URL의 sub_id가 실제로 현재 선택된 대분류의 하위 소분류일 때만 유효하게 취급한다.
$effective_category_id = ($selected_sub_id && in_array($selected_sub_id, array_column($sub_categories, 'id'), true)) ? $selected_sub_id : null;
// "상품 검색 → 쇼핑몰에 추가/이동등록" 대상 카테고리: 소분류를 골랐으면 소분류, 아니면 대분류.
$add_target_category_id = $effective_category_id ?: $selected_category_id;

// 선택한 대분류 + 그 하위 소분류 id까지 포함해 상품을 필터링한다(상품의 category_id는 보통 소분류에 걸림).
// 단, 특정 소분류를 골랐으면 그 소분류만으로 좁힌다.
$category_filter_ids = [];
if ($effective_category_id) {
    $category_filter_ids = [$effective_category_id];
} elseif ($selected_category_id) {
    $cat_stmt = $conn->prepare('SELECT id FROM categories WHERE id = ? OR parent_id = ?');
    $cat_stmt->bind_param('ii', $selected_category_id, $selected_category_id);
    $cat_stmt->execute();
    $category_filter_ids = array_column($cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    $cat_stmt->close();
    if (empty($category_filter_ids)) {
        $category_filter_ids = [$selected_category_id];
    }
}

// 최근 1개월 판매량 계산 준비: office/pos_data(pos_sales_data)의 매장 POS 판매 실적을 사용한다.
// sale_date가 자유 텍스트(VARCHAR)라 업로드마다 포맷이 다를 수 있어 office/pos_data/report.php와
// 동일한 방식으로 샘플을 뽑아 포맷을 감지한 뒤 비교 조건을 만든다.
function mall_detect_pos_date_format(string $sample): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sample)) return 'Y-m-d';
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $sample)) return 'm/d/Y';
    if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $sample)) return 'm-d-Y';
    return 'Y-m-d';
}

// 홈 노출(오늘의특가/기획전/새상품) 선택 중일 때는 "이 진열에 추가할 상품 검색" — 큐레이션 여부와
// 무관하게 전체 상품에서 찾는다(홈 노출 목록 자체가 mall_products 큐레이션과 별개로 관리되므로).
// 이 점포에 큐레이션되어 있는지는 결과에 표시만 해준다(안 되어 있어도 진열에는 추가 가능).
$home_slot_search_results = [];
if ($selected_home_slot && $search !== '') {
    $slot_stmt = $conn->prepare(
        "SELECT p.id AS product_id, p.name_ko, p.sku, mp.id AS mall_product_id, mp.display_name
         FROM products p
         LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
         WHERE p.is_active = 1
           AND (p.name_ko LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ?)
         ORDER BY p.name_ko LIMIT 30"
    );
    $like = '%' . $search . '%';
    $mall_store_id = (int)MALL_STORE_ID;
    $slot_stmt->bind_param('isss', $mall_store_id, $like, $like, $like);
    $slot_stmt->execute();
    $home_slot_search_results = $slot_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $slot_stmt->close();
}

// 검색 결과 (아직 몰에 큐레이션되지 않은 상품 위주로 보여주되, 이미 등록된 것도 함께 표시)
// 상품 등록을 위한 검색이므로 좌측 카테고리 선택과 무관하게 전체 상품에서 검색한다.
$search_results = [];
if (!$selected_home_slot && $search !== '') {
    $pos_sample_stmt = $conn->prepare(
        "SELECT sale_date FROM pos_sales_data
         WHERE upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id = ?)
         LIMIT 1"
    );
    $pos_sample_stmt->bind_param('i', $selected_store_id);
    $pos_sample_stmt->execute();
    $pos_sample = $pos_sample_stmt->get_result()->fetch_assoc()['sale_date'] ?? '';
    $pos_sample_stmt->close();

    $pos_date_fmt = $pos_sample !== '' ? mall_detect_pos_date_format($pos_sample) : 'Y-m-d';
    if ($pos_date_fmt === 'Y-m-d') {
        // sale_date가 'YYYY-MM-DD'로 0-padding 되어 있어 문자열 비교로도 날짜 순서가 보존된다.
        $pos_date_cond = "d.sale_date >= DATE_FORMAT(NOW() - INTERVAL 30 DAY, '%Y-%m-%d')";
    } else {
        $pos_mysql_fmt = ($pos_date_fmt === 'm/d/Y') ? '%c/%e/%Y' : '%c-%e-%Y';
        $pos_date_cond = "STR_TO_DATE(d.sale_date, '{$pos_mysql_fmt}') >= (NOW() - INTERVAL 30 DAY)";
    }

    // 최근 1개월 판매량: 선택한 기준 점포의 POS 판매 데이터(pos_sales_data.item_code = products.sku) 합계
    $stmt = $conn->prepare(
        "SELECT p.id, p.sku, p.name_ko, p.name_en, p.category_id, mp.id AS mall_product_id,
                COALESCE((
                    SELECT SUM(d.pcs)
                    FROM pos_sales_data d
                    WHERE d.item_code = p.sku AND p.sku IS NOT NULL AND p.sku <> ''
                      AND d.upload_id IN (SELECT id FROM pos_sales_uploads WHERE store_id = ?)
                      AND {$pos_date_cond}
                ), 0) AS recent_sales_qty
         FROM products p
         LEFT JOIN mall_products mp ON mp.product_id = p.id
         WHERE (p.name_ko LIKE ? OR p.name_en LIKE ? OR p.sku LIKE ?) AND p.is_active = 1
         ORDER BY p.name_ko LIMIT 50"
    );
    $like = '%' . $search . '%';
    $stmt->bind_param('isss', $selected_store_id, $like, $like, $like);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// 기준도매가 = 원가 x (1 + 마진율). 마진율은 할인 규칙 화면에서 관리(기본 15%).
$markup_stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'mall_wholesale_reference_markup_rate'");
$markup_stmt->execute();
$markup_row = $markup_stmt->get_result()->fetch_assoc();
$markup_stmt->close();
$wholesale_reference_markup_rate = $markup_row ? (float)$markup_row['setting_value'] : 15.0;

// 현재 큐레이션된 소매몰 상품 목록. 홈 노출(오늘의특가/기획전/새상품)을 선택했으면 그 슬롯에 담긴
// 상품만, 아니면 기존처럼 카테고리 선택 기준으로 보여준다.
// inventory는 선택한 기준 점포 기준 원가/판매가 표시용으로 LEFT JOIN한다(재고 행이 없을 수 있음).
//
// 홈 노출 모드는 products를 기준으로 mall_products를 LEFT JOIN한다(INNER JOIN이 아님) — 예전 방식으로
// 등록해둔 상품 중에는 이 점포에 큐레이션(mall_products)되어 있지 않은 것도 있어서, INNER JOIN으로
// 하면 그런 상품이 목록에서 통째로 사라져 "등록했는데 안 보인다"가 된다. LEFT JOIN으로 그런 상품도
// 일단 보여주고, 화면에서 "이 점포에 큐레이션되지 않음" 안내와 진열 제거 버튼만 제공한다.
$curated = [];
if ($selected_home_slot && empty($home_slot_membership[$selected_home_slot])) {
    // 슬롯에 담긴 상품이 아예 없으면 쿼리할 것도 없다.
} elseif ($selected_home_slot) {
    $slot_product_ids = $home_slot_membership[$selected_home_slot];
    $placeholders = implode(',', array_fill(0, count($slot_product_ids), '?'));
    $curated_stmt = $conn->prepare(
        "SELECT mp.id, p.id AS product_id, mp.display_name, mp.display_name_en, mp.is_active, mp.display_order,
                mp.retail_discount_allowed, mp.wholesale_discount_allowed,
                mp.wholesale_reference_price AS wholesale_reference_price_override,
                mp.cost_price_override, mp.selling_price_override,
                p.name_ko, p.sku, inv.cost_price AS original_cost_price, inv.selling_price AS original_selling_price,
                mp.is_sold_out, inv.quantity AS real_stock_quantity,
                mp.promo_type, mp.promo_value,
                (SELECT COUNT(*) FROM mall_product_images WHERE product_id = p.id) AS image_count
         FROM products p
         LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
         LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
         WHERE p.id IN ({$placeholders})
         ORDER BY FIELD(p.id, " . implode(',', $slot_product_ids) . ")"
    );
    $curated_types = 'ii' . str_repeat('i', count($slot_product_ids));
    // 홈 노출은 항상 실제 판매 기준 점포(MALL_STORE_ID) 큐레이션 여부를 기준으로 본다 —
    // 상단의 점포 선택 드롭다운(카테고리 큐레이션용)과는 무관하다.
    $curated_params = array_merge([(int)MALL_STORE_ID, (int)MALL_STORE_ID], $slot_product_ids);
    $curated_stmt->bind_param($curated_types, ...$curated_params);
} else {
    $curated_where = ['mp.store_id = ?'];
    $curated_params = [$selected_store_id, $selected_store_id];
    $curated_types = 'ii';
    if (!empty($category_filter_ids)) {
        $placeholders = implode(',', array_fill(0, count($category_filter_ids), '?'));
        $curated_where[] = "p.category_id IN ({$placeholders})";
        foreach ($category_filter_ids as $cid) {
            $curated_params[] = $cid;
            $curated_types .= 'i';
        }
    }

    // 총 개수(페이지네이션용) — INNER JOIN 조건은 본 조회와 동일하게 맞춘다.
    $count_stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM mall_products mp
         INNER JOIN products p ON p.id = mp.product_id
         WHERE " . implode(' AND ', $curated_where)
    );
    $count_stmt->bind_param(substr($curated_types, 1), ...array_slice($curated_params, 1));
    $count_stmt->execute();
    $curated_total = (int)($count_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $count_stmt->close();
    $curated_total_pages = max(1, (int)ceil($curated_total / $curated_per_page));
    $curated_page = min($curated_page, $curated_total_pages);
    $curated_offset = ($curated_page - 1) * $curated_per_page;

    $curated_stmt = $conn->prepare(
        "SELECT mp.id, mp.product_id, mp.display_name, mp.display_name_en, mp.is_active, mp.display_order,
                mp.retail_discount_allowed, mp.wholesale_discount_allowed,
                mp.wholesale_reference_price AS wholesale_reference_price_override,
                mp.cost_price_override, mp.selling_price_override,
                p.name_ko, p.sku, inv.cost_price AS original_cost_price, inv.selling_price AS original_selling_price,
                mp.is_sold_out, inv.quantity AS real_stock_quantity,
                (SELECT COUNT(*) FROM mall_product_images WHERE product_id = mp.product_id) AS image_count
         FROM mall_products mp
         INNER JOIN products p ON p.id = mp.product_id
         LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = ?
         WHERE " . implode(' AND ', $curated_where) . "
         ORDER BY mp.display_order, mp.id DESC
         LIMIT ? OFFSET ?"
    );
    $curated_types .= 'ii';
    $curated_params[] = $curated_per_page;
    $curated_params[] = $curated_offset;
    $curated_stmt->bind_param($curated_types, ...$curated_params);
}
if (isset($curated_stmt)) {
    $curated_stmt->execute();
    $curated = $curated_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $curated_stmt->close();
}

// 상품별 이미지 목록 (삭제/정렬 UI용)
$images_by_product = [];
if (!empty($curated)) {
    $img_stmt = $conn->prepare('SELECT id, image_path FROM mall_product_images WHERE product_id = ? ORDER BY sort_order');
    foreach ($curated as $c) {
        $img_stmt->bind_param('i', $c['product_id']);
        $img_stmt->execute();
        $images_by_product[$c['product_id']] = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $img_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>상품 큐레이션 - HOME K MART 쇼핑몰</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .category-item.drag-over { border-top: 2px solid #2563eb; }
        .category-item a { -webkit-user-drag: none; }
        .curated-row.drag-over { border-top: 2px solid #2563eb; }
        .edit-display-order::-webkit-outer-spin-button,
        .edit-display-order::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .edit-display-order { -moz-appearance: textfield; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-box mr-2"></i>상품 큐레이션</h1>
        <div id="flash-area"></div>

        <?php
            $__base_qs = [];
            if ($search !== '') { $__base_qs['q'] = $search; }
            if ($selected_store_id !== (int)MALL_STORE_ID) { $__base_qs['store_id'] = $selected_store_id; }
        ?>
        <div class="mb-4 bg-white rounded-lg border border-gray-200 p-3 flex items-center gap-2 flex-wrap">
            <label for="store-select" class="text-xs font-bold text-gray-700">기준 점포</label>
            <select id="store-select" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
                <?php foreach ($stores as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo $selected_store_id === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?> (ID <?php echo (int)$s['id']; ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?>
            <span class="text-xs text-amber-600"><i class="fas fa-triangle-exclamation mr-1"></i>현재 라이브 쇼핑몰 노출 기준 점포(ID <?php echo (int)MALL_STORE_ID; ?>)와 다릅니다. 이 점포로 등록한 상품은 지금은 실제 쇼핑몰에 노출되지 않습니다.</span>
            <?php endif; ?>
        </div>

        <div class="flex gap-4 items-start">
        <aside class="w-52 flex-shrink-0 bg-white rounded-lg border border-gray-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-gray-700">카테고리</h2>
                <?php if ($can_manage_categories): ?>
                <button type="button" id="open-category-manage-btn" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                    <i class="fas fa-pen mr-1"></i>수정
                </button>
                <?php endif; ?>
            </div>
            <ul class="space-y-1 mb-3">
                <li>
                    <a href="products.php<?php echo $__base_qs ? '?' . http_build_query($__base_qs) : ''; ?>"
                       class="block px-2 py-1.5 rounded text-xs font-medium <?php echo ($selected_category_id === null && !$selected_home_slot) ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">전체</a>
                </li>
            </ul>
            <ul class="space-y-1">
                <?php foreach ($mall_categories as $cat): ?>
                <li>
                    <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['cat_id' => $cat['id']])); ?>"
                       class="block px-2 py-1.5 rounded text-xs font-medium <?php echo $selected_category_id === (int)$cat['id'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <?php echo htmlspecialchars($cat['name']); ?> <span class="text-gray-400">(<?php echo (int)$cat['product_count']; ?>)</span>
                    </a>
                </li>
                <?php if ($selected_category_id === (int)$cat['id'] && !empty($sub_categories)): ?>
                <li class="pl-3 ml-2 border-l border-gray-200 mt-1">
                    <ul class="space-y-1">
                        <?php foreach ($sub_categories as $sub): ?>
                        <li>
                            <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['cat_id' => $selected_category_id, 'sub_id' => $sub['id']])); ?>"
                               class="block px-2 py-1 rounded text-xs font-medium <?php echo $selected_sub_id === (int)$sub['id'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                <?php echo htmlspecialchars($sub['name']); ?> <span class="text-gray-400">(<?php echo (int)$sub['product_count']; ?>)</span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ul>

            <h2 class="text-sm font-bold text-gray-700 mt-4 mb-3">홈 노출</h2>
            <ul class="space-y-1">
                <?php foreach ($home_slot_labels as $__slot_key => $__slot_label): ?>
                <li>
                    <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['home_slot' => $__slot_key])); ?>"
                       class="block px-2 py-1.5 rounded text-xs font-medium <?php echo $selected_home_slot === $__slot_key ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <?php echo htmlspecialchars($__slot_label); ?> <span class="text-gray-400">(<?php echo count($home_slot_membership[$__slot_key]); ?>)</span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <div class="flex-grow min-w-0">
        <section class="bg-white rounded-lg border border-gray-200 p-4">
            <h2 class="text-sm font-bold text-gray-700 mb-3">
                <?php if ($selected_home_slot): ?>
                    <?php echo htmlspecialchars($home_slot_labels[$selected_home_slot]); ?> 진열 상품 (<?php echo count($curated); ?>)
                <?php else: ?>
                    큐레이션된 상품 (<?php echo count($curated); ?>)
                <?php endif; ?>
            </h2>
            <?php if ($selected_home_slot): ?>
            <p class="text-[11px] text-gray-400 mb-3"><i class="fas fa-circle-info mr-1"></i>순서는 추가한 순서 그대로 노출됩니다. 오른쪽에서 검색해서 추가하거나, 아래 목록에서 "진열 제거"를 눌러 뺄 수 있습니다. 홈 레이아웃 화면의 "적용"을 눌러야 고객 화면에 반영됩니다.</p>
            <?php endif; ?>
            <?php $__is_today_deals = $selected_home_slot === 'today_deals'; $__col_count = $__is_today_deals ? 12 : 11; ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left">순서</th>
                        <th class="px-3 py-2 text-left">상품명</th>
                        <th class="px-3 py-2 text-left">표시명</th>
                        <th class="px-3 py-2 text-right">원가</th>
                        <th class="px-3 py-2 text-right">기준도매가</th>
                        <th class="px-3 py-2 text-right">기준판매가</th>
                        <th class="px-3 py-2 text-left">노출</th>
                        <th class="px-3 py-2 text-left">재고/품절</th>
                        <th class="px-3 py-2 text-left">할인</th>
                        <?php if ($__is_today_deals): ?><th class="px-3 py-2 text-left">프로모</th><?php endif; ?>
                        <th class="px-3 py-2 text-left">이미지</th>
                        <th class="px-3 py-2 text-left">저장</th>
                    </tr>
                </thead>
                <tbody id="curated-products-body" data-order-offset="<?php echo isset($curated_offset) ? (int)$curated_offset : 0; ?>">
                <?php if (empty($curated)): ?>
                    <tr><td colspan="<?php echo $__col_count; ?>" class="px-3 py-4 text-center text-gray-400"><?php echo $selected_home_slot ? '아직 이 진열에 추가된 상품이 없습니다. 오른쪽에서 검색해서 추가해보세요.' : '등록된 상품이 없습니다.'; ?></td></tr>
                <?php endif; ?>
                <?php foreach ($curated as $c): ?>
                    <?php
                        $original_cost_price = $c['original_cost_price'] !== null ? (float)$c['original_cost_price'] : null;
                        $original_selling_price = $c['original_selling_price'] !== null ? (float)$c['original_selling_price'] : null;
                        // 기준도매가는 소숫점 이하를 항상 올림 처리한다(원가 x 마진율 계산 결과에 끝수가 남지 않도록).
                        $original_wholesale_reference_price = $original_cost_price !== null ? ceil($original_cost_price * (1 + $wholesale_reference_markup_rate / 100)) : null;

                        $cost_price_override = $c['cost_price_override'] !== null ? (float)$c['cost_price_override'] : null;
                        $selling_price_override = $c['selling_price_override'] !== null ? (float)$c['selling_price_override'] : null;
                        $wholesale_reference_override = $c['wholesale_reference_price_override'] !== null ? (float)$c['wholesale_reference_price_override'] : null;

                        $effective_cost_price = $cost_price_override ?? $original_cost_price;
                        $effective_selling_price = $selling_price_override ?? $original_selling_price;
                        $effective_wholesale_reference_price = $wholesale_reference_override ?? ($effective_cost_price !== null ? ceil($effective_cost_price * (1 + $wholesale_reference_markup_rate / 100)) : null);
                        $__is_curated = $c['id'] !== null;
                    ?>
                    <?php if (!$__is_curated): ?>
                    <tr class="curated-row border-t border-gray-100">
                        <td class="px-3 py-2 text-gray-300">—</td>
                        <td class="px-3 py-2">
                            <div class="text-gray-400 barcode-copy" data-barcode="<?php echo htmlspecialchars($c['sku']); ?>" title="클릭해서 바코드 복사" style="cursor:pointer;"><?php echo htmlspecialchars($c['sku']); ?></div>
                            <?php echo htmlspecialchars($c['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2 text-amber-600" colspan="<?php echo $__col_count - 3; ?>">
                            <i class="fas fa-triangle-exclamation mr-1"></i>이 점포(ID <?php echo (int)MALL_STORE_ID; ?>)에 아직 큐레이션되지 않았습니다. "지금 큐레이션" 누르면 카테고리 화면과 똑같이 편집할 수 있게 됩니다.
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <button class="curate-home-slot-product-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>">지금 큐레이션</button>
                            <button class="remove-from-home-slot-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>">진열 제거</button>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr class="curated-row border-t border-gray-100" data-mall-product-id="<?php echo (int)$c['id']; ?>" <?php echo $selected_home_slot ? '' : 'draggable="true"'; ?>>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <?php if (!$selected_home_slot): ?><i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="드래그해서 순서 변경"></i><?php endif; ?>
                            <input type="number" min="0" max="999" class="edit-display-order border border-gray-300 rounded px-2 py-1 w-10" value="<?php echo (int)$c['display_order']; ?>" <?php echo $selected_home_slot ? 'title="이 목록에서는 추가한 순서로 노출됩니다(전체 큐레이션 순서와는 별개)"' : ''; ?>>
                        </td>
                        <td class="px-3 py-2">
                            <div class="text-gray-400 barcode-copy" data-barcode="<?php echo htmlspecialchars($c['sku']); ?>" title="클릭해서 바코드 복사" style="cursor:pointer;"><?php echo htmlspecialchars($c['sku']); ?></div>
                            <?php echo htmlspecialchars($c['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-1"><span class="text-gray-400 text-[11px] flex-shrink-0">한:</span><input type="text" class="edit-display-name border border-gray-300 rounded px-2 py-1 w-32" placeholder="한글" value="<?php echo htmlspecialchars($c['display_name'] ?? ''); ?>"></div>
                                <div class="flex items-center gap-1"><span class="text-gray-400 text-[11px] flex-shrink-0">영:</span><input type="text" class="edit-display-name-en border border-gray-300 rounded px-2 py-1 w-32" placeholder="영문" value="<?php echo htmlspecialchars($c['display_name_en'] ?? ''); ?>"></div>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" min="0" class="edit-cost-price border border-gray-300 rounded px-2 py-1 w-20 text-right font-mono" value="<?php echo $effective_cost_price !== null ? $effective_cost_price : ''; ?>">
                            <?php if ($cost_price_override !== null): ?>
                            <div class="text-gray-400 mt-0.5">오리지널: <?php echo $original_cost_price !== null ? number_format($original_cost_price, 2) : '-'; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" min="0" class="edit-wholesale-reference-price border border-gray-300 rounded px-2 py-1 w-20 text-right font-mono" value="<?php echo $effective_wholesale_reference_price !== null ? $effective_wholesale_reference_price : ''; ?>">
                            <?php if ($wholesale_reference_override !== null): ?>
                            <div class="text-gray-400 mt-0.5">오리지널: <?php echo $original_wholesale_reference_price !== null ? number_format($original_wholesale_reference_price, 2) : '-'; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input type="number" step="0.01" min="0" class="edit-selling-price border border-gray-300 rounded px-2 py-1 w-20 text-right font-mono" value="<?php echo $effective_selling_price !== null ? $effective_selling_price : ''; ?>">
                            <div class="selling-price-original-hint text-gray-400 mt-0.5" style="<?php echo $selling_price_override !== null ? '' : 'display:none;'; ?>">오리지널: <?php echo $original_selling_price !== null ? number_format($original_selling_price, 2) : '-'; ?></div>
                        </td>
                        <td class="px-3 py-2"><input type="checkbox" class="edit-is-active" <?php echo $c['is_active'] ? 'checked' : ''; ?>></td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <div class="text-gray-500 mb-1">실재고: <?php echo $c['real_stock_quantity'] !== null ? (int)$c['real_stock_quantity'] . '개' : '-'; ?></div>
                            <label class="flex items-center gap-1 text-[11px] text-red-600 font-semibold" title="켜면 실재고와 무관하게 몰 화면에서 무조건 품절로 표시됩니다">
                                <input type="checkbox" class="edit-is-sold-out" <?php echo $c['is_sold_out'] ? 'checked' : ''; ?>>품절 처리
                            </label>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <label class="flex items-center gap-1 text-[11px] text-gray-600" title="체크 해제 시 소매(일반) 구매 시 할인 규칙이 적용되지 않습니다">
                                <input type="checkbox" class="edit-retail-discount-allowed" <?php echo $c['retail_discount_allowed'] ? 'checked' : ''; ?>>일반
                            </label>
                            <label class="flex items-center gap-1 text-[11px] text-gray-600" title="체크 해제 시 도매 구매 시 할인 규칙이 적용되지 않습니다">
                                <input type="checkbox" class="edit-wholesale-discount-allowed" <?php echo $c['wholesale_discount_allowed'] ? 'checked' : ''; ?>>도매
                            </label>
                        </td>
                        <?php if ($__is_today_deals):
                            $__p_type = $c['promo_type'] ?? 'none';
                            $__p_value = $c['promo_value'] ?? '';
                        ?>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <select class="promo-type-select border border-gray-300 rounded px-1.5 py-1 text-xs" data-product-id="<?php echo (int)$c['product_id']; ?>">
                                <option value="none" <?php echo $__p_type === 'none' ? 'selected' : ''; ?>>없음</option>
                                <option value="1plus1" <?php echo $__p_type === '1plus1' ? 'selected' : ''; ?>>1+1</option>
                                <option value="percent" <?php echo $__p_type === 'percent' ? 'selected' : ''; ?>>퍼센트 할인</option>
                                <option value="cost_sale" <?php echo $__p_type === 'cost_sale' ? 'selected' : ''; ?>>원가세일</option>
                            </select>
                            <div class="promo-value-wrap mt-1" style="<?php echo $__p_type === 'percent' ? '' : 'display:none;'; ?>">
                                <input type="number" min="1" max="99" step="1" class="promo-value-input border border-gray-300 rounded px-1.5 py-1 text-xs w-14" value="<?php echo htmlspecialchars((string)$__p_value); ?>" placeholder="%">%
                            </div>
                        </td>
                        <?php endif; ?>
                        <td class="px-3 py-2">
                            <div class="image-thumb-list flex flex-wrap gap-1">
                                <?php foreach (($images_by_product[$c['product_id']] ?? []) as $img): ?>
                                <div class="image-thumb" data-image-id="<?php echo (int)$img['id']; ?>" style="position:relative;">
                                    <img src="/mall/<?php echo htmlspecialchars($img['image_path']); ?>" class="image-preview-trigger" data-full-src="/mall/<?php echo htmlspecialchars($img['image_path']); ?>" title="클릭해서 크게 보기" style="width:40px;height:40px;object-fit:cover;border-radius:0.3rem;border:1px solid #e5e7eb;cursor:zoom-in;">
                                    <div style="display:flex;gap:2px;margin-top:2px;">
                                        <button class="img-move-btn" data-dir="up" title="위로" style="font-size:0.6rem;">▲</button>
                                        <button class="img-move-btn" data-dir="down" title="아래로" style="font-size:0.6rem;">▼</button>
                                        <button class="img-delete-btn" title="삭제" style="font-size:0.6rem;color:#dc2626;">×</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($images_by_product[$c['product_id']] ?? [])): ?>
                                <span class="text-gray-300 text-[11px]">없음</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <button class="save-curated-btn px-2 py-1 bg-blue-600 text-white rounded text-xs">저장</button>
                            <?php if ($selected_home_slot): ?>
                            <button class="remove-from-home-slot-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>" title="쇼핑몰 큐레이션에서는 그대로 두고 이 진열에서만 뺍니다">진열 제거</button>
                            <?php else: ?>
                            <button class="delete-curated-btn px-2 py-1 bg-red-600 text-white rounded text-xs" data-product-name="<?php echo htmlspecialchars($c['name_ko']); ?>">삭제</button>
                            <?php endif; ?>
                            <div class="mt-1 flex items-center gap-1.5">
                                <label class="text-blue-600 cursor-pointer text-[11px]">
                                    사진추가<input type="file" class="image-upload-input hidden" data-product-id="<?php echo (int)$c['product_id']; ?>" accept="image/jpeg,image/png,image/webp">
                                </label>
                                <a href="https://www.google.com/search?tbm=isch&q=<?php echo urlencode($c['name_ko']); ?>" target="_blank" rel="noopener noreferrer" class="text-gray-500 hover:text-blue-600 text-[11px]">사진검색</a>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (isset($curated_total_pages) && $curated_total_pages > 1): ?>
            <?php
                $__page_qs = $__base_qs;
                if ($selected_category_id) { $__page_qs['cat_id'] = $selected_category_id; }
                if ($selected_sub_id) { $__page_qs['sub_id'] = $selected_sub_id; }
            ?>
            <div class="flex items-center justify-between mt-3 text-xs text-gray-600">
                <span>전체 <?php echo (int)$curated_total; ?>개 중 <?php echo (int)$curated_offset + 1; ?>–<?php echo min($curated_offset + $curated_per_page, $curated_total); ?>개</span>
                <div class="flex items-center gap-1">
                    <a href="?<?php echo http_build_query(array_merge($__page_qs, ['page' => max(1, $curated_page - 1)])); ?>"
                       class="px-2 py-1 rounded border border-gray-300 <?php echo $curated_page <= 1 ? 'pointer-events-none text-gray-300' : 'hover:bg-gray-100'; ?>">이전</a>
                    <span class="px-2">페이지 <?php echo (int)$curated_page; ?> / <?php echo (int)$curated_total_pages; ?></span>
                    <a href="?<?php echo http_build_query(array_merge($__page_qs, ['page' => min($curated_total_pages, $curated_page + 1)])); ?>"
                       class="px-2 py-1 rounded border border-gray-300 <?php echo $curated_page >= $curated_total_pages ? 'pointer-events-none text-gray-300' : 'hover:bg-gray-100'; ?>">다음</a>
                </div>
            </div>
            <?php endif; ?>
        </section>
        </div>

        <div class="w-96 flex-shrink-0">
        <section class="bg-white rounded-lg border border-gray-200 p-4">
            <?php if ($selected_home_slot): ?>
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo htmlspecialchars($home_slot_labels[$selected_home_slot]); ?>에 추가 <span class="text-gray-400 font-normal">(전체 상품 대상)</span></h2>
            <form method="get" class="flex gap-1 mb-3">
                <input type="hidden" name="home_slot" value="<?php echo htmlspecialchars($selected_home_slot); ?>">
                <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?><input type="hidden" name="store_id" value="<?php echo (int)$selected_store_id; ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="상품명/SKU 검색"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs w-full">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md">검색</button>
            </form>
            <?php if ($search !== ''): ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left">상품명</th><th class="px-3 py-2 text-left">액션</th></tr>
                </thead>
                <tbody>
                <?php if (empty($home_slot_search_results)): ?>
                    <tr><td colspan="2" class="px-3 py-4 text-center text-gray-400">검색 결과가 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($home_slot_search_results as $p): ?>
                    <?php $__already_in_slot = in_array((int)$p['product_id'], $home_slot_membership[$selected_home_slot], true); ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <div class="text-gray-400"><?php echo htmlspecialchars($p['sku']); ?></div>
                            <?php echo htmlspecialchars($p['display_name'] ?: $p['name_ko']); ?>
                            <?php if (!$p['mall_product_id']): ?><span class="text-amber-600" title="이 점포에 큐레이션되어 있지 않은 상품입니다">미큐레이션</span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php if ($__already_in_slot): ?>
                                <span class="text-gray-400">이미 추가됨</span>
                            <?php else: ?>
                                <button class="add-to-home-slot-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$p['product_id']; ?>">추가</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php else: ?>
            <h2 class="text-sm font-bold text-gray-700 mb-3">상품 검색 <span class="text-gray-400 font-normal">(전체 상품 대상)</span></h2>
            <?php if (!$add_target_category_id): ?>
            <div class="mb-3 px-3 py-2 text-xs rounded border bg-amber-50 text-amber-700 border-amber-200">
                <i class="fas fa-circle-info mr-1"></i>좌측에서 카테고리(가능하면 소분류까지)를 먼저 선택해야 상품을 몰에 추가할 수 있습니다.
            </div>
            <?php endif; ?>
            <form method="get" class="flex gap-1 mb-3">
                <?php if ($selected_category_id): ?><input type="hidden" name="cat_id" value="<?php echo (int)$selected_category_id; ?>"><?php endif; ?>
                <?php if ($selected_sub_id): ?><input type="hidden" name="sub_id" value="<?php echo (int)$selected_sub_id; ?>"><?php endif; ?>
                <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?><input type="hidden" name="store_id" value="<?php echo (int)$selected_store_id; ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="상품명/바코드/SKU 검색"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs w-full">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md">검색</button>
            </form>
            <?php if ($search !== ''): ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left">상품명</th><th class="px-3 py-2 text-left">최근 1개월 판매량</th><th class="px-3 py-2 text-left">액션</th></tr>
                </thead>
                <tbody>
                <?php if (empty($search_results)): ?>
                    <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400">검색 결과가 없습니다.</td></tr>
                <?php endif; ?>
                <?php foreach ($search_results as $p): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <div class="text-gray-400"><?php echo htmlspecialchars($p['sku']); ?></div>
                            <?php echo htmlspecialchars($p['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2"><?php echo number_format((float)$p['recent_sales_qty']); ?>개</td>
                        <td class="px-3 py-2">
                            <?php if ($p['mall_product_id']): ?>
                                <?php if ($add_target_category_id && (int)$p['category_id'] !== $add_target_category_id): ?>
                                <button class="move-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-product-id="<?php echo (int)$p['id']; ?>" data-category-id="<?php echo (int)$add_target_category_id; ?>" title="현재 선택된 카테고리로 이동등록">이동등록</button>
                                <?php else: ?>
                                <span class="text-gray-400">이미 등록됨</span>
                                <?php endif; ?>
                            <?php elseif (!$add_target_category_id): ?>
                                <span class="text-gray-400" title="좌측에서 카테고리를 먼저 선택하세요">카테고리 선택 필요</span>
                            <?php else: ?>
                                <button class="add-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-product-id="<?php echo (int)$p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name_ko']); ?>" data-name-en="<?php echo htmlspecialchars($p['name_en'] ?? ''); ?>" data-store-id="<?php echo (int)$selected_store_id; ?>" data-category-id="<?php echo (int)$add_target_category_id; ?>">쇼핑몰에 추가</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php endif; ?>
        </section>
        </div>
        </div>
    </main>

    <div id="image-preview-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-50 items-center justify-center p-4" style="cursor:zoom-out;">
        <img id="image-preview-modal-img" src="" alt="" style="max-width:90vw;max-height:90vh;object-fit:contain;border-radius:0.5rem;">
    </div>

    <?php if ($can_manage_categories): ?>
    <div id="category-manage-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden z-50 items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-800"><i class="fas fa-sitemap mr-1.5"></i>카테고리 관리</h3>
                <button type="button" id="close-category-manage-btn" class="text-gray-400 hover:text-gray-700"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="p-4 overflow-y-auto flex-1">
                <ul id="modal-category-list" class="space-y-1">
                    <?php foreach ($mall_categories as $cat): ?>
                    <li class="category-item border border-gray-100 rounded-md" data-category-id="<?php echo (int)$cat['id']; ?>" draggable="true">
                        <div class="flex items-center gap-1 px-2 py-1.5">
                            <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="드래그해서 순서 변경"></i>
                            <button type="button" class="toggle-sub-btn text-gray-400 hover:text-gray-700 px-1" title="소분류 펼치기/접기">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" data-category-id="<?php echo (int)$cat['id']; ?>" value="<?php echo htmlspecialchars($cat['name']); ?>">
                            <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="<?php echo htmlspecialchars($cat['name_en'] ?? ''); ?>" placeholder="EN">
                            <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$cat['product_count']; ?>)</span>
                            <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$cat['id']; ?>" data-category-name="<?php echo htmlspecialchars($cat['name']); ?>" title="삭제"><i class="fas fa-xmark"></i></button>
                        </div>
                        <div class="subcategory-panel hidden pl-6 pr-2 pb-2">
                            <ul class="subcategory-list space-y-1 mb-2" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <?php foreach (($sub_categories_by_parent[$cat['id']] ?? []) as $sub): ?>
                                <li class="category-item flex items-center gap-1" data-category-id="<?php echo (int)$sub['id']; ?>" draggable="true">
                                    <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="드래그해서 순서 변경"></i>
                                    <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" data-category-id="<?php echo (int)$sub['id']; ?>" value="<?php echo htmlspecialchars($sub['name']); ?>">
                                    <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs w-14 flex-shrink-0" value="<?php echo htmlspecialchars($sub['name_en'] ?? ''); ?>" placeholder="EN">
                                    <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$sub['product_count']; ?>)</span>
                                    <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$sub['id']; ?>" data-category-name="<?php echo htmlspecialchars($sub['name']); ?>" title="삭제"><i class="fas fa-xmark"></i></button>
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($sub_categories_by_parent[$cat['id']] ?? [])): ?>
                                <li class="text-[11px] text-gray-300">소분류 없음</li>
                                <?php endif; ?>
                            </ul>
                            <form class="add-subcategory-form flex gap-1" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <input type="text" name="name" placeholder="소분류명" required class="border border-gray-300 rounded px-2 py-1 text-xs flex-1 min-w-0">
                                <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded px-2 py-1 text-xs w-16">
                                <button type="submit" class="px-2 py-1 text-xs font-semibold bg-gray-500 text-white rounded flex-shrink-0">추가</button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="p-4 border-t border-gray-100 space-y-2">
                <p class="text-[11px] text-gray-400">"추가"를 누르면 목록에 <span class="text-blue-500 font-bold">NEW</span> 표시로 쌓이기만 하고, 아래 버튼을 눌러야 실제로 저장됩니다.</p>
                <button type="button" id="apply-category-edits-btn" class="w-full px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md">
                    <i class="fas fa-check mr-1"></i>변경/추가 내용 한번에 적용
                </button>
                <form id="add-category-form" class="flex gap-1">
                    <input type="text" name="name" placeholder="새 대분류명" required class="border border-gray-300 rounded-md px-2 py-1 text-xs flex-1 min-w-0">
                    <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded-md px-2 py-1 text-xs w-20">
                    <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md flex-shrink-0">추가</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

<script>
function showFlash(message, type, duration) {
    const area = document.getElementById('flash-area');
    const color = type === 'error' ? 'bg-red-100 text-red-700 border-red-300' : 'bg-green-100 text-green-700 border-green-300';
    area.innerHTML = '<div class="mb-3 px-3 py-2 text-xs rounded border ' + color + '">' + message + '</div>';
    setTimeout(() => { area.innerHTML = ''; }, duration || 4000);
}

// 큐레이션된 상품 목록의 이미지 썸네일 클릭 시 모달로 크게 보기.
const imagePreviewModal = document.getElementById('image-preview-modal');
const imagePreviewModalImg = document.getElementById('image-preview-modal-img');
document.querySelectorAll('.image-preview-trigger').forEach(function (img) {
    img.addEventListener('click', function () {
        imagePreviewModalImg.src = img.dataset.fullSrc;
        imagePreviewModalImg.alt = img.alt || '';
        imagePreviewModal.classList.remove('hidden');
        imagePreviewModal.classList.add('flex');
    });
});
if (imagePreviewModal) {
    imagePreviewModal.addEventListener('click', function () {
        imagePreviewModal.classList.add('hidden');
        imagePreviewModal.classList.remove('flex');
        imagePreviewModalImg.src = '';
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !imagePreviewModal.classList.contains('hidden')) {
            imagePreviewModal.classList.add('hidden');
            imagePreviewModal.classList.remove('flex');
            imagePreviewModalImg.src = '';
        }
    });
}

// 큐레이션된 상품 목록의 바코드(SKU) 클릭 시 클립보드로 복사.
document.querySelectorAll('.barcode-copy').forEach(function (el) {
    el.addEventListener('click', function () {
        const code = el.dataset.barcode;
        if (!code) return;
        const done = function () { showFlash('바코드를 복사했습니다: ' + code, 'success', 1500); };
        const fail = function () { showFlash('복사 실패 — 직접 선택해서 복사해주세요.', 'error'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(code).then(done).catch(fail);
        } else {
            // HTTP(비보안 컨텍스트)라 navigator.clipboard가 없을 때의 대체 방법.
            const tmp = document.createElement('textarea');
            tmp.value = code;
            tmp.style.position = 'fixed';
            tmp.style.opacity = '0';
            document.body.appendChild(tmp);
            tmp.focus();
            tmp.select();
            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                fail();
            }
            document.body.removeChild(tmp);
        }
    });
});

const storeSelect = document.getElementById('store-select');
if (storeSelect) {
    storeSelect.addEventListener('change', function () {
        const params = new URLSearchParams(window.location.search);
        params.set('store_id', storeSelect.value);
        window.location.href = 'products.php?' + params.toString();
    });
}

document.querySelectorAll('.add-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'add');
        params.set('product_id', btn.dataset.productId);
        params.set('display_name', btn.dataset.name);
        params.set('display_name_en', btn.dataset.nameEn);
        params.set('store_id', btn.dataset.storeId);
        if (btn.dataset.categoryId) { params.set('category_id', btn.dataset.categoryId); }
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '오류가 발생했습니다.', 'error'); }
            });
    });
});

document.querySelectorAll('.move-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'move_category');
        params.set('product_id', btn.dataset.productId);
        params.set('category_id', btn.dataset.categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '이동등록 실패', 'error'); }
            });
    });
});

// 품절 처리는 급한 조작이라 체크하는 즉시 저장한다(다른 값 바뀌지 않고 그대로 저장됨 — "저장" 버튼과 동일한 동작).
document.querySelectorAll('.edit-is-sold-out').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        const saveBtn = checkbox.closest('tr').querySelector('.save-curated-btn');
        if (saveBtn) { saveBtn.click(); }
    });
});

document.querySelectorAll('.save-curated-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = btn.closest('tr');
        const params = new URLSearchParams();
        params.set('action', 'update');
        params.set('mall_product_id', row.dataset.mallProductId);
        params.set('display_name', row.querySelector('.edit-display-name').value);
        params.set('display_name_en', row.querySelector('.edit-display-name-en').value);
        params.set('cost_price', row.querySelector('.edit-cost-price').value);
        params.set('wholesale_reference_price', row.querySelector('.edit-wholesale-reference-price').value);
        params.set('selling_price', row.querySelector('.edit-selling-price').value);
        params.set('display_order', row.querySelector('.edit-display-order').value);
        params.set('is_active', row.querySelector('.edit-is-active').checked ? '1' : '0');
        params.set('is_sold_out', row.querySelector('.edit-is-sold-out').checked ? '1' : '0');
        params.set('retail_discount_allowed', row.querySelector('.edit-retail-discount-allowed').checked ? '1' : '0');
        params.set('wholesale_discount_allowed', row.querySelector('.edit-wholesale-discount-allowed').checked ? '1' : '0');
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                showFlash(data.success ? '저장되었습니다.' : (data.error?.message || '오류가 발생했습니다.'), data.success ? 'success' : 'error');
            });
    });
});

// 홈 노출(오늘의특가/기획전/새상품) — 오른쪽 검색 결과에서 "추가", 왼쪽 목록에서 "진열 제거".
function toggleHomeSlotProduct(slotKey, productId, active, onDone) {
    const params = new URLSearchParams();
    params.set('slot_key', slotKey);
    params.set('product_id', productId);
    params.set('active', active ? '1' : '0');
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/toggle_home_section_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) { onDone(); } else { showFlash(data.error?.message || '저장 실패', 'error'); }
        })
        .catch(function () { showFlash('저장 실패', 'error'); });
}

document.querySelectorAll('.add-to-home-slot-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        btn.disabled = true;
        toggleHomeSlotProduct(btn.dataset.slotKey, btn.dataset.productId, true, function () {
            window.location.reload();
        });
    });
});

document.querySelectorAll('.remove-from-home-slot-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        btn.disabled = true;
        toggleHomeSlotProduct(btn.dataset.slotKey, btn.dataset.productId, false, function () {
            window.location.reload();
        });
    });
});

document.querySelectorAll('.curate-home-slot-product-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        btn.disabled = true;
        // 이미 진열에 들어있는 상품이라 active:true를 다시 보내도 목록엔 변화 없고, 큐레이션(mall_products)만 새로 생긴다.
        toggleHomeSlotProduct(btn.dataset.slotKey, btn.dataset.productId, true, function () {
            window.location.reload();
        });
    });
});

// 오늘의특가 상품별 프로모(1+1/퍼센트할인/원가세일) — 값 바꾸면 바로 저장된다.
function saveTodayDealPromo(productId, promoType, promoValue) {
    const params = new URLSearchParams();
    params.set('product_id', productId);
    params.set('promo_type', promoType);
    if (promoValue !== null) { params.set('promo_value', promoValue); }
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    return fetch('ajax/save_today_deal_promo.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json());
}

// 프로모 저장 성공 시 "기준판매가" 입력칸과 "오리지널: N" 안내를 그 자리에서 바로 갱신한다
// (안 그러면 저장은 됐는데 화면에는 예전 가격이 그대로 보여서 "적용이 안 된다"처럼 보인다).
function applyPromoResultToRow(select, data) {
    const row = select.closest('tr');
    const priceInput = row.querySelector('.edit-selling-price');
    const hint = row.querySelector('.selling-price-original-hint');
    if (!priceInput) return;
    if (data.new_price !== null && data.new_price !== undefined) {
        priceInput.value = data.new_price;
        if (hint) {
            hint.style.display = '';
            hint.textContent = '오리지널: ' + (data.original_selling_price !== null ? Number(data.original_selling_price).toFixed(2) : '-');
        }
    } else {
        // 프로모 해제(없음/1+1) — override가 풀렸으니 오리지널 판매가로 되돌리고 힌트는 숨긴다.
        if (data.original_selling_price !== null && data.original_selling_price !== undefined) {
            priceInput.value = data.original_selling_price;
        }
        if (hint) { hint.style.display = 'none'; }
    }
}

document.querySelectorAll('.promo-type-select').forEach(function (select) {
    const wrap = select.closest('td').querySelector('.promo-value-wrap');
    const valueInput = wrap ? wrap.querySelector('.promo-value-input') : null;

    select.addEventListener('change', function () {
        if (wrap) { wrap.style.display = select.value === 'percent' ? '' : 'none'; }
        if (select.value === 'percent') {
            if (!valueInput.value) { return; } // 퍼센트 값을 아직 안 넣었으면 값 입력 시 저장한다.
        }
        select.disabled = true;
        saveTodayDealPromo(select.dataset.productId, select.value, select.value === 'percent' ? valueInput.value : null)
            .then(function (data) {
                select.disabled = false;
                if (!data.success) { showFlash(data.error?.message || '저장 실패', 'error'); return; }
                applyPromoResultToRow(select, data.data);
                showFlash('프로모가 저장되었습니다. 홈 레이아웃에서 "적용"을 눌러야 반영됩니다.', 'success');
            })
            .catch(function () { select.disabled = false; showFlash('저장 실패', 'error'); });
    });

    if (valueInput) {
        valueInput.addEventListener('change', function () {
            if (select.value !== 'percent' || !valueInput.value) { return; }
            valueInput.disabled = true;
            saveTodayDealPromo(select.dataset.productId, 'percent', valueInput.value)
                .then(function (data) {
                    valueInput.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '저장 실패', 'error'); return; }
                    applyPromoResultToRow(select, data.data);
                    showFlash('프로모가 저장되었습니다. 홈 레이아웃에서 "적용"을 눌러야 반영됩니다.', 'success');
                })
                .catch(function () { valueInput.disabled = false; showFlash('저장 실패', 'error'); });
        });
    }
});

document.querySelectorAll('.delete-curated-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.productName + ' 상품을 쇼핑몰 큐레이션에서 삭제하시겠습니까?')) return;
        const row = btn.closest('tr');
        const params = new URLSearchParams();
        params.set('action', 'delete');
        params.set('mall_product_id', row.dataset.mallProductId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '삭제 실패', 'error'); }
            });
    });
});

document.querySelectorAll('.image-upload-input').forEach(function (input) {
    input.addEventListener('change', function () {
        if (!input.files.length) return;
        const formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('product_id', input.dataset.productId);
        formData.append('image', input.files[0]);
        formData.append('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '업로드 실패', 'error'); }
            });
    });
});

document.querySelectorAll('.img-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const imageId = btn.closest('.image-thumb').dataset.imageId;
        const params = new URLSearchParams();
        params.set('image_id', imageId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/delete_product_image.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '삭제 실패', 'error'); }
            });
    });
});

document.querySelectorAll('.img-move-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const imageId = btn.closest('.image-thumb').dataset.imageId;
        const params = new URLSearchParams();
        params.set('image_id', imageId);
        params.set('direction', btn.dataset.dir);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/reorder_product_images.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '정렬 실패', 'error'); }
            });
    });
});

// 카테고리/소분류 "추가"는 이제 즉시 서버로 보내지 않고 목록에 임시(NEW) 행으로만 쌓아둔다.
// 실제 저장은 "변경/추가 내용 한번에 적용" 버튼을 눌렀을 때 한 번에 반영된다.
function addPendingCategoryRow(name, nameEn, parentId, targetList) {
    const li = document.createElement('li');
    li.className = 'pending-category-item category-item flex items-center gap-1' + (parentId ? '' : ' border border-dashed border-blue-300 rounded-md px-2 py-1.5');
    li.dataset.parentId = parentId || '';
    li.innerHTML =
        '<span class="text-blue-500 text-[10px] font-bold flex-shrink-0" title="아직 저장 전입니다">NEW</span>' +
        '<input type="text" class="pending-name border border-blue-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="' + escHtml(name) + '">' +
        '<input type="text" class="pending-name-en border border-blue-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="' + escHtml(nameEn) + '" placeholder="EN">' +
        '<button type="button" class="remove-pending-btn text-gray-300 hover:text-red-500 px-1" title="취소"><i class="fas fa-xmark"></i></button>';
    li.querySelector('.remove-pending-btn').addEventListener('click', function () { li.remove(); });
    targetList.appendChild(li);
}

const addCategoryForm = document.getElementById('add-category-form');
if (addCategoryForm) {
    addCategoryForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = addCategoryForm.name.value.trim();
        if (!name) return;
        addPendingCategoryRow(name, addCategoryForm.name_en.value.trim(), null, document.getElementById('modal-category-list'));
        addCategoryForm.reset();
        addCategoryForm.name.focus();
    });
}

document.querySelectorAll('.add-subcategory-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = form.name.value.trim();
        if (!name) return;
        const targetList = form.closest('.subcategory-panel').querySelector('.subcategory-list');
        addPendingCategoryRow(name, form.name_en.value.trim(), form.dataset.parentId, targetList);
        form.reset();
        form.name.focus();
    });
});

// 카테고리 관리 모달 열기/닫기 + 소분류 펼치기/접기
const categoryManageModal = document.getElementById('category-manage-modal');
const openCategoryManageBtn = document.getElementById('open-category-manage-btn');
const closeCategoryManageBtn = document.getElementById('close-category-manage-btn');
if (openCategoryManageBtn && categoryManageModal) {
    openCategoryManageBtn.addEventListener('click', function () {
        categoryManageModal.classList.remove('hidden');
        categoryManageModal.classList.add('flex');
    });
}
if (closeCategoryManageBtn && categoryManageModal) {
    closeCategoryManageBtn.addEventListener('click', function () {
        categoryManageModal.classList.add('hidden');
        categoryManageModal.classList.remove('flex');
    });
    categoryManageModal.addEventListener('click', function (e) {
        if (e.target === categoryManageModal) { closeCategoryManageBtn.click(); }
    });
}
document.querySelectorAll('.toggle-sub-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const panel = btn.closest('.category-item').querySelector('.subcategory-panel');
        panel.classList.toggle('hidden');
        btn.querySelector('i').classList.toggle('fa-chevron-right');
        btn.querySelector('i').classList.toggle('fa-chevron-down');
    });
});

// 카테고리/소분류 이름 수정 + "추가"로 쌓아둔 신규(NEW) 항목을 한번에 서버로 보낸다.
const applyCategoryEditsBtn = document.getElementById('apply-category-edits-btn');
if (applyCategoryEditsBtn) {
    applyCategoryEditsBtn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'bulk_edit');

        document.querySelectorAll('#category-manage-modal .edit-category-name').forEach(function (nameInput) {
            const nameEnInput = nameInput.parentElement.querySelector('.edit-category-name-en');
            params.append('category_id[]', nameInput.dataset.categoryId);
            params.append('name[]', nameInput.value.trim());
            params.append('name_en[]', nameEnInput ? nameEnInput.value.trim() : '');
        });

        let hasEmptyPending = false;
        document.querySelectorAll('#category-manage-modal .pending-category-item').forEach(function (li) {
            const name = li.querySelector('.pending-name').value.trim();
            const nameEn = li.querySelector('.pending-name-en').value.trim();
            if (!name) { hasEmptyPending = true; return; }
            params.append('new_name[]', name);
            params.append('new_name_en[]', nameEn);
            params.append('new_parent_id[]', li.dataset.parentId || '');
        });
        if (hasEmptyPending) {
            showFlash('추가하려는 카테고리명이 비어있는 항목이 있습니다.', 'error');
            return;
        }

        params.set('csrf_token', window.MALL_CSRF_TOKEN);

        applyCategoryEditsBtn.disabled = true;
        fetch('ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '변경사항 적용 실패', 'error'); applyCategoryEditsBtn.disabled = false; }
            });
    });
}

function escHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// 대분류를 지웠으면 그 필터를 통째로 해제하고, 소분류를 지웠으면 sub_id만 해제한 뒤 새로고침한다.
function goToProductsAfterCategoryDelete(deletedId) {
    const url = new URL(window.location.href);
    if (url.searchParams.get('cat_id') === String(deletedId)) {
        url.searchParams.delete('cat_id');
        url.searchParams.delete('sub_id');
    } else if (url.searchParams.get('sub_id') === String(deletedId)) {
        url.searchParams.delete('sub_id');
    }
    window.location.href = url.pathname + url.search;
}

document.querySelectorAll('.delete-category-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.categoryName + ' 카테고리를 삭제하시겠습니까?\n등록된 상품이 있으면 삭제할 수 없습니다.')) return;
        const params = new URLSearchParams();
        params.set('action', 'delete');
        params.set('category_id', btn.dataset.categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    const details = data.error?.details;
                    if (details && details.products && details.products.length) {
                        let list = '<ul style="margin:0.3rem 0 0;padding-left:1.1rem;">' +
                            details.products.map(p => '<li>' + escHtml(p.name) + (p.sku ? ' (' + escHtml(p.sku) + ')' : '') + '</li>').join('') +
                            (details.truncated ? '<li>... 외 ' + (details.product_count - details.products.length) + '개</li>' : '') +
                            '</ul>';
                        const forceBtn = '<button type="button" class="force-clear-delete-btn" data-category-id="' + escHtml(btn.dataset.categoryId) +
                            '" data-category-name="' + escHtml(btn.dataset.categoryName) +
                            '" style="margin-top:0.5rem;padding:4px 10px;background:#dc2626;color:#fff;border-radius:4px;font-size:11px;cursor:pointer;border:none;">이 상품들 카테고리 비우고 삭제</button>';
                        showFlash((data.error?.message || '카테고리 삭제 실패') + list + forceBtn, 'error', 20000);
                    } else {
                        showFlash(data.error?.message || '카테고리 삭제 실패', 'error');
                    }
                    return;
                }
                goToProductsAfterCategoryDelete(btn.dataset.categoryId);
            });
    });
});

// 위 "이 상품들 카테고리 비우고 삭제" 버튼은 flash-area에 나중에 삽입되므로 이벤트 위임으로 처리한다.
document.getElementById('flash-area').addEventListener('click', function (e) {
    const btn = e.target.closest('.force-clear-delete-btn');
    if (!btn) return;
    if (!confirm(btn.dataset.categoryName + ' 카테고리에 걸려있는 상품들의 카테고리를 전부 비우고, 카테고리도 함께 삭제합니다.\n되돌릴 수 없습니다. 계속하시겠습니까?')) return;
    btn.disabled = true;
    const params = new URLSearchParams();
    params.set('action', 'clear_and_delete');
    params.set('category_id', btn.dataset.categoryId);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                goToProductsAfterCategoryDelete(btn.dataset.categoryId);
            } else {
                showFlash(data.error?.message || '삭제 실패', 'error');
                btn.disabled = false;
            }
        });
});

// 네이티브 HTML5 Drag & Drop으로 카테고리 순서 변경 — 대분류 목록/각 소분류 목록에 공용으로 쓴다.
// listEl의 "직계 자식" .category-item끼리만 순서를 바꾸므로 대분류 목록과 소분류 목록이 서로 섞이지 않는다.
function makeCategoryListSortable(listEl, onReordered) {
    let dragSrc = null;
    Array.from(listEl.children).forEach(function (item) {
        if (!item.classList.contains('category-item') || item.getAttribute('draggable') !== 'true') return;
        item.addEventListener('dragstart', function () { dragSrc = item; });
        item.addEventListener('dragover', function (e) { e.preventDefault(); item.classList.add('drag-over'); });
        item.addEventListener('dragleave', function () { item.classList.remove('drag-over'); });
        item.addEventListener('drop', function (e) {
            e.preventDefault();
            item.classList.remove('drag-over');
            if (dragSrc && dragSrc !== item && dragSrc.parentElement === listEl) {
                const items = Array.from(listEl.children);
                const srcIndex = items.indexOf(dragSrc);
                const dstIndex = items.indexOf(item);
                if (srcIndex < dstIndex) {
                    item.after(dragSrc);
                } else {
                    item.before(dragSrc);
                }
                onReordered();
            }
        });
    });
}

function saveCategoryOrder(listEl, parentId) {
    const ids = Array.from(listEl.children)
        .filter(el => el.classList.contains('category-item'))
        .map(el => el.dataset.categoryId);
    const params = new URLSearchParams();
    params.set('order', ids.join(','));
    if (parentId) { params.set('parent_id', parentId); }
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/reorder_categories.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showFlash(data.error?.message || '순서 저장 실패', 'error'); }
        });
}

const modalCategoryList = document.getElementById('modal-category-list');
if (modalCategoryList) {
    makeCategoryListSortable(modalCategoryList, function () { saveCategoryOrder(modalCategoryList, null); });
}
document.querySelectorAll('.subcategory-list').forEach(function (subList) {
    makeCategoryListSortable(subList, function () { saveCategoryOrder(subList, subList.dataset.parentId); });
});

// 네이티브 HTML5 Drag & Drop으로 큐레이션된 상품 순서 변경
let curatedDragSrcRow = null;
function attachCuratedDragHandlers() {
    document.querySelectorAll('.curated-row[draggable="true"]').forEach(function (row) {
        row.addEventListener('dragstart', function () { curatedDragSrcRow = row; });
        row.addEventListener('dragover', function (e) { e.preventDefault(); row.classList.add('drag-over'); });
        row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            row.classList.remove('drag-over');
            if (curatedDragSrcRow && curatedDragSrcRow !== row) {
                const isAfter = curatedDragSrcRow.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING;
                if (isAfter) {
                    row.after(curatedDragSrcRow);
                } else {
                    row.before(curatedDragSrcRow);
                }
                saveCuratedOrder();
            }
        });
    });
}
attachCuratedDragHandlers();

function saveCuratedOrder() {
    const body = document.getElementById('curated-products-body');
    const offset = parseInt(body.dataset.orderOffset, 10) || 0;
    const rows = Array.from(document.querySelectorAll('#curated-products-body .curated-row'));
    const ids = rows.map(r => r.dataset.mallProductId);
    const params = new URLSearchParams();
    params.set('order', ids.join(','));
    params.set('offset', offset);
    params.set('csrf_token', window.MALL_CSRF_TOKEN);
    fetch('ajax/reorder_curated_products.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                rows.forEach(function (r, idx) {
                    const input = r.querySelector('.edit-display-order');
                    if (input) { input.value = offset + idx; }
                });
            } else {
                showFlash(data.error?.message || '순서 저장 실패', 'error');
            }
        });
}
</script>
</body>
</html>
