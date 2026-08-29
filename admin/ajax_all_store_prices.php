<?php
/**
 * 전 점포 가격 조회 (All-Store Price Lookup) - AJAX
 *  - action=suggest : SKU/상품명 부분검색 → 상품 후보 목록
 *  - action=detail  : 특정 상품의 전 점포 원가/판매가 목록
 * 로그인 필수. JSON 응답.
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

// 로그인 확인 (AJAX이므로 리디렉션 대신 JSON 401)
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'detail';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ── 스키마 차이 흡수: 컬럼 존재 여부 확인 ──
    $hasProdPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='products' AND column_name='selling_price'"
    )->fetchColumn();
    $hasCostPrice = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='inventory' AND column_name='cost_price'"
    )->fetchColumn();
    $hasIsActive = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='products' AND column_name='is_active'"
    )->fetchColumn();

    // ───────────────────────── 상품 후보 검색 ─────────────────────────
    if ($action === 'suggest') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 1) {
            echo json_encode(['success' => true, 'products' => []]);
            exit;
        }
        $limit = min(max(1, (int)($_GET['limit'] ?? 15)), 50);
        $activeClause = $hasIsActive ? 'AND p.is_active = 1' : '';
        $like = '%' . $q . '%';

        $sql = "
            SELECT p.id AS product_id, p.sku, p.name_ko, p.name_en
            FROM products p
            WHERE (p.sku LIKE ? OR p.name_en LIKE ? OR p.name_ko LIKE ?)
            {$activeClause}
            ORDER BY
                CASE
                    WHEN p.sku     = ?    THEN 0
                    WHEN p.sku     LIKE ? THEN 1
                    WHEN p.name_en LIKE ? THEN 2
                    WHEN p.name_ko LIKE ? THEN 3
                    ELSE 4
                END,
                p.name_en ASC, p.name_ko ASC
            LIMIT {$limit}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$like, $like, $like, $q, $like, $like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $products = array_map(function ($r) {
            return [
                'product_id' => (int)$r['product_id'],
                'sku'        => $r['sku'],
                'name_ko'    => $r['name_ko'] ?? '',
                'name_en'    => $r['name_en'] ?? '',
            ];
        }, $rows);

        echo json_encode(['success' => true, 'products' => $products]);
        exit;
    }

    // ───────────────────────── 전 점포 가격 상세 ─────────────────────────
    $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
    $barcode   = trim($_GET['barcode'] ?? $_POST['barcode'] ?? '');

    // 상품 식별 (product_id 우선, 없으면 바코드/SKU)
    if ($productId > 0) {
        $pStmt = $pdo->prepare("SELECT id, sku, name_ko, name_en, pieces_per_box" . ($hasProdPrice ? ", selling_price" : "") . " FROM products WHERE id = ? LIMIT 1");
        $pStmt->execute([$productId]);
    } elseif ($barcode !== '') {
        $pStmt = $pdo->prepare("SELECT id, sku, name_ko, name_en, pieces_per_box" . ($hasProdPrice ? ", selling_price" : "") . " FROM products WHERE sku = ? LIMIT 1");
        $pStmt->execute([$barcode]);
    } else {
        echo json_encode(['success' => false, 'message' => '바코드 또는 상품을 선택해주세요.']);
        exit;
    }

    $product = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다' . ($barcode !== '' ? ': ' . $barcode : '.')]);
        exit;
    }
    $productId = (int)$product['id'];
    $prodFallbackPrice = $hasProdPrice ? $product['selling_price'] : null;

    // 전 점포 + 해당 상품 재고가 LEFT JOIN
    $costSel  = $hasCostPrice ? 'i.cost_price' : 'NULL';
    $stmt = $pdo->prepare("
        SELECT
            s.id   AS store_id,
            s.name AS store_name,
            {$costSel}        AS cost_price,
            i.selling_price   AS inv_selling,
            i.quantity        AS quantity
        FROM stores s
        LEFT JOIN inventory i ON i.store_id = s.id AND i.product_id = ?
        WHERE s.name NOT IN ('CENTER (물류센터)', 'KIMS MALL WHEREHOUSE (킴스몰 창고)')
        ORDER BY s.name ASC
    ");
    $stmt->execute([$productId]);
    $storeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stores = [];
    foreach ($storeRows as $r) {
        // 판매가: 점포 재고가 우선, 없으면 products 기본 판매가로 폴백
        $selling = $r['inv_selling'];
        if ($selling === null && $prodFallbackPrice !== null) {
            $selling = $prodFallbackPrice;
        }
        $cost = $r['cost_price'];
        $hasInv = ($r['inv_selling'] !== null) || ($r['cost_price'] !== null) || ($r['quantity'] !== null);

        $stores[] = [
            'store_id'          => (int)$r['store_id'],
            'store_name'        => $r['store_name'],
            'has_inventory'     => $hasInv,
            'cost_price'        => $cost !== null ? number_format((float)$cost, 2) : '-',
            'cost_price_raw'    => $cost !== null ? (float)$cost : null,
            'selling_price'     => $selling !== null ? number_format((float)$selling, 0) : '-',
            'selling_price_raw' => $selling !== null ? (float)$selling : null,
            'quantity'          => $r['quantity'] !== null ? (int)$r['quantity'] : null,
        ];
    }

    // ── 물류센터(logistics) 상품 정보 + 원가 ──
    // 바코드(SKU) 기준으로 lc_products 매칭 (단품/박스/물류코드 3종 교차 검색)
    $logistics = null;
    $lcStmt = $pdo->prepare("
        SELECT id, name_en, name_ko, capacity, pieces_per_box, unit
        FROM lc_products
        WHERE barcode_unit = ? OR barcode_box = ? OR barcode_logistics = ?
        LIMIT 1
    ");
    $lcStmt->execute([$product['sku'], $product['sku'], $product['sku']]);
    $lcProduct = $lcStmt->fetch(PDO::FETCH_ASSOC);

    if ($lcProduct) {
        $piecesPerBox = max(1, (int)$lcProduct['pieces_per_box']);

        // 물류센터 원가: 여러 배치가 있어도 가중평균 대신 "마지막 입고 배치"의 원가를 그대로 사용
        // (PCS 환산 원가 cost_price_pcs 는 BOX/PCS 입고 여부와 무관하게 낱개 단가이므로
        //  현재 박스포장수량을 곱해 "박스 원가" 기준으로 통일)
        $costStmt = $pdo->prepare("
            SELECT cost_price_pcs
            FROM lc_inbound
            WHERE product_id = ?
            ORDER BY inbound_date DESC, id DESC
            LIMIT 1
        ");
        $costStmt->execute([$lcProduct['id']]);
        $lastCostPcs = (float)($costStmt->fetch(PDO::FETCH_ASSOC)['cost_price_pcs'] ?? 0);

        $isEstimated = false;
        $cost = $lastCostPcs * $piecesPerBox;

        // 입고 이력이 없어 원가가 0인 경우: 킴스몰(지점명 KIMS%) 원가 × 박스포장수량으로 추정
        if ($cost <= 0) {
            $kimsCost = null;
            foreach ($stores as $s) {
                if ($s['cost_price_raw'] !== null && stripos($s['store_name'], 'KIMS') === 0) {
                    $kimsCost = $s['cost_price_raw'];
                    break;
                }
            }
            if ($kimsCost !== null) {
                $cost = $kimsCost * $piecesPerBox;
                $isEstimated = true;
            }
        }

        $logistics = [
            'found'           => true,
            'name_ko'         => $lcProduct['name_ko'] ?? '',
            'name_en'         => $lcProduct['name_en'] ?? '',
            'capacity'        => $lcProduct['capacity'] ?? '',
            'pieces_per_box'  => $piecesPerBox,
            'cost_price'      => $cost > 0 ? number_format($cost, 2) : '-',
            'cost_price_raw'  => $cost,
            'is_estimated'    => $isEstimated,
        ];
    } else {
        $logistics = ['found' => false];
    }

    echo json_encode([
        'success' => true,
        'product' => [
            'product_id'     => $productId,
            'sku'            => $product['sku'],
            'name_ko'        => $product['name_ko'] ?? '',
            'name_en'        => $product['name_en'] ?? '',
            'pieces_per_box' => isset($product['pieces_per_box']) ? (int)$product['pieces_per_box'] : null,
        ],
        'stores'     => $stores,
        'logistics'  => $logistics,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('admin/ajax_all_store_prices.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
