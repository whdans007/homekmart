<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../lib/home_layout.php';
require_once __DIR__ . '/../../admin/fresh_product_common.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'products.php';
$search = trim($_GET['q'] ?? '');
// 상품 검색 섹션의 탭 — 일반상품(products/mall_products) / 신선상품(mall_fresh_products)
$search_tab = ($_GET['search_tab'] ?? 'general') === 'fresh' ? 'fresh' : 'general';
// 신선상품 탭 전용: 과일/채소/정육/수산 고정 분류 버튼 — 검색어 없이도 분류 전체 상품을 바로 볼 수 있게 한다.
$selected_fresh_cat = $_GET['fresh_cat'] ?? '';
if (!array_key_exists($selected_fresh_cat, fresh_category_options())) {
    $selected_fresh_cat = null;
}
// 좌측 카테고리명이 신선상품 고정분류(과일/채소/정육/수산) 명칭을 포함하면, 그 카테고리를 클릭했을 때
// 우측 상품검색 패널이 자동으로 "신선상품" 탭 + 해당 분류로 전환되도록 매핑한다(예: "수산물" → seafood).
// 카테고리명은 관리자 화면 언어와 무관하게 항상 한글로 저장되어 있어(categories.name), fresh_category_options()의
// t() 결과(현재 언어에 따라 영문으로 바뀔 수 있음)로 비교하면 언어가 English일 때 매칭이 깨진다.
// 그래서 언어 설정과 무관하게 한글/영문 키워드를 고정으로 두고 매칭한다.
function mall_fresh_category_code_for_name(string $category_name): ?string {
    static $keywords_by_code = [
        'fruit' => ['과일', 'fruit'],
        'vegetable' => ['채소', 'vegetable'],
        'meat' => ['정육', '육류', 'meat'],
        'seafood' => ['수산', 'seafood'],
    ];
    $name_lower = mb_strtolower($category_name);
    foreach ($keywords_by_code as $code => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($name_lower, mb_strtolower($keyword)) !== false) {
                return $code;
            }
        }
    }
    return null;
}
$selected_category_id = isset($_GET['cat_id']) && $_GET['cat_id'] !== '' ? (int)$_GET['cat_id'] : null;
$selected_sub_id = isset($_GET['sub_id']) && $_GET['sub_id'] !== '' ? (int)$_GET['sub_id'] : null;
$can_manage_categories = has_permission('category_management');

// 카테고리 목록(특히 "전체")은 상품이 많으면 한 번에 다 그리기엔 너무 커서 페이지네이션한다.
// 홈 노출(오늘의특가 등) 목록은 보통 몇 개 안 되니 페이지네이션 없이 그대로 둔다.
$curated_page = max(1, (int)($_GET['page'] ?? 1));
$curated_per_page = 50;

// 카테고리 아랫쪽에 "홈 노출" 가상 카테고리(오늘의특가/기획전/새상품)를 둔다 — 실제 categories 테이블과는
// 무관하고, mall_home_sections의 해당 슬롯 draft product_ids로만 관리한다.
$home_slot_labels = [
    'today_deals' => t('mall_admin.products.slot_today_deals'), 'promo_products' => t('mall_admin.products.slot_promo'),
    'new_arrivals' => t('mall_admin.products.slot_new_arrivals'),
];
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
// product_count: 대분류 + 그 하위 소분류에 걸린 일반 큐레이션 상품과 신선상품의 합계.
// products.category_id만으로 세면 큐레이션에서 삭제된 상품(category_id는 안 지워짐)까지 잡혀서
// 일반상품은 mall_products에 실제 등록된 건만 세고, 신선상품은 카테고리가 배정된 건을 함께 센다.
$cat_count_stmt = $conn->prepare(
    "SELECT c.id, c.name, c.name_en,
            COUNT(DISTINCT mp.id) + COUNT(DISTINCT mfp.id) AS product_count
     FROM categories c
     LEFT JOIN categories sub ON sub.parent_id = c.id
     LEFT JOIN products p ON (p.category_id = c.id OR p.category_id = sub.id)
     LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
     LEFT JOIN mall_fresh_products mfp ON (mfp.category_id = c.id OR mfp.category_id = sub.id)
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
    "SELECT c.id, c.name, c.name_en, c.parent_id,
            COUNT(DISTINCT mp.id) + COUNT(DISTINCT mfp.id) AS product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     LEFT JOIN mall_products mp ON mp.product_id = p.id AND mp.store_id = ?
     LEFT JOIN mall_fresh_products mfp ON mfp.category_id = c.id
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

// 신선상품 검색(신선상품 탭) — mall_fresh_products에서 코드/이름으로 검색한다. 등록/이동 대상 카테고리는
// 일반상품과 동일하게 좌측에서 선택한 카테고리($add_target_category_id) 기준이다.
// mall_fresh_products는 점포 구분이 없는 몰 전체 단일 카탈로그라 store_id 필터는 적용하지 않는다.
$fresh_search_results = [];
if (!$selected_home_slot && $search_tab === 'fresh' && ($search !== '' || $selected_fresh_cat)) {
    $fresh_where = ["status = 'active'"];
    $fresh_types = '';
    $fresh_params = [];
    if ($search !== '') {
        $fresh_like = '%' . $search . '%';
        $fresh_where[] = '(code LIKE ? OR name_ko LIKE ? OR name_en LIKE ?)';
        $fresh_types .= 'sss';
        array_push($fresh_params, $fresh_like, $fresh_like, $fresh_like);
    }
    if ($selected_fresh_cat) {
        $fresh_where[] = 'fresh_category = ?';
        $fresh_types .= 's';
        $fresh_params[] = $selected_fresh_cat;
    }
    // 분류 버튼만으로 전체 상품을 볼 때는 검색어가 없을 수 있으니 넉넉히 보여준다.
    $fresh_limit = $search !== '' ? 50 : 500;
    $fresh_search_stmt = $conn->prepare(
        "SELECT id, code, name_ko, name_en, fresh_category, sale_type, price_per_100g, category_id
         FROM mall_fresh_products
         WHERE " . implode(' AND ', $fresh_where) . "
         ORDER BY name_ko LIMIT {$fresh_limit}"
    );
    if ($fresh_types !== '') {
        $fresh_search_stmt->bind_param($fresh_types, ...$fresh_params);
    }
    $fresh_search_stmt->execute();
    $fresh_search_results = $fresh_search_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fresh_search_stmt->close();
}

// 좌측에서 선택한 카테고리에 배정된 신선상품 — 검색 탭과 무관하게 항상 중앙 패널에 표시한다.
// 상태(active/inactive)와 무관하게 전부 보여줘야 비활성 상품도 배정 해제가 가능하다.
// 카테고리를 "전체"로 두고 있으면(=$category_filter_ids가 비어있으면) 카테고리가 배정된 신선상품 전체를 보여준다.
$fresh_curated = [];
if (!$selected_home_slot) {
    if (!empty($category_filter_ids)) {
        $fc_where = 'mfp.category_id IN (' . implode(',', array_fill(0, count($category_filter_ids), '?')) . ')';
        $fc_types = str_repeat('i', count($category_filter_ids));
        $fc_params = $category_filter_ids;
    } else {
        $fc_where = 'mfp.category_id IS NOT NULL';
        $fc_types = '';
        $fc_params = [];
    }
    $fresh_curated_stmt = $conn->prepare(
        "SELECT mfp.id, mfp.code, mfp.name_ko, mfp.name_en, mfp.fresh_category, mfp.sale_type, mfp.price_per_100g,
                mfp.status, mfp.image_url, mfp.display_order, mfp.cost_price_override, mfp.wholesale_reference_price_override,
                mfp.selling_price_override, mfp.selling_weight_reference_g,
                mfp.display_name_override, mfp.display_name_en_override,
                mfp.is_sold_out, mfp.retail_discount_allowed, mfp.wholesale_discount_allowed, cc.name AS category_name,
                (SELECT fpi.unit_cost_per_100g FROM fresh_purchase_items fpi
                 WHERE fpi.mall_fresh_product_id = mfp.id
                 ORDER BY fpi.purchase_date DESC, fpi.id DESC LIMIT 1) AS latest_unit_cost_100g,
                (SELECT fpi.unit_cost_per_piece FROM fresh_purchase_items fpi
                 WHERE fpi.mall_fresh_product_id = mfp.id
                 ORDER BY fpi.purchase_date DESC, fpi.id DESC LIMIT 1) AS latest_unit_cost_piece
         FROM mall_fresh_products mfp
         LEFT JOIN categories cc ON cc.id = mfp.category_id
         WHERE {$fc_where}
         ORDER BY mfp.display_order, mfp.id DESC"
    );
    if ($fc_types !== '') {
        $fresh_curated_stmt->bind_param($fc_types, ...$fc_params);
    }
    $fresh_curated_stmt->execute();
    $fresh_curated = $fresh_curated_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fresh_curated_stmt->close();
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
                p.name_ko, p.name_en, p.sku, p.category_id, inv.cost_price AS original_cost_price, inv.selling_price AS original_selling_price,
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
                p.name_ko, p.name_en, p.sku, p.category_id, inv.cost_price AS original_cost_price, inv.selling_price AS original_selling_price,
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

// 신선상품도 같은 "큐레이션된 상품" 테이블/컬럼 구조에 완전히 통합한다 — 별도 목록/템플릿을 만들지 않고,
// 좌측에서 선택한 카테고리에 배정된 신선상품($fresh_curated)을 일반상품과 동일한 키 이름으로 정규화해
// 같은 배열 끝에 이어붙인다. 그러면 아래 렌더링 루프가 행 종류와 무관하게 그대로 재사용된다:
//   - sku/display_name/display_name_en → code/name_ko/name_en (표시명 편집은 mall_fresh_products 원본을 직접 수정)
//   - cost_price_override/wholesale_reference_price_override → 신선상품 전용 override 컬럼, "오리지널"은 최근 매입원가
//   - selling_price_override는 개념이 없어 항상 null, "오리지널"에 현재 price_per_100g을 넣어 그대로 편집되게 함
//   - is_active/is_sold_out/할인 플래그/display_order → 신선상품 전용 컬럼을 그대로 매핑
//   - product_id/real_stock_quantity/promo_*는 신선상품에 해당 없어 항상 null(템플릿이 이미 null-safe)
if (!$selected_home_slot) {
    foreach ($fresh_curated as $fc) {
        $latest_purchase_cost = $fc['sale_type'] === 'piece' ? $fc['latest_unit_cost_piece'] : $fc['latest_unit_cost_100g'];
        $curated[] = [
            'row_type' => 'fresh',
            'id' => null,
            'fresh_id' => (int)$fc['id'],
            'product_id' => null,
            'category_id' => (int)$fc['category_id'],
            'sku' => $fc['code'],
            'name_ko' => $fc['name_ko'],
            'name_en' => $fc['name_en'],
            'display_name' => $fc['display_name_override'] ?? $fc['name_ko'],
            'display_name_en' => $fc['display_name_en_override'] ?? $fc['name_en'],
            'sale_type' => $fc['sale_type'],
            'display_order' => (int)$fc['display_order'],
            'cost_price_override' => $fc['cost_price_override'],
            'selling_price_override' => $fc['selling_price_override'],
            'selling_weight_reference_g' => $fc['selling_weight_reference_g'],
            'wholesale_reference_price_override' => $fc['wholesale_reference_price_override'],
            'original_cost_price' => $latest_purchase_cost,
            'original_selling_price' => $fc['price_per_100g'],
            'is_active' => $fc['status'] === 'active' ? 1 : 0,
            'is_sold_out' => (int)$fc['is_sold_out'],
            'retail_discount_allowed' => (int)$fc['retail_discount_allowed'],
            'wholesale_discount_allowed' => (int)$fc['wholesale_discount_allowed'],
            'real_stock_quantity' => null,
            'image_url' => $fc['image_url'],
        ];
    }
}

// 상품별 이미지 목록 (삭제/정렬 UI용) — 신선상품 행(product_id 없음)은 대상이 아니므로 건너뛴다.
$images_by_product = [];
if (!empty($curated)) {
    $img_stmt = $conn->prepare('SELECT id, image_path FROM mall_product_images WHERE product_id = ? ORDER BY sort_order');
    foreach ($curated as $c) {
        if (($c['row_type'] ?? 'general') !== 'general') { continue; }
        $img_stmt->bind_param('i', $c['product_id']);
        $img_stmt->execute();
        $images_by_product[$c['product_id']] = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $img_stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('mall_admin.nav.products'); ?> - HOME K MART <?php echo t('mall_admin.title'); ?></title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .category-item.drag-over { border-top: 2px solid #2563eb; }
        .category-item a { -webkit-user-drag: none; }
        .curated-row.drag-over { border-top: 2px solid #2563eb; }
        .curated-row.is-dragging { opacity: .45; }
        .mall-category-drop-target { outline: 2px dashed #2563eb; outline-offset: 2px; background:#eff6ff !important; color:#1d4ed8 !important; }
        .product-name-cell { width: 360px !important; min-width: 360px !important; }
        .product-name-cell .edit-display-name,
        .product-name-cell .edit-display-name-en { width: 100% !important; min-width: 280px !important; }
        .edit-display-order::-webkit-outer-spin-button,
        .edit-display-order::-webkit-inner-spin-button,
        .edit-cost-price::-webkit-outer-spin-button,
        .edit-cost-price::-webkit-inner-spin-button,
        .edit-wholesale-reference-price::-webkit-outer-spin-button,
        .edit-wholesale-reference-price::-webkit-inner-spin-button,
        .edit-selling-price::-webkit-outer-spin-button,
        .edit-selling-price::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .edit-display-order,
        .edit-cost-price,
        .edit-wholesale-reference-price,
        .edit-selling-price { -moz-appearance: textfield; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6">
        <h1 class="text-lg font-bold text-gray-800 mb-4"><i class="fas fa-box mr-2"></i><?php echo t('mall_admin.nav.products'); ?></h1>
        <div id="flash-area"></div>

        <?php
            $__base_qs = [];
            if ($search !== '') { $__base_qs['q'] = $search; }
            if ($selected_store_id !== (int)MALL_STORE_ID) { $__base_qs['store_id'] = $selected_store_id; }
        ?>
        <div class="mb-4 bg-white rounded-lg border border-gray-200 p-3 flex items-center gap-2 flex-wrap">
            <label for="store-select" class="text-xs font-bold text-gray-700"><?php echo t('mall_admin.products.reference_store'); ?></label>
            <select id="store-select" class="border border-gray-300 rounded-md px-2 py-1 text-xs">
                <?php foreach ($stores as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo $selected_store_id === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?> (ID <?php echo (int)$s['id']; ?>)</option>
                <?php endforeach; ?>
            </select>
            <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?>
            <span class="text-xs text-amber-600"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo t('mall_admin.products.store_mismatch_warning', ['id' => (int)MALL_STORE_ID]); ?></span>
            <?php endif; ?>
        </div>

        <div class="flex gap-4 items-start">
        <aside class="w-52 flex-shrink-0 bg-white rounded-lg border border-gray-200 p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-gray-700"><?php echo t('mall_admin.products.category'); ?></h2>
                <?php if ($can_manage_categories): ?>
                <button type="button" id="open-category-manage-btn" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                    <i class="fas fa-pen mr-1"></i><?php echo t('common.edit'); ?>
                </button>
                <?php endif; ?>
            </div>
            <ul class="space-y-1 mb-3">
                <li>
                    <a href="products.php<?php echo $__base_qs ? '?' . http_build_query($__base_qs) : ''; ?>"
                       class="block px-2 py-1.5 rounded text-xs font-medium <?php echo ($selected_category_id === null && !$selected_home_slot) ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>"><?php echo t('common.all'); ?></a>
                </li>
            </ul>
            <ul class="space-y-1">
                <?php foreach ($mall_categories as $cat): ?>
                <?php
                    $__cat_fresh_code = mall_fresh_category_code_for_name($cat['name']);
                    $__cat_extra_qs = $__cat_fresh_code ? ['search_tab' => 'fresh', 'fresh_cat' => $__cat_fresh_code] : [];
                ?>
                <li>
                    <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['cat_id' => $cat['id']], $__cat_extra_qs)); ?>" data-drop-category-id="<?php echo (int)$cat['id']; ?>"
                       class="block px-2 py-1.5 rounded text-xs font-medium <?php echo $selected_category_id === (int)$cat['id'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <?php echo htmlspecialchars($cat['name']); ?> <span class="text-gray-400">(<?php echo (int)$cat['product_count']; ?>)</span>
                    </a>
                </li>
                <?php if ($selected_category_id === (int)$cat['id'] && !empty($sub_categories)): ?>
                <li class="pl-3 ml-2 border-l border-gray-200 mt-1">
                    <ul class="space-y-1">
                        <?php foreach ($sub_categories as $sub): ?>
                        <li>
                            <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['cat_id' => $selected_category_id, 'sub_id' => $sub['id']], $__cat_extra_qs)); ?>" data-drop-category-id="<?php echo (int)$sub['id']; ?>"
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

            <h2 class="text-sm font-bold text-gray-700 mt-4 mb-3"><?php echo t('mall_admin.products.home_exposure'); ?></h2>
            <ul class="space-y-1">
                <?php foreach ($home_slot_labels as $__slot_key => $__slot_label): ?>
                <li>
                    <a href="products.php?<?php echo http_build_query(array_merge($__base_qs, ['home_slot' => $__slot_key])); ?>"
                       data-drop-home-slot="<?php echo htmlspecialchars($__slot_key); ?>"
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
                    <?php echo t('mall_admin.products.slot_display_products', ['slot' => htmlspecialchars($home_slot_labels[$selected_home_slot]), 'count' => count($curated)]); ?>
                <?php else: ?>
                    <?php echo t('mall_admin.products.curated_products', ['count' => count($curated)]); ?>
                <?php endif; ?>
            </h2>
            <?php if ($selected_home_slot): ?>
            <p class="text-[11px] text-gray-400 mb-3"><i class="fas fa-circle-info mr-1"></i><?php echo t('mall_admin.products.slot_hint'); ?></p>
            <?php endif; ?>
            <?php $__is_today_deals = $selected_home_slot === 'today_deals'; $__col_count = $__is_today_deals ? 11 : 10; ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.discount_rules.sort_order'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.product_name'); ?></th>
                        <th class="px-1 py-2 text-right w-16 leading-tight"><?php echo t('mall_admin.products.cost_price'); ?></th>
                        <th class="px-1 py-2 text-right w-16 leading-tight"><?php echo t('mall_admin.products.wholesale_reference_price'); ?></th>
                        <th class="px-1 py-2 text-right w-16 leading-tight"><?php echo t('mall_admin.products.reference_selling_price'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.home_layout.visible'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.stock_sold_out'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.discount'); ?></th>
                        <?php if ($__is_today_deals): ?><th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.promo'); ?></th><?php endif; ?>
                        <th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.image'); ?></th>
                        <th class="px-3 py-2 text-left"><?php echo t('common.save'); ?></th>
                    </tr>
                </thead>
                <tbody id="curated-products-body" data-order-offset="<?php echo isset($curated_offset) ? (int)$curated_offset : 0; ?>">
                <?php if (empty($curated)): ?>
                    <tr><td colspan="<?php echo $__col_count; ?>" class="px-3 py-4 text-center text-gray-400"><?php echo $selected_home_slot ? t('mall_admin.products.slot_empty') : t('mall_admin.products.no_products'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($curated as $c): ?>
                    <?php
                        $__row_type = $c['row_type'] ?? 'general';
                        // 신선상품 행은 mall_products 큐레이션/inventory 개념이 없어, $curated 배열에 담을 때
                        // 이미 원가/기준도매가/판매가/재고/할인 등을 일반상품과 같은 키 이름으로 정규화해뒀다
                        // (원가/기준도매가/판매가 모두 mall_fresh_products의 override 컬럼을 "값"으로,
                        //  최근 매입원가/price_per_100g(admin/fresh_products.php 마스터 값)을 "오리지널"로
                        //  취급 — 판매가도 원가/도매가와 동일하게 override 컬럼이 있어 원본을 건드리지 않고
                        //  큐레이션에서 자유롭게 덮어쓸 수 있다). 그래서 아래 계산/렌더링 로직은 행 종류와
                        // 무관하게 동일하게 재사용된다.
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

                        // 신선상품 행은 mall_products 큐레이션 개념이 없어(항상 배정된 상태로만 이 목록에 들어옴)
                        // id 유무와 무관하게 항상 "큐레이션됨" 취급한다.
                        $__is_curated = $c['id'] !== null || $__row_type === 'fresh';
                    ?>
                    <?php if (!$__is_curated): ?>
                    <tr class="curated-row border-t border-gray-100">
                        <td class="px-3 py-2 text-gray-300">—</td>
                        <td class="px-3 py-2">
                            <div class="text-gray-400 barcode-copy" data-barcode="<?php echo htmlspecialchars($c['sku']); ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.copy_barcode_title')); ?>" style="cursor:pointer;"><?php echo htmlspecialchars($c['sku']); ?></div>
                            <?php echo htmlspecialchars($c['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2 text-amber-600" colspan="<?php echo $__col_count - 3; ?>">
                            <i class="fas fa-triangle-exclamation mr-1"></i><?php echo t('mall_admin.products.not_curated_yet', ['id' => (int)MALL_STORE_ID]); ?>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <button class="curate-home-slot-product-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>"><?php echo t('mall_admin.products.curate_now'); ?></button>
                            <button class="remove-from-home-slot-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>"><?php echo t('mall_admin.products.remove_from_slot'); ?></button>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr class="curated-row border-t border-gray-100"
                        <?php if ($__row_type === 'fresh'): ?>
                        data-row-type="fresh" data-fresh-product-id="<?php echo (int)$c['fresh_id']; ?>" data-category-id="<?php echo (int)($c['category_id'] ?? 0); ?>"
                        data-original-cost-price="<?php echo $original_cost_price !== null ? $original_cost_price : ''; ?>"
                        data-original-selling-price="<?php echo $original_selling_price !== null ? $original_selling_price : ''; ?>"
                        data-sale-type="<?php echo htmlspecialchars($c['sale_type'] ?? ''); ?>"
                        data-selling-weight-reference-g="<?php echo $c['selling_weight_reference_g'] !== null ? (int)$c['selling_weight_reference_g'] : ''; ?>"
                        <?php else: ?>
                        data-mall-product-id="<?php echo (int)$c['id']; ?>" data-product-id="<?php echo (int)$c['product_id']; ?>" data-category-id="<?php echo (int)($c['category_id'] ?? 0); ?>"<?php if ($selected_home_slot): ?> data-home-slot="<?php echo htmlspecialchars($selected_home_slot); ?>"<?php endif; ?>
                        <?php endif; ?>>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <i class="mall-product-drag-handle fas fa-grip-vertical text-gray-300 cursor-grab" draggable="true" title="카테고리 또는 홈 노출 목록으로 끌어서 이동하거나 순서를 변경하세요"></i>
                            <input type="number" min="0" max="999" class="edit-display-order border border-gray-300 rounded px-2 py-1 w-10" value="<?php echo (int)$c['display_order']; ?>" <?php echo $selected_home_slot ? 'title="' . htmlspecialchars(t('mall_admin.products.slot_order_title')) . '"' : ''; ?>>
                        </td>
                        <td class="product-name-cell px-3 py-2 w-64 min-w-[16rem]">
                            <div class="text-gray-400 barcode-copy" data-barcode="<?php echo htmlspecialchars($c['sku']); ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.copy_barcode_title')); ?>" style="cursor:pointer;"><span class="text-gray-400 text-[11px]">원본:</span> <?php echo htmlspecialchars($c['sku']); ?></div>
                            <div><span class="text-gray-400 text-[11px]">원본:</span> <?php echo htmlspecialchars($c['name_ko']); ?><?php if (trim((string)($c['name_en'] ?? '')) !== ''): ?> (<?php echo htmlspecialchars($c['name_en']); ?>)<?php endif; ?></div>
                            <?php if ($__row_type === 'fresh'): ?><span class="ml-1 px-1.5 py-0.5 rounded text-[10px] bg-emerald-100 text-emerald-700"><?php echo t('mall_fresh_products.fresh_product_label'); ?></span><?php endif; ?>
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-1"><span class="text-gray-400 text-[11px] flex-shrink-0">한글:</span><input type="text" class="edit-display-name border border-gray-300 rounded px-2 py-1 flex-1 min-w-0 w-full" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.korean')); ?>" value="<?php echo htmlspecialchars($c['display_name'] ?? ''); ?>"></div>
                                <div class="flex items-center gap-1"><span class="text-gray-400 text-[11px] flex-shrink-0">ENG:</span><input type="text" class="edit-display-name-en border border-gray-300 rounded px-2 py-1 flex-1 min-w-0 w-full" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.english')); ?>" value="<?php echo htmlspecialchars($c['display_name_en'] ?? ''); ?>"></div>
                            </div>
                        </td>
                        <td class="px-1 py-2 text-right w-16">
                            <input type="number" step="0.01" min="0" class="edit-cost-price border border-gray-300 rounded px-1 py-1 w-16 text-right font-mono" value="<?php echo $effective_cost_price !== null ? $effective_cost_price : ''; ?>">
                            <?php if ($cost_price_override !== null): ?>
                            <div class="text-gray-400 mt-0.5"><?php echo t('mall_admin.products.original'); ?>: <?php echo $original_cost_price !== null ? number_format($original_cost_price, 2) : '-'; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-1 py-2 text-right w-16">
                            <input type="number" step="0.01" min="0" class="edit-wholesale-reference-price border border-gray-300 rounded px-1 py-1 w-16 text-right font-mono" value="<?php echo $effective_wholesale_reference_price !== null ? $effective_wholesale_reference_price : ''; ?>">
                            <?php if ($wholesale_reference_override !== null): ?>
                            <div class="text-gray-400 mt-0.5"><?php echo t('mall_admin.products.original'); ?>: <?php echo $original_wholesale_reference_price !== null ? number_format($original_wholesale_reference_price, 2) : '-'; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-1 py-2 text-right w-16">
                            <input type="number" step="0.01" min="0" class="edit-selling-price border border-gray-300 rounded px-1 py-1 w-16 text-right font-mono" value="<?php echo $effective_selling_price !== null ? $effective_selling_price : ''; ?>">
                            <div class="selling-price-original-hint text-gray-400 mt-0.5" style="<?php echo $selling_price_override !== null ? '' : 'display:none;'; ?>"><?php echo t('mall_admin.products.original'); ?>: <?php echo $original_selling_price !== null ? number_format($original_selling_price, 2) : '-'; ?></div>
                            <?php if ($__row_type === 'fresh'): ?>
                            <div class="text-gray-400 mt-0.5"><?php echo $c['sale_type'] === 'piece' ? htmlspecialchars(t('mall_fresh_products.price_label_piece')) : htmlspecialchars(t('mall_fresh_products.price_label_weight')); ?></div>
                            <button type="button" class="open-price-calc-btn mt-1 px-2 py-1 bg-blue-600 text-white rounded text-xs whitespace-nowrap" data-product-name="<?php echo htmlspecialchars($c['name_ko']); ?>"><?php echo t('mall_admin.products.price_calc_title'); ?></button>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2"><input type="checkbox" class="edit-is-active" <?php echo $c['is_active'] ? 'checked' : ''; ?>></td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <div class="text-gray-500 mb-1"><?php echo t('mall_admin.products.real_stock'); ?>: <?php echo $c['real_stock_quantity'] !== null ? t('mall_admin.dashboard.count_unit', ['count' => (int)$c['real_stock_quantity']]) : '-'; ?></div>
                            <label class="flex items-center gap-1 text-[11px] text-red-600 font-semibold" title="<?php echo htmlspecialchars(t('mall_admin.products.force_sold_out_title')); ?>">
                                <input type="checkbox" class="edit-is-sold-out" <?php echo $c['is_sold_out'] ? 'checked' : ''; ?>><?php echo t('mall_admin.orders.mark_sold_out'); ?>
                            </label>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <label class="flex items-center gap-1 text-[11px] text-gray-600" title="<?php echo htmlspecialchars(t('mall_admin.products.retail_discount_title')); ?>">
                                <input type="checkbox" class="edit-retail-discount-allowed" <?php echo $c['retail_discount_allowed'] ? 'checked' : ''; ?>><?php echo t('mall_admin.members.tier_general'); ?>
                            </label>
                            <label class="flex items-center gap-1 text-[11px] text-gray-600" title="<?php echo htmlspecialchars(t('mall_admin.products.wholesale_discount_title')); ?>">
                                <input type="checkbox" class="edit-wholesale-discount-allowed" <?php echo $c['wholesale_discount_allowed'] ? 'checked' : ''; ?>><?php echo t('mall_admin.orders.channel_wholesale'); ?>
                            </label>
                        </td>
                        <?php if ($__is_today_deals):
                            $__p_type = $c['promo_type'] ?? 'none';
                            $__p_value = $c['promo_value'] ?? '';
                        ?>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <div class="flex flex-col gap-1">
                                <label class="inline-flex items-center gap-1"><input type="radio" class="promo-type-select" name="promo_type_<?php echo (int)$c['product_id']; ?>" value="none" data-product-id="<?php echo (int)$c['product_id']; ?>" <?php echo $__p_type === 'none' ? 'checked' : ''; ?>><?php echo t('common.none'); ?></label>
                                <label class="inline-flex items-center gap-1"><input type="radio" class="promo-type-select" name="promo_type_<?php echo (int)$c['product_id']; ?>" value="1plus1" data-product-id="<?php echo (int)$c['product_id']; ?>" <?php echo $__p_type === '1plus1' ? 'checked' : ''; ?>>1+1</label>
                                <label class="inline-flex items-center gap-1"><input type="radio" class="promo-type-select" name="promo_type_<?php echo (int)$c['product_id']; ?>" value="percent" data-product-id="<?php echo (int)$c['product_id']; ?>" <?php echo $__p_type === 'percent' ? 'checked' : ''; ?>><?php echo t('mall_admin.products.promo_percent'); ?></label>
                                <label class="inline-flex items-center gap-1"><input type="radio" class="promo-type-select" name="promo_type_<?php echo (int)$c['product_id']; ?>" value="cost_sale" data-product-id="<?php echo (int)$c['product_id']; ?>" <?php echo $__p_type === 'cost_sale' ? 'checked' : ''; ?>><?php echo t('mall_admin.products.promo_cost_sale'); ?></label>
                            </div>
                            <div class="promo-value-wrap mt-1" style="<?php echo $__p_type === 'percent' ? '' : 'display:none;'; ?>">
                                <input type="number" min="1" max="99" step="1" class="promo-value-input border border-gray-300 rounded px-1.5 py-1 text-xs w-14" value="<?php echo htmlspecialchars((string)$__p_value); ?>" placeholder="%">%
                            </div>
                        </td>
                        <?php endif; ?>
                        <td class="px-3 py-2">
                            <?php if ($__row_type === 'fresh'): ?>
                            <div class="image-thumb-list flex flex-wrap gap-1">
                                <?php if (!empty($c['image_url'])): ?>
                                <div class="image-thumb" style="position:relative;">
                                    <img src="<?php echo htmlspecialchars($c['image_url']); ?>" class="image-preview-trigger" data-full-src="<?php echo htmlspecialchars($c['image_url']); ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.click_to_enlarge')); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:0.3rem;border:1px solid #e5e7eb;cursor:zoom-in;">
                                </div>
                                <?php else: ?>
                                <span class="text-gray-300 text-[11px]"><?php echo t('common.none'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="image-thumb-list flex flex-wrap gap-1">
                                <?php foreach (($images_by_product[$c['product_id']] ?? []) as $img): ?>
                                <div class="image-thumb" data-image-id="<?php echo (int)$img['id']; ?>" style="position:relative;">
                                    <img src="/mall/<?php echo htmlspecialchars($img['image_path']); ?>" class="image-preview-trigger" data-full-src="/mall/<?php echo htmlspecialchars($img['image_path']); ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.click_to_enlarge')); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:0.3rem;border:1px solid #e5e7eb;cursor:zoom-in;">
                                    <div style="display:flex;gap:2px;margin-top:2px;">
                                        <button class="img-move-btn" data-dir="up" title="<?php echo htmlspecialchars(t('mall_admin.products.move_up')); ?>" style="font-size:0.6rem;">▲</button>
                                        <button class="img-move-btn" data-dir="down" title="<?php echo htmlspecialchars(t('mall_admin.products.move_down')); ?>" style="font-size:0.6rem;">▼</button>
                                        <button class="img-delete-btn" title="<?php echo htmlspecialchars(t('common.delete')); ?>" style="font-size:0.6rem;color:#dc2626;">×</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($images_by_product[$c['product_id']] ?? [])): ?>
                                <span class="text-gray-300 text-[11px]"><?php echo t('common.none'); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            <button class="save-curated-btn px-2 py-1 bg-blue-600 text-white rounded text-xs"><?php echo t('common.save'); ?></button>
                            <?php if ($__row_type === 'fresh'): ?>
                            <button class="unassign-fresh-category-btn px-2 py-1 bg-red-600 text-white rounded text-xs" data-fresh-product-id="<?php echo (int)$c['fresh_id']; ?>" data-product-name="<?php echo htmlspecialchars($c['name_ko']); ?>"><?php echo t('mall_admin.products.unassign_category'); ?></button>
                            <?php elseif ($selected_home_slot): ?>
                            <button class="remove-from-home-slot-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$c['product_id']; ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.remove_from_slot_title')); ?>"><?php echo t('mall_admin.products.remove_from_slot'); ?></button>
                            <?php else: ?>
                            <button class="delete-curated-btn px-2 py-1 bg-red-600 text-white rounded text-xs" data-product-name="<?php echo htmlspecialchars($c['name_ko']); ?>"><?php echo t('common.delete'); ?></button>
                            <?php endif; ?>
                            <div class="mt-1 flex items-center gap-1.5">
                                <label class="text-blue-600 cursor-pointer text-[11px]">
                                    <?php echo t('mall_admin.products.add_photo'); ?><input type="file" class="<?php echo $__row_type === 'fresh' ? 'fresh-image-upload-input' : 'image-upload-input'; ?> hidden" data-<?php echo $__row_type === 'fresh' ? 'fresh-product-id' : 'product-id'; ?>="<?php echo $__row_type === 'fresh' ? (int)$c['fresh_id'] : (int)$c['product_id']; ?>" accept="image/jpeg,image/png,image/webp,image/avif,.avif">
                                </label>
                                <a href="https://www.google.com/search?tbm=isch&q=<?php echo urlencode(trim($c['name_ko'] . ' png')); ?>" target="_blank" rel="noopener noreferrer" class="text-gray-500 hover:text-blue-600 text-[11px]"><?php echo t('mall_admin.products.search_photo'); ?></a>
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
                <span><?php echo t('mall_admin.products.pagination_range', ['total' => (int)$curated_total, 'from' => (int)$curated_offset + 1, 'to' => min($curated_offset + $curated_per_page, $curated_total)]); ?></span>
                <div class="flex items-center gap-1">
                    <a href="?<?php echo http_build_query(array_merge($__page_qs, ['page' => max(1, $curated_page - 1)])); ?>"
                       class="px-2 py-1 rounded border border-gray-300 <?php echo $curated_page <= 1 ? 'pointer-events-none text-gray-300' : 'hover:bg-gray-100'; ?>"><?php echo t('common.previous'); ?></a>
                    <span class="px-2"><?php echo t('mall_admin.products.page_of', ['page' => (int)$curated_page, 'total' => (int)$curated_total_pages]); ?></span>
                    <a href="?<?php echo http_build_query(array_merge($__page_qs, ['page' => min($curated_total_pages, $curated_page + 1)])); ?>"
                       class="px-2 py-1 rounded border border-gray-300 <?php echo $curated_page >= $curated_total_pages ? 'pointer-events-none text-gray-300' : 'hover:bg-gray-100'; ?>"><?php echo t('common.next'); ?></a>
                </div>
            </div>
            <?php endif; ?>
        </section>
        </div>

        <div class="w-96 flex-shrink-0">
        <section class="bg-white rounded-lg border border-gray-200 p-4">
            <?php if ($selected_home_slot): ?>
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.products.add_to_slot', ['slot' => htmlspecialchars($home_slot_labels[$selected_home_slot])]); ?> <span class="text-gray-400 font-normal">(<?php echo t('mall_admin.products.all_products_scope'); ?>)</span></h2>
            <form method="get" class="flex gap-1 mb-3">
                <input type="hidden" name="home_slot" value="<?php echo htmlspecialchars($selected_home_slot); ?>">
                <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?><input type="hidden" name="store_id" value="<?php echo (int)$selected_store_id; ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.search_placeholder_short')); ?>"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs w-full">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md"><?php echo t('common.search'); ?></button>
            </form>
            <?php if ($search !== ''): ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.product_name'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.actions'); ?></th></tr>
                </thead>
                <tbody>
                <?php if (empty($home_slot_search_results)): ?>
                    <tr><td colspan="2" class="px-3 py-4 text-center text-gray-400"><?php echo t('mall_admin.products.no_search_results'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($home_slot_search_results as $p): ?>
                    <?php $__already_in_slot = in_array((int)$p['product_id'], $home_slot_membership[$selected_home_slot], true); ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <div class="text-gray-400"><?php echo htmlspecialchars($p['sku']); ?></div>
                            <?php echo htmlspecialchars($p['display_name'] ?: $p['name_ko']); ?>
                            <?php if (!$p['mall_product_id']): ?><span class="text-amber-600" title="<?php echo htmlspecialchars(t('mall_admin.products.not_curated_at_store_title')); ?>"><?php echo t('mall_admin.products.not_curated'); ?></span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <?php if ($__already_in_slot): ?>
                                <span class="text-gray-400"><?php echo t('mall_admin.products.already_added'); ?></span>
                            <?php else: ?>
                                <button class="add-to-home-slot-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-slot-key="<?php echo htmlspecialchars($selected_home_slot); ?>" data-product-id="<?php echo (int)$p['product_id']; ?>"><?php echo t('common.add'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php else: ?>
            <?php
                $__tab_qs = $__base_qs;
                if ($selected_category_id) { $__tab_qs['cat_id'] = $selected_category_id; }
                if ($selected_sub_id) { $__tab_qs['sub_id'] = $selected_sub_id; }
            ?>
            <div class="flex gap-1 mb-3 border-b border-gray-200">
                <a href="?<?php echo http_build_query(array_merge($__tab_qs, ['search_tab' => 'general'])); ?>"
                   class="px-3 py-2 text-xs font-semibold border-b-2 <?php echo $search_tab === 'general' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo t('mall_admin.products.tab_general'); ?></a>
                <a href="?<?php echo http_build_query(array_merge($__tab_qs, ['search_tab' => 'fresh'])); ?>"
                   class="px-3 py-2 text-xs font-semibold border-b-2 <?php echo $search_tab === 'fresh' ? 'border-blue-600 text-blue-700' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo t('mall_admin.products.tab_fresh'); ?></a>
            </div>
            <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo t('mall_admin.products.search_products'); ?> <span class="text-gray-400 font-normal">(<?php echo t('mall_admin.products.all_products_scope'); ?>)</span></h2>
            <?php if (!$add_target_category_id): ?>
            <div class="mb-3 px-3 py-2 text-xs rounded border bg-amber-50 text-amber-700 border-amber-200">
                <i class="fas fa-circle-info mr-1"></i><?php echo t('mall_admin.products.category_required_hint'); ?>
            </div>
            <?php endif; ?>
            <?php if ($search_tab === 'fresh'): ?>
            <?php
                $__fresh_cat_qs = $__tab_qs;
                $__fresh_cat_qs['search_tab'] = 'fresh';
                if ($search !== '') { $__fresh_cat_qs['q'] = $search; }
            ?>
            <div class="flex gap-1 mb-3 flex-wrap">
                <?php foreach (fresh_category_options() as $__fc_code => $__fc_label): ?>
                <a href="?<?php echo http_build_query(array_merge($__fresh_cat_qs, ['fresh_cat' => $__fc_code])); ?>"
                   class="px-3 py-1.5 rounded text-xs font-semibold border <?php echo $selected_fresh_cat === $__fc_code ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-100'; ?>"><?php echo htmlspecialchars($__fc_label); ?></a>
                <?php endforeach; ?>
                <?php if ($selected_fresh_cat): ?>
                <a href="?<?php echo http_build_query(array_diff_key($__fresh_cat_qs, ['fresh_cat' => true])); ?>"
                   class="px-3 py-1.5 rounded text-xs font-semibold border bg-white text-gray-400 border-gray-200 hover:bg-gray-100"><?php echo t('common.all'); ?></a>
                <?php endif; ?>
            </div>
            <form method="get" class="flex gap-1 mb-3">
                <input type="hidden" name="search_tab" value="fresh">
                <?php if ($selected_category_id): ?><input type="hidden" name="cat_id" value="<?php echo (int)$selected_category_id; ?>"><?php endif; ?>
                <?php if ($selected_sub_id): ?><input type="hidden" name="sub_id" value="<?php echo (int)$selected_sub_id; ?>"><?php endif; ?>
                <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?><input type="hidden" name="store_id" value="<?php echo (int)$selected_store_id; ?>"><?php endif; ?>
                <?php if ($selected_fresh_cat): ?><input type="hidden" name="fresh_cat" value="<?php echo htmlspecialchars($selected_fresh_cat); ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars(t('mall_fresh_products.search_placeholder')); ?>"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs w-full">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md"><?php echo t('common.search'); ?></button>
            </form>
            <?php if ($search !== '' || $selected_fresh_cat): ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('product.name'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_fresh_products.category_label'); ?></th><th class="px-3 py-2 text-right"><?php echo t('mall_admin.products.fresh_unit_price'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.actions'); ?></th></tr>
                </thead>
                <tbody>
                <?php if (empty($fresh_search_results)): ?>
                    <tr><td colspan="4" class="px-3 py-4 text-center text-gray-400"><?php echo t('mall_fresh_products.no_search_results'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($fresh_search_results as $fp): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <div class="text-gray-400 font-mono text-[11px]"><?php echo htmlspecialchars($fp['code']); ?></div>
                            <?php echo htmlspecialchars($fp['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2"><?php echo htmlspecialchars(fresh_category_label($fp['fresh_category'])); ?> · <?php echo $fp['sale_type'] === 'piece' ? htmlspecialchars(t('mall_fresh_products.sale_type_piece')) : htmlspecialchars(t('mall_fresh_products.sale_type_weight')); ?></td>
                        <td class="px-3 py-2 text-right"><?php echo number_format((float)$fp['price_per_100g'], 2); ?><?php echo $fp['sale_type'] === 'weight' ? ' / 100g' : ''; ?></td>
                        <td class="px-3 py-2">
                            <?php if (!$add_target_category_id): ?>
                                <span class="text-gray-400" title="<?php echo htmlspecialchars(t('mall_admin.products.select_category_first')); ?>"><?php echo t('mall_admin.products.category_selection_required'); ?></span>
                            <?php elseif ((int)$fp['category_id'] === (int)$add_target_category_id): ?>
                                <span class="text-gray-400"><?php echo t('mall_admin.products.already_registered'); ?></span>
                            <?php else: ?>
                                <button class="fresh-assign-btn px-2 py-1 <?php echo $fp['category_id'] ? 'bg-amber-500' : 'bg-blue-600'; ?> text-white rounded text-xs" data-fresh-product-id="<?php echo (int)$fp['id']; ?>" data-category-id="<?php echo (int)$add_target_category_id; ?>"><?php echo $fp['category_id'] ? t('mall_admin.products.move_register') : t('mall_admin.products.add_to_mall'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            <?php else: ?>
            <form method="get" class="flex gap-1 mb-3">
                <?php if ($selected_category_id): ?><input type="hidden" name="cat_id" value="<?php echo (int)$selected_category_id; ?>"><?php endif; ?>
                <?php if ($selected_sub_id): ?><input type="hidden" name="sub_id" value="<?php echo (int)$selected_sub_id; ?>"><?php endif; ?>
                <?php if ($selected_store_id !== (int)MALL_STORE_ID): ?><input type="hidden" name="store_id" value="<?php echo (int)$selected_store_id; ?>"><?php endif; ?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.search_placeholder_long')); ?>"
                       class="border border-gray-300 rounded-md px-2 py-1 text-xs w-full">
                <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md"><?php echo t('common.search'); ?></button>
            </form>
            <?php if ($search !== ''): ?>
            <table class="min-w-full text-xs">
                <thead class="bg-gray-100 text-gray-600">
                    <tr><th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.product_name'); ?></th><th class="px-3 py-2 text-left"><?php echo t('mall_admin.products.recent_sales'); ?></th><th class="px-3 py-2 text-left"><?php echo t('common.actions'); ?></th></tr>
                </thead>
                <tbody>
                <?php if (empty($search_results)): ?>
                    <tr><td colspan="3" class="px-3 py-4 text-center text-gray-400"><?php echo t('mall_admin.products.no_search_results'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($search_results as $p): ?>
                    <tr class="border-t border-gray-100">
                        <td class="px-3 py-2">
                            <div class="text-gray-400"><?php echo htmlspecialchars($p['sku']); ?></div>
                            <?php echo htmlspecialchars($p['name_ko']); ?>
                        </td>
                        <td class="px-3 py-2"><?php echo t('mall_admin.dashboard.count_unit', ['count' => number_format((float)$p['recent_sales_qty'])]); ?></td>
                        <td class="px-3 py-2">
                            <?php if ($p['mall_product_id']): ?>
                                <?php if ($add_target_category_id && (int)$p['category_id'] !== $add_target_category_id): ?>
                                <button class="move-btn px-2 py-1 bg-amber-500 text-white rounded text-xs" data-product-id="<?php echo (int)$p['id']; ?>" data-category-id="<?php echo (int)$add_target_category_id; ?>" title="<?php echo htmlspecialchars(t('mall_admin.products.move_to_category_title')); ?>"><?php echo t('mall_admin.products.move_register'); ?></button>
                                <?php else: ?>
                                <span class="text-gray-400"><?php echo t('mall_admin.products.already_registered'); ?></span>
                                <?php endif; ?>
                            <?php elseif (!$add_target_category_id): ?>
                                <span class="text-gray-400" title="<?php echo htmlspecialchars(t('mall_admin.products.select_category_first')); ?>"><?php echo t('mall_admin.products.category_selection_required'); ?></span>
                            <?php else: ?>
                                <button class="add-btn px-2 py-1 bg-blue-600 text-white rounded text-xs" data-product-id="<?php echo (int)$p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name_ko']); ?>" data-name-en="<?php echo htmlspecialchars($p['name_en'] ?? ''); ?>" data-store-id="<?php echo (int)$selected_store_id; ?>" data-category-id="<?php echo (int)$add_target_category_id; ?>"><?php echo t('mall_admin.products.add_to_mall'); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
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
                <h3 class="text-sm font-bold text-gray-800"><i class="fas fa-sitemap mr-1.5"></i><?php echo t('mall_admin.products.manage_categories'); ?></h3>
                <button type="button" id="close-category-manage-btn" class="text-gray-400 hover:text-gray-700"><i class="fas fa-xmark"></i></button>
            </div>
            <div class="p-4 overflow-y-auto flex-1">
                <ul id="modal-category-list" class="space-y-1">
                    <?php foreach ($mall_categories as $cat): ?>
                    <li class="category-item border border-gray-100 rounded-md" data-category-id="<?php echo (int)$cat['id']; ?>" draggable="true">
                        <div class="flex items-center gap-1 px-2 py-1.5">
                            <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="<?php echo htmlspecialchars(t('mall_admin.products.drag_to_reorder')); ?>"></i>
                            <button type="button" class="toggle-sub-btn text-gray-400 hover:text-gray-700 px-1" title="<?php echo htmlspecialchars(t('mall_admin.products.toggle_subcategory_title')); ?>">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" data-category-id="<?php echo (int)$cat['id']; ?>" value="<?php echo htmlspecialchars($cat['name']); ?>">
                            <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="<?php echo htmlspecialchars($cat['name_en'] ?? ''); ?>" placeholder="EN">
                            <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$cat['product_count']; ?>)</span>
                            <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$cat['id']; ?>" data-category-name="<?php echo htmlspecialchars($cat['name']); ?>" title="<?php echo htmlspecialchars(t('common.delete')); ?>"><i class="fas fa-xmark"></i></button>
                        </div>
                        <div class="subcategory-panel hidden pl-6 pr-2 pb-2">
                            <ul class="subcategory-list space-y-1 mb-2" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <?php foreach (($sub_categories_by_parent[$cat['id']] ?? []) as $sub): ?>
                                <li class="category-item flex items-center gap-1" data-category-id="<?php echo (int)$sub['id']; ?>" draggable="true">
                                    <i class="fas fa-grip-vertical text-gray-300 cursor-grab" title="<?php echo htmlspecialchars(t('mall_admin.products.drag_to_reorder')); ?>"></i>
                                    <input type="text" class="edit-category-name border border-gray-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" data-category-id="<?php echo (int)$sub['id']; ?>" value="<?php echo htmlspecialchars($sub['name']); ?>">
                                    <input type="text" class="edit-category-name-en border border-gray-300 rounded px-1.5 py-1 text-xs w-14 flex-shrink-0" value="<?php echo htmlspecialchars($sub['name_en'] ?? ''); ?>" placeholder="EN">
                                    <span class="text-gray-400 text-[10px] flex-shrink-0">(<?php echo (int)$sub['product_count']; ?>)</span>
                                    <button type="button" class="delete-category-btn text-gray-300 hover:text-red-500 px-1" data-category-id="<?php echo (int)$sub['id']; ?>" data-category-name="<?php echo htmlspecialchars($sub['name']); ?>" title="<?php echo htmlspecialchars(t('common.delete')); ?>"><i class="fas fa-xmark"></i></button>
                                </li>
                                <?php endforeach; ?>
                                <?php if (empty($sub_categories_by_parent[$cat['id']] ?? [])): ?>
                                <li class="text-[11px] text-gray-300"><?php echo t('mall_admin.products.no_subcategories'); ?></li>
                                <?php endif; ?>
                            </ul>
                            <form class="add-subcategory-form flex gap-1" data-parent-id="<?php echo (int)$cat['id']; ?>">
                                <input type="text" name="name" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.subcategory_name_placeholder')); ?>" required class="border border-gray-300 rounded px-2 py-1 text-xs flex-1 min-w-0">
                                <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded px-2 py-1 text-xs w-16">
                                <button type="submit" class="px-2 py-1 text-xs font-semibold bg-gray-500 text-white rounded flex-shrink-0"><?php echo t('common.add'); ?></button>
                            </form>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="p-4 border-t border-gray-100 space-y-2">
                <p class="text-[11px] text-gray-400"><?php echo t('mall_admin.products.new_badge_hint'); ?></p>
                <button type="button" id="apply-category-edits-btn" class="w-full px-3 py-1.5 text-xs font-semibold bg-blue-600 text-white rounded-md">
                    <i class="fas fa-check mr-1"></i><?php echo t('mall_admin.products.apply_all_changes'); ?>
                </button>
                <form id="add-category-form" class="flex gap-1">
                    <input type="text" name="name" placeholder="<?php echo htmlspecialchars(t('mall_admin.products.new_category_name_placeholder')); ?>" required class="border border-gray-300 rounded-md px-2 py-1 text-xs flex-1 min-w-0">
                    <input type="text" name="name_en" placeholder="EN" class="border border-gray-300 rounded-md px-2 py-1 text-xs w-20">
                    <button type="submit" class="px-3 py-1 text-xs font-semibold bg-gray-700 text-white rounded-md flex-shrink-0"><?php echo t('common.add'); ?></button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="price-calc-modal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden z-50 items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="price-calc-title">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between p-4 border-b border-gray-100">
                <h3 id="price-calc-title" class="text-sm font-bold text-gray-800"><?php echo t('mall_admin.products.price_calc_title'); ?> — <span id="price-calc-product-name"></span></h3>
                <button type="button" id="price-calc-close" class="text-gray-400 hover:text-gray-700" aria-label="<?php echo htmlspecialchars(t('mall_admin.products.price_calc_close')); ?>"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="p-4 overflow-y-auto space-y-4">
                <div class="flex items-center gap-2">
                    <label id="price-calc-quantity-label" for="price-calc-quantity" class="text-sm font-semibold"></label>
                    <button type="button" id="price-calc-minus" class="px-3 py-1 border border-gray-300 rounded" aria-label="<?php echo htmlspecialchars(t('mall_admin.products.price_calc_decrease')); ?>">−</button>
                    <input id="price-calc-quantity" type="number" min="1" step="1" value="1" class="border border-gray-300 rounded px-2 py-1 w-24 text-right">
                    <button type="button" id="price-calc-plus" class="px-3 py-1 border border-gray-300 rounded" aria-label="<?php echo htmlspecialchars(t('mall_admin.products.price_calc_increase')); ?>">+</button>
                </div>
                <?php foreach (['cost' => 'cost_price', 'wholesale' => 'wholesale_reference_price', 'selling' => 'reference_selling_price'] as $price_calc_key => $price_calc_label): ?>
                <fieldset class="border border-gray-200 rounded p-3">
                    <legend class="text-sm font-semibold px-1"><?php echo t('mall_admin.products.' . $price_calc_label); ?></legend>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-xs text-gray-600"><span class="price-calc-unit-label"></span>
                            <input id="price-calc-<?php echo $price_calc_key; ?>-unit" type="number" min="0" step="0.01" disabled class="mt-1 w-full border border-gray-300 rounded px-2 py-1 text-right font-mono bg-gray-100 text-gray-500">
                        </label>
                        <label class="text-xs text-gray-600"><span class="price-calc-total-label"></span>
                            <input id="price-calc-<?php echo $price_calc_key; ?>-total" type="number" min="0" step="0.01" class="mt-1 w-full border border-gray-300 rounded px-2 py-1 text-right font-mono disabled:bg-gray-100" <?php echo $price_calc_key === 'cost' ? 'aria-describedby="price-calc-cost-unavailable"' : ''; ?>>
                        </label>
                    </div>
                    <?php if ($price_calc_key === 'wholesale'): ?>
                    <button type="button" id="price-calc-wholesale-auto" class="mt-2 px-2 py-1 bg-blue-600 text-white rounded text-xs"><?php echo t('mall_admin.products.price_calc_wholesale_auto'); ?></button>
                    <?php endif; ?>
                </fieldset>
                <?php endforeach; ?>
                <p id="price-calc-cost-unavailable" class="hidden text-xs text-amber-600"><?php echo t('mall_admin.products.price_calc_cost_unavailable'); ?></p>
                <p class="text-xs text-gray-500"><?php echo t('mall_admin.products.price_calc_rounding_hint'); ?></p>
                <p class="text-xs text-gray-500"><?php echo t('mall_admin.products.price_calc_save_hint'); ?></p>
            </div>
            <div class="flex justify-end gap-2 p-4 border-t border-gray-100">
                <button type="button" id="price-calc-cancel" class="px-3 py-2 border border-gray-300 rounded text-sm"><?php echo t('mall_admin.products.price_calc_cancel'); ?></button>
                <button type="button" id="price-calc-apply" class="px-3 py-2 bg-blue-600 text-white rounded text-sm"><?php echo t('mall_admin.products.price_calc_apply'); ?></button>
            </div>
        </div>
    </div>

<script>
window.MALL_WHOLESALE_MARKUP_RATE = <?php echo json_encode($wholesale_reference_markup_rate); ?>;

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
        const done = function () { showFlash('<?php echo addslashes(t('mall_admin.products.barcode_copied')); ?>: ' + code, 'success', 1500); };
        const fail = function () { showFlash('<?php echo addslashes(t('mall_admin.products.copy_failed')); ?>', 'error'); };
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
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>', 'error'); }
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
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.move_register_failed')); ?>', 'error'); }
            });
    });
});

// 신선상품 탭 — 카테고리 배정/이동은 등록 여부와 무관하게 항상 UPDATE 한 번으로 처리된다(mall_products처럼
// 별도 큐레이션 행을 만들지 않으므로 add/move가 같은 액션).
document.querySelectorAll('.fresh-assign-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const params = new URLSearchParams();
        params.set('action', 'assign_category');
        params.set('mall_fresh_product_id', btn.dataset.freshProductId);
        params.set('category_id', btn.dataset.categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_fresh_curation.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.move_register_failed')); ?>', 'error'); }
            });
    });
});

document.querySelectorAll('.unassign-fresh-category-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.productName + '<?php echo addslashes(t('mall_admin.products.unassign_category_confirm')); ?>')) return;
        const params = new URLSearchParams();
        params.set('action', 'unassign_category');
        params.set('mall_fresh_product_id', btn.dataset.freshProductId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_fresh_curation.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.unassign_category_failed')); ?>', 'error'); }
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
        if (row.dataset.rowType === 'fresh') {
            // 신선상품 행 — 표시명/노출상태는 mall_fresh_products 원본에 직접 반영. 원가/기준도매가/판매가
            // 3개는 전부 override 컬럼에 저장(오리지널과 다를 때만 — 서버가 판단).
            const freshParams = new URLSearchParams();
            freshParams.set('action', 'update');
            freshParams.set('mall_fresh_product_id', row.dataset.freshProductId);
            freshParams.set('name_ko', row.querySelector('.edit-display-name').value);
            freshParams.set('name_en', row.querySelector('.edit-display-name-en').value);
            freshParams.set('cost_price', row.querySelector('.edit-cost-price').value);
            freshParams.set('wholesale_reference_price', row.querySelector('.edit-wholesale-reference-price').value);
            freshParams.set('price_per_100g', row.querySelector('.edit-selling-price').value);
            freshParams.set('selling_weight_reference_g', row.dataset.sellingWeightReferenceG || '');
            freshParams.set('is_active', row.querySelector('.edit-is-active').checked ? '1' : '0');
            freshParams.set('is_sold_out', row.querySelector('.edit-is-sold-out').checked ? '1' : '0');
            freshParams.set('retail_discount_allowed', row.querySelector('.edit-retail-discount-allowed').checked ? '1' : '0');
            freshParams.set('wholesale_discount_allowed', row.querySelector('.edit-wholesale-discount-allowed').checked ? '1' : '0');
            freshParams.set('csrf_token', window.MALL_CSRF_TOKEN);
            fetch('ajax/save_fresh_curation.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: freshParams.toString() })
                .then(r => r.json())
                .then(data => {
                    showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
                });
            return;
        }
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
                showFlash(data.success ? '<?php echo addslashes(t('common.save_success')); ?>' : (data.error?.message || '<?php echo addslashes(t('common.error_occurred')); ?>'), data.success ? 'success' : 'error');
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
            if (data.success) { onDone(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); }
        })
        .catch(function () { showFlash('<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); });
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
            hint.textContent = '<?php echo addslashes(t('mall_admin.products.original')); ?>: ' + (data.original_selling_price !== null ? Number(data.original_selling_price).toFixed(2) : '-');
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
    const getSelectedPromo = function () {
        return select.closest('td').querySelector('.promo-type-select:checked');
    };

    select.addEventListener('change', function () {
        const selectedPromo = getSelectedPromo();
        if (!selectedPromo) return;
        if (wrap) { wrap.style.display = selectedPromo.value === 'percent' ? '' : 'none'; }
        if (selectedPromo.value === 'percent') {
            if (!valueInput.value) { return; } // 퍼센트 값을 아직 안 넣었으면 값 입력 시 저장한다.
        }
        selectedPromo.disabled = true;
        saveTodayDealPromo(selectedPromo.dataset.productId, selectedPromo.value, selectedPromo.value === 'percent' ? valueInput.value : null)
            .then(function (data) {
                selectedPromo.disabled = false;
                if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); return; }
                applyPromoResultToRow(selectedPromo, data.data);
                showFlash('<?php echo addslashes(t('mall_admin.products.promo_saved_msg')); ?>', 'success');
            })
            .catch(function () { selectedPromo.disabled = false; showFlash('<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); });
    });

    if (valueInput) {
        valueInput.addEventListener('change', function () {
            const selectedPromo = getSelectedPromo();
            if (!selectedPromo || selectedPromo.value !== 'percent' || !valueInput.value) { return; }
            valueInput.disabled = true;
            saveTodayDealPromo(selectedPromo.dataset.productId, 'percent', valueInput.value)
                .then(function (data) {
                    valueInput.disabled = false;
                    if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); return; }
                    applyPromoResultToRow(selectedPromo, data.data);
                    showFlash('<?php echo addslashes(t('mall_admin.products.promo_saved_msg')); ?>', 'success');
                })
                .catch(function () { valueInput.disabled = false; showFlash('<?php echo addslashes(t('mall_admin.products.save_failed')); ?>', 'error'); });
        });
    }
});

document.querySelectorAll('.delete-curated-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (!confirm(btn.dataset.productName + '<?php echo addslashes(t('mall_admin.products.delete_curated_confirm')); ?>')) return;
        const row = btn.closest('tr');
        const params = new URLSearchParams();
        params.set('action', 'delete');
        params.set('mall_product_id', row.dataset.mallProductId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_retail_product.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_failed')); ?>', 'error'); }
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
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.upload_failed')); ?>', 'error'); }
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
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_failed')); ?>', 'error'); }
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
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.sort_failed')); ?>', 'error'); }
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
        '<span class="text-blue-500 text-[10px] font-bold flex-shrink-0" title="<?php echo addslashes(t('mall_admin.products.not_saved_yet')); ?>">NEW</span>' +
        '<input type="text" class="pending-name border border-blue-300 rounded px-1.5 py-1 text-xs flex-1 min-w-0" value="' + escHtml(name) + '">' +
        '<input type="text" class="pending-name-en border border-blue-300 rounded px-1.5 py-1 text-xs w-16 flex-shrink-0" value="' + escHtml(nameEn) + '" placeholder="EN">' +
        '<button type="button" class="remove-pending-btn text-gray-300 hover:text-red-500 px-1" title="<?php echo addslashes(t('common.cancel')); ?>"><i class="fas fa-xmark"></i></button>';
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
            showFlash('<?php echo addslashes(t('mall_admin.products.empty_category_name_error')); ?>', 'error');
            return;
        }

        params.set('csrf_token', window.MALL_CSRF_TOKEN);

        applyCategoryEditsBtn.disabled = true;
        fetch('ajax/save_category.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.apply_changes_failed')); ?>', 'error'); applyCategoryEditsBtn.disabled = false; }
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
        if (!confirm(btn.dataset.categoryName + '<?php echo addslashes(t('mall_admin.products.delete_category_confirm')); ?>')) return;
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
                            (details.truncated ? '<li>' + '<?php echo addslashes(t('mall_admin.products.and_more_count')); ?>'.replace('{count}', details.product_count - details.products.length) + '</li>' : '') +
                            '</ul>';
                        const forceBtn = '<button type="button" class="force-clear-delete-btn" data-category-id="' + escHtml(btn.dataset.categoryId) +
                            '" data-category-name="' + escHtml(btn.dataset.categoryName) +
                            '" style="margin-top:0.5rem;padding:4px 10px;background:#dc2626;color:#fff;border-radius:4px;font-size:11px;cursor:pointer;border:none;"><?php echo addslashes(t('mall_admin.products.clear_and_delete_products')); ?></button>';
                        showFlash((data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>') + list + forceBtn, 'error', 20000);
                    } else {
                        showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_category_failed')); ?>', 'error');
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
    if (!confirm(btn.dataset.categoryName + '<?php echo addslashes(t('mall_admin.products.clear_and_delete_confirm')); ?>')) return;
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
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.delete_failed')); ?>', 'error');
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
            if (!data.success) { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.order_save_failed')); ?>', 'error'); }
        });
}

const modalCategoryList = document.getElementById('modal-category-list');
if (modalCategoryList) {
    makeCategoryListSortable(modalCategoryList, function () { saveCategoryOrder(modalCategoryList, null); });
}
document.querySelectorAll('.subcategory-list').forEach(function (subList) {
    makeCategoryListSortable(subList, function () { saveCategoryOrder(subList, subList.dataset.parentId); });
});

// 네이티브 HTML5 Drag & Drop으로 큐레이션된 상품 순서 변경.
// 일반상품/신선상품은 서로 다른 정렬 공간(mall_products.display_order / mall_fresh_products.display_order)을
// 쓰므로, 종류가 다른 행끼리는 드롭해도 순서가 섞이지 않도록 무시한다.
let curatedDragSrcRow = null;
function curatedRowType(row) { return row.dataset.rowType === 'fresh' ? 'fresh' : 'general'; }
function attachCuratedDragHandlers() {
    document.querySelectorAll('.curated-row').forEach(function (row) {
        const handle = row.querySelector('.mall-product-drag-handle');
        if (!handle) return;
        handle.addEventListener('dragstart', function (e) {
            curatedDragSrcRow = row;
            row.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', row.dataset.mallProductId || row.dataset.freshProductId || '');
        });
        handle.addEventListener('dragend', function () {
            row.classList.remove('is-dragging');
            document.querySelectorAll('.mall-category-drop-target').forEach(el => el.classList.remove('mall-category-drop-target'));
            curatedDragSrcRow = null;
        });
        row.addEventListener('dragover', function (e) { e.preventDefault(); row.classList.add('drag-over'); });
        row.addEventListener('dragleave', function () { row.classList.remove('drag-over'); });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            row.classList.remove('drag-over');
            if (curatedDragSrcRow && curatedDragSrcRow !== row && curatedRowType(curatedDragSrcRow) === curatedRowType(row)) {
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

// 정렬 그립을 좌측 카테고리에 드롭하면 일반/신선상품의 실제 카테고리를 변경한다.
document.querySelectorAll('aside a[data-drop-category-id]').forEach(function (categoryLink) {
    categoryLink.addEventListener('dragover', function (e) {
        if (!curatedDragSrcRow) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        categoryLink.classList.add('mall-category-drop-target');
    });
    categoryLink.addEventListener('dragleave', function () {
        categoryLink.classList.remove('mall-category-drop-target');
    });
    categoryLink.addEventListener('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        categoryLink.classList.remove('mall-category-drop-target');
        if (!curatedDragSrcRow) return;

        const movedRow = curatedDragSrcRow;
        const categoryId = parseInt(categoryLink.dataset.dropCategoryId, 10) || 0;
        const sourceHomeSlot = movedRow.dataset.homeSlot || '';
        const categoryChanged = categoryId && categoryId !== (parseInt(movedRow.dataset.categoryId, 10) || 0);
        if (!categoryId || (!categoryChanged && !sourceHomeSlot)) return;

        const params = new URLSearchParams();
        params.set('category_id', categoryId);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        let endpoint;
        if (curatedRowType(movedRow) === 'fresh') {
            endpoint = 'ajax/save_fresh_curation.php';
            params.set('action', 'assign_category');
            params.set('mall_fresh_product_id', movedRow.dataset.freshProductId);
        } else {
            endpoint = 'ajax/save_retail_product.php';
            params.set('action', 'move_category');
            params.set('product_id', movedRow.dataset.productId);
        }

        const categoryRequest = categoryChanged ? fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(r => r.json()) : Promise.resolve({ success: true });
        categoryRequest.then(function (data) {
            if (data.success) {
                const removeFromSource = sourceHomeSlot && movedRow.dataset.productId
                    ? new Promise(function (resolve) {
                        toggleHomeSlotProduct(sourceHomeSlot, movedRow.dataset.productId, false, resolve);
                    })
                    : Promise.resolve();
                removeFromSource.then(function () {
                    showFlash('상품 이동이 완료되었습니다.', 'success');
                    setTimeout(function () { window.location.reload(); }, 350);
                });
            } else {
                showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.move_register_failed')); ?>', 'error');
            }
        });
    });
});

// 카테고리 상품을 오늘의특가/기획전/새상품으로 끌어 놓으면 홈 노출 목록에 추가한다.
document.querySelectorAll('aside a[data-drop-home-slot]').forEach(function (slotLink) {
    slotLink.addEventListener('dragover', function (e) {
        if (!curatedDragSrcRow || curatedRowType(curatedDragSrcRow) !== 'general') return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        slotLink.classList.add('mall-category-drop-target');
    });
    slotLink.addEventListener('dragleave', function () {
        slotLink.classList.remove('mall-category-drop-target');
    });
    slotLink.addEventListener('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        slotLink.classList.remove('mall-category-drop-target');
        if (!curatedDragSrcRow || curatedRowType(curatedDragSrcRow) !== 'general') return;

        const movedRow = curatedDragSrcRow;
        const targetSlot = slotLink.dataset.dropHomeSlot;
        const sourceSlot = movedRow.dataset.homeSlot || '';
        const productId = movedRow.dataset.productId;
        if (!targetSlot || !productId || targetSlot === sourceSlot) return;

        const addToTarget = new Promise(function (resolve) {
            toggleHomeSlotProduct(targetSlot, productId, true, resolve);
        });
        const removeFromSource = sourceSlot
            ? addToTarget.then(function () {
                return new Promise(function (resolve) {
                    toggleHomeSlotProduct(sourceSlot, productId, false, resolve);
                });
            })
            : addToTarget;

        removeFromSource.then(function () {
            showFlash('홈 노출 목록으로 이동되었습니다.', 'success');
            setTimeout(function () { window.location.reload(); }, 350);
        });
    });
});

function saveCuratedOrder() {
    const body = document.getElementById('curated-products-body');
    const offset = parseInt(body.dataset.orderOffset, 10) || 0;
    const generalRows = Array.from(document.querySelectorAll('#curated-products-body .curated-row[data-mall-product-id]'));
    const freshRows = Array.from(document.querySelectorAll('#curated-products-body .curated-row[data-fresh-product-id]'));

    if (generalRows.length) {
        const params = new URLSearchParams();
        params.set('order', generalRows.map(r => r.dataset.mallProductId).join(','));
        params.set('offset', offset);
        params.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/reorder_curated_products.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    generalRows.forEach(function (r, idx) {
                        const input = r.querySelector('.edit-display-order');
                        if (input) { input.value = offset + idx; }
                    });
                } else {
                    showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.order_save_failed')); ?>', 'error');
                }
            });
    }

    if (freshRows.length) {
        const freshParams = new URLSearchParams();
        freshParams.set('action', 'reorder');
        freshParams.set('order', freshRows.map(r => r.dataset.freshProductId).join(','));
        freshParams.set('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_fresh_curation.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: freshParams.toString() })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    freshRows.forEach(function (r, idx) {
                        const input = r.querySelector('.edit-display-order');
                        if (input) { input.value = idx; }
                    });
                } else {
                    showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.order_save_failed')); ?>', 'error');
                }
            });
    }
}

document.querySelectorAll('.fresh-image-upload-input').forEach(function (input) {
    input.addEventListener('change', function () {
        if (!input.files.length) return;
        const formData = new FormData();
        formData.append('action', 'upload_image');
        formData.append('mall_fresh_product_id', input.dataset.freshProductId);
        formData.append('image', input.files[0]);
        formData.append('csrf_token', window.MALL_CSRF_TOKEN);
        fetch('ajax/save_fresh_curation.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) { window.location.reload(); } else { showFlash(data.error?.message || '<?php echo addslashes(t('mall_admin.products.upload_failed')); ?>', 'error'); }
            });
    });
});
// 모달은 단가만 표에 적용하고 실제 저장은 기존 저장 버튼에 맡긴다.
const priceCalcModal = document.getElementById('price-calc-modal');
const priceCalcQuantity = document.getElementById('price-calc-quantity');
const priceCalcLabels = <?php echo json_encode([
    'pieceQuantity' => t('mall_admin.products.price_calc_quantity'),
    'weightQuantity' => t('mall_admin.products.price_calc_weight'),
    'originalPiece' => t('mall_admin.products.price_calc_original_piece'),
    'originalWeight' => t('mall_admin.products.price_calc_original_weight'),
    'appliedPiece' => t('mall_admin.products.price_calc_applied_piece'),
    'appliedWeight' => t('mall_admin.products.price_calc_applied_weight'),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const priceCalcPairs = [
    { key: 'cost', selector: '.edit-cost-price' },
    { key: 'wholesale', selector: '.edit-wholesale-reference-price' },
    { key: 'selling', selector: '.edit-selling-price' }
].map(function (pair) {
    pair.unit = document.getElementById('price-calc-' + pair.key + '-unit');
    pair.total = document.getElementById('price-calc-' + pair.key + '-total');
    pair.divisor = 1;
    pair.original = null;
    pair.unitLabel = pair.unit.closest('label').querySelector('.price-calc-unit-label');
    pair.totalLabel = pair.total.closest('label').querySelector('.price-calc-total-label');
    pair.value = '';
    return pair;
});
let currentTargetRow = null;
let priceCalcTrigger = null;
let priceCalcStep = 1;
let priceCalcIsWeight = false;
let priceCalcRawQuantity = 1;

function priceCalcNumber(value) {
    if (String(value).trim() === '') return null;
    const number = Number(value);
    return Number.isFinite(number) && number >= 0 ? number : null;
}

function priceCalcMoney(value) {
    const rounded = Math.round((value + Number.EPSILON) * 100) / 100;
    return Number.isFinite(rounded) ? rounded.toFixed(2) : null;
}

// 왼쪽(오리지널)은 읽기전용 표시라 더 이상 편집 이벤트가 없다 — 오른쪽(현재 적용가)만 직접 입력한다.
function recalcFromTotal(pair) {
    const total = priceCalcNumber(pair.total.value);
    if (total === null) return;
    const unit = priceCalcMoney(total / (priceCalcRawQuantity / pair.divisor));
    if (unit === null) return;
    pair.value = unit;
}

function updatePriceCalcQuantity(normalize = true) {
    const quantity = priceCalcNumber(priceCalcQuantity.value);
    let factor;
    if (normalize || priceCalcStep === 1) {
        factor = Math.max(1, Math.round((quantity ?? priceCalcStep) / priceCalcStep));
        if (!Number.isSafeInteger(factor * priceCalcStep)) return;
        priceCalcQuantity.value = factor * priceCalcStep;
    } else {
        // Keep partial weight input intact; invalid input retains the previous preview.
        if (quantity === null || quantity <= 0) return;
        factor = quantity / priceCalcStep;
        if (!Number.isFinite(factor) || factor <= 0) return;
    }
    priceCalcRawQuantity = factor * priceCalcStep;
    // 수량/무게를 실제로 바꾸는 순간부터는 항상 기준원가(오리지널)를 기준으로 다시 계산한다 — 표에
    // 예전부터 들어있던 "현재 적용가"는 모달을 처음 열 때 그대로 보여주는 용도일 뿐, 수량이 바뀌면
    // 더 이상 계산 기준으로 쓰지 않는다(사용자 확정, 2026-09-12).
    // 매입 이력이 없어 오리지널이 없는 경우(주로 원가/도매가)에는 되돌아갈 기준이 없으므로, 지금까지
    // 쓰던 값(pair.value)을 기준으로 계속 계산한다 — 이전에는 여기서 그냥 멈춰서 수량을 바꿔도
    // 원가/도매가 "현재 적용가"가 갱신되지 않는 버그가 있었다(2026-09-12 수정).
    priceCalcPairs.forEach(function (pair) {
        if (pair.original !== null) {
            pair.value = priceCalcMoney(pair.original);
        }
        const value = priceCalcNumber(pair.value);
        if (value === null) return;
        const total = priceCalcMoney(value * (priceCalcRawQuantity / pair.divisor));
        if (total !== null) pair.total.value = total;
    });
}

function closePriceCalcModal() {
    priceCalcModal.classList.add('hidden');
    priceCalcModal.classList.remove('flex');
    currentTargetRow = null;
    if (priceCalcTrigger) priceCalcTrigger.focus();
    priceCalcTrigger = null;
}

function openPriceCalcModal(row) {
    if (row.dataset.rowType !== 'fresh') return;
    if (currentTargetRow) closePriceCalcModal();
    currentTargetRow = row;
    priceCalcTrigger = row.querySelector('.open-price-calc-btn');
    priceCalcIsWeight = row.dataset.saleType === 'weight';
    priceCalcStep = 1;
    // 이 상품에 마지막으로 저장해둔 수량/무게(선택 판매 단위 기준값)가 있으면 그걸 기본값으로 쓰고,
    // 없으면 무게 상품은 100g, 낱개 상품은 1개로 시작한다. 저장은 "표에 적용" 시 row.dataset에
    // 반영되고, 실제 DB 저장은 기존 저장 버튼을 눌러야 이뤄진다(낱개/무게 모두 동일하게 지원).
    const savedQuantity = priceCalcNumber(row.dataset.sellingWeightReferenceG ?? '');
    priceCalcRawQuantity = savedQuantity ?? (priceCalcIsWeight ? 100 : 1);
    priceCalcQuantity.min = priceCalcStep;
    priceCalcQuantity.step = priceCalcStep;
    priceCalcQuantity.value = priceCalcRawQuantity;
    document.getElementById('price-calc-product-name').textContent = row.querySelector('.edit-display-name').value || priceCalcTrigger.dataset.productName;
    document.getElementById('price-calc-quantity-label').textContent = priceCalcIsWeight ? priceCalcLabels.weightQuantity : priceCalcLabels.pieceQuantity;
    const originalCost = priceCalcNumber(row.dataset.originalCostPrice ?? '');
    // The calculator's selling baseline is receipt unit cost plus 40%, rounded up.
    // Keep the stored master price separate when detecting an existing applied price.
    const storedOriginalSelling = priceCalcNumber(row.dataset.originalSellingPrice ?? '');
    const originalSelling = originalCost !== null ? Math.ceil(originalCost * 1.4) : null;
    const markupRate = Number(window.MALL_WHOLESALE_MARKUP_RATE);
    const originalWholesale = (originalCost !== null && Number.isFinite(markupRate)) ? Math.ceil(originalCost * (1 + markupRate / 100)) : null;
    const originalByKey = { cost: originalCost, wholesale: originalWholesale, selling: originalSelling };
    // 매입 이력이 없어도(원가 오리지널 없음) 오른쪽(현재 적용가)은 계속 직접 입력할 수 있다 —
    // 참고용 안내 문구만 보여준다.
    document.getElementById('price-calc-cost-unavailable').classList.toggle('hidden', originalCost !== null);
    priceCalcPairs.forEach(function (pair) {
        pair.divisor = priceCalcIsWeight ? 1000 : 1;
        pair.original = originalByKey[pair.key];
        // 왼쪽 = 매입 단위원가 기준 오리지널(판매가는 원가 + 40%, 읽기전용).
        pair.unit.value = pair.original !== null ? priceCalcMoney(pair.original) : '';
        pair.unitLabel.textContent = priceCalcIsWeight ? priceCalcLabels.originalWeight : priceCalcLabels.originalPiece;
        pair.totalLabel.textContent = priceCalcIsWeight ? priceCalcLabels.appliedWeight : priceCalcLabels.appliedPiece;
        // 오른쪽(현재 적용가): 표 값이 오리지널과 실질적으로 다르면(=예전에 이 계산기로 저장해둔
        // "이 수량/무게 기준 가격") 그 값을 그대로 보여준다. 표 값이 오리지널과 같으면(아직 한 번도
        // 계산해본 적 없는 상품) 오리지널 기준으로 지금 수량/무게에 맞춰 새로 계산해 보여준다 —
        // 마스터 원가/판매가(kg당·1개당)를 그대로 "이 무게의 가격"인 것처럼 보여주면 안 되기 때문.
        // 수량/무게를 실제로 바꾸면 그 다음부터는 항상 오리지널 기준으로 재계산된다(updatePriceCalcQuantity
        // 참고, 사용자 확정 2026-09-12).
        const currentApplied = priceCalcNumber(row.querySelector(pair.selector).value);
        const factor = priceCalcRawQuantity / pair.divisor;
        const storedOriginal = pair.key === 'selling' ? storedOriginalSelling : pair.original;
        const isOverridden = currentApplied !== null && storedOriginal !== null && Math.abs(currentApplied - storedOriginal) > 0.005;
        if (isOverridden && factor > 0) {
            pair.value = priceCalcMoney(currentApplied / factor);
            pair.total.value = priceCalcMoney(currentApplied);
        } else if (pair.original !== null) {
            pair.value = priceCalcMoney(pair.original);
            pair.total.value = factor > 0 ? priceCalcMoney(pair.original * factor) : '';
        } else {
            pair.value = currentApplied !== null ? priceCalcMoney(currentApplied) : '';
            pair.total.value = currentApplied !== null ? priceCalcMoney(currentApplied) : '';
        }
    });
    priceCalcModal.classList.remove('hidden');
    priceCalcModal.classList.add('flex');
    priceCalcQuantity.focus();
}

function recalcWholesaleFromCost() {
    // 현재 원가 칸(오른쪽, 현재 적용가)에 지금 보이는 값을 기준으로 도매가를 계산한다.
    const cost = priceCalcNumber(priceCalcPairs[0].total.value);
    const markup = Number(window.MALL_WHOLESALE_MARKUP_RATE);
    if (cost === null || !Number.isFinite(markup)) return;
    const wholesale = Math.ceil(cost * (1 + markup / 100));
    const value = priceCalcMoney(wholesale);
    if (wholesale < 0 || value === null) return;
    priceCalcPairs[1].total.value = value;
    const factor = priceCalcRawQuantity / priceCalcPairs[1].divisor;
    if (factor > 0) priceCalcPairs[1].value = priceCalcMoney(wholesale / factor);
}

function applyToRow() {
    if (!currentTargetRow) return;
    priceCalcPairs.forEach(function (pair) {
        // 낱개/무게 모두 "지금 입력한 수량/무게 기준 총액"(오른쪽, 현재 적용가)을 그대로 적용한다
        // (사용자 확정, 2026-09-12). 실제 몰 주문화면은 아직 개별 수량/무게로 결제되므로, 수량을
        // 1(무게는 기본 100g)이 아닌 값으로 저장한 상품은 스토어프론트 반영 전까지 주의가 필요하다.
        const value = priceCalcNumber(pair.total.value);
        currentTargetRow.querySelector(pair.selector).value = value === null ? pair.value : priceCalcMoney(value);
    });
    // 이번에 쓴 수량/무게를 행에 기억시켜, 다음에 이 상품의 가격 설정을 다시 열 때 기본값(무게
    // 100g/낱개 1개) 대신 이 값으로 시작하게 한다(낱개/무게 모두 동일). 실제 DB 저장은 기존 저장
    // 버튼을 눌러야 이뤄진다.
    currentTargetRow.dataset.sellingWeightReferenceG = String(priceCalcRawQuantity);
    closePriceCalcModal();
}

// 공용 모달의 리스너는 로드 시 한 번만 등록한다.
document.querySelectorAll('.open-price-calc-btn').forEach(function (button) {
    button.addEventListener('click', function () { openPriceCalcModal(button.closest('tr')); });
});
priceCalcPairs.forEach(function (pair) {
    // 왼쪽(오리지널)은 읽기전용이라 리스너가 없다 — 오른쪽(현재 적용가)만 직접 입력한다.
    pair.total.addEventListener('input', function () { recalcFromTotal(pair); });
    pair.total.addEventListener('change', function () {
        const value = priceCalcNumber(pair.total.value);
        if (value !== null && priceCalcMoney(value) !== null) pair.total.value = priceCalcMoney(value);
    });
});
priceCalcQuantity.addEventListener('input', function () {
    updatePriceCalcQuantity(false);
});
priceCalcQuantity.addEventListener('change', function () {
    updatePriceCalcQuantity();
});
document.getElementById('price-calc-minus').addEventListener('click', function () {
    priceCalcQuantity.value = priceCalcRawQuantity - priceCalcStep;
    updatePriceCalcQuantity();
});
document.getElementById('price-calc-plus').addEventListener('click', function () {
    priceCalcQuantity.value = priceCalcRawQuantity + priceCalcStep;
    updatePriceCalcQuantity();
});
document.getElementById('price-calc-wholesale-auto').addEventListener('click', recalcWholesaleFromCost);
document.getElementById('price-calc-apply').addEventListener('click', applyToRow);
document.getElementById('price-calc-close').addEventListener('click', closePriceCalcModal);
document.getElementById('price-calc-cancel').addEventListener('click', closePriceCalcModal);
priceCalcModal.addEventListener('click', function (e) {
    if (e.target === priceCalcModal) closePriceCalcModal();
});
document.addEventListener('keydown', function (e) {
    if (!currentTargetRow) return;
    if (e.key === 'Escape') closePriceCalcModal();
    if (e.key === 'Tab') {
        const controls = Array.from(priceCalcModal.querySelectorAll('button, input')).filter(function (control) { return !control.disabled; });
        const first = controls[0];
        const last = controls[controls.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }
});
</script>
</body>
</html>
