<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/fresh_product_common.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'fresh_products.php';
$conn = get_db_connection();
$categories = fresh_admin_categories($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        fresh_admin_flash('error', t('mall_fresh_products.request_expired'));
        fresh_admin_redirect('fresh_products.php');
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $code = trim($_POST['code'] ?? '');
            $nameKo = trim($_POST['name_ko'] ?? '');
            $nameEn = trim($_POST['name_en'] ?? '');
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $priceRaw = trim($_POST['price_per_100g'] ?? '');
            $status = $_POST['status'] ?? '';

            if ($code === '' || $nameKo === '' || $priceRaw === '' || !is_numeric($priceRaw)) {
                throw new InvalidArgumentException('코드, 한글 상품명, 100g당 판매가를 올바르게 입력해 주세요.');
            }
            if (strlen($code) > 50 || mb_strlen($nameKo) > 255 || mb_strlen($nameEn) > 255) {
                throw new InvalidArgumentException('입력값이 허용 길이를 초과했습니다.');
            }
            if (!fresh_admin_valid_category($categories, $categoryId)) {
                throw new InvalidArgumentException('신선식품 카테고리를 선택해 주세요.');
            }
            $price = (float)$priceRaw;
            if ($price < 0 || !in_array($status, ['active', 'inactive'], true)) {
                throw new InvalidArgumentException('판매가 또는 상태 값을 확인해 주세요.');
            }
            $unitStep = 100;
            $nameEnValue = $nameEn === '' ? null : $nameEn;

            if ($id > 0) {
                $stmt = $conn->prepare('UPDATE mall_fresh_products SET code = ?, name_ko = ?, name_en = ?, category_id = ?, unit_step_g = ?, price_per_100g = ?, status = ? WHERE id = ?');
                $stmt->bind_param('sssiidsi', $code, $nameKo, $nameEnValue, $categoryId, $unitStep, $price, $status, $id);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $check = $conn->prepare('SELECT id FROM mall_fresh_products WHERE id = ?');
                    $check->bind_param('i', $id);
                    $check->execute();
                    $exists = (bool)$check->get_result()->fetch_assoc();
                    $check->close();
                    if (!$exists) {
                        throw new InvalidArgumentException('수정할 신선상품을 찾을 수 없습니다.');
                    }
                }
                $stmt->close();
                fresh_admin_flash('success', t('mall_fresh_products.edit_success'));
            } else {
                $stmt = $conn->prepare('INSERT INTO mall_fresh_products (code, name_ko, name_en, category_id, unit_step_g, price_per_100g, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssiids', $code, $nameKo, $nameEnValue, $categoryId, $unitStep, $price, $status);
                $stmt->execute();
                $stmt->close();
                fresh_admin_flash('success', t('mall_fresh_products.register_success'));
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('삭제할 신선상품을 선택해 주세요.');
            }
            $stmt = $conn->prepare('DELETE FROM mall_fresh_products WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new InvalidArgumentException('삭제할 신선상품을 찾을 수 없습니다.');
            }
            $stmt->close();
            fresh_admin_flash('success', t('mall_fresh_products.delete_success'));
        } else {
            throw new InvalidArgumentException('지원하지 않는 작업입니다.');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('fresh_products.php write error: ' . $e->getMessage());
        $message = ((int)$e->getCode() === 1062)
            ? t('mall_fresh_products.duplicate_code')
            : t('mall_fresh_products.save_failed');
        fresh_admin_flash('error', $message);
    } catch (InvalidArgumentException $e) {
        fresh_admin_flash('error', $e->getMessage());
    }
    $conn->close();
    fresh_admin_redirect('fresh_products.php');
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['', 'active', 'inactive'], true)) {
    $statusFilter = '';
}
$categoryFilter = (int)($_GET['category_id'] ?? 0);
$like = '%' . $search . '%';
$stmt = $conn->prepare(
    "SELECT fp.id, fp.code, fp.name_ko, fp.name_en, fp.category_id, fp.unit_step_g,
            fp.price_per_100g, fp.status, c.name AS category_name
     FROM mall_fresh_products fp
     LEFT JOIN categories c ON c.id = fp.category_id
     WHERE (? = '' OR fp.code LIKE ? OR fp.name_ko LIKE ? OR COALESCE(fp.name_en, '') LIKE ?)
       AND (? = '' OR fp.status = ?)
       AND (? = 0 OR fp.category_id = ?)
     ORDER BY fp.id DESC"
);
$stmt->bind_param('ssssssii', $search, $like, $like, $like, $statusFilter, $statusFilter, $categoryFilter, $categoryFilter);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$editProduct = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT id, code, name_ko, name_en, category_id, unit_step_g, price_per_100g, status FROM mall_fresh_products WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editProduct = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$conn->close();
$flash = fresh_admin_take_flash();

function fresh_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo fresh_h(t('mall_fresh_products.master_management')); ?> - HOME K MART</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-apple-whole mr-2"></i><?php echo fresh_h(t('mall_fresh_products.master_management')); ?></h1>
        <a href="fresh_product_links.php" class="px-3 py-2 text-xs font-semibold bg-emerald-600 text-white rounded-md"><i class="fas fa-link mr-1"></i><?php echo fresh_h(t('mall_fresh_products.mapping_link')); ?></a>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'; ?>"><?php echo fresh_h($flash['message']); ?></div>
    <?php endif; ?>
    <?php if (empty($categories)): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border bg-amber-50 text-amber-800 border-amber-200"><?php echo fresh_h(t('mall_fresh_products.category_missing_notice')); ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-lg border border-gray-200 p-4 mb-5">
        <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo fresh_h($editProduct ? t('mall_fresh_products.edit_title') : t('mall_fresh_products.register_title')); ?></h2>
        <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <?php echo mall_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int)($editProduct['id'] ?? 0); ?>">
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.code')); ?> *</label><input name="code" required maxlength="50" value="<?php echo fresh_h($editProduct['code'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.name_ko')); ?> *</label><input name="name_ko" required maxlength="255" value="<?php echo fresh_h($editProduct['name_ko'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.name_en')); ?></label><input name="name_en" maxlength="255" value="<?php echo fresh_h($editProduct['name_en'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.category')); ?> *</label><select name="category_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"><option value=""><?php echo fresh_h(t('common.select')); ?></option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo (int)($editProduct['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : ''; ?>><?php echo ((int)$category['depth'] ? '└ ' : '') . fresh_h($category['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.unit_step_g')); ?></label><input value="100" readonly class="w-full bg-gray-100 border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-600"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.price_per_100g')); ?> *</label><input name="price_per_100g" type="number" min="0" step="0.01" required value="<?php echo fresh_h($editProduct['price_per_100g'] ?? '0.00'); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('common.status')); ?></label><select name="status" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="active" <?php echo ($editProduct['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.active')); ?></option><option value="inactive" <?php echo ($editProduct['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.inactive')); ?></option></select></div>
            <div class="flex gap-2"><button <?php echo empty($categories) ? 'disabled' : ''; ?> class="px-4 py-2 text-sm font-semibold bg-blue-600 disabled:bg-gray-300 text-white rounded-md"><?php echo fresh_h($editProduct ? t('mall_fresh_products.save_edit') : t('mall_fresh_products.register')); ?></button><?php if ($editProduct): ?><a href="fresh_products.php" class="px-4 py-2 text-sm border border-gray-300 rounded-md"><?php echo fresh_h(t('common.cancel')); ?></a><?php endif; ?></div>
        </form>
    </section>

    <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <form method="get" class="p-4 border-b border-gray-200 flex gap-2 flex-wrap">
            <input name="q" value="<?php echo fresh_h($search); ?>" placeholder="<?php echo fresh_h(t('mall_fresh_products.search_placeholder')); ?>" class="border border-gray-300 rounded-md px-3 py-2 text-sm min-w-64">
            <select name="category_id" class="border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="0"><?php echo fresh_h(t('mall_fresh_products.all_categories')); ?></option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo $categoryFilter === (int)$category['id'] ? 'selected' : ''; ?>><?php echo fresh_h($category['name']); ?></option><?php endforeach; ?></select>
            <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm"><option value=""><?php echo fresh_h(t('mall_fresh_products.all_status')); ?></option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.active')); ?></option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo fresh_h(t('common.inactive')); ?></option></select>
            <button class="px-4 py-2 text-sm bg-gray-800 text-white rounded-md"><i class="fas fa-search mr-1"></i><?php echo fresh_h(t('common.search')); ?></button>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-100 text-gray-600"><tr>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('mall_fresh_products.code')); ?></th>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('product.name')); ?></th>
            <th class="px-4 py-3 text-left"><?php echo fresh_h(t('mall_fresh_products.category')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('mall_fresh_products.price_per_100g')); ?></th>
            <th class="px-4 py-3 text-center"><?php echo fresh_h(t('common.status')); ?></th>
            <th class="px-4 py-3 text-right"><?php echo fresh_h(t('common.actions')); ?></th>
        </tr></thead><tbody>
        <?php if (!$products): ?><tr><td colspan="6" class="px-4 py-10 text-center text-gray-500"><?php echo fresh_h(t('mall_fresh_products.no_products')); ?></td></tr><?php endif; ?>
        <?php foreach ($products as $product): ?><tr class="border-t border-gray-100"><td class="px-4 py-3 font-mono text-xs"><?php echo fresh_h($product['code']); ?></td><td class="px-4 py-3"><div class="font-semibold"><?php echo fresh_h($product['name_ko']); ?></div><div class="text-xs text-gray-500"><?php echo fresh_h($product['name_en']); ?></div></td><td class="px-4 py-3"><?php echo fresh_h($product['category_name'] ?? '-'); ?></td><td class="px-4 py-3 text-right"><?php echo number_format((float)$product['price_per_100g'], 2); ?></td><td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded-full text-xs <?php echo $product['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'; ?>"><?php echo $product['status'] === 'active' ? fresh_h(t('common.active')) : fresh_h(t('common.inactive')); ?></span></td><td class="px-4 py-3"><div class="flex justify-end gap-3"><a class="text-emerald-700" href="fresh_product_links.php?fresh_product_id=<?php echo (int)$product['id']; ?>"><?php echo fresh_h(t('mall_fresh_products.mapping_link')); ?></a><a class="text-blue-700" href="fresh_products.php?edit=<?php echo (int)$product['id']; ?>"><?php echo fresh_h(t('common.edit')); ?></a><form method="post" onsubmit="return confirm('<?php echo fresh_h(t('mall_fresh_products.delete_confirm')); ?>');"><?php echo mall_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><button class="text-red-600"><?php echo fresh_h(t('common.delete')); ?></button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
