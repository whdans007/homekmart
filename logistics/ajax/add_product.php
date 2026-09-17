<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/unit_helper.php'; // unit 검증(BOX/PCS만 허용)
require_once __DIR__ . '/../lib/image_helper.php'; // 상품 대표 이미지 업로드 처리
require_once __DIR__ . '/../lib/barcode_helper.php'; // 바코드 중복 검증
header('Content-Type: application/json; charset=utf-8');

lc_require_staff();

$token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['lc_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_add_product.security_error')]);
    exit;
}

$name_en           = trim($_POST['name_en'] ?? '');
$name_ko           = trim($_POST['name_ko'] ?? '') ?: null;
$capacity          = trim($_POST['capacity'] ?? '') ?: null;
$brand_id          = (int)($_POST['brand_id'] ?? 0) ?: null;
$category_id       = (int)($_POST['category_id'] ?? 0) ?: null;
$unit              = lc_valid_unit($_POST['unit'] ?? '', LC_UNIT_BOX); // BOX/PCS 외 값은 BOX로
$pieces_per_box    = max(1, (int)($_POST['pieces_per_box'] ?? 1));
$barcode_unit      = trim($_POST['barcode_unit'] ?? '') ?: null;
$barcode_box       = trim($_POST['barcode_box'] ?? '') ?: null;
$barcode_logistics = trim($_POST['barcode_logistics'] ?? '') ?: null;
$min_stock         = max(0, (int)($_POST['min_stock'] ?? 0));
$requires_expiry   = isset($_POST['requires_expiry']) ? 1 : 0;

if ($name_en === '') {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_add_product.name_required')]);
    exit;
}

// 대표 이미지 업로드 (선택)
$image_path = null;
if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $img_err = '';
    $image_path = lc_handle_product_image_upload($_FILES['image'], $img_err);
    if ($image_path === null && $img_err !== '') {
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_add_product.image_error', ['error' => $img_err])]);
        exit;
    }
}

try {
    $conn = get_lc_db();

    // 바코드 중복 검증 (3개 컬럼 교차 검사) — 다른 상품과 겹치면 등록 차단
    $conflicts = lc_find_barcode_conflicts($conn, [$barcode_unit, $barcode_box, $barcode_logistics]);
    if (!empty($conflicts)) {
        $conn->close();
        echo json_encode(['success' => false, 'message' => t('logistics.ajax_add_product.barcode_conflict', ['details' => lc_format_barcode_conflict_msg($conflicts)])]);
        exit;
    }

    $st = $conn->prepare(
        "INSERT INTO lc_products
         (name_en, name_ko, capacity, brand_id, category_id, unit, pieces_per_box,
          barcode_unit, barcode_box, barcode_logistics, min_stock, requires_expiry, image_path, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $uid = lc_current_user_id();
    $st->bind_param('sssiisisssiisi',
        $name_en, $name_ko, $capacity, $brand_id, $category_id,
        $unit, $pieces_per_box,
        $barcode_unit, $barcode_box, $barcode_logistics,
        $min_stock, $requires_expiry, $image_path, $uid
    );
    $st->execute();
    $new_id = $conn->insert_id;
    $st->close();

    $uname = $_SESSION['name'] ?? ($_SESSION['username'] ?? 'Unknown');
    $sh = $conn->prepare(
        "INSERT INTO lc_product_history (product_id, user_id, user_name, action) VALUES (?,?,?,'create')"
    );
    $sh->bind_param('iis', $new_id, $uid, $uname);
    $sh->execute();
    $sh->close();

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
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => t('logistics.ajax_add_product.db_error', ['error' => $e->getMessage()])]);
}
