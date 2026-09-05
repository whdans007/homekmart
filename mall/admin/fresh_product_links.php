<?php
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../../lib/permission_helper.php';
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
$stmt->bind_param('i', $active); $stmt->execute();
$stores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$stmt = $conn->prepare("SELECT id, code, name_ko, name_en, status FROM mall_fresh_products ORDER BY FIELD(status, 'active', 'inactive'), name_ko");
$stmt->execute(); $masters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$masterId = (int)($_REQUEST['fresh_product_id'] ?? 0);
$storeId = (int)($_REQUEST['store_id'] ?? 0);
$masterIds = array_map('intval', array_column($masters, 'id'));
$storeIds = array_map('intval', array_column($stores, 'id'));
if (!in_array($masterId, $masterIds, true)) $masterId = $masterIds[0] ?? 0;
if (!in_array($storeId, $storeIds, true)) $storeId = $storeIds[0] ?? 0;
$returnUrl = 'fresh_product_links.php?fresh_product_id=' . $masterId . '&store_id=' . $storeId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mall_csrf_verify($_POST['csrf_token'] ?? '')) {
        fresh_admin_flash('error', '요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
        fresh_admin_redirect($returnUrl);
    }
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'link') {
            $productId = (int)($_POST['store_product_id'] ?? 0);
            $source = $_POST['match_source'] ?? '';
            if (!$masterId || !$storeId || !$productId || !in_array($source, ['auto_suggested', 'manual'], true)) throw new InvalidArgumentException('연결 대상을 올바르게 선택해 주세요.');
            $check = $conn->prepare('SELECT p.id FROM products p INNER JOIN inventory i ON i.product_id = p.id AND i.store_id = ? WHERE p.id = ? AND p.is_active = ?');
            $check->bind_param('iii', $storeId, $productId, $active); $check->execute();
            $exists = (bool)$check->get_result()->fetch_assoc(); $check->close();
            if (!$exists) throw new InvalidArgumentException('선택 점포에서 사용하는 상품이 아닙니다.');
            $userId = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare('INSERT INTO mall_fresh_product_store_links (mall_fresh_product_id, store_id, store_product_id, match_source, linked_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iiisi', $masterId, $storeId, $productId, $source, $userId); $stmt->execute(); $stmt->close();
            fresh_admin_flash('success', '점포 상품 코드를 연결했습니다.');
        } elseif ($action === 'unlink') {
            $linkId = (int)($_POST['link_id'] ?? 0);
            $stmt = $conn->prepare('DELETE FROM mall_fresh_product_store_links WHERE id = ? AND mall_fresh_product_id = ?');
            $stmt->bind_param('ii', $linkId, $masterId); $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new InvalidArgumentException('해제할 연결을 찾을 수 없습니다.');
            $stmt->close(); fresh_admin_flash('success', '상품 연결을 해제했습니다.');
        } else throw new InvalidArgumentException('지원하지 않는 작업입니다.');
    } catch (mysqli_sql_exception $e) {
        error_log('fresh_product_links.php: ' . $e->getMessage());
        fresh_admin_flash('error', (int)$e->getCode() === 1062 ? '이 점포 상품은 이미 다른 신선상품에 연결되어 있습니다.' : '연결 정보를 저장하지 못했습니다.');
    } catch (InvalidArgumentException $e) { fresh_admin_flash('error', $e->getMessage()); }
    $conn->close(); fresh_admin_redirect($returnUrl);
}

$selected = null;
foreach ($masters as $master) if ((int)$master['id'] === $masterId) { $selected = $master; break; }
$links = $suggestions = $results = [];
$search = trim($_GET['q'] ?? '');
if ($masterId) {
    $stmt = $conn->prepare('SELECT l.id, s.name store_name, p.sku, p.name_ko, p.name_en, l.match_source, l.linked_at, u.username linked_by_name FROM mall_fresh_product_store_links l INNER JOIN stores s ON s.id=l.store_id INNER JOIN products p ON p.id=l.store_product_id LEFT JOIN users u ON u.id=l.linked_by WHERE l.mall_fresh_product_id=? ORDER BY s.name,p.name_ko');
    $stmt->bind_param('i', $masterId); $stmt->execute(); $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
}
function load_candidates(mysqli $conn, int $storeId, array $categoryIds, string $term, bool $suggested): array {
    if (!$storeId || !$categoryIds || $term === '') return [];
    $in = implode(',', array_fill(0, count($categoryIds), '?'));
    $sql = "SELECT DISTINCT p.id,p.sku,p.name_ko,p.name_en,c.name category_name,i.quantity FROM products p INNER JOIN inventory i ON i.product_id=p.id AND i.store_id=? LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN mall_fresh_product_store_links l ON l.store_id=i.store_id AND l.store_product_id=p.id WHERE p.is_active=? AND p.category_id IN ($in) AND l.id IS NULL AND (p.name_ko LIKE ? OR COALESCE(p.name_en,'') LIKE ?" . ($suggested ? ')' : " OR COALESCE(p.sku,'') LIKE ?)") . ' ORDER BY p.name_ko LIMIT ' . ($suggested ? '10' : '50');
    $types = 'ii' . str_repeat('i', count($categoryIds)) . ($suggested ? 'ss' : 'sss');
    $params = [$storeId, 1]; foreach ($categoryIds as $id) $params[] = $id;
    $like = '%' . $term . '%'; $params[] = $like; $params[] = $like; if (!$suggested) $params[] = $like;
    $stmt = $conn->prepare($sql); $stmt->bind_param($types, ...$params); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); return $rows;
}
if ($selected) {
    $suggestions = load_candidates($conn, $storeId, $categoryIds, $selected['name_ko'], true);
    if ($search !== '') $results = load_candidates($conn, $storeId, $categoryIds, $search, false);
}
$conn->close(); $flash = fresh_admin_take_flash();
function flh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function candidate_card(array $p, string $source, int $masterId, int $storeId): void { ?>
<div class="border rounded-md p-3 flex justify-between gap-3"><div><b><?php echo flh($p['name_ko']); ?></b><div class="text-xs text-gray-500"><?php echo flh($p['name_en']); ?> · SKU <?php echo flh($p['sku']); ?></div><div class="text-xs text-gray-500"><?php echo flh($p['category_name']); ?> · 재고 <?php echo flh($p['quantity']); ?></div></div><form method="post"><?php echo mall_csrf_field(); ?><input type="hidden" name="action" value="link"><input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>"><input type="hidden" name="store_id" value="<?php echo $storeId; ?>"><input type="hidden" name="store_product_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="match_source" value="<?php echo $source; ?>"><button class="px-3 py-2 text-xs bg-emerald-600 text-white rounded">연결 확인</button></form></div><?php }
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>점포-몰 코드 매핑 관리</title><link href="../../admin/css/style.css" rel="stylesheet"><link href="../../admin/css/design-system.css" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head><body class="bg-gray-50 min-h-screen">
<?php include __DIR__ . '/partials/sidebar.php'; ?><main class="p-6 max-w-7xl mx-auto">
<div class="flex justify-between mb-4"><h1 class="text-lg font-bold"><i class="fas fa-link mr-2"></i>점포-몰 코드 매핑 관리</h1><a href="fresh_products.php" class="text-blue-700 text-sm">신선상품 관리</a></div>
<?php if ($flash): ?><div class="mb-4 p-3 rounded border <?php echo $flash['type']==='error'?'bg-red-50 text-red-700':'bg-green-50 text-green-700'; ?>"><?php echo flh($flash['message']); ?></div><?php endif; ?>
<section class="bg-white border rounded-lg p-4 mb-5"><form method="get" class="grid md:grid-cols-2 gap-3"><select name="fresh_product_id" class="border rounded px-3 py-2" required><option value="">신선상품 선택</option><?php foreach($masters as $m): ?><option value="<?php echo (int)$m['id']; ?>" <?php echo $masterId===(int)$m['id']?'selected':''; ?>>[<?php echo flh($m['code']); ?>] <?php echo flh($m['name_ko']); ?><?php echo $m['status']==='inactive'?' (비활성)':''; ?></option><?php endforeach; ?></select><div class="flex gap-2"><select name="store_id" class="border rounded px-3 py-2 grow" required><?php foreach($stores as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $storeId===(int)$s['id']?'selected':''; ?>><?php echo flh($s['name']); ?></option><?php endforeach; ?></select><button class="bg-gray-800 text-white px-4 rounded">선택</button></div></form></section>
<?php if (!$masters): ?><div class="p-4 bg-amber-50">먼저 신선상품 마스터를 등록해 주세요.</div><?php elseif (!$categoryIds): ?><div class="p-4 bg-amber-50">신선식품 카테고리 구성을 확인해 주세요.</div><?php else: ?>
<section class="bg-white border rounded-lg p-4 mb-5"><h2 class="font-bold text-sm">추천 후보</h2><p class="text-xs text-gray-500 mb-3">선택 점포의 신선식품 재고에서 상품명으로 추천합니다.</p><div class="grid lg:grid-cols-2 gap-3"><?php foreach($suggestions as $p) candidate_card($p,'auto_suggested',$masterId,$storeId); ?></div><?php if(!$suggestions): ?><p class="text-sm text-gray-500">추천 후보가 없습니다.</p><?php endif; ?></section>
<section class="bg-white border rounded-lg p-4 mb-5"><h2 class="font-bold text-sm mb-3">점포 상품 직접 검색</h2><form method="get" class="flex gap-2 mb-4"><input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>"><input type="hidden" name="store_id" value="<?php echo $storeId; ?>"><input name="q" value="<?php echo flh($search); ?>" required placeholder="상품명 또는 SKU" class="border rounded px-3 py-2 grow"><button class="bg-blue-600 text-white px-4 rounded">검색</button></form><div class="grid lg:grid-cols-2 gap-3"><?php foreach($results as $p) candidate_card($p,'manual',$masterId,$storeId); ?></div><?php if($search!==''&&!$results): ?><p class="text-sm text-gray-500">검색 결과가 없습니다.</p><?php endif; ?></section><?php endif; ?>
<section class="bg-white border rounded-lg overflow-hidden"><h2 class="font-bold text-sm p-4">현재 연결 목록</h2><table class="min-w-full text-sm"><thead class="bg-gray-100"><tr><th class="p-3 text-left">점포</th><th class="p-3 text-left">점포 상품</th><th class="p-3">연결 방식</th><th class="p-3">연결자/일시</th><th class="p-3"></th></tr></thead><tbody><?php if(!$links): ?><tr><td colspan="5" class="p-8 text-center text-gray-500">연결된 상품이 없습니다.</td></tr><?php endif; ?><?php foreach($links as $l): ?><tr class="border-t"><td class="p-3"><?php echo flh($l['store_name']); ?></td><td class="p-3"><b><?php echo flh($l['name_ko']); ?></b><div class="text-xs text-gray-500"><?php echo flh($l['sku']); ?> · <?php echo flh($l['name_en']); ?></div></td><td class="p-3 text-center"><?php echo $l['match_source']==='auto_suggested'?'추천 후보':'수동 검색'; ?></td><td class="p-3 text-center text-xs"><?php echo flh($l['linked_by_name']??'-'); ?><br><?php echo flh($l['linked_at']); ?></td><td class="p-3"><form method="post" onsubmit="return confirm('연결을 해제하시겠습니까?')"><?php echo mall_csrf_field(); ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="fresh_product_id" value="<?php echo $masterId; ?>"><input type="hidden" name="store_id" value="<?php echo $storeId; ?>"><input type="hidden" name="link_id" value="<?php echo (int)$l['id']; ?>"><button class="text-red-600">연결 해제</button></form></td></tr><?php endforeach; ?></tbody></table></section>
</main></body></html>
