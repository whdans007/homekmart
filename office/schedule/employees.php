<?php
$page_title      = '직원휴무관리 — 직원등록';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$roles    = get_job_roles();

define('EMP_PHOTO_DIR', __DIR__ . '/../../uploads/employees/');
define('EMP_PHOTO_URL', '../../uploads/employees/');


function save_employee_photo(array $file, int $store_id, int $emp_id): ?string {
    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    if (!is_dir(EMP_PHOTO_DIR)) mkdir(EMP_PHOTO_DIR, 0755, true);

    $filename = 'emp_' . $store_id . '_' . $emp_id . '_' . time() . '.' . $ext;
    $dest     = EMP_PHOTO_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    return $filename;
}

function delete_employee_photo(?string $filename): void {
    if ($filename && file_exists(EMP_PHOTO_DIR . $filename)) {
        unlink(EMP_PHOTO_DIR . $filename);
    }
}

// POST 처리 (등록/Edit/Status변경)
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
            $new_id = $conn->insert_id;
            $stmt->close();

            if ($new_id && !empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $filename = save_employee_photo($_FILES['photo'], $store_id, $new_id);
                if ($filename) {
                    $stmt2 = $conn->prepare("UPDATE office_employees SET photo=? WHERE id=?");
                    $stmt2->bind_param('si', $filename, $new_id);
                    $stmt2->execute();
                    $stmt2->close();
                }
            }
            $conn->close();
        }

    } elseif ($action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = post_str('name');
        $job_role = in_array($_POST['job_role'] ?? '', $roles) ? $_POST['job_role'] : '';
        if ($id && $name && $job_role) {
            $conn = get_db_connection();

            $new_photo = null;
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                // 기존 사진 파일명 조회 후 삭제
                $old = $conn->prepare("SELECT photo FROM office_employees WHERE id=? AND store_id=?");
                $old->bind_param('ii', $id, $store_id);
                $old->execute();
                $old_row = $old->get_result()->fetch_assoc();
                $old->close();
                delete_employee_photo($old_row['photo'] ?? null);

                $new_photo = save_employee_photo($_FILES['photo'], $store_id, $id);
            }

            if ($new_photo !== null) {
                $stmt = $conn->prepare("UPDATE office_employees SET name=?, job_role=?, photo=? WHERE id=? AND store_id=?");
                $stmt->bind_param('sssii', $name, $job_role, $new_photo, $id, $store_id);
            } else {
                $stmt = $conn->prepare("UPDATE office_employees SET name=?, job_role=? WHERE id=? AND store_id=?");
                $stmt->bind_param('ssii', $name, $job_role, $id, $store_id);
            }
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }

    } elseif ($action === 'toggle_status') {
        $id     = (int)($_POST['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($id) {
            $conn = get_db_connection();
            // 현재 상태 확인
            $cur = $conn->prepare("SELECT status FROM office_employees WHERE id=? AND store_id=?");
            $cur->bind_param('ii', $id, $store_id);
            $cur->execute();
            $cur_row = $cur->get_result()->fetch_assoc();
            $cur->close();

            if ($cur_row['status'] === 'active') {
                $today = date('Y-m-d');
                $stmt  = $conn->prepare(
                    "UPDATE office_employees SET status='inactive', inactive_reason=?, inactive_date=? WHERE id=? AND store_id=?"
                );
                $stmt->bind_param('ssii', $reason, $today, $id, $store_id);
            } else {
                $stmt = $conn->prepare(
                    "UPDATE office_employees SET status='active', inactive_reason=NULL, inactive_date=NULL WHERE id=? AND store_id=?"
                );
                $stmt->bind_param('ii', $id, $store_id);
            }
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn = get_db_connection();
            $chk = $conn->prepare("SELECT photo, status FROM office_employees WHERE id=? AND store_id=?");
            $chk->bind_param('ii', $id, $store_id);
            $chk->execute();
            $row = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($row && $row['status'] === 'inactive') {
                delete_employee_photo($row['photo'] ?? null);
                $stmt = $conn->prepare("DELETE FROM office_employees WHERE id=? AND store_id=?");
                $stmt->bind_param('ii', $id, $store_id);
                $stmt->execute();
                $stmt->close();
            }
            $conn->close();
        }

    } elseif ($action === 'fp_delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("SELECT employee_id, finger_slot FROM office_fingerprints WHERE id=? AND store_id=?");
            $stmt->bind_param('ii', $id, $store_id);
            $stmt->execute();
            $fp_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM office_fingerprints WHERE id=? AND store_id=?");
            $stmt->bind_param('ii', $id, $store_id);
            $stmt->execute();
            $stmt->close();
            $conn->close();

            // 웹에서 삭제 시 ESP32 센서에서도 해당 슬롯의 지문 템플릿을 삭제하도록 요청 등록
            if ($fp_row) {
                $requested_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                create_delete_request($store_id, (int)$fp_row['finger_slot'], (int)$fp_row['employee_id'], $requested_by);
            }
        }

    } elseif ($action === 'fp_reenroll') {
        // 재등록(갱신): 기존 지문 삭제 + 동기화 요청 후, 동일 직원에 대해 즉시 신규 등록 요청
        $id = (int)($_POST['id'] ?? 0);
        $fp_error = '';
        if ($id > 0) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("SELECT employee_id, finger_slot FROM office_fingerprints WHERE id=? AND store_id=?");
            $stmt->bind_param('ii', $id, $store_id);
            $stmt->execute();
            $fp_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($fp_row) {
                $stmt = $conn->prepare("DELETE FROM office_fingerprints WHERE id=? AND store_id=?");
                $stmt->bind_param('ii', $id, $store_id);
                $stmt->execute();
                $stmt->close();
                $conn->close();

                $requested_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                create_delete_request($store_id, (int)$fp_row['finger_slot'], (int)$fp_row['employee_id'], $requested_by);

                $request_id = create_enroll_request($store_id, (int)$fp_row['employee_id'], $requested_by);
                if ($request_id > 0) {
                    header('Location: employees.php?enroll_track=' . $request_id);
                } else {
                    header('Location: employees.php?fp_err=busy');
                }
                exit;
            }
            $conn->close();
        } else {
            $fp_error = 'invalid';
        }

        header('Location: employees.php' . ($fp_error ? '?fp_err=' . $fp_error : ''));
        exit;

    } elseif ($action === 'fp_enroll_request') {
        // Design Ref: §4.3 — 단말기 원격 등록 트리거 (웹 -> ESP32 polling)
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $fp_error = '';

        if ($employee_id > 0) {
            $conn = get_db_connection();
            $chk = $conn->prepare("SELECT id FROM office_employees WHERE id=? AND store_id=? AND status='active'");
            $chk->bind_param('ii', $employee_id, $store_id);
            $chk->execute();
            $valid = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($valid) {
                $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM office_fingerprints WHERE store_id=? AND employee_id=?");
                $cnt->bind_param('ii', $store_id, $employee_id);
                $cnt->execute();
                $count = (int)($cnt->get_result()->fetch_assoc()['c'] ?? 0);
                $cnt->close();

                if ($count >= 2) {
                    $fp_error = 'limit';
                } else {
                    $requested_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                    $request_id = create_enroll_request($store_id, $employee_id, $requested_by);
                    $conn->close();
                    if ($request_id > 0) {
                        header('Location: employees.php?enroll_track=' . $request_id);
                    } else {
                        header('Location: employees.php?fp_err=busy');
                    }
                    exit;
                }
            }
            $conn->close();
        } else {
            $fp_error = 'invalid';
        }

        header('Location: employees.php' . ($fp_error ? '?fp_err=' . $fp_error : ''));
        exit;

    } elseif ($action === 'fp_enroll_cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            cancel_enroll_request($id, $store_id);
        }

    } elseif ($action === 'fp_reset_sensor') {
        // Design Ref: §4.3 — ESP32 센서에 남아있는 모든 지문 템플릿 초기화 (emptyDatabase)
        $requested_by = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $reset_id = create_delete_all_request($store_id, $requested_by);
        if ($reset_id > 0) {
            header('Location: employees.php?reset_track=' . $reset_id);
            exit;
        }

    } elseif ($action === 'fp_reset_cancel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            cancel_enroll_request($id, $store_id);
        }
    }

    header('Location: employees.php');
    exit;
}

// Employee List (전체, Role별 그룹)
$filter_role = $_GET['role'] ?? 'all';
$employees   = get_office_employees($store_id, $filter_role === 'all' ? null : $filter_role, 'active');
$inactive    = get_office_employees($store_id, $filter_role === 'all' ? null : $filter_role, 'inactive');

// 지문 등록 현황 (직원별, 최대 2개: 지문1/지문2)
$fp_by_emp   = get_fingerprints_by_employee($store_id);

$fp_err_messages = [
    'limit' => '한 직원당 최대 2개까지 등록할 수 있습니다.',
    'busy'  => '이미 다른 직원의 지문 등록이 진행 중입니다. 완료 후 다시 시도해주세요.',
];
$fp_err = $fp_err_messages[$_GET['fp_err'] ?? ''] ?? null;

// 지문 등록(enroll) 진행 상태 추적
$enroll_track   = isset($_GET['enroll_track']) ? (int)$_GET['enroll_track'] : 0;
$enroll_request = $enroll_track > 0 ? get_enroll_request_by_id($enroll_track, $store_id) : null;
// enroll_track 파라미터가 없어도(예: 페이지 새로고침), 진행 중인 요청이 있으면 표시 + 취소 가능하게
if (!$enroll_request) {
    $enroll_request = get_active_enroll_request($store_id);
}

// 센서 전체 초기화(delete_all) 진행 상태 추적
$reset_track   = isset($_GET['reset_track']) ? (int)$_GET['reset_track'] : 0;
$reset_request = $reset_track > 0 ? get_latest_delete_all_request($store_id) : null;
if ($reset_request && (int)($reset_request['id'] ?? 0) !== $reset_track) {
    $reset_request = null;
}
if (!$reset_request) {
    $reset_request = get_active_delete_all_request($store_id);
}
?>

<?php if ($fp_err): ?>
<div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg">
  <i class="fa-solid fa-triangle-exclamation mr-1"></i><?= htmlspecialchars($fp_err) ?>
</div>
<?php endif; ?>

<?php if ($enroll_request): ?>
  <?php if (in_array($enroll_request['status'], ['pending', 'enrolling'], true)): ?>
  <meta http-equiv="refresh" content="3;url=employees.php?enroll_track=<?= (int)$enroll_request['id'] ?>">
  <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 text-blue-700 text-sm rounded-lg flex items-center justify-between gap-3">
    <div>
      <i class="fa-solid fa-fingerprint mr-1 animate-pulse"></i>
      <strong><?= htmlspecialchars($enroll_request['name']) ?></strong>님의 지문 등록
      <?= $enroll_request['status'] === 'pending' ? '대기 중...' : '진행 중...' ?>
      ESP32 단말기에서 화면 안내에 따라 손가락을 스캔하세요. (3초마다 자동 새로고침)
    </div>
    <form method="POST" class="flex-shrink-0">
      <input type="hidden" name="action" value="fp_enroll_cancel">
      <input type="hidden" name="id" value="<?= (int)$enroll_request['id'] ?>">
      <button type="submit" class="text-xs text-blue-400 hover:text-red-500 underline">취소</button>
    </form>
  </div>
  <?php elseif ($enroll_request['status'] === 'success'): ?>
  <div class="mb-4 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg">
    <i class="fa-solid fa-circle-check mr-1"></i>
    <strong><?= htmlspecialchars($enroll_request['name']) ?></strong>님의 지문이 슬롯 <?= (int)$enroll_request['result_slot'] ?>번으로 등록되었습니다.
  </div>
  <?php elseif ($enroll_request['status'] === 'failed'): ?>
  <div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg">
    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
    <strong><?= htmlspecialchars($enroll_request['name']) ?></strong>님의 지문 등록에 실패했습니다.
    (<?= htmlspecialchars($enroll_request['error_message'] ?? 'unknown') ?>)
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($reset_request): ?>
  <?php if (in_array($reset_request['status'], ['pending', 'enrolling'], true)): ?>
  <meta http-equiv="refresh" content="3;url=employees.php?reset_track=<?= (int)$reset_request['id'] ?>">
  <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-lg flex items-center justify-between gap-3">
    <div>
      <i class="fa-solid fa-fingerprint mr-1 animate-pulse"></i>
      ESP32 센서 지문 전체 초기화 진행 중... (3초마다 자동 새로고침)
    </div>
    <form method="POST" class="flex-shrink-0">
      <input type="hidden" name="action" value="fp_reset_cancel">
      <input type="hidden" name="id" value="<?= (int)$reset_request['id'] ?>">
      <button type="submit" class="text-xs text-amber-500 hover:text-red-500 underline">취소</button>
    </form>
  </div>
  <?php elseif ($reset_request['status'] === 'success'): ?>
  <div class="mb-4 px-4 py-2.5 bg-green-50 border border-green-200 text-green-700 text-sm rounded-lg">
    <i class="fa-solid fa-circle-check mr-1"></i>
    ESP32 센서의 지문 데이터가 모두 초기화되었습니다.
  </div>
  <?php elseif ($reset_request['status'] === 'failed'): ?>
  <div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg">
    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
    센서 초기화에 실패했습니다. (<?= htmlspecialchars($reset_request['error_message'] ?? 'unknown') ?>)
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="flex items-center justify-between mb-1">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-users mr-2 text-green-600"></i>직원등록
    <span class="ml-2 text-sm font-normal text-gray-400">
      <i class="fa-solid fa-store mr-1"></i><?php echo htmlspecialchars($_office_store_name); ?>
    </span>
  </h2>
  <div class="flex gap-2">
    <form method="POST" onsubmit="return confirm('ESP32 센서에 저장된 모든 지문 데이터를 삭제합니다. 등록된 모든 직원의 지문을 다시 등록해야 합니다. 계속하시겠습니까?')">
      <input type="hidden" name="action" value="fp_reset_sensor">
      <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-sm font-medium" title="ESP32 센서에 남아있는 모든 지문 템플릿을 초기화합니다">
        <i class="fa-solid fa-fingerprint mr-1"></i>센서 지문 전체 초기화
      </button>
    </form>
    <button onclick="openAddModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-plus mr-1"></i>Add Employee
    </button>
  </div>
</div>
<p class="text-xs text-gray-400 mb-6">
  각 직원 카드의 <i class="fa-solid fa-fingerprint mr-0.5"></i><i class="fa-solid fa-plus text-[8px]"></i> 버튼을 누르면
  ESP32 단말기에 등록 요청이 전송됩니다. 단말기 화면 안내에 따라 손가락을 두 번 스캔하면 자동으로 등록이 완료됩니다.
</p>

<!-- Role 필터 탭 -->
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
  <p class="text-gray-400 text-sm py-6 text-center bg-white rounded-xl border border-gray-100">No employees registered.</p>
  <?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
    <?php foreach ($employees as $emp): ?>
    <?php $photo_url = !empty($emp['photo']) ? EMP_PHOTO_URL . htmlspecialchars($emp['photo']) : null; ?>
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden flex hover:border-green-300 transition-colors" style="min-height:90px">
      <!-- 좌측 사진 -->
      <div class="w-24 flex-shrink-0 <?php echo $photo_url ? '' : 'bg-green-50'; ?> flex items-center justify-center overflow-hidden">
        <?php if ($photo_url): ?>
        <img src="<?php echo $photo_url; ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>"
             class="w-full h-full object-cover" style="min-height:90px">
        <?php else: ?>
        <i class="fa-solid fa-user text-green-300 text-3xl"></i>
        <?php endif; ?>
      </div>
      <!-- 우측 정보 -->
      <div class="flex-1 p-3 flex flex-col justify-between min-w-0">
        <div>
          <div class="font-semibold text-gray-800 text-sm truncate">
            <?php echo htmlspecialchars($emp['name']); ?>
            <span class="text-gray-400 font-normal text-xs">#<?php echo (int)$emp['id']; ?></span>
          </div>
          <div class="text-xs text-gray-500 mt-0.5"><?php echo get_job_role_label($emp['job_role']); ?></div>
          <!-- 지문 등록 (지문1 / 지문2) -->
          <div class="flex gap-1 mt-1.5">
            <?php
            $emp_fps = $fp_by_emp[$emp['id']] ?? [];
            for ($slot_idx = 0; $slot_idx < 2; $slot_idx++):
                $fp = $emp_fps[$slot_idx] ?? null;
            ?>
              <?php if ($fp): ?>
              <span class="inline-flex items-center gap-1 text-xs bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded-md"
                    title="지문<?php echo $slot_idx + 1; ?> · 슬롯 <?php echo (int)$fp['finger_slot']; ?>">
                <i class="fa-solid fa-fingerprint"></i><?php echo (int)$fp['finger_slot']; ?>
                <form method="POST" class="inline"
                      onsubmit="return confirm('지문<?php echo $slot_idx + 1; ?> (슬롯 <?php echo (int)$fp['finger_slot']; ?>번)을 재등록하시겠습니까? 기존 지문은 삭제되고 새 지문을 등록하게 됩니다.')">
                  <input type="hidden" name="action" value="fp_reenroll">
                  <input type="hidden" name="id" value="<?php echo (int)$fp['id']; ?>">
                  <button type="submit" class="ml-0.5 text-blue-300 hover:text-amber-500" title="재등록"><i class="fa-solid fa-rotate"></i></button>
                </form>
                <form method="POST" class="inline"
                      onsubmit="return confirm('지문<?php echo $slot_idx + 1; ?> (슬롯 <?php echo (int)$fp['finger_slot']; ?>번) 등록을 삭제하시겠습니까?')">
                  <input type="hidden" name="action" value="fp_delete">
                  <input type="hidden" name="id" value="<?php echo (int)$fp['id']; ?>">
                  <button type="submit" class="ml-0.5 text-blue-300 hover:text-red-500"><i class="fa-solid fa-xmark"></i></button>
                </form>
              </span>
              <?php elseif ($enroll_request && in_array($enroll_request['status'], ['pending','enrolling'], true)): ?>
              <span title="다른 직원의 등록이 진행 중입니다"
                    class="inline-flex items-center gap-1 text-xs border border-dashed border-gray-200 text-gray-300 px-1.5 py-0.5 rounded-md cursor-not-allowed">
                <i class="fa-solid fa-fingerprint"></i><i class="fa-solid fa-plus text-[9px]"></i>
              </span>
              <?php else: ?>
              <form method="POST" class="inline">
                <input type="hidden" name="action" value="fp_enroll_request">
                <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                <button type="submit"
                        title="지문<?php echo $slot_idx + 1; ?> 등록 - ESP32 단말기에 등록 요청 전송"
                        class="inline-flex items-center gap-1 text-xs border border-dashed border-gray-300 text-gray-400 px-1.5 py-0.5 rounded-md hover:border-blue-400 hover:text-blue-500">
                  <i class="fa-solid fa-fingerprint"></i><i class="fa-solid fa-plus text-[9px]"></i>
                </button>
              </form>
              <?php endif; ?>
            <?php endfor; ?>
          </div>
        </div>
        <div class="flex gap-2 mt-2">
          <button onclick="openEditModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['name'])); ?>', '<?php echo $emp['job_role']; ?>', '<?php echo $photo_url ?? ''; ?>')"
                  class="text-xs text-blue-600 hover:underline">Edit</button>
          <span class="text-gray-300">|</span>
          <button onclick="openDeactivateModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['name'])); ?>')"
                  class="text-xs text-gray-400 hover:text-red-500 hover:underline">비Active</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 비Active 직원 -->
<?php if (!empty($inactive)): ?>
<div>
  <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wide mb-3">비Active (<?php echo count($inactive); ?>명)</h3>
  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
    <?php foreach ($inactive as $emp): ?>
    <?php $photo_url = !empty($emp['photo']) ? EMP_PHOTO_URL . htmlspecialchars($emp['photo']) : null; ?>
    <div class="bg-gray-50 rounded-xl border border-gray-200 overflow-hidden flex opacity-60" style="min-height:90px">
      <!-- 좌측 사진 -->
      <div class="w-24 flex-shrink-0 <?php echo $photo_url ? '' : 'bg-gray-200'; ?> flex items-center justify-center overflow-hidden">
        <?php if ($photo_url): ?>
        <img src="<?php echo $photo_url; ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>"
             class="w-full h-full object-cover grayscale" style="min-height:90px">
        <?php else: ?>
        <i class="fa-solid fa-user text-gray-400 text-3xl"></i>
        <?php endif; ?>
      </div>
      <!-- 우측 정보 -->
      <div class="flex-1 p-3 flex flex-col justify-between min-w-0">
        <div>
          <div class="font-semibold text-gray-500 text-sm truncate">
            <?php echo htmlspecialchars($emp['name']); ?>
            <span class="text-gray-400 font-normal text-xs">#<?php echo (int)$emp['id']; ?></span>
          </div>
          <div class="text-xs text-gray-400 mt-0.5"><?php echo get_job_role_label($emp['job_role']); ?></div>
          <?php if (!empty($emp['inactive_date'])): ?>
          <div class="text-xs text-gray-400 mt-1">
            <i class="fa-solid fa-calendar-xmark mr-0.5"></i><?php echo $emp['inactive_date']; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($emp['inactive_reason'])): ?>
          <div class="text-xs text-red-400 mt-0.5 line-clamp-2" title="<?php echo htmlspecialchars($emp['inactive_reason']); ?>">
            <i class="fa-solid fa-circle-info mr-0.5"></i><?php echo htmlspecialchars($emp['inactive_reason']); ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="flex gap-2 mt-2">
          <form method="POST" class="inline">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
            <button type="submit" class="text-xs text-green-600 hover:underline">복직</button>
          </form>
          <span class="text-gray-300">|</span>
          <button onclick="openDeleteModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['name'])); ?>')"
                  class="text-xs text-red-400 hover:text-red-600 hover:underline">삭제</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- 삭제 확인 모달 -->
<div id="modal_delete" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
        <i class="fa-solid fa-triangle-exclamation text-red-500"></i>
      </div>
      <h3 class="text-lg font-bold text-gray-800">직원 영구 삭제</h3>
    </div>
    <p class="text-sm text-gray-600 mb-1">
      <span id="delete_name" class="font-semibold text-gray-800"></span> 직원을 완전히 삭제합니다.
    </p>
    <p class="text-xs text-red-500 mb-5">이 작업은 되돌릴 수 없습니다.</p>
    <form method="POST">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" id="delete_id">
      <div class="flex gap-3">
        <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg text-sm font-medium">영구 삭제</button>
        <button type="button" onclick="closeModal('modal_delete')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- 비Active 사유 모달 -->
<div id="modal_deactivate" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-1">비Active 처리</h3>
    <p class="text-sm text-gray-500 mb-4"><span id="deactivate_name" class="font-medium text-gray-700"></span> 직원을 비Active 처리합니다.</p>
    <form method="POST" id="form_deactivate" class="space-y-4">
      <input type="hidden" name="action" value="toggle_status">
      <input type="hidden" name="id" id="deactivate_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">사유 <span class="text-gray-400 font-normal">(선택)</span></label>
        <textarea name="reason" id="deactivate_reason" rows="3" placeholder="퇴직, 휴직, 계약 만료 등..."
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-red-400 resize-none"></textarea>
      </div>
      <div class="flex gap-3 pt-1">
        <button type="submit" class="flex-1 bg-red-500 hover:bg-red-600 text-white py-2 rounded-lg text-sm font-medium">비Active</button>
        <button type="button" onclick="closeModal('modal_deactivate')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- 등록 모달 -->
<div id="modal_add" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-5">Add Employee</h3>
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="action" value="add">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" required autofocus
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
        <select name="job_role" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
          <option value="">Role 선택</option>
          <?php foreach ($roles as $r): ?>
          <option value="<?php echo $r; ?>"><?php echo get_job_role_label($r); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">사진 (선택)</label>
        <div class="flex items-center gap-3">
          <div id="add_preview_wrap" class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0 border border-gray-200">
            <i class="fa-solid fa-user text-gray-400 text-lg" id="add_preview_icon"></i>
            <img id="add_preview_img" class="w-full h-full object-cover hidden">
          </div>
          <label class="cursor-pointer flex-1">
            <span class="block w-full text-center py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-500 hover:border-green-400 hover:text-green-600 transition-colors">
              <i class="fa-solid fa-camera mr-1"></i>사진 선택
            </span>
            <input type="file" name="photo" accept="image/*" class="hidden" onchange="previewPhoto(this,'add')">
          </label>
        </div>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 rounded-lg text-sm font-medium">등록</button>
        <button type="button" onclick="closeModal('modal_add')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit 모달 -->
<div id="modal_edit" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-5">직원 Edit</h3>
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="edit_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="edit_name" required
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Role <span class="text-red-500">*</span></label>
        <select name="job_role" id="edit_role" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
          <?php foreach ($roles as $r): ?>
          <option value="<?php echo $r; ?>"><?php echo get_job_role_label($r); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">사진 변경 (선택)</label>
        <div class="flex items-center gap-3">
          <div id="edit_preview_wrap" class="w-14 h-14 rounded-full bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0 border border-gray-200">
            <i class="fa-solid fa-user text-gray-400 text-lg" id="edit_preview_icon"></i>
            <img id="edit_preview_img" class="w-full h-full object-cover hidden">
          </div>
          <label class="cursor-pointer flex-1">
            <span class="block w-full text-center py-2 border border-dashed border-gray-300 rounded-lg text-sm text-gray-500 hover:border-blue-400 hover:text-blue-600 transition-colors">
              <i class="fa-solid fa-camera mr-1"></i>사진 변경
            </span>
            <input type="file" name="photo" accept="image/*" class="hidden" onchange="previewPhoto(this,'edit')">
          </label>
        </div>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">Save</button>
        <button type="button" onclick="closeModal('modal_edit')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function openAddModal() {
    // 미리보기 초기화
    setPreview('add', null);
    document.getElementById('modal_add').classList.remove('hidden');
}
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function openEditModal(id, name, role, photoUrl) {
    document.getElementById('edit_id').value   = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_role').value = role;
    setPreview('edit', photoUrl || null);
    document.getElementById('modal_edit').classList.remove('hidden');
}

function setPreview(prefix, src) {
    const img  = document.getElementById(prefix + '_preview_img');
    const icon = document.getElementById(prefix + '_preview_icon');
    if (src) {
        img.src = src;
        img.classList.remove('hidden');
        icon.classList.add('hidden');
    } else {
        img.src = '';
        img.classList.add('hidden');
        icon.classList.remove('hidden');
    }
}

function previewPhoto(input, prefix) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => setPreview(prefix, e.target.result);
    reader.readAsDataURL(input.files[0]);
}

function openDeleteModal(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    document.getElementById('modal_delete').classList.remove('hidden');
}

function openDeactivateModal(id, name) {
    document.getElementById('deactivate_id').value    = id;
    document.getElementById('deactivate_name').textContent = name;
    document.getElementById('deactivate_reason').value = '';
    document.getElementById('modal_deactivate').classList.remove('hidden');
    setTimeout(() => document.getElementById('deactivate_reason').focus(), 50);
}

// 모달 외부 클릭 닫기
['modal_add','modal_edit','modal_deactivate','modal_delete'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
