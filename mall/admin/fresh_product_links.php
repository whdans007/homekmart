<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
require_once __DIR__ . '/../../lib/lang_helper.php';
require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/fresh_product_common.php';

ensure_logged_in();
require_permission('mall_management', '../../admin/index.php');

$current_page = 'fresh_product_links.php';
$conn = get_db_connection();
$categories = fresh_admin_categories($conn);
$categoryIds = fresh_admin_category_ids($categories);

$active = 1;
$stmt = $conn->prepare('SELECT id, name FROM stores WHERE is_active = ? ORDER BY name');
$stmt->bind_param('i', $active);
$stmt->execute();
$stores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT id, code, name_ko, name_en, status FROM mall_fresh_products ORDER BY FIELD(status, 'active', 'inactive'), name_ko");
$stmt->execute();
$masters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$masterId = (int)($_REQUEST['fresh_product_id'] ?? 0);
$storeId = (int)($_REQUEST['store_id'] ?? 0);
$masterIds = array_map('intval', array_column($masters, 'id'));
$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($masterId, $masterIds, true)) {
    $masterId = $masterIds[0] ?? 0;
}
if (!in_array($storeId, $storeIds, true)) {
    $storeId = $storeIds[0] ?? 0;
}
$returnUrl = 'fresh_product_links.php?fresh_product_id=' . $masterId . '&store_id=' . $storeId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        fresh_admin_flash('error', t('mall_fresh_products.request_expired'));
        fresh_admin_redirect($returnUrl);
    }
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'link') {
            $productId = (int)($_POST['store_product_id'] ?? 0);
            $source = $_POST['match_source'] ?? '';
            if (!$masterId || !$storeId || !$productId || !in_array($source, ['auto_suggested', 'manual'], true)) {
                throw new InvalidArgumentException('연결 대상을 올바르게 선택해 주세요.');
            }
            $check = $conn->prepare('SELECT p.id FROM products p INNER JOIN inventory i ON i.product_id = p.id AND i.store_id = ? WHERE p.id = ? AND p.is_active = ?');
            $check->bind_param('iii', $storeId, $productId, $active);
            $check->execute();
            $exists = (bool)$check->get_result()->fetch_assoc();
            $check->close();
            if (!$exists) {
                throw new InvalidArgumentException('선택 점포에서 사용하는 상품이 아닙니다.');
            }
            $userId = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare('INSERT INTO mall_fresh_product_store_links (mall_fresh_product_id, store_id, store_product_id, match_source, linked_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iiisi', $masterId, $storeId, $productId, $source, $userId);
            $stmt->execute();
            $stmt->close();
            fresh_admin_flash('success', t('mall_fresh_products.link_success'));
        } elseif ($action === 'unlink') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM mall_fresh_product_store_links WHERE id = ? AND mall_fresh_product_id = ?');
            $stmt->bind_param('ii', $linkId, $masterId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new InvalidArgumentException('해제할 연결을 찾을 수 없습니다.');
            }
            $stmt->close();
            fresh_admin_flash('success', t('mall_fresh_products.unlink_success'));
        } else {
            throw new InvalidArgumentException('지원하지 않는 작업입니다.');
        }
    } catch (mysqli_sql_exception $e) {
        error_log('fresh_product_links.php: ' . $e->getMessage());
        fresh_admin_flash('error', (int)$e->getCode() === 1062 ? t('mall_fresh_products.duplicate_link') : t('mall_fresh_products.save_failed'));
    } catch (InvalidArgumentException $e) {
        fresh_admin_flash('error', $e->getMessage());
    }
    $conn->close();
    fresh_admin_redirect($returnUrl);
}

$selected = null;
foreach ($masters as $master) {
    if ((int)$master['id'] === $masterId) {
        $selected = $master;
        break;
    }
}

$links = $suggestions = $results = [];
$search = trim($_GET['q'] ?? '');

if ($masterId) {
    $stmt = $conn->prepare('SELECT l.id, s.name store_name, p.sku, p.name_ko, p.name_en, l.match_source, l.linked_at, u.username linked_by_name
        FROM mall_fresh_product_store_links l
        INNER JOIN stores s ON s.id = l.store_id
        INNER JOIN products p ON p.id = l.store_product_id
        LEFT JOIN users u ON u.id = l.linked_by
        WHERE l.mall_fresh_product_id = ?
        ORDER BY s.name, p.name_ko');
    $stmt->bind_param('i', $masterId);
    $stmt->execute();
    $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function load_candidates(mysqli $conn, int $storeId, array $categoryIds, string $term, bool $suggested): array
{
    if (!$storeId || !$categoryIds || $term === '') {
        return [];
    }
    $categoryPlaceholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $nameMatch = "(p.name_ko LIKE ? OR COALESCE(p.name_en, '') LIKE ?" . ($suggested ? ')' : " OR COALESCE(p.sku, '') LIKE ?)");
    $sql = "SELECT DISTINCT p.id, p.sku, p.name_ko, p.name_en, c.name AS category_name, i.quantity
            FROM products p
            INNER JOIN inventory i ON i.product_id = p.id AND i.store_id = ?
            LEFT JOIN categories c ON c.id = p.category_id
            LEFT JOIN mall_fresh_product_store_links l ON l.store_id = i.store_id AND l.store_product_id = p.id
            WHERE p.is_active = ? AND p.category_id IN ($categoryPlaceholders) AND l.id IS NULL AND $nameMatch
            ORDER BY p.name_ko
            LIMIT " . ($suggested ? 10 : 50);

    $types = 'ii' . str_repeat('i', count($categoryIds)) . ($suggested ? 'ss' : 'sss');
    $params = [$storeId, 1];
    foreach ($categoryIds as $id) {
        $params[] = $id;
    }
    $like = '%' . $term . '%';
    $params[] = $like;
    $params[] = $like;
    if (!$suggested) {
        $params[] = $like;
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

if ($selected) {
    $suggestions = load_candidates($conn, $storeId, $categoryIds, $selected['name_ko'], true);
    if ($search !== '') {
        $results = load_candidates($conn, $storeId, $categoryIds, $search, false);
    }
}
$conn->close();
$flash = fresh_admin_take_flash();

function flh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function candidate_card(array $product, string $source, int $masterId, int $storeId): void {
    ?>
    <div class="border rounded-md p-3 flex justify-between gap-3">
        <div>
            <b><?php echo flh($product['name_ko']); ?></b>
            <div class="text-xs text-gray-500"><?php echo flh($product['name_en']); ?> · SKU <?php echo flh($product['sku']); ?></div>
            <div class="text-xs text-gray-500"><?php echo flh($product['category_name']); ?> · <?php echo flh(t('common.quantity')); ?> <?php echo flh($product['quantity']); ?></div>
        </div>
        <form method="post">
            <?php echo mall_csrf_field(); ?>
            <input type="hidden" name="action" value="link">
            <input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>">
            <input type="hidden" name="store_id" value="<?php echo $storeId; ?>">
            <input type="hidden" name="store_product_id" value="<?php echo (int)$product['id']; ?>">
            <input type="hidden" name="match_source" value="<?php echo $source; ?>">
            <button class="px-3 py-2 text-xs bg-emerald-600 text-white rounded"><?php echo flh(t('mall_fresh_products.link_confirm')); ?></button>
        </form>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo flh(t('mall_fresh_products.store_link_management')); ?></title>
    <link href="../../admin/css/style.css" rel="stylesheet">
    <link href="../../admin/css/design-system.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?>
<main class="p-6 max-w-7xl mx-auto">
    <div class="flex justify-between mb-4">
        <h1 class="text-lg font-bold"><i class="fas fa-link mr-2"></i><?php echo flh(t('mall_fresh_products.store_link_management')); ?></h1>
        <a href="fresh_products.php" class="text-blue-700 text-sm"><?php echo flh(t('mall_fresh_products.master_management')); ?></a>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 p-3 rounded border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'; ?>"><?php echo flh($flash['message']); ?></div>
    <?php endif; ?>

    <section class="bg-white border rounded-lg p-4 mb-5">
        <form method="get" class="grid md:grid-cols-2 gap-3">
            <select name="fresh_product_id" class="border rounded px-3 py-2" required>
                <option value=""><?php echo flh(t('mall_fresh_products.select_fresh_product')); ?></option>
                <?php foreach ($masters as $master): ?>
                    <option value="<?php echo (int)$master['id']; ?>" <?php echo $masterId === (int)$master['id'] ? 'selected' : ''; ?>>
                        [<?php echo flh($master['code']); ?>] <?php echo flh($master['name_ko']); ?><?php echo $master['status'] === 'inactive' ? ' (' . flh(t('common.inactive')) . ')' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div class="flex gap-2">
                <select name="store_id" class="border rounded px-3 py-2 grow" required>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo (int)$store['id']; ?>" <?php echo $storeId === (int)$store['id'] ? 'selected' : ''; ?>><?php echo flh($store['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="bg-gray-800 text-white px-4 rounded"><?php echo flh(t('common.select')); ?></button>
            </div>
        </form>
    </section>

    <?php if (!$masters): ?>
        <div class="p-4 bg-amber-50"><?php echo flh(t('mall_fresh_products.masters_missing_notice')); ?></div>
    <?php elseif (!$categoryIds): ?>
        <div class="p-4 bg-amber-50"><?php echo flh(t('mall_fresh_products.category_missing_notice')); ?></div>
    <?php else: ?>
        <section class="bg-white border rounded-lg p-4 mb-5">
            <h2 class="font-bold text-sm"><?php echo flh(t('mall_fresh_products.suggested_candidates')); ?></h2>
            <p class="text-xs text-gray-500 mb-3"><?php echo flh(t('mall_fresh_products.suggestion_hint')); ?></p>
            <div class="grid lg:grid-cols-2 gap-3"><?php foreach ($suggestions as $product) { candidate_card($product, 'auto_suggested', $masterId, $storeId); } ?></div>
            <?php if (!$suggestions): ?><p class="text-sm text-gray-500"><?php echo flh(t('mall_fresh_products.no_suggestions')); ?></p><?php endif; ?>
        </section>
        <section class="bg-white border rounded-lg p-4 mb-5">
            <h2 class="font-bold text-sm mb-3"><?php echo flh(t('mall_fresh_products.manual_search')); ?></h2>
            <form method="get" class="flex gap-2 mb-4">
                <input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>">
                <input type="hidden" name="store_id" value="<?php echo $storeId; ?>">
                <input name="q" value="<?php echo flh($search); ?>" required placeholder="<?php echo flh(t('mall_fresh_products.search_hint_placeholder')); ?>" class="border rounded px-3 py-2 grow">
                <button class="bg-blue-600 text-white px-4 rounded"><?php echo flh(t('common.search')); ?></button>
            </form>
            <div class="grid lg:grid-cols-2 gap-3"><?php foreach ($results as $product) { candidate_card($product, 'manual', $masterId, $storeId); } ?></div>
            <?php if ($search !== '' && !$results): ?><p class="text-sm text-gray-500"><?php echo flh(t('mall_fresh_products.no_search_results')); ?></p><?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="bg-white border rounded-lg overflow-hidden">
        <h2 class="font-bold text-sm p-4"><?php echo flh(t('mall_fresh_products.current_links_title')); ?></h2>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-3 text-left"><?php echo flh(t('common.store')); ?></th>
                    <th class="p-3 text-left"><?php echo flh(t('mall_fresh_products.store_product')); ?></th>
                    <th class="p-3"><?php echo flh(t('mall_fresh_products.match_method')); ?></th>
                    <th class="p-3"><?php echo flh(t('mall_fresh_products.linked_by_at')); ?></th>
                    <th class="p-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$links): ?><tr><td colspan="5" class="p-8 text-center text-gray-500"><?php echo flh(t('mall_fresh_products.no_links')); ?></td></tr><?php endif; ?>
                <?php foreach ($links as $link): ?>
                    <tr class="border-t">
                        <td class="p-3"><?php echo flh($link['store_name']); ?></td>
                        <td class="p-3"><b><?php echo flh($link['name_ko']); ?></b><div class="text-xs text-gray-500"><?php echo flh($link['sku']); ?> · <?php echo flh($link['name_en']); ?></div></td>
                        <td class="p-3 text-center"><?php echo $link['match_source'] === 'auto_suggested' ? flh(t('mall_fresh_products.match_source_suggested')) : flh(t('mall_fresh_products.match_source_manual')); ?></td>
                        <td class="p-3 text-center text-xs"><?php echo flh($link['linked_by_name'] ?? '-'); ?><br><?php echo flh($link['linked_at']); ?></td>
                        <td class="p-3">
                            <form method="post" onsubmit="return confirm('<?php echo flh(t('mall_fresh_products.unlink_confirm_dialog')); ?>')">
                                <?php echo mall_csrf_field(); ?>
                                <input type="hidden" name="action" value="unlink">
                                <input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>">
                                <input type="hidden" name="store_id" value="<?php echo $storeId; ?>">
                                <input type="hidden" name="link_id" value="<?php echo (int)$link['id']; ?>">
                                <button class="text-red-600"><?php echo flh(t('mall_fresh_products.unlink')); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
