<?php
// 오류가 HTML로 출력되지 않도록 억제
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

// 에러를 JSON으로 반환하도록 핸들러 등록
set_error_handler(function($errno, $errstr, $errfile, $errline){
    http_response_code(500);
    $msg = sprintf('PHP error [%s] %s at %s:%d', $errno, $errstr, basename($errfile), $errline);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
});
set_exception_handler(function($ex){
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '로그인이 필요합니다.']);
    exit;
}

$prefix = isset($_GET['prefix']) ? preg_replace('/\D/', '', $_GET['prefix']) : '2011223';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1 || $limit > 200) { $limit = 50; }
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit;

if (strlen($prefix) !== 7) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'prefix는 7자리 숫자여야 합니다.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => true // LIMIT 바인딩 이슈 회피
    ]);

    // 세션의 매장 ID 사용 (header.php에서 세팅됨). 없으면 1로 기본.
    $storeId = isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : 1;

    // products.selling_price 컬럼 존재 확인 (없으면 NULL로 대체)
    $hasPriceCol = false;
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'products' AND column_name = 'selling_price'");
        $chk->execute();
        $hasPriceCol = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        $hasPriceCol = false;
    }
    $priceSelect = $hasPriceCol ? 'p.selling_price' : 'NULL';

    // inventory.selling_price 사용 가능 여부 확인
    $hasInventory = false; $hasInvPrice = false;
    try {
        $chkInv = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inventory'");
        $chkInv->execute();
        $hasInventory = (bool)$chkInv->fetchColumn();
        if ($hasInventory) {
            $chkInvCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inventory' AND column_name = 'selling_price'");
            $chkInvCol->execute();
            $hasInvPrice = (bool)$chkInvCol->fetchColumn();
        }
    } catch (Throwable $e) { $hasInventory = false; $hasInvPrice = false; }

    // 최종 판매가 선택식: 매장별 inventory.selling_price 우선, 없으면 products.selling_price
    $finalPriceExpr = ($hasInventory && $hasInvPrice)
        ? 'COALESCE(inv.selling_price, ' . $priceSelect . ') AS selling_price'
        : ($hasPriceCol ? 'p.selling_price AS selling_price' : 'NULL AS selling_price');

    // 13자리 숫자 + 지정 prefix로 시작하는 SKU만 조회
    $joinInventory = ($hasInventory && $hasInvPrice) ? 'LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.store_id = :store_id' : '';

    // 전체 개수 조회
    $countSql = "
        SELECT COUNT(*)
        FROM products p
        WHERE LEFT(p.sku,7) = :prefix AND CHAR_LENGTH(p.sku) = 13
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $countStmt->execute();
    $totalCount = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalCount / $limit);

    $sql = "
        SELECT p.id, p.sku, p.name_en, p.name_ko, $finalPriceExpr, b.name_ko AS brand_name
        FROM products p
        LEFT JOIN brands b ON p.brand_id = b.id
        $joinInventory
        WHERE LEFT(p.sku,7) = :prefix AND CHAR_LENGTH(p.sku) = 13
        ORDER BY p.sku DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    if ($joinInventory) { $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT); }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'items' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_count' => $totalCount,
            'total_pages' => $totalPages
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
