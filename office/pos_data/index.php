<?php
$page_title      = 'POS Sales Data';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$conn     = get_db_connection();

$tbl = $conn->query("SHOW TABLES LIKE 'pos_sales_uploads'");
if (!$tbl || $tbl->num_rows === 0) {
    $conn->close();
    echo '<div class="max-w-2xl mx-auto mt-10 bg-amber-50 border border-amber-200 rounded-xl p-6 text-amber-800">';
    echo '<i class="fa-solid fa-triangle-exclamation mr-2"></i>';
    echo 'DB 테이블이 없습니다. <a href="../run_pos_sales_migration.php" class="underline font-medium">마이그레이션 실행</a> 후 다시 시도하세요.';
    echo '</div>';
    require_once __DIR__ . '/../partials/footer.php';
    exit;
}

// 삭제 처리 (super_admin/admin/branch_manager만 가능 — 버튼 노출 여부와 무관하게 서버에서도 검증)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'admin', 'branch_manager'])) {
        header('Location: index.php');
        exit;
    }
    $del_id = (int)$_POST['delete_id'];
    $conn->query("DELETE FROM pos_sales_data WHERE upload_id IN (SELECT id FROM pos_sales_uploads WHERE id={$del_id} AND store_id={$store_id})");
    $conn->query("DELETE FROM pos_sales_uploads WHERE id={$del_id} AND store_id={$store_id}");
    header('Location: index.php');
    exit;
}

// 목록 조회
$stmt = $conn->prepare(
    "SELECT id, file_name, row_count, uploaded_at
     FROM pos_sales_uploads
     WHERE store_id=?
     ORDER BY uploaded_at DESC"
);
$stmt->bind_param('i', $store_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
?>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-file-arrow-up mr-2 text-blue-600"></i>POS Sales Data
  </h2>
  <a href="upload.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-upload mr-1"></i>Upload Excel
  </a>
</div>

<?php if (empty($rows)): ?>
<div class="text-center py-16 text-gray-400">
  <i class="fa-solid fa-file-excel text-4xl mb-3"></i>
  <p>업로드된 데이터가 없습니다.</p>
  <a href="upload.php" class="mt-3 inline-block text-blue-600 hover:underline text-sm">첫 번째 파일 업로드하기</a>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <table class="min-w-full divide-y divide-gray-100">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">파일명</th>
        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">건수</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">업로드 일시</th>
        <th class="px-4 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
    <?php foreach ($rows as $r): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-sm font-medium text-gray-800">
          <i class="fa-solid fa-file-excel mr-1.5 text-green-600"></i>
          <?php echo htmlspecialchars($r['file_name']); ?>
        </td>
        <td class="px-4 py-3 text-sm text-gray-600 text-right font-mono">
          <?php echo number_format($r['row_count']); ?>건
        </td>
        <td class="px-4 py-3 text-sm text-gray-400">
          <?php echo date('Y-m-d H:i', strtotime($r['uploaded_at'])); ?>
        </td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
          <a href="view.php?id=<?php echo $r['id']; ?>"
             class="px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium mr-1">
            <i class="fa-solid fa-table mr-1"></i>조회
          </a>
          <?php if (in_array($_SESSION['role'] ?? '', ['super_admin', 'admin', 'branch_manager'])): ?>
          <form method="POST" class="inline"
                onsubmit="return confirm('<?php echo number_format($r['row_count']); ?>건의 데이터를 삭제합니다. 계속하시겠습니까?')">
            <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
            <button type="submit" class="px-3 py-1.5 bg-red-50 hover:bg-red-100 text-red-600 rounded-lg text-xs font-medium">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
