<?php
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/fresh_product_common.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensure_logged_in();
    if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'], true)) {
        http_response_code(403);
        throw new RuntimeException(t('messages.permission_denied'));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed.');
    }

    $code = trim($_POST['code'] ?? '');
    $nameKo = trim($_POST['name_ko'] ?? '');
    $nameEn = trim($_POST['name_en'] ?? '');
    $freshCategory = $_POST['fresh_category'] ?? '';
    $saleType = $_POST['sale_type'] ?? 'weight';
    $priceRaw = trim($_POST['price_per_100g'] ?? '');
    $pkgWeightRaw = trim($_POST['pkg_weight_kg'] ?? '');
    $pkgPiecesRaw = trim($_POST['pkg_pieces_per_box'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $unitType = $saleType === 'piece' ? 'pcs' : 'kg';

    if ($code === '' || $nameKo === '' || $priceRaw === '' || !is_numeric($priceRaw)) {
        throw new InvalidArgumentException('코드, 한글 상품명, 판매가를 올바르게 입력해 주세요.');
    }
    if (strlen($code) > 50 || mb_strlen($nameKo) > 255 || mb_strlen($nameEn) > 255) {
        throw new InvalidArgumentException('입력값이 허용 길이를 초과했습니다.');
    }
    if (!array_key_exists($freshCategory, fresh_category_options())) {
        throw new InvalidArgumentException('분류를 선택해 주세요.');
    }
    if (!in_array($saleType, ['piece', 'weight'], true)) {
        throw new InvalidArgumentException('판매 방식을 선택해 주세요.');
    }
    $price = (float)$priceRaw;
    if ($price < 0 || !in_array($status, ['active', 'inactive'], true)) {
        throw new InvalidArgumentException('판매가 또는 상태 값을 확인해 주세요.');
    }
    if (($pkgWeightRaw !== '' && (!is_numeric($pkgWeightRaw) || (float)$pkgWeightRaw < 0))
        || ($pkgPiecesRaw !== '' && (!ctype_digit($pkgPiecesRaw) || (int)$pkgPiecesRaw < 0))) {
        throw new InvalidArgumentException('박스당 무게와 개수를 올바르게 입력해 주세요.');
    }

    $unitStep = 100;
    $nameEnValue = $nameEn === '' ? null : $nameEn;
    $pkgWeightKg = $pkgWeightRaw === '' ? null : (float)$pkgWeightRaw;
    $pkgPiecesPerBox = $pkgPiecesRaw === '' ? null : (int)$pkgPiecesRaw;

    $conn = get_db_connection();
    $stmt = $conn->prepare('INSERT INTO mall_fresh_products (code, name_ko, name_en, fresh_category, sale_type, unit_step_g, price_per_100g, pkg_weight_kg, pkg_pieces_per_box, unit_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssiddiss', $code, $nameKo, $nameEnValue, $freshCategory, $saleType, $unitStep, $price, $pkgWeightKg, $pkgPiecesPerBox, $unitType, $status);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    $conn->close();

    echo json_encode([
        'ok' => true,
        'message' => t('mall_fresh_products.register_success'),
        'product' => [
            'id' => $id,
            'code' => $code,
            'name_ko' => $nameKo,
            'name_en' => $nameEnValue,
            'sale_type' => $saleType,
            'pkg_weight_kg' => $pkgWeightKg,
            'pkg_pieces_per_box' => $pkgPiecesPerBox,
            'unit_type' => $unitType,
            'status' => $status,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (mysqli_sql_exception $e) {
    error_log('ajax_create_fresh_product.php: ' . $e->getMessage());
    http_response_code(((int)$e->getCode() === 1062) ? 409 : 500);
    echo json_encode([
        'ok' => false,
        'error' => ((int)$e->getCode() === 1062)
            ? t('mall_fresh_products.duplicate_code')
            : t('mall_fresh_products.save_failed'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (!($e instanceof InvalidArgumentException)) {
        error_log('ajax_create_fresh_product.php: ' . $e->getMessage());
    }
    http_response_code($e instanceof InvalidArgumentException ? 422 : (http_response_code() ?: 500));
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
