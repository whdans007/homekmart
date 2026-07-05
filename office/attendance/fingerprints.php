<?php
// Design Ref: §4.2 — 지문 슬롯 ↔ 직원 매핑 관리. employees.php 패턴 답습.
$page_title      = 'Attendance — Fingerprint Registration';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();

// ── POST 처리 ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $finger_slot = (int)($_POST['finger_slot'] ?? 0);

        if ($employee_id > 0 && $finger_slot >= 1 && $finger_slot <= 127) {
            // 해당 직원이 이 매장 소속인지 확인
            $conn = get_db_connection();
            $chk  = $conn->prepare(
                "SELECT id FROM office_employees WHERE id=? AND store_id=? AND status='active'"
            );
            $chk->bind_param('ii', $employee_id, $store_id);
            $chk->execute();
            $valid = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($valid) {
                // 직원당 최대 2개(지문1/지문2)까지 등록 가능
                $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM office_fingerprints WHERE store_id=? AND employee_id=?");
                $cnt->bind_param('ii', $store_id, $employee_id);
                $cnt->execute();
                $count = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
                $cnt->close();

                if ($count < 2) {
                    $enrolled_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                    $stmt = $conn->prepare(
                        "INSERT INTO office_fingerprints (store_id, employee_id, finger_slot, enrolled_by)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->bind_param('iiii', $store_id, $employee_id, $finger_slot, $enrolled_by);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $conn->close();
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn = get_db_connection();
            $stmt = $conn->prepare(
                "DELETE FROM office_fingerprints WHERE id=? AND store_id=?"
            );
            $stmt->bind_param('ii', $id, $store_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }
    }

    header('Location: fingerprints.php');
    exit;
}

// ── GET: 데이터 조회 ───────────────────────────────────────────

// 등록된 지문 목록
$fingerprints = get_attendance_fingerprints($store_id);

// 직원별 등록 개수 (최대 2개: 지문1/지문2)
$emp_fp_counts = array_count_values(array_column($fingerprints, 'employee_id'));

// 사용 중인 슬롯 번호
$used_slots = array_column($fingerprints, 'finger_slot');

// 지문 2개 미만 등록된 active 직원 목록 (드롭다운용)
$all_employees  = get_office_employees($store_id, null, 'active');
$unregistered   = array_filter($all_employees, fn($e) => ($emp_fp_counts[$e['id']] ?? 0) < 2);
?>

<div class="mb-6">
  <h1 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-fingerprint text-blue-600 mr-2"></i>Fingerprint Registration
  </h1>
  <p class="text-sm text-gray-500 mt-1">
    지문 슬롯 번호와 직원을 매핑합니다. 슬롯 번호는 ESP32 센서에서 직접 등록된 번호입니다.
  </p>
</div>

<!-- 등록 폼 -->
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-6">
  <h2 class="text-sm font-semibold text-gray-700 mb-3">
    <i class="fa-solid fa-plus text-blue-500 mr-1"></i>새 지문 슬롯 등록
  </h2>

  <?php if (empty($unregistered)): ?>
    <p class="text-sm text-gray-500">모든 재직 직원의 지문이 등록되어 있습니다.</p>
  <?php else: ?>
  <form method="POST" class="flex flex-wrap items-end gap-3">
    <input type="hidden" name="action" value="add">

    <div>
      <label class="block text-xs text-gray-500 mb-1">직원 선택</label>
      <select name="employee_id" required
              class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
        <option value="">— 직원 선택 —</option>
        <?php foreach ($unregistered as $emp): ?>
          <?php $fp_cnt = $emp_fp_counts[$emp['id']] ?? 0; ?>
          <option value="<?= $emp['id'] ?>">
            <?= htmlspecialchars($emp['name']) ?>
            (<?= htmlspecialchars(get_job_role_label($emp['job_role'])) ?>)<?= $fp_cnt > 0 ? " — 지문{$fp_cnt}개 등록됨" : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-xs text-gray-500 mb-1">슬롯 번호 (1~127)</label>
      <input type="number" name="finger_slot" min="1" max="127" required
             placeholder="e.g. 1"
             class="w-28 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
    </div>

    <?php if (!empty($used_slots)): ?>
    <div class="text-xs text-gray-400">
      사용 중인 슬롯:
      <span class="font-mono text-orange-500"><?= implode(', ', $used_slots) ?></span>
    </div>
    <?php endif; ?>

    <button type="submit"
            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
      <i class="fa-solid fa-plus mr-1"></i>등록
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- 등록 목록 -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
  <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
    <h2 class="text-sm font-semibold text-gray-700">
      <i class="fa-solid fa-list text-gray-400 mr-1"></i>
      등록된 지문 슬롯 — <?= count($fingerprints) ?>명
    </h2>
  </div>

  <?php if (empty($fingerprints)): ?>
    <div class="px-5 py-8 text-center text-sm text-gray-400">
      등록된 지문이 없습니다. 위 폼에서 등록하세요.
    </div>
  <?php else: ?>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
      <tr>
        <th class="px-4 py-2.5 text-left">슬롯</th>
        <th class="px-4 py-2.5 text-left">직원명</th>
        <th class="px-4 py-2.5 text-left">직무</th>
        <th class="px-4 py-2.5 text-left">등록일</th>
        <th class="px-4 py-2.5 text-center">삭제</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($fingerprints as $fp): ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2.5">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-100 text-blue-700 font-mono font-bold text-sm">
            <?= (int)$fp['finger_slot'] ?>
          </span>
        </td>
        <td class="px-4 py-2.5 font-medium text-gray-800">
          <?= htmlspecialchars($fp['name']) ?>
        </td>
        <td class="px-4 py-2.5 text-gray-500">
          <?= htmlspecialchars(get_job_role_label($fp['job_role'])) ?>
        </td>
        <td class="px-4 py-2.5 text-gray-500">
          <?= date('Y-m-d', strtotime($fp['enrolled_at'])) ?>
        </td>
        <td class="px-4 py-2.5 text-center">
          <form method="POST"
                onsubmit="return confirm('<?= htmlspecialchars($fp['name']) ?> 슬롯 <?= (int)$fp['finger_slot'] ?>번 등록을 삭제하시겠습니까?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$fp['id'] ?>">
            <button type="submit"
                    class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded hover:bg-red-50">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
