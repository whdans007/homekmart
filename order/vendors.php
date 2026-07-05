<?php
$page_title = '업체 관리';
require_once __DIR__ . '/partials/header.php';
ord_require_admin();

$conn = get_ord_db();

// 업체 삭제
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    ord_verify_csrf();
    $id = (int)($_POST['vendor_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM order_vendors WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    ord_set_flash('success', '업체가 삭제되었습니다.');
    header('Location: ' . ORD_BASE . '/vendors.php');
    exit;
}

// 업체 목록 조회 (컬럼 설정 여부, 최근 업로드일 포함)
$sql = "
    SELECT v.*,
           cm.id AS col_map_id,
           (SELECT MAX(upload_date) FROM order_vendor_inventories oi WHERE oi.vendor_id = v.id AND oi.is_current = 1) AS last_upload
    FROM order_vendors v
    LEFT JOIN order_vendor_column_maps cm ON cm.vendor_id = v.id
    ORDER BY v.name
";
$vendors = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-xl font-bold text-gray-800"><i class="fas fa-building mr-2 text-indigo-600"></i>업체 관리</h1>
    <a href="<?php echo ORD_BASE; ?>/vendor_form.php" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
        <i class="fas fa-plus mr-2"></i>업체 등록
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left font-medium text-gray-500">#</th>
                <th class="px-4 py-3 text-left font-medium text-gray-500">업체명</th>
                <th class="px-4 py-3 text-left font-medium text-gray-500">연락처</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">컬럼 설정</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">최근 업로드</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">상태</th>
                <th class="px-4 py-3 text-center font-medium text-gray-500">액션</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($vendors)): ?>
            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">등록된 업체가 없습니다.</td></tr>
            <?php else: foreach ($vendors as $v): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-400"><?php echo $v['id']; ?></td>
                <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($v['name']); ?></td>
                <td class="px-4 py-3 text-gray-500"><?php echo htmlspecialchars($v['contact_info'] ?? ''); ?></td>
                <td class="px-4 py-3 text-center">
                    <?php if ($v['col_map_id']): ?>
                        <span class="inline-flex items-center text-green-600"><i class="fas fa-check-circle mr-1"></i>설정됨</span>
                    <?php else: ?>
                        <span class="inline-flex items-center text-yellow-600"><i class="fas fa-exclamation-circle mr-1"></i>미설정</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center text-gray-500">
                    <?php echo $v['last_upload'] ? htmlspecialchars($v['last_upload']) : '<span class="text-gray-300">-</span>'; ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if ($v['is_active']): ?>
                        <span class="inline-block px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded-full">활성</span>
                    <?php else: ?>
                        <span class="inline-block px-2 py-0.5 text-xs bg-gray-100 text-gray-500 rounded-full">비활성</span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center space-x-1">
                    <a href="<?php echo ORD_BASE; ?>/vendor_column_setup.php?vendor_id=<?php echo $v['id']; ?>"
                       class="inline-flex items-center px-2 py-1 text-xs bg-indigo-50 text-indigo-700 rounded hover:bg-indigo-100">
                        <i class="fas fa-columns mr-1"></i>컬럼
                    </a>
                    <a href="<?php echo ORD_BASE; ?>/vendor_form.php?id=<?php echo $v['id']; ?>"
                       class="inline-flex items-center px-2 py-1 text-xs bg-gray-50 text-gray-700 rounded hover:bg-gray-100">
                        <i class="fas fa-edit mr-1"></i>수정
                    </a>
                    <form method="post" class="inline" onsubmit="return confirm('정말 삭제하시겠습니까?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="vendor_id" value="<?php echo $v['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">
                        <button type="submit" class="inline-flex items-center px-2 py-1 text-xs bg-red-50 text-red-700 rounded hover:bg-red-100">
                            <i class="fas fa-trash mr-1"></i>삭제
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
