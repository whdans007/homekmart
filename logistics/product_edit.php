<?php
$page_title = 'Edit Product - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/unit_helper.php'; // unit 검증(BOX/PCS만 허용)
require_once __DIR__ . '/lib/image_helper.php'; // 상품 대표 이미지 업로드 처리
require_once __DIR__ . '/lib/barcode_helper.php'; // 바코드 중복 검증

lc_require_staff();

// 리스트로 돌아갈 URL 복원 (검색/필터/페이지 상태 유지). GET 진입 + POST 저장 모두 처리.
$ret_raw = $_POST['ret'] ?? $_GET['ret'] ?? '';
$list_qs = '';
if ($ret_raw !== '') {
    parse_str($ret_raw, $_rp);
    $_allowed = [];
    foreach (['search', 'cat', 'brand', 'page'] as $_k) {
        if (isset($_rp[$_k]) && $_rp[$_k] !== '') $_allowed[$_k] = $_rp[$_k];
    }
    $list_qs = http_build_query($_allowed);
}
// brand_manage / category_manage 등 다른 페이지에서 진입 시 해당 페이지로 복귀
// open redirect 방지: logistics 디렉터리 내 "파일명.php[?쿼리]" 형태만 허용
$back_raw = $_POST['back'] ?? $_GET['back'] ?? '';

// 폴백: back 파라미터가 없으면(예: 캐시된 이전 링크) referer 로 진입 출처를 판단
// 같은 출처 내비게이션이므로 경로/쿼리가 전달됨. brand_manage/category_manage 만 허용
if ($back_raw === '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !empty($_SERVER['HTTP_REFERER'])) {
    $_ref = parse_url($_SERVER['HTTP_REFERER']);
    $_ref_base = basename($_ref['path'] ?? '');
    if (in_array($_ref_base, ['brand_manage.php', 'category_manage.php'], true)) {
        $back_raw = $_ref_base . (!empty($_ref['query']) ? '?' . $_ref['query'] : '');
    }
}

$back_url = '';
if ($back_raw !== '' && preg_match('~^[A-Za-z0-9_]+\.php(?:\?[^\s#]*)?$~', $back_raw)) {
    $back_url = LC_BASE . '/' . $back_raw;
}
$back_param = htmlspecialchars($back_raw);
$back_q     = $back_raw !== '' ? '&back=' . urlencode($back_raw) : '';

// 최종 복귀 URL: back 우선, 없으면 기존 products.php 목록
$list_url = $back_url !== '' ? $back_url : (LC_BASE . '/products.php' . ($list_qs ? '?' . $list_qs : ''));
$ret_param = htmlspecialchars($ret_raw);
$ret_q     = $ret_raw !== '' ? '&ret=' . urlencode($ret_raw) : ''; // 수정 화면에 머무는 리다이렉트용

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . $list_url); exit; }

$errors = [];

try {
    $conn = get_lc_db();
    $st = $conn->prepare("SELECT * FROM lc_products WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    $form = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$form) { $conn->close(); lc_set_flash('error','Product not found.'); header('Location: ' . $list_url); exit; }
    $brands     = $conn->query("SELECT id, name_en, name_ko FROM lc_brands ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $categories = $conn->query("SELECT id, name_en, name_ko FROM lc_categories ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
} catch (Exception $e) {
    lc_set_flash('error', 'DB Error: ' . $e->getMessage());
    header('Location: ' . $list_url); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    lc_verify_csrf();
    try {
        $conn = get_lc_db();
        $st = $conn->prepare(
            "SELECT
               (SELECT COUNT(*) FROM lc_inbound    WHERE product_id = ?) +
               (SELECT COUNT(*) FROM lc_inventory  WHERE product_id = ?) +
               (SELECT COUNT(*) FROM lc_order_items WHERE product_id = ?) AS cnt"
        );
        $st->bind_param('iii', $id, $id, $id);
        $st->execute();
        $cnt = (int)$st->get_result()->fetch_assoc()['cnt'];
        $st->close();

        if ($cnt > 0) {
            $conn->close();
            lc_set_flash('error', 'Cannot delete products with inbound/inventory/order history. Use deactivation instead.');
            header('Location: ' . LC_BASE . '/product_edit.php?id=' . $id . $ret_q . $back_q);
            exit;
        }

        $st = $conn->prepare("DELETE FROM lc_products WHERE id = ?");
        $st->bind_param('i', $id);
        $st->execute();
        $conn->close();
        lc_set_flash('success', 'Product deleted successfully.');
        header('Location: ' . $list_url);
        exit;
    } catch (Exception $e) {
        lc_set_flash('error', 'DB Error: ' . $e->getMessage());
        header('Location: ' . LC_BASE . '/product_edit.php?id=' . $id . $ret_q . $back_q);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    lc_verify_csrf();
    $old_form = $form; // 변경 전 값 스냅샷
    $form['name_en']          = trim($_POST['name_en'] ?? '');
    $form['name_ko']          = trim($_POST['name_ko'] ?? '') ?: null;
    $form['capacity']         = trim($_POST['capacity'] ?? '') ?: null;
    $form['brand_id']         = (int)($_POST['brand_id'] ?? 0) ?: null;
    $form['category_id']      = (int)($_POST['category_id'] ?? 0) ?: null;
    $form['unit']             = lc_valid_unit($_POST['unit'] ?? '', LC_UNIT_PCS); // BOX/PCS 외 값은 PCS로
    $form['pieces_per_box']   = max(1, (int)($_POST['pieces_per_box'] ?? 1));
    $form['barcode_unit']     = trim($_POST['barcode_unit'] ?? '') ?: null;
    $form['barcode_box']      = trim($_POST['barcode_box'] ?? '') ?: null;
    $form['barcode_logistics']= trim($_POST['barcode_logistics'] ?? '') ?: null;
    $form['min_stock']        = max(0, (int)($_POST['min_stock'] ?? 0));
    $form['requires_expiry']  = isset($_POST['requires_expiry']) ? 1 : 0;

    if ($form['name_en'] === '') $errors[] = 'Please enter the English product name.';

    // 바코드 중복 검증 (3개 컬럼 교차 검사, 자기 자신 제외) — 다른 상품과 겹치면 저장 차단
    if (empty($errors)) {
        try {
            $conn_chk = get_lc_db();
            $conflicts = lc_find_barcode_conflicts($conn_chk, [
                $form['barcode_unit'], $form['barcode_box'], $form['barcode_logistics'],
            ], $id);
            $conn_chk->close();
            if (!empty($conflicts)) $errors[] = lc_format_barcode_conflict_msg($conflicts);
        } catch (Exception $e) {
            $errors[] = 'Barcode check error: ' . $e->getMessage();
        }
    }

    // 대표 이미지: 기존 유지 → 삭제 체크 시 제거 → 새 업로드 시 교체
    $form['image_path'] = $old_form['image_path'] ?? null;
    if (empty($errors)) {
        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if (!empty($old_form['image_path'])) lc_delete_product_image($old_form['image_path']);
            $form['image_path'] = null;
        }
        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $img_err = '';
            $new_img = lc_handle_product_image_upload($_FILES['image'], $img_err);
            if ($new_img !== null) {
                if (!empty($old_form['image_path'])) lc_delete_product_image($old_form['image_path']);
                $form['image_path'] = $new_img;
            } elseif ($img_err !== '') {
                $errors[] = $img_err;
            }
        }
    }

    if (empty($errors)) {
        try {
            $conn = get_lc_db();
            $st = $conn->prepare(
                "UPDATE lc_products SET
                 name_en=?, name_ko=?, capacity=?, brand_id=?, category_id=?, unit=?, pieces_per_box=?,
                 barcode_unit=?, barcode_box=?, barcode_logistics=?, min_stock=?, requires_expiry=?, image_path=?
                 WHERE id=?"
            );
            $st->bind_param('sssiisisssiisi',
                $form['name_en'], $form['name_ko'], $form['capacity'], $form['brand_id'], $form['category_id'],
                $form['unit'], $form['pieces_per_box'],
                $form['barcode_unit'], $form['barcode_box'], $form['barcode_logistics'],
                $form['min_stock'], $form['requires_expiry'], $form['image_path'], $id
            );
            $st->execute();

            // 변경 이력 기록
            $uid   = lc_current_user_id();
            $uname = $_SESSION['name'] ?? ($_SESSION['username'] ?? 'Unknown');

            // brand/category 이름 조회용 맵
            $brand_map = $cat_map = [];
            foreach ($brands as $b) $brand_map[$b['id']] = $b['name_en'] . ($b['name_ko'] ? ' ('.$b['name_ko'].')' : '');
            foreach ($categories as $c) $cat_map[$c['id']] = $c['name_en'] . ($c['name_ko'] ? ' ('.$c['name_ko'].')' : '');

            $tracked = [
                'name_en'          => 'English Name',
                'name_ko'          => 'Korean Name',
                'capacity'         => 'Capacity',
                'brand_id'         => 'Brand',
                'category_id'      => 'Category',
                'unit'             => 'Unit',
                'pieces_per_box'   => 'Units per Box',
                'barcode_unit'     => 'Barcode',
                'barcode_box'      => 'Box Code',
                'barcode_logistics'=> 'Logistics Code',
                'min_stock'        => 'Min Stock',
                'requires_expiry'  => 'Expiry Required',
                'image_path'       => 'Product Image',
            ];

            $sh = $conn->prepare(
                "INSERT INTO lc_product_history
                 (product_id, user_id, user_name, action, field_name, field_label, old_value, new_value)
                 VALUES (?,?,?,'update',?,?,?,?)"
            );

            foreach ($tracked as $field => $label) {
                $old_raw = $old_form[$field] ?? '';
                $new_raw = $form[$field] ?? '';

                // brand/category: ID → 이름으로 변환
                if ($field === 'brand_id') {
                    $old_raw = $old_raw ? ($brand_map[$old_raw] ?? "ID:{$old_raw}") : '';
                    $new_raw = $new_raw ? ($brand_map[$new_raw] ?? "ID:{$new_raw}") : '';
                } elseif ($field === 'category_id') {
                    $old_raw = $old_raw ? ($cat_map[$old_raw] ?? "ID:{$old_raw}") : '';
                    $new_raw = $new_raw ? ($cat_map[$new_raw] ?? "ID:{$new_raw}") : '';
                } elseif ($field === 'requires_expiry') {
                    $old_raw = $old_raw ? 'Yes' : 'No';
                    $new_raw = $new_raw ? 'Yes' : 'No';
                } elseif ($field === 'image_path') {
                    // 경로 전체 대신 등록/제거 여부로 표기
                    $old_raw = $old_raw ? basename($old_raw) : '';
                    $new_raw = $new_raw ? basename($new_raw) : '';
                }

                if ((string)$old_raw === (string)$new_raw) continue;

                $sh->bind_param('iisssss', $id, $uid, $uname, $field, $label, $old_raw, $new_raw);
                $sh->execute();
            }
            $sh->close();

            $conn->close();
            lc_set_flash('success', 'Updated successfully.');
            header('Location: ' . $list_url);
            exit;
        } catch (Exception $e) {
            $errors[] = 'DB Error: ' . $e->getMessage();
        }
    }
}
?>
<div class="flex items-center gap-3 mb-6">
    <a href="<?php echo htmlspecialchars($list_url); ?>" class="text-gray-400 hover:text-gray-600"><i class="fas fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-900">Edit Product</h2>
</div>
<?php if (!empty($errors)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
    <?php foreach ($errors as $e): ?><p class="text-sm text-red-700"><?php echo htmlspecialchars($e); ?></p><?php endforeach; ?>
</div>
<?php endif; ?>
<div class="bg-white rounded-lg border border-gray-200 p-6" style="max-width:63rem;">
    <form method="post" class="space-y-5" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
        <input type="hidden" name="ret" value="<?php echo $ret_param; ?>">
        <input type="hidden" name="back" value="<?php echo $back_param; ?>">
        <input type="hidden" name="remove_image" id="removeImageFlag" value="0">

        <div class="border-b border-gray-100 pb-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Product Name</h3>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">English Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name_en" id="prodNameEn" value="<?php echo htmlspecialchars($form['name_en']); ?>" required
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Korean Name <span class="text-xs text-gray-400 font-normal">(Korean products only)</span></label>
                    <div class="flex gap-2">
                        <input type="text" name="name_ko" id="prodNameKo" value="<?php echo htmlspecialchars($form['name_ko'] ?? ''); ?>"
                               class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <button type="button" id="prodRomanizeBtn" onclick="romanizeKoreanName()" title="한글을 영문 발음(로마자)으로 변환"
                                class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                                style="background:#f3e8ff;color:#7e22ce;">
                            발음 ▶ English
                        </button>
                        <button type="button" id="prodTranslateBtn" onclick="translateProdName()"
                                class="px-3 py-2 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap transition-colors">
                            Translate ▶ English
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3"><i class="fas fa-image text-gray-400 mr-1"></i>Product Image</h3>
            <div class="flex items-start gap-4">
                <?php $cur_img = !empty($form['image_path']) ? (LC_BASE . '/' . $form['image_path']) : ''; ?>
                <div id="imgThumbWrap" class="w-28 h-28 rounded-lg border border-gray-200 bg-gray-50 flex items-center justify-center overflow-hidden shrink-0 <?php echo $cur_img ? 'cursor-pointer' : ''; ?>"
                     <?php echo $cur_img ? 'onclick="openImageLightbox(document.getElementById(\'imgPreview\').src, \'' . htmlspecialchars(addslashes($form['name_en']), ENT_QUOTES) . '\')"' : ''; ?>>
                    <img id="imgPreview" src="<?php echo htmlspecialchars($cur_img); ?>" alt=""
                         class="w-full h-full object-cover <?php echo $cur_img ? '' : 'hidden'; ?>">
                    <span id="imgPlaceholder" class="text-gray-300 text-3xl <?php echo $cur_img ? 'hidden' : ''; ?>"><i class="fas fa-image"></i></span>
                </div>
                <div class="flex-1">
                    <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif"
                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                    <p class="text-xs text-gray-400 mt-1">JPG, PNG, WEBP, GIF · max 5MB. Click the thumbnail to enlarge.</p>
                    <button type="button" id="removeImageBtn" onclick="removeProductImage()"
                            class="mt-2 text-xs text-red-600 hover:text-red-700 <?php echo $cur_img ? '' : 'hidden'; ?>">
                        <i class="fas fa-trash-alt mr-1"></i>Remove image
                    </button>
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <div class="gap-4" style="display:grid; grid-template-columns:1fr 2fr 2fr; gap:1rem;">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Capacity</label>
                    <input type="text" name="capacity" value="<?php echo htmlspecialchars($form['capacity'] ?? ''); ?>"
                           placeholder="E.g.: 500ml, 1kg, 20ea"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="hidden" name="brand_id" id="brandIdHidden" value="<?php echo (int)$form['brand_id']; ?>">
                            <input type="text" id="brandSearch" autocomplete="off" placeholder="Search brand..."
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <div id="brandDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                <ul id="brandList" class="py-1"></ul>
                            </div>
                        </div>
                        <button type="button" onclick="openQuickCreate('brand')" title="Add New Brand"
                                class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <input type="hidden" name="category_id" id="catIdHidden" value="<?php echo (int)$form['category_id']; ?>">
                            <input type="text" id="catSearch" autocomplete="off" placeholder="Search category..."
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                            <div id="catDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                <ul id="catList" class="py-1"></ul>
                            </div>
                        </div>
                        <button type="button" onclick="openQuickCreate('category')" title="Add New Category"
                                class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 transition-colors text-sm">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                    <?php $cur_unit = $form['unit'] !== '' ? $form['unit'] : 'BOX'; ?>
                    <select name="unit"
                            class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <option value="BOX" <?php echo $cur_unit === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                        <option value="PACK" <?php echo $cur_unit === 'PACK' ? 'selected' : ''; ?>>PACK</option>
                        <option value="PCS" <?php echo $cur_unit === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                        <?php if (!in_array($cur_unit, ['BOX', 'PACK', 'PCS'], true)): ?>
                        <option value="<?php echo htmlspecialchars($cur_unit); ?>" selected><?php echo htmlspecialchars($cur_unit); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Units per Box(PKG)</label>
                    <input type="number" name="pieces_per_box" value="<?php echo $form['pieces_per_box']; ?>" min="1"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="border-b border-gray-100 pb-5">
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-barcode text-gray-400 mr-1"></i>Barcode</label>
                    <input type="text" name="barcode_unit" id="barcodeUnitInput" value="<?php echo htmlspecialchars($form['barcode_unit'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-box text-gray-400 mr-1"></i>Box Code</label>
                    <input type="text" name="barcode_box" id="barcodeBoxInput" value="<?php echo htmlspecialchars($form['barcode_box'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-warehouse text-gray-400 mr-1"></i>Logistics Code</label>
                    <input type="text" name="barcode_logistics" id="barcodeLogisticsInput" value="<?php echo htmlspecialchars($form['barcode_logistics'] ?? ''); ?>"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                </div>
            </div>
        </div>

        <div class="grid grid-cols-4 gap-4 items-start pb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Stock</label>
                <input type="number" name="min_stock" value="<?php echo $form['min_stock']; ?>" min="0"
                       class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            </div>
            <div class="col-span-3 flex items-start gap-3 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                <input type="checkbox" name="requires_expiry" id="requiresExpiry" value="1"
                       <?php echo !empty($form['requires_expiry']) ? 'checked' : ''; ?>
                       class="mt-0.5 w-4 h-4 text-orange-500 border-gray-300 rounded focus:ring-orange-400">
                <label for="requiresExpiry" class="cursor-pointer">
                    <span class="text-sm font-medium text-gray-800">Expiry Date Required</span>
                    <p class="text-xs text-gray-500 mt-0.5">When checked, expiry date must be entered when receiving this product.</p>
                </label>
            </div>
        </div>

        <div class="flex items-center justify-between pt-2">
            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
                    <i class="fas fa-save mr-2"></i>Save
                </button>
                <a href="<?php echo htmlspecialchars($list_url); ?>" class="px-6 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</a>
            </div>
            <button type="button" onclick="openDeleteModal()"
                    class="px-4 py-2 text-red-600 border border-red-200 text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">
                <i class="fas fa-trash-alt mr-1.5"></i>Delete
            </button>
        </div>
    </form>
</div>
<script>
(function() {
    // 한글 → 영문 발음(로마자) 변환: 음절을 초성/중성/종성으로 분해하여 변환
    var RR_CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    var RR_JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    var RR_JONG = ['','k','k','k','n','n','n','t','l','k','m','l','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','h'];

    function romanizeKorean(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                var cho  = Math.floor(s / 588);
                var jung = Math.floor((s % 588) / 28);
                var jong = s % 28;
                out += RR_CHO[cho] + RR_JUNG[jung] + RR_JONG[jong];
            } else {
                out += text.charAt(i);
            }
        }
        return out;
    }

    window.romanizeKoreanName = function() {
        var koInput = document.getElementById('prodNameKo');
        var enInput = document.getElementById('prodNameEn');
        var text = koInput.value.trim();
        if (!text) { koInput.focus(); return; }
        enInput.value = romanizeKorean(text).toUpperCase();
        enInput.focus();
    };

    window.translateProdName = function() {
        var koInput = document.getElementById('prodNameKo');
        var enInput = document.getElementById('prodNameEn');
        var btn = document.getElementById('prodTranslateBtn');
        var text = koInput.value.trim();
        if (!text) { koInput.focus(); return; }

        btn.textContent = 'Translating…';
        btn.disabled = true;

        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(text) + '&langpair=ko|en')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    enInput.value = data.responseData.translatedText.toUpperCase();
                    enInput.focus();
                } else {
                    alert('Translation failed: Please try again.');
                }
            })
            .catch(function() {
                alert('Cannot connect to translation service.');
            })
            .finally(function() {
                btn.textContent = 'Translate ▶ English';
                btn.disabled = false;
            });
    };
})();

window.openDeleteModal = function() {
    document.getElementById('deleteModal').classList.remove('hidden');
};
window.closeDeleteModal = function() {
    document.getElementById('deleteModal').classList.add('hidden');
};
window.confirmDelete = function() {
    document.getElementById('deleteForm').submit();
};

document.querySelector('form').addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
    e.preventDefault();
    const fields = Array.from(this.querySelectorAll('input:not([type=hidden]), select, textarea'));
    const idx = fields.indexOf(e.target);
    if (idx >= 0 && idx < fields.length - 1) fields[idx + 1].focus();
});

(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var excludeId = <?php echo (int)$id; ?>;

    window.closeDuplicateCodeModal = function() {
        document.getElementById('duplicateCodeModal').classList.add('hidden');
    };

    function showDuplicateCodeModal(product) {
        document.getElementById('duplicateCodeMessage').textContent =
            '"' + product.name + '" (' + product.fields.join(', ') + ')';
        document.getElementById('duplicateCodeViewLink').href = LC_BASE + '/product_edit.php?id=' + product.id;
        document.getElementById('duplicateCodeModal').classList.remove('hidden');
    }

    function setupDuplicateCheck(inputId) {
        var input = document.getElementById(inputId);
        var timer = null;

        function check(showModal) {
            var value = input.value.trim();
            if (!value) {
                input.classList.remove('border-red-400');
                return;
            }
            fetch(LC_BASE + '/ajax/check_product_code.php?exclude_id=' + excludeId + '&value=' + encodeURIComponent(value))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success && data.duplicate) {
                        input.classList.add('border-red-400');
                        if (showModal) showDuplicateCodeModal(data.product);
                    } else {
                        input.classList.remove('border-red-400');
                    }
                })
                .catch(function() { /* 네트워크 오류 시 검사 무시 */ });
        }

        input.addEventListener('blur', function() { check(true); });
        input.addEventListener('input', function() {
            if (timer) clearTimeout(timer);
            timer = setTimeout(function() { check(false); }, 400);
        });
    }

    setupDuplicateCheck('barcodeUnitInput');
    setupDuplicateCheck('barcodeBoxInput');
    setupDuplicateCheck('barcodeLogisticsInput');
})();
</script>
<!-- 코드 중복 알림 모달 -->
<div id="duplicateCodeModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-red-50 border-2 border-red-300 rounded-xl shadow-xl p-6 w-full max-w-sm mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-500 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-white"></i>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-red-700">Duplicate Code Detected</h3>
                <p id="duplicateCodeMessage" class="text-xs text-red-600 mt-0.5"></p>
            </div>
        </div>
        <div class="flex gap-3">
            <a id="duplicateCodeViewLink" href="#" target="_blank"
               class="flex-1 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors text-center">
                View Product
            </a>
            <button type="button" onclick="closeDuplicateCodeModal()"
                    class="flex-1 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm mx-4">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-trash-alt text-red-600"></i>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Delete Product</h3>
                <p class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($form['name_en']); ?></p>
            </div>
        </div>
        <p class="text-sm text-gray-600 mb-1">Permanently delete this product?</p>
        <p class="text-xs text-gray-400 mb-5">Cannot delete if inbound/inventory/order history exists.</p>
        <div class="flex gap-3">
            <button type="button" onclick="confirmDelete()"
                    class="flex-1 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                Delete
            </button>
            <button type="button" onclick="closeDeleteModal()"
                    class="flex-1 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                Cancel
            </button>
        </div>
    </div>
</div>
<form id="deleteForm" method="post">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="ret" value="<?php echo $ret_param; ?>">
    <input type="hidden" name="back" value="<?php echo $back_param; ?>">
</form>

<?php
// 변경 이력 조회
$history = [];
try {
    $conn = get_lc_db();
    $sh = $conn->prepare(
        "SELECT action, field_label, old_value, new_value, user_name, changed_at
         FROM lc_product_history
         WHERE product_id = ?
         ORDER BY changed_at DESC
         LIMIT 50"
    );
    $sh->bind_param('i', $id);
    $sh->execute();
    $history = $sh->get_result()->fetch_all(MYSQLI_ASSOC);
    $sh->close();
    $conn->close();
} catch (Exception $e) { /* 테이블 미생성 시 무시 */ }
?>

<?php if (!empty($history)): ?>
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden mt-5" style="max-width:63rem;">
    <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
        <i class="fas fa-history text-gray-400 text-sm"></i>
        <span class="text-sm font-semibold text-gray-700">Change History</span>
        <span class="ml-auto text-xs text-gray-400"><?php echo count($history); ?> item(s)</span>
    </div>
    <div class="divide-y divide-gray-50">
    <?php foreach ($history as $h): ?>
        <div class="px-4 py-3 flex items-start gap-3">
            <!-- 아이콘 -->
            <div class="mt-0.5 flex-shrink-0">
                <?php if ($h['action'] === 'create'): ?>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-teal-100">
                    <i class="fas fa-plus text-teal-600 text-xs"></i>
                </span>
                <?php else: ?>
                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-100">
                    <i class="fas fa-pen text-blue-600 text-xs"></i>
                </span>
                <?php endif; ?>
            </div>
            <!-- 내용 -->
            <div class="flex-1 min-w-0">
                <?php if ($h['action'] === 'create'): ?>
                <p class="text-sm text-gray-700">
                    <span class="font-medium"><?php echo htmlspecialchars($h['user_name']); ?></span>
                    registered this product.
                </p>
                <?php else: ?>
                <p class="text-sm text-gray-700">
                    <span class="font-medium"><?php echo htmlspecialchars($h['user_name']); ?></span>
                    changed <span class="font-medium text-gray-900"><?php echo htmlspecialchars($h['field_label']); ?></span>.
                </p>
                <div class="flex items-center gap-2 mt-1 text-xs">
                    <span class="px-2 py-0.5 bg-red-50 text-red-600 rounded line-through"><?php echo htmlspecialchars($h['old_value'] ?: '(none)'); ?></span>
                    <i class="fas fa-arrow-right text-gray-400"></i>
                    <span class="px-2 py-0.5 bg-green-50 text-green-700 rounded font-medium"><?php echo htmlspecialchars($h['new_value'] ?: '(none)'); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <!-- 시각 -->
            <div class="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap">
                <?php echo date('Y-m-d H:i', strtotime($h['changed_at'])); ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/inline_edit_widget.php'; // Design Ref: §5.5 ?>

<!-- Design Ref: §5.5 — 브랜드/카테고리 커스텀 검색 위젯 (네이티브 select 대체) + 인라인 수정 -->
<script>
(function() {
    var _brands = <?php echo json_encode(array_values($brands), JSON_UNESCAPED_UNICODE); ?>;
    var _cats   = <?php echo json_encode(array_values($categories), JSON_UNESCAPED_UNICODE); ?>;
    var CSRF    = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    function makeSearchWidget(cfg) {
        var data = cfg.data;
        var searchEl = document.getElementById(cfg.searchId);
        var hiddenEl = document.getElementById(cfg.hiddenId);
        var dropEl   = document.getElementById(cfg.dropdownId);
        var listEl   = document.getElementById(cfg.listId);
        var _selId = '', _focusIdx = -1, _filtered = [];
        function lbl(item) { return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : ''); }
        function highlight(idx) {
            var lis = listEl.querySelectorAll('li[data-s]');
            Array.prototype.forEach.call(lis, function(li, i) {
                li.style.backgroundColor = (i === idx) ? '#ccfbf1' : '';
                li.style.color           = (i === idx) ? '#0f766e' : '';
                li.style.fontWeight      = (i === idx) ? '600'     : '';
            });
            _focusIdx = idx;
            if (lis[idx]) lis[idx].scrollIntoView({ block: 'nearest' });
        }
        function render(filter) {
            listEl.innerHTML = ''; _focusIdx = -1;
            var q = (filter || '').toLowerCase();
            _filtered = data.filter(function(i) { return !q || lbl(i).toLowerCase().indexOf(q) !== -1; });
            if (!_filtered.length) { listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">No results</li>'; return; }
            _filtered.forEach(function(item) {
                var li = document.createElement('li');
                li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
                li.dataset.s = '1';
                li.addEventListener('mousedown', function(e) { e.preventDefault(); select(item.id, lbl(item)); });
                // Design Ref: §5.5 — 항목별 인라인 수정 (연필) 부착
                LcInlineEdit.attach({
                    li: li, item: item, type: cfg.type, csrf: CSRF,
                    onSaved: function(u) {
                        if (_selId === String(u.id)) { searchEl.value = lbl(u); hiddenEl.value = u.id; } // Plan SC-2
                        render(searchEl.value);
                    }
                });
                listEl.appendChild(li);
            });
        }
        function select(id, label) { _selId = String(id); hiddenEl.value = id; searchEl.value = label; dropEl.classList.add('hidden'); }
        searchEl.addEventListener('focus', function() { render(searchEl.value); dropEl.classList.remove('hidden'); });
        searchEl.addEventListener('input', function() { _selId = ''; hiddenEl.value = ''; render(searchEl.value); dropEl.classList.remove('hidden'); });
        searchEl.addEventListener('blur', function() { setTimeout(function() { if (window.LcInlineEdit && window.LcInlineEdit.editing) return; dropEl.classList.add('hidden'); if (!_selId) { searchEl.value = ''; hiddenEl.value = ''; } }, 150); });
        searchEl.addEventListener('keydown', function(e) {
            var open = !dropEl.classList.contains('hidden');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!open) { render(searchEl.value); dropEl.classList.remove('hidden'); }
                if (_filtered.length) highlight(Math.min(_focusIdx + 1, _filtered.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (_focusIdx > 0) highlight(_focusIdx - 1);
            } else if (e.key === 'Enter' && open) {
                e.preventDefault(); e.stopPropagation();
                if (_focusIdx >= 0 && _filtered[_focusIdx]) select(_filtered[_focusIdx].id, lbl(_filtered[_focusIdx]));
                else dropEl.classList.add('hidden');
            } else if (e.key === 'Escape') {
                dropEl.classList.add('hidden');
            }
        });
        return { reset: function() { _selId=''; _focusIdx=-1; _filtered=[]; hiddenEl.value=''; searchEl.value=''; dropEl.classList.add('hidden'); }, select: select, data: data };
    }

    window._brandWidget = makeSearchWidget({ data: _brands, type: 'brand',    searchId: 'brandSearch', hiddenId: 'brandIdHidden', dropdownId: 'brandDropdown', listId: 'brandList' });
    window._catWidget   = makeSearchWidget({ data: _cats,   type: 'category', searchId: 'catSearch',   hiddenId: 'catIdHidden',   dropdownId: 'catDropdown',   listId: 'catList'   });

    window.onQuickCreateSuccess = function(type, id, nameEn, nameKo) {
        var label = nameEn + (nameKo ? ' (' + nameKo + ')' : '');
        if (type === 'brand') { _brands.push({id:id,name_en:nameEn,name_ko:nameKo}); window._brandWidget.select(id, label); }
        else                  { _cats.push({id:id,name_en:nameEn,name_ko:nameKo});   window._catWidget.select(id, label); }
    };

    // 현재 저장된 brand/category 미리 선택
    <?php if ($form['brand_id']): ?>
    (function() { var b = _brands.find(function(x){return x.id==<?php echo (int)$form['brand_id']; ?>;});
        if(b) window._brandWidget.select(b.id, b.name_en+(b.name_ko?' ('+b.name_ko+')':'')); })();
    <?php endif; ?>
    <?php if ($form['category_id']): ?>
    (function() { var c = _cats.find(function(x){return x.id==<?php echo (int)$form['category_id']; ?>;});
        if(c) window._catWidget.select(c.id, c.name_en+(c.name_ko?' ('+c.name_ko+')':'')); })();
    <?php endif; ?>
})();
</script>

<!-- 이미지 미리보기 / 삭제 / 라이트박스 -->
<div id="imageLightbox" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4" onclick="closeImageLightbox()">
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden flex flex-col border-2 border-gray-300 ring-1 ring-black/5" style="width:26rem;max-width:92vw;" onclick="event.stopPropagation()">
        <!-- 헤더: 제목 + 닫기 -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
            <span id="lightboxTitle" class="text-sm font-semibold text-gray-800 truncate pr-2"></span>
            <button type="button" onclick="closeImageLightbox()" title="Close"
                    class="text-gray-400 hover:text-gray-700 text-lg flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- 고정 크기 이미지 영역 -->
        <div class="bg-gray-50 flex items-center justify-center" style="height:22rem;">
            <img id="lightboxImg" src="" alt="" class="max-w-full max-h-full object-contain">
        </div>
        <!-- 푸터: 닫기 버튼 -->
        <div class="px-4 py-3 border-t border-gray-100 flex justify-end">
            <button type="button" onclick="closeImageLightbox()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                Close
            </button>
        </div>
    </div>
</div>
<script>
(function() {
    var fileInput   = document.getElementById('imageInput');
    var preview     = document.getElementById('imgPreview');
    var placeholder = document.getElementById('imgPlaceholder');
    var removeBtn   = document.getElementById('removeImageBtn');
    var removeFlag  = document.getElementById('removeImageFlag');
    var thumbWrap   = document.getElementById('imgThumbWrap');

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            var f = this.files && this.files[0];
            if (!f) return;
            var url = URL.createObjectURL(f);
            preview.src = url;
            preview.classList.remove('hidden');
            placeholder.classList.add('hidden');
            removeBtn.classList.remove('hidden');
            removeFlag.value = '0'; // 새 파일 선택 시 삭제 의사 취소
            thumbWrap.classList.add('cursor-pointer');
        });
    }

    window.removeProductImage = function() {
        if (fileInput) fileInput.value = '';
        preview.src = '';
        preview.classList.add('hidden');
        placeholder.classList.remove('hidden');
        removeBtn.classList.add('hidden');
        removeFlag.value = '1';
        thumbWrap.classList.remove('cursor-pointer');
    };

    window.openImageLightbox = function(src, alt) {
        if (!src) return;
        var img = document.getElementById('lightboxImg');
        img.src = src; img.alt = alt || '';
        document.getElementById('lightboxTitle').textContent = alt || '';
        document.getElementById('imageLightbox').classList.remove('hidden');
    };
    window.closeImageLightbox = function() {
        document.getElementById('imageLightbox').classList.add('hidden');
        document.getElementById('lightboxImg').src = '';
    };
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImageLightbox();
    });
})();
</script>

<?php require_once __DIR__ . '/partials/modal_brand_cat.php'; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
