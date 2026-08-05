<?php
$page_title      = '직원휴무관리 — 직원등록';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$roles    = get_job_roles();
$agencies = get_agency_options();

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
        $name      = post_str('name');
        $job_role  = in_array($_POST['job_role'] ?? '', $roles) ? $_POST['job_role'] : '';
        $agency    = in_array($_POST['agency'] ?? '', $agencies, true) ? $_POST['agency'] : null;
        $hire_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hire_date'] ?? '') ? $_POST['hire_date'] : null;
        if ($name && $job_role) {
            $conn = get_db_connection();
            $stmt = $conn->prepare("INSERT INTO office_employees (store_id, name, job_role, agency, hire_date) VALUES (?,?,?,?,?)");
            $stmt->bind_param('issss', $store_id, $name, $job_role, $agency, $hire_date);
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

            // Design Ref: employee-hire-transfer §4 — 입사일을 발령 이력에 자동 기록
            if ($new_id) {
                $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                add_employee_history($new_id, $store_id, 'hire', '입사', $hire_date ?: date('Y-m-d'), $by);
            }
        }

    } elseif ($action === 'edit') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = post_str('name');
        $job_role  = in_array($_POST['job_role'] ?? '', $roles) ? $_POST['job_role'] : '';
        $agency    = in_array($_POST['agency'] ?? '', $agencies, true) ? $_POST['agency'] : null;
        $hire_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['hire_date'] ?? '') ? $_POST['hire_date'] : null;
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
                $stmt = $conn->prepare("UPDATE office_employees SET name=?, job_role=?, agency=?, hire_date=?, photo=? WHERE id=? AND store_id=?");
                $stmt->bind_param('sssssii', $name, $job_role, $agency, $hire_date, $new_photo, $id, $store_id);
            } else {
                $stmt = $conn->prepare("UPDATE office_employees SET name=?, job_role=?, agency=?, hire_date=? WHERE id=? AND store_id=?");
                $stmt->bind_param('ssssii', $name, $job_role, $agency, $hire_date, $id, $store_id);
            }
            $stmt->execute();
            $stmt->close();
            $conn->close();
        }

    } elseif ($action === 'history_add') {
        // Design Ref: employee-hire-transfer §4 — 발령 이력 수동 추가
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $event_type  = in_array($_POST['event_type'] ?? '', ['role_change', 'promotion', 'note'], true) ? $_POST['event_type'] : 'note';
        $content     = post_str('content');
        $event_date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['event_date'] ?? '') ? $_POST['event_date'] : date('Y-m-d');
        if ($employee_id && $content !== '') {
            $conn = get_db_connection();
            $chk = $conn->prepare("SELECT id FROM office_employees WHERE id=? AND store_id=?");
            $chk->bind_param('ii', $employee_id, $store_id);
            $chk->execute();
            $owned = $chk->get_result()->fetch_assoc();
            $chk->close();
            $conn->close();

            if ($owned) {
                $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                add_employee_history($employee_id, $store_id, $event_type, $content, $event_date, $by);
            }
        }

    } elseif ($action === 'transfer_request') {
        // Design Ref: employee-hire-transfer §4 — 점포 전입 승인 요청 생성
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $to_store_id = (int)($_POST['to_store_id'] ?? 0);
        $reason      = post_str('reason');
        $transfer_err = '';
        if ($employee_id && $to_store_id) {
            $conn = get_db_connection();
            $chk = $conn->prepare("SELECT id FROM office_employees WHERE id=? AND store_id=?");
            $chk->bind_param('ii', $employee_id, $store_id);
            $chk->execute();
            $owned = $chk->get_result()->fetch_assoc();
            $chk->close();
            $conn->close();

            if ($owned) {
                $by = (int)($_SESSION['user_id'] ?? 0) ?: null;
                $result = create_employee_transfer_request($employee_id, $store_id, $to_store_id, $reason, $by);
                if ($result !== true) $transfer_err = 'duplicate_transfer';
            }
        }
        header('Location: employees.php' . ($transfer_err ? '?err=' . $transfer_err : ''));
        exit;

    } elseif ($action === 'transfer_decide') {
        // Design Ref: employee-hire-transfer §4/§6 — 전입 승인/반려 (목적지 점포 재검증 필수)
        $request_id = (int)($_POST['request_id'] ?? 0);
        $approve    = ($_POST['approve'] ?? '') === '1';
        $note       = post_str('decision_note');
        if ($request_id) {
            $approver = (int)($_SESSION['user_id'] ?? 0) ?: 0;
            decide_employee_transfer($request_id, $approve, $store_id, $approver, $note);
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

// 발령 이력 / 점포 전입 (Design Ref: employee-hire-transfer.design.md §2.2)
$employee_history          = get_employee_history_by_store($store_id);
$pending_transfers_in      = get_pending_transfers_to_store($store_id);
$employee_ids_with_pending = get_employee_ids_with_pending_transfer($store_id);
$transfer_target_stores    = array_values(array_filter(get_all_stores(), fn($s) => (int)$s['id'] !== $store_id));

$fp_err_messages = [
    'limit' => '한 직원당 최대 2개까지 등록할 수 있습니다.',
    'busy'  => '이미 다른 직원의 지문 등록이 진행 중입니다. 완료 후 다시 시도해주세요.',
];
$fp_err = $fp_err_messages[$_GET['fp_err'] ?? ''] ?? null;

$transfer_err_messages = [
    'duplicate_transfer' => '이미 처리 대기중인 전입 요청이 있습니다.',
];
$transfer_err = $transfer_err_messages[$_GET['err'] ?? ''] ?? null;

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

// 정보 모달용 직원 데이터 맵 (사진 클릭 시 JS에서 id로 조회, Design Ref: §3.1)
$emp_data_map = [];
foreach (array_merge($employees, $inactive) as $e) {
    $e_photo_url = !empty($e['photo']) ? EMP_PHOTO_URL . $e['photo'] : null;
    $e_fp_slots  = array_map(fn($f) => (int)$f['finger_slot'], $fp_by_emp[$e['id']] ?? []);
    $emp_data_map[$e['id']] = [
        'name'            => $e['name'],
        'roleLabel'       => get_job_role_label($e['job_role']),
        'agency'          => $e['agency'] ?? '',
        'photoUrl'        => $e_photo_url ?? '',
        'status'          => $e['status'],
        'inactiveDate'    => $e['inactive_date'] ?? '',
        'inactiveReason'  => $e['inactive_reason'] ?? '',
        'fpSlots'         => implode(', ', $e_fp_slots),
        'hireDate'        => $e['hire_date'] ?? '',
        'history'         => $employee_history[$e['id']] ?? [],
        'hasPendingTransfer' => in_array((int)$e['id'], $employee_ids_with_pending, true),
    ];
}

$history_type_labels = ['hire' => '입사', 'transfer' => '전입', 'role_change' => '직책변경', 'promotion' => '승진', 'note' => '메모'];
?>

<?php if ($fp_err): ?>
<div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg">
  <i class="fa-solid fa-triangle-exclamation mr-1"></i><?= htmlspecialchars($fp_err) ?>
</div>
<?php endif; ?>

<?php if ($transfer_err): ?>
<div class="mb-4 px-4 py-2.5 bg-red-50 border border-red-200 text-red-600 text-sm rounded-lg">
  <i class="fa-solid fa-triangle-exclamation mr-1"></i><?= htmlspecialchars($transfer_err) ?>
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
    <?php if (!empty($pending_transfers_in)): ?>
    <button onclick="openTransfersIncomingModal()" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-right-to-bracket mr-1"></i>전입 승인 대기 <?php echo count($pending_transfers_in); ?>건
    </button>
    <?php endif; ?>
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
      <?php
        $emp_fps_slots = array_map(fn($f) => (int)$f['finger_slot'], $fp_by_emp[$emp['id']] ?? []);
      ?>
      <div class="w-24 flex-shrink-0 <?php echo $photo_url ? '' : 'bg-green-50'; ?> flex items-center justify-center overflow-hidden cursor-pointer"
           title="정보 보기"
           onclick="openInfoModal(<?php echo $emp['id']; ?>)">
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
          <div class="text-xs text-gray-500 mt-0.5">
            <?php echo get_job_role_label($emp['job_role']); ?>
            <?php if (!empty($emp['agency'])): ?>
            <span class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-600"><?php echo htmlspecialchars($emp['agency']); ?></span>
            <?php endif; ?>
          </div>
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
          <button onclick="openEditModal(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['name'])); ?>', '<?php echo $emp['job_role']; ?>', '<?php echo htmlspecialchars(addslashes($emp['agency'] ?? '')); ?>', '<?php echo $photo_url ?? ''; ?>', '<?php echo htmlspecialchars(addslashes($emp['hire_date'] ?? '')); ?>')"
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
      <?php
        $emp_fps_slots = array_map(fn($f) => (int)$f['finger_slot'], $fp_by_emp[$emp['id']] ?? []);
      ?>
      <div class="w-24 flex-shrink-0 <?php echo $photo_url ? '' : 'bg-gray-200'; ?> flex items-center justify-center overflow-hidden cursor-pointer"
           title="정보 보기"
           onclick="openInfoModal(<?php echo $emp['id']; ?>)">
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
          <div class="text-xs text-gray-400 mt-0.5">
            <?php echo get_job_role_label($emp['job_role']); ?>
            <?php if (!empty($emp['agency'])): ?>
            <span class="inline-flex items-center ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-200 text-gray-500"><?php echo htmlspecialchars($emp['agency']); ?></span>
            <?php endif; ?>
          </div>
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
        <label class="block text-sm font-medium text-gray-700 mb-1">소속 에이전시 <span class="text-gray-400 font-normal">(선택)</span></label>
        <select name="agency" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
          <option value="">미지정</option>
          <?php foreach ($agencies as $a): ?>
          <option value="<?php echo htmlspecialchars($a); ?>"><?php echo htmlspecialchars($a); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">입사일자 <span class="text-gray-400 font-normal">(선택)</span></label>
        <input type="date" name="hire_date"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500">
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
        <label class="block text-sm font-medium text-gray-700 mb-1">소속 에이전시 <span class="text-gray-400 font-normal">(선택)</span></label>
        <select name="agency" id="edit_agency" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
          <option value="">미지정</option>
          <?php foreach ($agencies as $a): ?>
          <option value="<?php echo htmlspecialchars($a); ?>"><?php echo htmlspecialchars($a); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">입사일자 <span class="text-gray-400 font-normal">(선택)</span></label>
        <input type="date" name="hire_date" id="edit_hire_date"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
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

<!-- 직원 정보 모달 -->
<div id="modal_info" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <div class="flex items-center gap-4 mb-4">
      <div id="info_photo_wrap" class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0 border border-gray-200">
        <i class="fa-solid fa-user text-gray-400 text-2xl" id="info_photo_icon"></i>
        <img id="info_photo_img" class="w-full h-full object-cover hidden">
      </div>
      <div class="min-w-0">
        <div class="text-lg font-bold text-gray-800 truncate"><span id="info_name"></span> <span class="text-gray-400 font-normal text-sm">#<span id="info_id"></span></span></div>
        <span id="info_status" class="inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium"></span>
      </div>
    </div>
    <div class="space-y-2 text-sm">
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-400">Role</span>
        <span id="info_role" class="text-gray-700 font-medium"></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-400">소속 에이전시</span>
        <span id="info_agency" class="text-gray-700 font-medium"></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-400">지문 등록</span>
        <span id="info_fp" class="text-gray-700 font-medium"></span>
      </div>
      <div class="flex justify-between border-b border-gray-100 pb-2">
        <span class="text-gray-400">입사일자</span>
        <span id="info_hire_date" class="text-gray-700 font-medium"></span>
      </div>
      <div id="info_inactive_wrap" class="hidden">
        <div class="flex justify-between border-b border-gray-100 pb-2 pt-2">
          <span class="text-gray-400">비Active 일자</span>
          <span id="info_inactive_date" class="text-gray-700 font-medium"></span>
        </div>
        <div class="pt-2">
          <span class="text-gray-400 block mb-1">사유</span>
          <span id="info_inactive_reason" class="text-red-500 text-xs"></span>
        </div>
      </div>
    </div>

    <div class="pt-4">
      <div class="flex items-center justify-between mb-2">
        <span class="text-sm font-semibold text-gray-600">발령 이력</span>
        <button type="button" onclick="openHistoryAddModal()" class="text-xs text-blue-600 hover:underline">
          <i class="fa-solid fa-plus mr-0.5"></i>추가
        </button>
      </div>
      <div id="info_history_list" class="space-y-1.5 max-h-40 overflow-y-auto text-xs"></div>
    </div>

    <div class="pt-5 flex gap-3">
      <button type="button" id="info_transfer_btn" onclick="openTransferRequestModal()"
              class="flex-1 bg-orange-50 hover:bg-orange-100 text-orange-600 py-2 rounded-lg text-sm font-medium">전입 요청</button>
      <button type="button" onclick="closeModal('modal_info')"
              class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">닫기</button>
    </div>
  </div>
</div>

<!-- 발령 추가 모달 -->
<div id="modal_history_add" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-5">발령 추가</h3>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="history_add">
      <input type="hidden" name="employee_id" id="history_employee_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">날짜</label>
        <input type="date" name="event_date" id="history_event_date"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">유형</label>
        <select name="event_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500">
          <option value="promotion">승진</option>
          <option value="role_change">직책변경</option>
          <option value="note">메모</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">내용 <span class="text-red-500">*</span></label>
        <textarea name="content" rows="3" required
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 resize-none"></textarea>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium">추가</button>
        <button type="button" onclick="closeModal('modal_history_add')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- 전입 요청 모달 -->
<div id="modal_transfer_request" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-1">전입 요청</h3>
    <p class="text-sm text-gray-500 mb-4"><span id="transfer_emp_name" class="font-medium text-gray-700"></span> 직원의 점포 전입을 요청합니다. 목적지 점포의 승인 후 반영됩니다.</p>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="action" value="transfer_request">
      <input type="hidden" name="employee_id" id="transfer_employee_id">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">목적지 점포 <span class="text-red-500">*</span></label>
        <select name="to_store_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500">
          <option value="">점포 선택</option>
          <?php foreach ($transfer_target_stores as $ts): ?>
          <option value="<?php echo (int)$ts['id']; ?>"><?php echo htmlspecialchars($ts['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">사유 <span class="text-gray-400 font-normal">(선택)</span></label>
        <textarea name="reason" rows="3" placeholder="인력 재배치 사유 등..."
                  class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-orange-500 resize-none"></textarea>
      </div>
      <div class="flex gap-3 pt-2">
        <button type="submit" class="flex-1 bg-orange-500 hover:bg-orange-600 text-white py-2 rounded-lg text-sm font-medium">요청</button>
        <button type="button" onclick="closeModal('modal_transfer_request')"
                class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- 전입 승인 대기 목록 모달 -->
<div id="modal_transfers_incoming" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-md mx-4 p-6">
    <h3 class="text-lg font-bold text-gray-800 mb-4">전입 승인 대기</h3>
    <div class="space-y-3 max-h-96 overflow-y-auto">
      <?php if (empty($pending_transfers_in)): ?>
      <p class="text-gray-400 text-sm text-center py-4">대기중인 전입 요청이 없습니다.</p>
      <?php endif; ?>
      <?php foreach ($pending_transfers_in as $pt): ?>
      <?php $pt_photo = !empty($pt['photo']) ? EMP_PHOTO_URL . $pt['photo'] : null; ?>
      <div class="border border-gray-200 rounded-lg p-3">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center overflow-hidden flex-shrink-0">
            <?php if ($pt_photo): ?>
            <img src="<?php echo htmlspecialchars($pt_photo); ?>" class="w-full h-full object-cover">
            <?php else: ?>
            <i class="fa-solid fa-user text-gray-400"></i>
            <?php endif; ?>
          </div>
          <div class="min-w-0">
            <div class="text-sm font-semibold text-gray-800 truncate"><?php echo htmlspecialchars($pt['employee_name']); ?> (<?php echo get_job_role_label($pt['job_role']); ?>)</div>
            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($pt['from_store_name'] ?? '알 수 없음'); ?> → 현재 점포</div>
          </div>
        </div>
        <?php if (!empty($pt['reason'])): ?>
        <div class="text-xs text-gray-500 mb-2">사유: <?php echo htmlspecialchars($pt['reason']); ?></div>
        <?php endif; ?>
        <form method="POST" class="flex items-center gap-2">
          <input type="hidden" name="action" value="transfer_decide">
          <input type="hidden" name="request_id" value="<?php echo (int)$pt['id']; ?>">
          <input type="text" name="decision_note" placeholder="반려 메모 (선택)"
                 class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-xs">
          <button type="submit" name="approve" value="1" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">승인</button>
          <button type="submit" name="approve" value="0" class="bg-red-100 hover:bg-red-200 text-red-600 px-3 py-1.5 rounded-lg text-xs font-medium">반려</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="pt-4">
      <button type="button" onclick="closeModal('modal_transfers_incoming')"
              class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 py-2 rounded-lg text-sm">닫기</button>
    </div>
  </div>
</div>

<script>
const EMP_DATA = <?php echo json_encode($emp_data_map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const HISTORY_TYPE_LABELS = <?php echo json_encode($history_type_labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
let CURRENT_INFO_ID = null;
</script>

<script>
function openInfoModal(id) {
    const d = EMP_DATA[id];
    if (!d) return;
    CURRENT_INFO_ID = id;

    document.getElementById('info_id').textContent   = id;
    document.getElementById('info_name').textContent = d.name;
    document.getElementById('info_role').textContent = d.roleLabel;
    document.getElementById('info_agency').textContent = d.agency || '미지정';
    document.getElementById('info_fp').textContent = d.fpSlots ? ('슬롯 ' + d.fpSlots) : '미등록';
    document.getElementById('info_hire_date').textContent = d.hireDate || '미입력';

    const img  = document.getElementById('info_photo_img');
    const icon = document.getElementById('info_photo_icon');
    if (d.photoUrl) {
        img.src = d.photoUrl;
        img.classList.remove('hidden');
        icon.classList.add('hidden');
    } else {
        img.src = '';
        img.classList.add('hidden');
        icon.classList.remove('hidden');
    }

    const statusEl = document.getElementById('info_status');
    const inactiveWrap = document.getElementById('info_inactive_wrap');
    if (d.status === 'active') {
        statusEl.textContent = '재직 중';
        statusEl.className = 'inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-50 text-green-600';
        inactiveWrap.classList.add('hidden');
    } else {
        statusEl.textContent = '비Active';
        statusEl.className = 'inline-block mt-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-200 text-gray-500';
        document.getElementById('info_inactive_date').textContent = d.inactiveDate || '-';
        document.getElementById('info_inactive_reason').textContent = d.inactiveReason || '-';
        inactiveWrap.classList.remove('hidden');
    }

    const historyList = document.getElementById('info_history_list');
    if (!d.history || d.history.length === 0) {
        historyList.innerHTML = '<p class="text-gray-400 text-center py-2">이력 없음</p>';
    } else {
        const typeColors = {
            hire: 'bg-green-50 text-green-600',
            transfer: 'bg-orange-50 text-orange-600',
            role_change: 'bg-blue-50 text-blue-600',
            promotion: 'bg-purple-50 text-purple-600',
            note: 'bg-gray-100 text-gray-500',
        };
        historyList.innerHTML = d.history.map(h => {
            const label = HISTORY_TYPE_LABELS[h.event_type] || h.event_type;
            const color = typeColors[h.event_type] || 'bg-gray-100 text-gray-500';
            return '<div class="flex items-start gap-2">' +
                   '<span class="text-gray-400 flex-shrink-0">' + h.event_date + '</span>' +
                   '<span class="px-1.5 py-0 rounded ' + color + ' flex-shrink-0">' + label + '</span>' +
                   '<span class="text-gray-600 break-words">' + escapeHtml(h.content) + '</span>' +
                   '</div>';
        }).join('');
    }

    const transferBtn = document.getElementById('info_transfer_btn');
    if (d.hasPendingTransfer) {
        transferBtn.textContent = '전입 대기중';
        transferBtn.disabled = true;
        transferBtn.className = 'flex-1 bg-gray-100 text-gray-400 py-2 rounded-lg text-sm font-medium cursor-not-allowed';
    } else {
        transferBtn.textContent = '전입 요청';
        transferBtn.disabled = false;
        transferBtn.className = 'flex-1 bg-orange-50 hover:bg-orange-100 text-orange-600 py-2 rounded-lg text-sm font-medium';
    }

    document.getElementById('modal_info').classList.remove('hidden');
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function openHistoryAddModal() {
    if (!CURRENT_INFO_ID) return;
    document.getElementById('history_employee_id').value = CURRENT_INFO_ID;
    document.getElementById('history_event_date').value = new Date().toISOString().slice(0, 10);
    document.getElementById('modal_history_add').classList.remove('hidden');
}

function openTransferRequestModal() {
    if (!CURRENT_INFO_ID) return;
    const d = EMP_DATA[CURRENT_INFO_ID];
    if (d.hasPendingTransfer) return;
    document.getElementById('transfer_employee_id').value = CURRENT_INFO_ID;
    document.getElementById('transfer_emp_name').textContent = d.name;
    document.getElementById('modal_transfer_request').classList.remove('hidden');
}

function openTransfersIncomingModal() {
    document.getElementById('modal_transfers_incoming').classList.remove('hidden');
}

function openAddModal() {
    // 미리보기 초기화
    setPreview('add', null);
    document.getElementById('modal_add').classList.remove('hidden');
}
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

function openEditModal(id, name, role, agency, photoUrl, hireDate) {
    document.getElementById('edit_id').value        = id;
    document.getElementById('edit_name').value      = name;
    document.getElementById('edit_role').value      = role;
    document.getElementById('edit_agency').value    = agency || '';
    document.getElementById('edit_hire_date').value = hireDate || '';
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
['modal_add','modal_edit','modal_deactivate','modal_delete','modal_info','modal_history_add','modal_transfer_request','modal_transfers_incoming'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
