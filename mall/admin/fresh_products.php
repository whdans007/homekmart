<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
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
        fresh_admin_flash('error', '요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
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
                fresh_admin_flash('success', '신선상품을 수정했습니다.');
            } else {
                $stmt = $conn->prepare('INSERT INTO mall_fresh_products (code, name_ko, name_en, category_id, unit_step_g, price_per_100g, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('sssiids', $code, $nameKo, $nameEnValue, $categoryId, $unitStep, $price, $status);
                $stmt->execute();
                $stmt->close();
                fresh_admin_flash('success', '신선상품을 등록했습니다.');
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
            fresh_admin_flash('success', '신선상품을 삭제했습니다.');
        } else {
            throw new InvalidArgumentException('지원하지 않는 작업입니다.');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('fresh_products.php write error: ' . $e->getMessage());
        $message = ((int)$e->getCode() === 1062)
            ? '이미 사용 중인 신선상품 코드입니다.'
            : '저장하지 못했습니다. 입력값과 연결된 데이터를 확인해 주세요.';
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
    <title>신선상품 마스터 관리 - HOME K MART 쇼핑몰</title>
    <link rel="icon" href="data:,">
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-apple-whole mr-2"></i>신선상품 마스터 관리</h1>
        <a href="fresh_product_links.php" class="px-3 py-2 text-xs font-semibold bg-emerald-600 text-white rounded-md"><i class="fas fa-link mr-1"></i>점포 코드 매핑</a>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'; ?>"><?php echo fresh_h($flash['message']); ?></div>
    <?php endif; ?>
    <?php if (empty($categories)): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border bg-amber-50 text-amber-800 border-amber-200">신선식품 상위 카테고리와 과일/채소/정육/수산물 하위 카테고리를 먼저 등록해 주세요.</div>
    <?php endif; ?>

    <section class="bg-white rounded-lg border border-gray-200 p-4 mb-5">
        <h2 class="text-sm font-bold text-gray-700 mb-3"><?php echo $editProduct ? '신선상품 수정' : '신선상품 등록'; ?></h2>
        <form method="post" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
            <?php echo mall_csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int)($editProduct['id'] ?? 0); ?>">
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">코드 *</label><input name="code" required maxlength="50" value="<?php echo fresh_h($editProduct['code'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">상품명(한국어) *</label><input name="name_ko" required maxlength="255" value="<?php echo fresh_h($editProduct['name_ko'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">상품명(영어)</label><input name="name_en" maxlength="255" value="<?php echo fresh_h($editProduct['name_en'] ?? ''); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">카테고리 *</label><select name="category_id" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="">선택</option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo (int)($editProduct['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : ''; ?>><?php echo ((int)$category['depth'] ? '└ ' : '') . fresh_h($category['name']); ?></option><?php endforeach; ?></select></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">주문 단위(g)</label><input value="100" readonly class="w-full bg-gray-100 border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-600"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">100g당 판매가 *</label><input name="price_per_100g" type="number" min="0" step="0.01" required value="<?php echo fresh_h($editProduct['price_per_100g'] ?? '0.00'); ?>" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"></div>
            <div><label class="block text-xs font-semibold text-gray-600 mb-1">상태</label><select name="status" class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="active" <?php echo ($editProduct['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>활성</option><option value="inactive" <?php echo ($editProduct['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>비활성</option></select></div>
            <div class="flex gap-2"><button <?php echo empty($categories) ? 'disabled' : ''; ?> class="px-4 py-2 text-sm font-semibold bg-blue-600 disabled:bg-gray-300 text-white rounded-md"><?php echo $editProduct ? '수정 저장' : '등록'; ?></button><?php if ($editProduct): ?><a href="fresh_products.php" class="px-4 py-2 text-sm border border-gray-300 rounded-md">취소</a><?php endif; ?></div>
        </form>
    </section>

    <section class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <form method="get" class="p-4 border-b border-gray-200 flex gap-2 flex-wrap">
            <input name="q" value="<?php echo fresh_h($search); ?>" placeholder="코드 또는 상품명 검색" class="border border-gray-300 rounded-md px-3 py-2 text-sm min-w-64">
            <select name="category_id" class="border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="0">전체 카테고리</option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo $categoryFilter === (int)$category['id'] ? 'selected' : ''; ?>><?php echo fresh_h($category['name']); ?></option><?php endforeach; ?></select>
            <select name="status" class="border border-gray-300 rounded-md px-3 py-2 text-sm"><option value="">전체 상태</option><option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>활성</option><option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>비활성</option></select>
            <button class="px-4 py-2 text-sm bg-gray-800 text-white rounded-md"><i class="fas fa-search mr-1"></i>검색</button>
        </form>
        <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-gray-100 text-gray-600"><tr><th class="px-4 py-3 text-left">코드</th><th class="px-4 py-3 text-left">상품명</th><th class="px-4 py-3 text-left">카테고리</th><th class="px-4 py-3 text-right">100g당 판매가</th><th class="px-4 py-3 text-center">상태</th><th class="px-4 py-3 text-right">관리</th></tr></thead><tbody>
        <?php if (!$products): ?><tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">등록된 신선상품이 없습니다.</td></tr><?php endif; ?>
        <?php foreach ($products as $product): ?><tr class="border-t border-gray-100"><td class="px-4 py-3 font-mono text-xs"><?php echo fresh_h($product['code']); ?></td><td class="px-4 py-3"><div class="font-semibold"><?php echo fresh_h($product['name_ko']); ?></div><div class="text-xs text-gray-500"><?php echo fresh_h($product['name_en']); ?></div></td><td class="px-4 py-3"><?php echo fresh_h($product['category_name'] ?? '-'); ?></td><td class="px-4 py-3 text-right"><?php echo number_format((float)$product['price_per_100g'], 2); ?></td><td class="px-4 py-3 text-center"><span class="px-2 py-1 rounded-full text-xs <?php echo $product['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'; ?>"><?php echo $product['status'] === 'active' ? '활성' : '비활성'; ?></span></td><td class="px-4 py-3"><div class="flex justify-end gap-3"><a class="text-emerald-700" href="fresh_product_links.php?fresh_product_id=<?php echo (int)$product['id']; ?>">매핑</a><a class="text-blue-700" href="fresh_products.php?edit=<?php echo (int)$product['id']; ?>">수정</a><form method="post" onsubmit="return confirm('이 신선상품을 삭제하시겠습니까? 연결 정보도 함께 삭제됩니다.');"><?php echo mall_csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><button class="text-red-600">삭제</button></form></div></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
</body>
</html>
