<?php
$page_title      = '직원휴무관리 — 휴무계획서';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']   ?? date('Y'));
$month    = (int)($_GET['month']  ?? date('n'));
$period   = in_array($_GET['period'] ?? '', ['first','second']) ? $_GET['period'] : 'first';

// 해당 기간 날짜 범위 계산
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
if ($period === 'first') {
    $date_from = 1;
    $date_to   = 15;
} else {
    $date_from = 16;
    $date_to   = $days_in_month;
}
$dates = [];
for ($d = $date_from; $d <= $date_to; $d++) {
    $ts       = mktime(0,0,0,$month,$d,$year);
    $dates[]  = [
        'day'     => $d,
        'date'    => sprintf('%04d-%02d-%02d', $year, $month, $d),
        'weekday' => ['일','월','화','수','목','금','토'][date('w', $ts)],
        'is_sun'  => date('w', $ts) == 0,
        'is_sat'  => date('w', $ts) == 6,
    ];
}

// 기존 저장 데이터 로드
$schedule_id = null;
$saved_items = [];
$conn = get_db_connection();
$sch_stmt = $conn->prepare("SELECT id FROM office_schedules WHERE store_id=? AND year=? AND month=? AND period=?");
$sch_stmt->bind_param('iiis', $store_id, $year, $month, $period);
$sch_stmt->execute();
$sch_row = $sch_stmt->get_result()->fetch_assoc();
$sch_stmt->close();

if ($sch_row) {
    $schedule_id = (int)$sch_row['id'];
    $item_stmt = $conn->prepare("SELECT * FROM office_schedule_items WHERE schedule_id=?");
    $item_stmt->bind_param('i', $schedule_id);
    $item_stmt->execute();
    $rows = $item_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $item_stmt->close();
    // 인덱스: [date][shift][job_role]
    foreach ($rows as $r) {
        $saved_items[$r['schedule_date']][$r['shift']][$r['job_role']] = $r;
    }
}
$conn->close();

// 직원 목록 (직무별, JS로 전달)
$roles   = get_job_roles();
$shifts  = ['morning' => 'MORNING', 'mid' => 'MID', 'gy' => 'GY'];
$emp_by_role = [];
foreach ($roles as $r) {
    $emp_by_role[$r] = get_office_employees($store_id, $r, 'active');
}

// 이전/다음 달 계산
$prev_ts    = mktime(0,0,0,$month-1,1,$year);
$next_ts    = mktime(0,0,0,$month+1,1,$year);
$prev_y     = date('Y', $prev_ts); $prev_m = date('n', $prev_ts);
$next_y     = date('Y', $next_ts); $next_m = date('n', $next_ts);
?>

<!-- 헤더 컨트롤 -->
<div class="flex flex-wrap items-center gap-3 mb-6">
  <h2 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-calendar-days mr-2 text-green-600"></i>휴무계획서
  </h2>

  <a href="?year=<?php echo $prev_y; ?>&month=<?php echo $prev_m; ?>&period=<?php echo $period; ?>"
     class="p-2 rounded-lg border border-gray-300 hover:bg-gray-100 text-gray-600 text-sm">
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <span class="font-semibold text-gray-700"><?php echo "{$year}년 {$month}월"; ?></span>
  <a href="?year=<?php echo $next_y; ?>&month=<?php echo $next_m; ?>&period=<?php echo $period; ?>"
     class="p-2 rounded-lg border border-gray-300 hover:bg-gray-100 text-gray-600 text-sm">
    <i class="fa-solid fa-chevron-right"></i>
  </a>

  <!-- 기간 탭 -->
  <div class="flex rounded-lg border border-gray-300 overflow-hidden ml-2">
    <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&period=first"
       class="px-4 py-2 text-sm font-medium <?php echo $period==='first' ? 'bg-green-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
      1 ~ 15일
    </a>
    <a href="?year=<?php echo $year; ?>&month=<?php echo $month; ?>&period=second"
       class="px-4 py-2 text-sm font-medium border-l border-gray-300 <?php echo $period==='second' ? 'bg-green-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">
      16 ~ <?php echo $days_in_month; ?>일
    </a>
  </div>

  <div class="ml-auto flex gap-2">
    <a href="employees.php" class="px-3 py-2 rounded-lg border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">
      <i class="fa-solid fa-users mr-1"></i>직원관리
    </a>
    <button id="btn_save" onclick="saveSchedule()"
            class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-floppy-disk mr-1"></i>저장
    </button>
    <a href="../exports/print_schedule.php?schedule_id=<?php echo $schedule_id ?? 0; ?>&year=<?php echo $year; ?>&month=<?php echo $month; ?>&period=<?php echo $period; ?>"
       target="_blank"
       class="px-3 py-2 rounded-lg border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">
      <i class="fa-solid fa-print mr-1"></i>프린트
    </a>
  </div>
</div>

<!-- 저장 상태 표시 -->
<div id="save_status" class="hidden mb-4 px-4 py-2 rounded-lg text-sm font-medium"></div>

<!-- 근무별 그리드 -->
<?php foreach ($shifts as $shift_key => $shift_label): ?>
<div class="mb-8">
  <div class="bg-gray-800 text-white px-4 py-2 rounded-t-lg text-sm font-bold tracking-wider">
    <?php echo $shift_label; ?> 근무
  </div>
  <div class="overflow-x-auto">
    <table class="w-full border border-gray-200 text-xs">
      <thead>
        <tr class="bg-gray-50">
          <th class="border border-gray-200 px-3 py-2 text-left text-gray-600 w-24 sticky left-0 bg-gray-50">직무</th>
          <?php foreach ($dates as $d): ?>
          <th class="border border-gray-200 px-1 py-2 text-center min-w-[70px]
                     <?php echo $d['is_sun'] ? 'text-red-500' : ($d['is_sat'] ? 'text-blue-500' : 'text-gray-600'); ?>">
            <?php echo $d['day']; ?>일<br>
            <span class="text-xs">(<?php echo $d['weekday']; ?>)</span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $role): ?>
        <?php
        $role_label = get_job_role_label($role);
        $role_emps  = $emp_by_role[$role];
        ?>
        <tr class="hover:bg-blue-50/30">
          <td class="border border-gray-200 px-3 py-2 font-medium text-gray-700 sticky left-0 bg-white">
            <?php echo $role_label; ?>
          </td>
          <?php foreach ($dates as $d): ?>
          <?php
          $saved = $saved_items[$d['date']][$shift_key][$role] ?? null;
          $cell_emp_id  = $saved ? (int)$saved['employee_id'] : 0;
          $cell_is_off  = $saved ? (int)$saved['is_off'] : 0;
          $cell_rep_id  = $saved ? (int)$saved['replacement_employee_id'] : 0;
          $cell_sup_time = $saved ? ($saved['supervisor_shift_time'] ?? '') : '';
          $cell_id = "cell_{$shift_key}_{$role}_{$d['day']}";
          ?>
          <td class="border border-gray-200 px-1 py-1 text-center align-top"
              data-date="<?php echo $d['date']; ?>"
              data-shift="<?php echo $shift_key; ?>"
              data-role="<?php echo $role; ?>">

            <!-- 직원 선택 -->
            <select class="emp-select w-full border border-gray-200 rounded px-1 py-1 text-xs bg-white"
                    data-date="<?php echo $d['date']; ?>"
                    data-shift="<?php echo $shift_key; ?>"
                    data-role="<?php echo $role; ?>"
                    onchange="onEmpChange(this)">
              <option value="0">미배정</option>
              <?php foreach ($role_emps as $emp): ?>
              <option value="<?php echo $emp['id']; ?>"
                      <?php echo $cell_emp_id == $emp['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($emp['name']); ?>
              </option>
              <?php endforeach; ?>
            </select>

            <!-- 휴무 체크박스 -->
            <div class="flex items-center justify-center gap-1 mt-1">
              <input type="checkbox" class="off-check"
                     data-date="<?php echo $d['date']; ?>"
                     data-shift="<?php echo $shift_key; ?>"
                     data-role="<?php echo $role; ?>"
                     <?php echo $cell_is_off ? 'checked' : ''; ?>
                     onchange="onOffChange(this)">
              <label class="text-xs text-red-500">휴무</label>
            </div>

            <!-- 대체인원 (휴무 시 표시) -->
            <div class="rep-section mt-1 <?php echo $cell_is_off ? '' : 'hidden'; ?>"
                 data-date="<?php echo $d['date']; ?>"
                 data-shift="<?php echo $shift_key; ?>"
                 data-role="<?php echo $role; ?>">
              <select class="rep-select w-full border border-orange-300 rounded px-1 py-1 text-xs bg-orange-50">
                <option value="0">대체인원</option>
                <?php foreach ($role_emps as $emp): ?>
                <option value="<?php echo $emp['id']; ?>"
                        <?php echo $cell_rep_id == $emp['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($emp['name']); ?>
                </option>
                <?php endforeach; ?>
              </select>

              <?php if ($role === 'supervisor'): ?>
              <!-- 슈퍼바이저 전용: 교대 시간 -->
              <select class="sup-time-select w-full border border-purple-300 rounded px-1 py-1 text-xs bg-purple-50 mt-1">
                <option value="">시간 선택</option>
                <option value="8AM-8PM"  <?php echo $cell_sup_time === '8AM-8PM'  ? 'selected' : ''; ?>>8AM ~ 8PM</option>
                <option value="8PM-8AM"  <?php echo $cell_sup_time === '8PM-8AM'  ? 'selected' : ''; ?>>8PM ~ 8AM</option>
              </select>
              <?php endif; ?>
            </div>

          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<script>
const STORE_ID  = <?php echo $store_id; ?>;
const YEAR      = <?php echo $year; ?>;
const MONTH     = <?php echo $month; ?>;
const PERIOD    = '<?php echo $period; ?>';

function onOffChange(checkbox) {
    const td     = checkbox.closest('td');
    const repSec = td.querySelector('.rep-section');
    if (repSec) repSec.classList.toggle('hidden', !checkbox.checked);
}

function onEmpChange(select) {
    // 직원 선택 시 자동으로 휴무 체크 해제
    if (parseInt(select.value) > 0) {
        const td  = select.closest('td');
        const chk = td.querySelector('.off-check');
        if (chk && chk.checked) {
            chk.checked = false;
            onOffChange(chk);
        }
    }
}

function collectItems() {
    const items = [];
    document.querySelectorAll('td[data-date]').forEach(td => {
        const date    = td.dataset.date;
        const shift   = td.dataset.shift;
        const role    = td.dataset.role;
        const empSel  = td.querySelector('.emp-select');
        const offChk  = td.querySelector('.off-check');
        const repSel  = td.querySelector('.rep-select');
        const supSel  = td.querySelector('.sup-time-select');

        if (!empSel) return;

        const employeeId  = parseInt(empSel.value) || null;
        const isOff       = offChk && offChk.checked ? 1 : 0;
        const replaceId   = (isOff && repSel) ? (parseInt(repSel.value) || null) : null;
        const supTime     = (isOff && supSel) ? (supSel.value || null) : null;

        // 미배정이고 휴무도 아니면 저장 불필요 (선택 시 포함)
        if (!employeeId && !isOff) return;

        items.push({
            date, shift, job_role: role,
            employee_id: employeeId,
            is_off: isOff,
            replacement_employee_id: replaceId,
            supervisor_shift_time: supTime
        });
    });
    return items;
}

function showStatus(msg, isOk) {
    const el = document.getElementById('save_status');
    el.textContent = msg;
    el.className = 'mb-4 px-4 py-2 rounded-lg text-sm font-medium ' +
                   (isOk ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 3000);
}

async function saveSchedule() {
    const btn = document.getElementById('btn_save');
    btn.disabled = true;
    btn.textContent = '저장 중...';

    const payload = { store_id: STORE_ID, year: YEAR, month: MONTH, period: PERIOD, items: collectItems() };

    try {
        const res  = await fetch('ajax_save_schedule.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showStatus(`저장 완료 (${data.saved_count}건)`, true);
        } else {
            showStatus('저장 실패: ' + (data.error ?? ''), false);
        }
    } catch (e) {
        showStatus('네트워크 오류', false);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i>저장';
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
