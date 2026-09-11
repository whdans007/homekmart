<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.edit_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';
require_once __DIR__ . '/../lib/fresh_margin_helper.php';

if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    $conn->close();
    fresh_admin_flash('error', '수정할 신선상품을 찾을 수 없습니다.');
    fresh_admin_redirect('fresh_products.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $code = trim($_POST['code'] ?? '');
        $nameKo = trim($_POST['name_ko'] ?? '');
        $nameEn = trim($_POST['name_en'] ?? '');
        $freshCategory = $_POST['fresh_category'] ?? '';
        $productMode = $_POST['product_mode'] ?? 'weight';
        $boxUnit = $_POST['box_unit'] ?? 'kg';
        $saleType = $productMode === 'box' && in_array($boxUnit, ['pcs', 'pack'], true) ? 'piece' : 'weight';
        $unitType = $productMode === 'weight' ? 'kg' : $boxUnit;
        $priceRaw = trim($_POST['price_per_100g'] ?? '');
        $boxSalePriceRaw = trim($_POST['box_sale_price'] ?? '');
        $pkgWeightRaw = trim($_POST['pkg_weight_kg'] ?? '');
        $pkgPiecesRaw = trim($_POST['pkg_pieces_per_box'] ?? '');
        $status = $_POST['status'] ?? 'active';

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
        if (!in_array($productMode, ['weight', 'box'], true) || !in_array($boxUnit, ['kg', 'pcs', 'pack'], true)) {
            throw new InvalidArgumentException('상품 형태와 BOX 기준 단위를 확인해 주세요.');
        }
        if ($productMode === 'weight') {
            $pkgWeightRaw = '';
            $pkgPiecesRaw = '';
        } elseif ($boxUnit === 'kg') {
            $pkgPiecesRaw = '';
            if ($pkgWeightRaw === '' || !is_numeric($pkgWeightRaw) || (float)$pkgWeightRaw <= 0) {
                throw new InvalidArgumentException('BOX당 무게(kg)를 입력해 주세요.');
            }
        } else {
            $pkgWeightRaw = '';
            if ($pkgPiecesRaw === '' || !ctype_digit($pkgPiecesRaw) || (int)$pkgPiecesRaw <= 0) {
                throw new InvalidArgumentException('BOX당 개수를 입력해 주세요.');
            }
        }
        $price = (float)$priceRaw;
        if ($price < 0 || !in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('판매가 또는 상태 값을 확인해 주세요.');
        }
        if ($boxSalePriceRaw !== '' && (!is_numeric($boxSalePriceRaw) || (float)$boxSalePriceRaw < 0)) {
            throw new InvalidArgumentException('박스판매가를 올바르게 입력해 주세요.');
        }
        if (($pkgWeightRaw !== '' && (!is_numeric($pkgWeightRaw) || (float)$pkgWeightRaw < 0))
            || ($pkgPiecesRaw !== '' && (!ctype_digit($pkgPiecesRaw) || (int)$pkgPiecesRaw < 0))) {
            throw new InvalidArgumentException('박스당 무게와 개수를 올바르게 입력해 주세요.');
        }
        $unitStep = 100;
        $nameEnValue = $nameEn === '' ? null : $nameEn;
        $pkgWeightKg = $pkgWeightRaw === '' ? null : (float)$pkgWeightRaw;
        $pkgPiecesPerBox = $pkgPiecesRaw === '' ? null : (int)$pkgPiecesRaw;
        $boxSalePrice = $boxSalePriceRaw === '' ? null : (float)$boxSalePriceRaw;

        $stmt = $conn->prepare('UPDATE mall_fresh_products SET code = ?, name_ko = ?, name_en = ?, fresh_category = ?, sale_type = ?, unit_step_g = ?, price_per_100g = ?, box_sale_price = ?, pkg_weight_kg = ?, pkg_pieces_per_box = ?, unit_type = ?, status = ? WHERE id = ?');
        $stmt->bind_param('sssssidddissi', $code, $nameKo, $nameEnValue, $freshCategory, $saleType, $unitStep, $price, $boxSalePrice, $pkgWeightKg, $pkgPiecesPerBox, $unitType, $status, $id);
        $stmt->execute();
        $stmt->close();
        $conn->close();
        fresh_admin_flash('success', t('mall_fresh_products.edit_success'));
        fresh_admin_redirect('fresh_products.php');
    } catch (mysqli_sql_exception $e) {
        error_log('edit_fresh_product.php error: ' . $e->getMessage());
        $errorMessage = ((int)$e->getCode() === 1062)
            ? t('mall_fresh_products.duplicate_code')
            : t('mall_fresh_products.save_failed');
    } catch (InvalidArgumentException $e) {
        $errorMessage = $e->getMessage();
    }
}

$stmt = $conn->prepare('SELECT id, code, name_ko, name_en, fresh_category, sale_type, unit_step_g, price_per_100g, box_sale_price, pkg_weight_kg, pkg_pieces_per_box, unit_type, status FROM mall_fresh_products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    $conn->close();
    fresh_admin_flash('error', '수정할 신선상품을 찾을 수 없습니다.');
    fresh_admin_redirect('fresh_products.php');
}

// $_POST 값이 있으면(저장 실패 후 재표시) 그 값을 우선 사용, 없으면 DB 값 사용
$formValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $product;

// 최근 매입 원가 + 마진 기반 제안가 (매입 이력이 있어야 값이 나옴)
$stmt = $conn->prepare(
    "SELECT fpi.weight_kg, fpi.pieces_per_box, fpi.total_cost, fpi.unit_cost_per_100g, fpi.unit_cost_per_piece,
            fpi.box_cost, fpi.purchase_date, s.name AS store_name
     FROM fresh_purchase_items fpi
     JOIN stores s ON s.id = fpi.store_id
     WHERE fpi.mall_fresh_product_id = ?
     ORDER BY fpi.purchase_date DESC, fpi.id DESC
     LIMIT 1"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$latestCost = $stmt->get_result()->fetch_assoc();
$stmt->close();

$suggestedPrice = null;
$suggestedBoxPrice = null;
$marginRate = null;
$latestUnitCost = null;
if ($latestCost) {
    $latestUnitCost = $product['sale_type'] === 'piece' ? $latestCost['unit_cost_per_piece'] : $latestCost['unit_cost_per_100g'];
    $marginRate = get_fresh_margin_rate($product['fresh_category'], $conn);
    $suggestedPrice = calculate_fresh_sale_price((float)$latestUnitCost, $marginRate);
    $suggestedBoxPrice = calculate_fresh_sale_price((float)$latestCost['box_cost'], $marginRate);
}
$conn->close();

function fresh_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$curProductMode = $formValues['product_mode'] ?? (($formValues['sale_type'] ?? 'weight') === 'piece' || !empty($formValues['pkg_weight_kg']) || !empty($formValues['pkg_pieces_per_box']) ? 'box' : 'weight');
$curBoxUnit = $formValues['box_unit'] ?? ($formValues['unit_type'] ?? (($formValues['sale_type'] ?? 'weight') === 'piece' ? 'pcs' : 'kg'));
$curSaleType = $curProductMode === 'box' && in_array($curBoxUnit, ['pcs', 'pack'], true) ? 'piece' : 'weight';
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-apple-whole mr-2"></i><?php echo fresh_h(t('mall_fresh_products.edit_title')); ?></h1>
        <a href="fresh_products.php" class="text-sm text-gray-600"><i class="fas fa-arrow-left mr-1"></i><?php echo fresh_h(t('mall_fresh_products.master_management')); ?></a>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border bg-red-50 text-red-700 border-red-200"><?php echo fresh_h($errorMessage); ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-lg border border-gray-200 p-4 max-w-3xl">
        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4" id="fresh-product-form">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.code')); ?> *</label>
                <div class="flex gap-1">
                    <input id="code-input" name="code" required maxlength="50" value="<?php echo fresh_h($formValues['code'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono">
                    <button type="button" id="btn-generate-code" class="px-2 py-2 text-xs bg-blue-700 text-white rounded-md whitespace-nowrap hover:bg-blue-800" style="background-color:#1d4ed8 !important;color:#ffffff !important;"><?php echo fresh_h(t('mall_fresh_products.auto_generate_code')); ?></button>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.name_ko')); ?> *</label>
                <div class="flex gap-1">
                    <input id="name-ko-input" name="name_ko" required maxlength="255" value="<?php echo fresh_h($formValues['name_ko'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <button type="button" id="btn-translate" class="px-2 py-2 text-xs bg-blue-700 text-white rounded-md whitespace-nowrap hover:bg-blue-800" style="background-color:#1d4ed8 !important;color:#ffffff !important;"><?php echo fresh_h(t('product.translate')); ?></button>
                </div>
            </div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.name_en')); ?></label><input id="name-en-input" name="name_en" maxlength="255" value="<?php echo fresh_h($formValues['name_en'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.category_label')); ?> *</label>
                <select name="fresh_category" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    <option value=""><?php echo fresh_h(t('common.select')); ?></option>
                    <?php foreach (fresh_category_options() as $code => $label): ?>
                        <option value="<?php echo fresh_h($code); ?>" <?php echo ($formValues['fresh_category'] ?? '') === $code ? 'selected' : ''; ?>><?php echo fresh_h($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.product_mode_label')); ?> *</label>
                <div class="flex gap-3 items-center h-[38px]">
                    <label class="flex items-center gap-1 text-sm"><input type="radio" name="product_mode" value="weight" id="product-mode-weight" <?php echo $curProductMode === 'weight' ? 'checked' : ''; ?>> <?php echo fresh_h(t('mall_fresh_products.product_mode_weight')); ?></label>
                    <label class="flex items-center gap-1 text-sm"><input type="radio" name="product_mode" value="box" id="product-mode-box" <?php echo $curProductMode === 'box' ? 'checked' : ''; ?>> <?php echo fresh_h(t('mall_fresh_products.product_mode_box')); ?></label>
                </div>
            </div>
            <div id="box-unit-row">
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.box_unit_label')); ?> *</label>
                <div class="flex gap-3 items-center h-[38px]">
                    <label class="flex items-center gap-1 text-sm"><input type="radio" name="box_unit" value="kg" id="box-unit-kg" <?php echo $curBoxUnit === 'kg' ? 'checked' : ''; ?>> /kg</label>
                    <label class="flex items-center gap-1 text-sm"><input type="radio" name="box_unit" value="pcs" id="box-unit-pcs" <?php echo $curBoxUnit === 'pcs' ? 'checked' : ''; ?>> /pcs</label>
                    <label class="flex items-center gap-1 text-sm"><input type="radio" name="box_unit" value="pack" id="box-unit-pack" <?php echo $curBoxUnit === 'pack' ? 'checked' : ''; ?>> /pack</label>
                </div>
            </div>
            <div id="pkg-weight-row"><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.box_weight_kg_label')); ?></label><input type="number" step="0.001" min="0" name="pkg_weight_kg" value="<?php echo fresh_h($formValues['pkg_weight_kg'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div id="pkg-pieces-row"><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.pieces_per_box_label')); ?></label><input type="number" step="1" min="0" name="pkg_pieces_per_box" value="<?php echo fresh_h($formValues['pkg_pieces_per_box'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1" id="price-label"><?php echo fresh_h($curSaleType === 'piece' ? t('mall_fresh_products.price_label_piece') : t('mall_fresh_products.price_label_weight')); ?> *</label>
                <input id="price-input" name="price_per_100g" type="number" min="0" step="0.01" required value="<?php echo fresh_h($formValues['price_per_100g'] ?? '0.00'); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.box_sale_price_label')); ?></label>
                <input id="box-sale-price-input" name="box_sale_price" type="number" min="0" step="0.01" value="<?php echo fresh_h($formValues['box_sale_price'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
            </div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('common.status')); ?></label><select name="status" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="active" <?php echo ($formValues['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.active')); ?></option><option value="inactive" <?php echo ($formValues['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.inactive')); ?></option></select></div>

            <div class="md:col-span-2 rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm">
                <div class="font-semibold text-blue-900 mb-1"><i class="fas fa-coins mr-1"></i><?php echo fresh_h(t('mall_fresh_products.latest_cost_title')); ?></div>
                <?php if ($latestCost): ?>
                    <div class="text-blue-900">
                        <?php echo fresh_h(t('mall_fresh_products.total_cost_label')); ?>: <b><?php echo number_format((float)$latestCost['total_cost'], 2); ?></b>
                        &nbsp;·&nbsp;
                        <?php echo fresh_h($curSaleType === 'piece' ? t('mall_fresh_products.unit_cost_per_piece_label') : t('mall_fresh_products.unit_cost_result_label')); ?>: <b><?php echo number_format((float)$latestUnitCost, 2); ?></b>
                        (<?php echo fresh_h(t('mall_fresh_products.latest_cost_info', ['store' => $latestCost['store_name'], 'date' => $latestCost['purchase_date']])); ?>)
                    </div>
                    <div class="text-blue-900 mt-1 flex items-center gap-2">
                        <?php echo fresh_h(t('mall_fresh_products.suggested_price_label', ['rate' => number_format((float)$marginRate, 1)])); ?>: <b><?php echo number_format((float)$suggestedPrice, 2); ?></b>
                        <button type="button" id="btn-apply-suggested" data-suggested="<?php echo (float)$suggestedPrice; ?>" class="px-2 py-1 text-xs bg-blue-600 text-white rounded"><?php echo fresh_h(t('mall_fresh_products.apply_suggested_price')); ?></button>
                    </div>
                    <div class="text-blue-900 mt-1 flex items-center gap-2">
                        <?php echo fresh_h(t('mall_fresh_products.suggested_box_price_label', ['rate' => number_format((float)$marginRate, 1)])); ?>: <b><?php echo number_format((float)$suggestedBoxPrice, 2); ?></b>
                        <button type="button" id="btn-apply-suggested-box" data-suggested="<?php echo (float)$suggestedBoxPrice; ?>" class="px-2 py-1 text-xs bg-blue-600 text-white rounded"><?php echo fresh_h(t('mall_fresh_products.apply_suggested_box_price')); ?></button>
                    </div>
                <?php else: ?>
                    <div class="text-blue-800"><?php echo fresh_h(t('mall_fresh_products.latest_cost_none')); ?></div>
                <?php endif; ?>
            </div>

            <div class="md:col-span-2 flex gap-2 pt-2">
                <button class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-md"><?php echo fresh_h(t('mall_fresh_products.save_edit')); ?></button>
                <a href="fresh_products.php" class="px-4 py-2 text-sm border border-gray-300 rounded-md"><?php echo fresh_h(t('common.cancel')); ?></a>
            </div>
        </form>
    </section>
</div>
<script>
const FRESH_I18N = {
    priceLabelWeight: <?php echo json_encode(t('mall_fresh_products.price_label_weight')); ?>,
    priceLabelPiece: <?php echo json_encode(t('mall_fresh_products.price_label_piece')); ?>,
    priceLabelPack: <?php echo json_encode(t('mall_fresh_products.price_label_pack')); ?>,
    generatingCode: <?php echo json_encode(t('mall_fresh_products.generating_code')); ?>,
    autoGenerateCode: <?php echo json_encode(t('mall_fresh_products.auto_generate_code')); ?>,
    codeGenerateFailed: <?php echo json_encode(t('mall_fresh_products.code_generate_failed')); ?>,
    enterKoreanName: <?php echo json_encode(t('product.enter_korean_name')); ?>,
    translating: <?php echo json_encode(t('product.translating')); ?>,
    translationFailed: <?php echo json_encode(t('product.translation_failed')); ?>,
    translationError: <?php echo json_encode(t('product.translation_error')); ?>,
    translateLabel: <?php echo json_encode(t('product.translate')); ?>
};

function syncSaleTypeUI() {
    const isBox = document.getElementById('product-mode-box').checked;
    const isPiece = isBox && document.getElementById('box-unit-pcs').checked;
    const isPack = isBox && document.getElementById('box-unit-pack').checked;
    document.getElementById('price-label').textContent = (isPack ? FRESH_I18N.priceLabelPack : (isPiece ? FRESH_I18N.priceLabelPiece : FRESH_I18N.priceLabelWeight)) + ' *';
    document.getElementById('box-unit-row').style.display = isBox ? '' : 'none';
    document.getElementById('pkg-weight-row').style.display = isBox && !isPiece && !isPack ? '' : 'none';
    document.getElementById('pkg-pieces-row').style.display = isPiece || isPack ? '' : 'none';
}
['product-mode-weight', 'product-mode-box', 'box-unit-kg', 'box-unit-pcs', 'box-unit-pack'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', syncSaleTypeUI);
});
syncSaleTypeUI();

document.getElementById('btn-generate-code').addEventListener('click', function () {
    const btn = this;
    btn.disabled = true;
    btn.textContent = FRESH_I18N.generatingCode;
    fetch('ajax_generate_fresh_code.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.ok) {
                document.getElementById('code-input').value = data.code;
            } else {
                alert(FRESH_I18N.codeGenerateFailed + ': ' + (data.error || ''));
            }
        })
        .catch(function () { alert(FRESH_I18N.codeGenerateFailed); })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = FRESH_I18N.autoGenerateCode;
        });
});

document.getElementById('btn-translate').addEventListener('click', function () {
    const text = document.getElementById('name-ko-input').value;
    if (!text.trim()) {
        alert(FRESH_I18N.enterKoreanName);
        return;
    }
    const btn = this;
    btn.disabled = true;
    btn.textContent = FRESH_I18N.translating;
    fetch('ajax_translate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'text=' + encodeURIComponent(text) + '&target_lang=EN'
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                document.getElementById('name-en-input').value = data.translated_text;
            } else {
                alert(FRESH_I18N.translationFailed + ': ' + data.message);
            }
        })
        .catch(function () { alert(FRESH_I18N.translationError); })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = FRESH_I18N.translateLabel;
        });
});

const applySuggestedBtn = document.getElementById('btn-apply-suggested');
if (applySuggestedBtn) {
    applySuggestedBtn.addEventListener('click', function () {
        document.getElementById('price-input').value = this.dataset.suggested;
    });
}

const applySuggestedBoxBtn = document.getElementById('btn-apply-suggested-box');
if (applySuggestedBoxBtn) {
    applySuggestedBoxBtn.addEventListener('click', function () {
        document.getElementById('box-sale-price-input').value = this.dataset.suggested;
    });
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
