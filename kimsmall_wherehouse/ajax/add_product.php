<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/unit_helper.php'; // unit 검증(BOX/PCS만 허용)
require_once __DIR__ . '/../lib/image_helper.php'; // 상품 대표 이미지 업로드 처리
require_once __DIR__ . '/../lib/barcode_helper.php'; // 바코드 중복 검증
require_once __DIR__ . '/../lib/shared_product_helper.php'; // admin 공용 상품 연결/생성
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error: Please try again.']);
    exit;
}

$name_en           = trim($_POST['name_en'] ?? '');
$name_ko           = trim($_POST['name_ko'] ?? '') ?: null;
$capacity          = trim($_POST['capacity'] ?? '') ?: null;
$brand_id          = (int)($_POST['brand_id'] ?? 0) ?: null;
$category_id       = (int)($_POST['category_id'] ?? 0) ?: null;
$unit              = kw_valid_unit($_POST['unit'] ?? '', LC_UNIT_BOX); // BOX/PCS 외 값은 BOX로
$pieces_per_box    = max(1, (int)($_POST['pieces_per_box'] ?? 1));
$barcode_unit      = trim($_POST['barcode_unit'] ?? '') ?: null;
$barcode_box       = trim($_POST['barcode_box'] ?? '') ?: null;
$barcode_logistics = trim($_POST['barcode_logistics'] ?? '') ?: null;
$min_stock         = max(0, (int)($_POST['min_stock'] ?? 0));
$requires_expiry   = isset($_POST['requires_expiry']) ? 1 : 0;

if ($name_en === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter the English product name.']);
    exit;
}

// 대표 이미지 업로드 (선택)
$image_path = null;
if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $img_err = '';
    $image_path = kw_handle_product_image_upload($_FILES['image'], $img_err);
    if ($image_path === null && $img_err !== '') {
        echo json_encode(['success' => false, 'message' => $img_err]);
        exit;
    }
}

try {
    $conn = get_lc_db();

    // 바코드 중복 검증 (3개 컬럼 교차 검사) — 다른 상품과 겹치면 등록 차단
    $conflicts = kw_find_barcode_conflicts($conn, [$barcode_unit, $barcode_box, $barcode_logistics]);
    if (!empty($conflicts)) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => kw_format_barcode_conflict_msg($conflicts)]);
        exit;
    }

    // 공용 상품(admin products) 연결 또는 신규 생성 + 창고 상품 등록 (한 트랜잭션)
    $res = kw_register_product($conn, [
        'name_en' => $name_en, 'name_ko' => $name_ko, 'capacity' => $capacity,
        'brand_id' => $brand_id, 'category_id' => $category_id,
        'unit' => $unit, 'pieces_per_box' => $pieces_per_box,
        'barcode_unit' => $barcode_unit, 'barcode_box' => $barcode_box, 'barcode_logistics' => $barcode_logistics,
        'min_stock' => $min_stock, 'requires_expiry' => $requires_expiry, 'image_path' => $image_path,
    ], kw_current_user_id());
    $new_id  = $res['id'];
    $name_en = $res['name_en'];
    $name_ko = $res['name_ko'];

    $conn->close();
    echo json_encode([
        'success'         => true,
        'id'              => $new_id,
        'name_en'         => $name_en,
        'name_ko'         => $name_ko,
        'capacity'        => $capacity,
        'unit'            => $unit,
        'pieces_per_box'  => $pieces_per_box,
        'requires_expiry' => $requires_expiry,
        'barcode_unit'    => $barcode_unit,
        'linked_existing' => $res['linked_existing'],
        'created_shared'  => $res['created_shared'],
    ]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
