<?php
$page_title = '사용자 관리';
require_once __DIR__ . '/partials/header.php';
ord_require_admin();

$currentStoreId = ord_current_store_id();
$isSuperAdmin   = ($_SESSION['role'] ?? '') === 'super_admin';

$conn = get_ord_db();

// 현재 점포 이름
$storeName = '';
if ($currentStoreId) {
    $sr = $conn->prepare("SELECT name FROM stores WHERE id = ? LIMIT 1");
    $sr->bind_param('i', $currentStoreId);
    $sr->execute();
    $sr->bind_result($storeName);
    $sr->fetch();
    $sr->close();
}

// 유저 목록 조회
if ($isSuperAdmin) {
    $stmt = $conn->prepare("SELECT u.id, u.username, u.full_name, u.role, u.store_id, s.name AS store_name, u.created_at
                             FROM users u LEFT JOIN stores s ON u.store_id = s.id
                             ORDER BY u.store_id, u.full_name");
    $stmt->execute();
} else {
    $stmt = $conn->prepare("SELECT u.id, u.username, u.full_name, u.role, u.store_id, ? AS store_name, u.created_at
                             FROM users u
                             WHERE u.store_id = ?
                             ORDER BY u.full_name");
    $stmt->bind_param('si', $storeName, $currentStoreId);
    $stmt->execute();
}
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 점포 목록 (super_admin용)
$stores = [];
if ($isSuperAdmin) {
    $stores = $conn->query("SELECT id, name FROM stores ORDER BY id")->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
?>

<!-- 추가 모달 -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fas fa-user-plus mr-1 text-indigo-500"></i>사용자 추가
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <div id="addUserError" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"></div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">이름 <span class="text-red-500">*</span></label>
          <input type="text" id="new_full_name" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="홍길동">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">아이디 <span class="text-red-500">*</span></label>
          <input type="text" id="new_username" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="user123" autocomplete="off">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">비밀번호 <span class="text-red-500">*</span></label>
          <input type="password" id="new_password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="8자 이상" autocomplete="new-password">
        </div>
        <?php if ($isSuperAdmin): ?>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">점포 <span class="text-red-500">*</span></label>
          <select id="new_store_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="">— 점포 선택 —</option>
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="new_store_id" value="<?php echo $currentStoreId; ?>">
        <?php endif; ?>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">역할</label>
          <select id="new_role" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="admin">Admin (관리자)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">취소</button>
        <button onclick="createUser()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium">
          <i class="fas fa-save mr-1"></i>저장
        </button>
      </div>
    </div>
  </div>
</div>

<!-- 비밀번호 초기화 모달 -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-sm font-semibold text-gray-800">
          <i class="fas fa-key mr-1 text-orange-500"></i>비밀번호 초기화
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-4 space-y-3">
        <input type="hidden" id="reset_uid">
        <p id="reset_target_name" class="text-sm text-gray-700"></p>
        <div id="resetPwError" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm"></div>
        <div>
          <label class="block text-xs font-medium text-gray-700 mb-1">새 비밀번호 <span class="text-red-500">*</span></label>
          <input type="password" id="reset_password" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="8자 이상" autocomplete="new-password">
        </div>
      </div>
      <div class="modal-footer px-4 py-3 flex gap-2 justify-end">
        <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm" data-bs-dismiss="modal">취소</button>
        <button onclick="resetPassword()" class="px-4 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
          <i class="fas fa-key mr-1"></i>변경
        </button>
      </div>
    </div>
  </div>
</div>

<div class="flex items-center justify-between mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fas fa-users mr-2 text-indigo-500"></i>사용자 관리
    <?php if ($storeName): ?>
    <span class="text-sm font-normal text-gray-400 ml-2"><?php echo htmlspecialchars($storeName); ?></span>
    <?php endif; ?>
  </h2>
  <button onclick="new bootstrap.Modal(document.getElementById('addUserModal')).show()"
          class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
    <i class="fas fa-user-plus mr-1"></i>사용자 추가
  </button>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
  <table class="min-w-full text-sm">
    <thead class="bg-gray-50 border-b border-gray-100">
      <tr>
        <?php if ($isSuperAdmin): ?>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">점포</th>
        <?php endif; ?>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">이름</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">아이디</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">역할</th>
        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">등록일</th>
        <th class="px-4 py-3"></th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
    <?php if (empty($users)): ?>
      <tr><td colspan="<?php echo $isSuperAdmin ? 6 : 5; ?>" class="px-4 py-8 text-center text-gray-400">등록된 사용자가 없습니다.</td></tr>
    <?php else: ?>
    <?php foreach ($users as $u): ?>
    <?php $isMe = ((int)$u['id'] === (int)ord_current_user_id()); ?>
    <tr class="hover:bg-gray-50">
      <?php if ($isSuperAdmin): ?>
      <td class="px-4 py-3 text-xs text-gray-500"><?php echo htmlspecialchars($u['store_name'] ?? '—'); ?></td>
      <?php endif; ?>
      <td class="px-4 py-3 font-medium text-gray-800">
        <?php echo htmlspecialchars($u['full_name']); ?>
        <?php if ($isMe): ?><span class="ml-1 text-[10px] bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full">나</span><?php endif; ?>
      </td>
      <td class="px-4 py-3 text-gray-600 font-mono text-xs"><?php echo htmlspecialchars($u['username']); ?></td>
      <td class="px-4 py-3">
        <span class="text-xs px-2 py-0.5 rounded-full font-medium
          <?php echo $u['role'] === 'super_admin' ? 'bg-purple-100 text-purple-700' :
                    ($u['role'] === 'admin' ? 'bg-indigo-100 text-indigo-700' : 'bg-gray-100 text-gray-600'); ?>">
          <?php echo htmlspecialchars($u['role']); ?>
        </span>
      </td>
      <td class="px-4 py-3 text-xs text-gray-400"><?php echo $u['created_at'] ? date('Y-m-d', strtotime($u['created_at'])) : '—'; ?></td>
      <td class="px-4 py-3 text-right">
        <?php if (!$isMe && $u['role'] !== 'super_admin'): ?>
        <button onclick="openResetPw(<?php echo $u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['full_name'])); ?>')"
                class="text-xs text-orange-500 hover:text-orange-700 border border-orange-200 hover:border-orange-400 px-2 py-1 rounded-lg transition-colors">
          <i class="fas fa-key mr-1"></i>비밀번호 초기화
        </button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
// CSRF_TOKEN 은 partials/header.php 에서 전역 선언됨 (재선언 시 충돌하므로 여기서 생략)

async function createUser() {
    const errEl = document.getElementById('addUserError');
    errEl.classList.add('hidden');

    const body = {
        action:    'create',
        full_name: document.getElementById('new_full_name').value.trim(),
        username:  document.getElementById('new_username').value.trim(),
        password:  document.getElementById('new_password').value,
        store_id:  document.getElementById('new_store_id').value,
        role:      document.getElementById('new_role').value,
        csrf_token: CSRF_TOKEN,
    };

    const res = await fetch('<?php echo ORD_BASE; ?>/ajax/users_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(body),
    }).then(r => r.json());

    if (res.success) {
        location.reload();
    } else {
        errEl.textContent = res.error || '저장 실패';
        errEl.classList.remove('hidden');
    }
}

function openResetPw(uid, name) {
    document.getElementById('reset_uid').value = uid;
    document.getElementById('reset_target_name').textContent = `"${name}" 의 비밀번호를 변경합니다.`;
    document.getElementById('reset_password').value = '';
    document.getElementById('resetPwError').classList.add('hidden');
    new bootstrap.Modal(document.getElementById('resetPwModal')).show();
}

async function resetPassword() {
    const errEl = document.getElementById('resetPwError');
    errEl.classList.add('hidden');

    const res = await fetch('<?php echo ORD_BASE; ?>/ajax/users_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            action:   'reset_password',
            id:       document.getElementById('reset_uid').value,
            password: document.getElementById('reset_password').value,
            csrf_token: CSRF_TOKEN,
        }),
    }).then(r => r.json());

    if (res.success) {
        bootstrap.Modal.getInstance(document.getElementById('resetPwModal')).hide();
        alert('비밀번호가 변경되었습니다.');
    } else {
        errEl.textContent = res.error || '변경 실패';
        errEl.classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
