<?php
$page_title      = '직원휴무관리 — 직원등록';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$roles    = get_job_roles();

// POST 처리 (등록/수정/상태변경)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = post_str('name');
        $job_role = in_array($_POST['job_role'] ?? '', $roles) ? $_POST['job_role'] : '';
        if ($name && $job_role) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("INSERT INTO office_employees (store_id, name, job_role) VALUES (?,?,?)");
            $stmt->bind_param('iss', $store_id, $name, $job_role);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }

    } elseif ($action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = post_str('name');
        $job_role = in_array($_POST['job_role'] ?? '', $roles) ? $_POST['job_role'] : '';
        if ($id && $name && $job_role) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("UPDATE office_employees SET name=?, job_role=? WHERE id=? AND store_id=?");
            $stmt->bind_param('ssii', $name, $job_role, $id, $store_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }

    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn = get_db_connection();
            $stmt = $conn->prepare(
                "UPDATE office_employees SET status = IF(status='active','inactive','active') WHERE id=? AND store_id=?"
            );
            $stmt->bind_param('ii', $id, $store_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }
    }

    header('Location: employees.php');
    exit;
}

// 직원 목록 (전체, 직무별 그룹)
$filter_role = $_GET['role'] ?? 'all';
$employees   = get_office_employees($store_id, $filter_role === 'all' ? null : $filter_role, 'active');
$inactive    = get_office_employees($store_id, $filter_role === 'all' ? null : $filter_role, 'inactive');
?>

<div class="flex items-center justify-between mb-6">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-users mr-2 text-green-600"></i>직원등록
  </h2>
  <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fa-solid fa-plus mr-1"></i>직원 등록
  </button>
</div>

<!-- 직무 필터 탭 -->
<div class="flex flex-wrap gap-2 mb-6">
  <?php
  $tab_items = array_merge(['all' => '전체'], array_combine($roles, array_map('get_job_role_label', $roles)));
  foreach ($tab_items as $val => $label):
    $active = $filter_role === $val;
  ?>
  <a href="?role=<?php echo $val; ?>"
     class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors
            <?php echo $active ? 'bg-green-600 text-white border-green-600' : 'bg-white text-gray-600 border-gray-300 hover:border-green-400'; ?>">
    <?php echo $label; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- 재직 직원 -->
<div class="mb-8">
  <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">재직 중 (<?php echo count($employees); ?>명)</h3>
  <?php if (empty($employees)): ?>
  <p class="text-gray-400 text-sm py-6 text-center bg-white rounded-xl border border-gray-100">등록된 직원이 없습니다.</p>
  <?php else: ?>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
    <?php foreach ($employees as $emp): ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 text-center hover:border-green-300 transition-colors">
      <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-2">
        <i class="fa-solid fa-user text-green-600 text-sm"></i>
      </div>
      <div class="font-medium text-gray-800 text-sm mb-0.5"><?php echo htmlspecialchars($emp['name']); ?></div>
      <div class="text-xs text-gray-500 mb-3"><?php echo get_job_role_label($emp['job_role']); ?></div>
      <div class="flex gap-1 justify-center">
        <button onclick="openEditModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['name'])); ?>', '<?php echo $emp['job_role']; ?>')"
                class="text-xs text-blue-600 hover:underline">수정</button>
        <span class="text-gray-300">|</span>
        <form method="POST" class="inline" onsubmit="return confirm('비활성화 하시겠습니까?')">
          <input type="hidden" name="action" value="toggle_status">
          <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
          <button type="submit" class="text-xs text-gray-400 hover:text-red-500 hover:underline">비활성</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 비활성 직원 -->
<?php if (!empty($inactive)): ?>
<div>
  <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">비활성 (<?php echo count($inactive); ?>명)</h3>
  <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
    <?php foreach ($inactive as $emp): ?>
    <div class="bg-gray-50 rounded-xl border border-gray-200 p-4 text-center opacity-60">
      <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center mx-auto mb-2">
        <i class="fa-solid fa-user text-gray-400 text-sm"></i>
      </div>
      <div class="font-medium text-gray-500 text-sm mb-0.5"><?php echo htmlspecialchars($emp['name']); ?></div>
      <div class="text-xs text-gray-400 mb-3"><?php echo get_job_role_label($emp['job_role']); ?></div>
      <form method="POST" class="inline">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
        <button type="submit" class="text-xs text-green-600 hover:underline">복직</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- 등록 모달 -->
<div id="modal_add" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-5">직원 등록</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="add">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">이름 <span class="text-red-500">*</span></label>
        <input type="text" name="name" required autofocus
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">직무 <span class="text-red-500">*</span></label>
        <select name="job_role" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
          <option value="">직무 선택</option>
          <?php foreach ($roles as $r): ?>
          <option value="<?php echo $r; ?>"><?php echo get_job_role_label($r); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-sm font-medium">등록</button>
        <button type="button" onclick="closeModal('modal_add')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">취소</button>
      </div>
    </form>
  </div>
</div>

<!-- 수정 모달 -->
<div id="modal_edit" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-5">직원 수정</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">이름 <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="edit_name" required
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">직무 <span class="text-red-500">*</span></label>
        <select name="job_role" id="edit_role" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
          <?php foreach ($roles as $r): ?>
          <option value="<?php echo $r; ?>"><?php echo get_job_role_label($r); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">저장</button>
        <button type="button" onclick="closeModal('modal_edit')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">취소</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal()        { document.getElementById('modal_add').classList.remove('hidden'); }
function closeModal(id)        { document.getElementById(id).classList.add('hidden'); }
function openEditModal(id, name, role) {
    document.getElementById('edit_id').value   = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_role').value = role;
    document.getElementById('modal_edit').classList.remove('hidden');
}
// 모달 외부 클릭 닫기
['modal_add','modal_edit'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
