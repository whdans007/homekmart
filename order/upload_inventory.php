<?php
$page_title = '재고 업로드';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/order_helper.php';
// 재고 목록/업로드는 발주 시스템 접근 권한자(매니저 이상) 누구나 가능 — header.php의 ord_require_manager()로 게이트됨

$conn = get_ord_db();

// 활성화 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_id'])) {
    ord_verify_csrf();
    $act_id = (int)$_POST['activate_id'];
    $act_vendor = (int)$_POST['activate_vendor_id'];
    $stmt = $conn->prepare("UPDATE order_vendor_inventories SET is_current = 0 WHERE vendor_id = ?");
    $stmt->bind_param('i', $act_vendor);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("UPDATE order_vendor_inventories SET is_current = 1 WHERE id = ?");
    $stmt->bind_param('i', $act_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    ord_set_flash('success', '재고가 활성화되었습니다.');
    header('Location: ' . ORD_BASE . '/upload_inventory.php');
    exit;
}

// 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    ord_verify_csrf();
    $del_id = (int)$_POST['delete_id'];
    // 1) 장바구니 항목 먼저 삭제 (FK: order_cart_items → order_vendor_inventory_items)
    $stmt = $conn->prepare("DELETE c FROM order_cart_items c JOIN order_vendor_inventory_items i ON c.inventory_item_id = i.id WHERE i.inventory_id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    // 2) 재고 아이템 삭제
    $stmt = $conn->prepare("DELETE FROM order_vendor_inventory_items WHERE inventory_id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    // 3) 재고 메타 삭제
    $stmt = $conn->prepare("DELETE FROM order_vendor_inventories WHERE id = ?");
    $stmt->bind_param('i', $del_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    ord_set_flash('success', '재고 데이터가 삭제되었습니다.');
    header('Location: ' . ORD_BASE . '/upload_inventory.php');
    exit;
}

// 목록 조회
$stmt = $conn->prepare("
    SELECT inv.id, inv.vendor_id, inv.original_filename, inv.upload_date, inv.row_count, inv.is_current,
           v.name AS vendor_name
    FROM order_vendor_inventories inv
    JOIN order_vendors v ON v.id = inv.vendor_id
    ORDER BY inv.upload_date DESC, inv.id DESC
");
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-bold text-gray-800">
        <i class="fas fa-boxes-stacked mr-2 text-indigo-600"></i>재고 업로드 목록
    </h2>
    <a href="<?php echo ORD_BASE; ?>/upload_excel.php"
       class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
        <i class="fas fa-upload mr-1"></i>새 업로드
    </a>
</div>

<?php if (empty($rows)): ?>
<div class="text-center py-16 text-gray-400">
    <i class="fas fa-file-excel text-4xl mb-3"></i>
    <p class="mb-2">업로드된 재고 데이터가 없습니다.</p>
    <a href="<?php echo ORD_BASE; ?>/upload_excel.php" class="text-indigo-600 hover:underline text-sm">
        첫 번째 파일 업로드하기
    </a>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-100">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">업체</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">파일명</th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">상품 수</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">업로드일</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">상태</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
        <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm font-medium text-gray-800">
                    <?php echo htmlspecialchars($r['vendor_name']); ?>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600">
                    <i class="fas fa-file-excel mr-1.5 text-green-600"></i>
                    <?php echo htmlspecialchars($r['original_filename']); ?>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 text-right font-mono">
                    <?php echo number_format($r['row_count']); ?>건
                </td>
                <td class="px-4 py-3 text-sm text-gray-400">
                    <?php echo htmlspecialchars($r['upload_date']); ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if ($r['is_current']): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">현재</span>
                    <?php else: ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-400">이전</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap flex items-center justify-end gap-2">
                    <?php if (!$r['is_current']): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">
                        <input type="hidden" name="activate_id" value="<?php echo $r['id']; ?>">
                        <input type="hidden" name="activate_vendor_id" value="<?php echo $r['vendor_id'] ?? 0; ?>">
                        <button type="submit"
                                class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-600 rounded-lg text-xs font-medium">
                            <i class="fas fa-check-circle mr-1"></i>활성화
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" class="inline"
                          onsubmit="return confirm('<?php echo number_format($r['row_count']); ?>건의 재고 데이터를 삭제합니다. 계속하시겠습니까?')">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">
                        <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
                        <button type="submit"
                                class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-xs font-medium">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
