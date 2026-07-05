<?php
$page_title      = 'Day Off Schedule';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id      = get_office_store_id();
$year          = (int)($_GET['year']  ?? date('Y'));
$month         = (int)($_GET['month'] ?? date('n'));
$default_period = ((int)date('j') >= 16) ? 'second' : 'first';
$period        = in_array($_GET['period'] ?? '', ['first','second']) ? $_GET['period'] : $default_period;
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
$date_from     = $period === 'first' ? 1 : 16;
$date_to       = $period === 'first' ? 15 : $days_in_month;

$dates = [];
for ($d = $date_from; $d <= $date_to; $d++) {
    $ts = mktime(0,0,0,$month,$d,$year);
    $dates[] = [
        'day'  => $d,
        'date' => sprintf('%04d-%02d-%02d', $year, $month, $d),
        'wd'   => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][date('w',$ts)],
        'sun'  => date('w',$ts) == 0,
        'sat'  => date('w',$ts) == 6,
    ];
}

// load saved data — multi-employee support
$schedule_id  = null;
$shift_emps   = [];  // [shift][role][] = {id, name}
$day_status   = [];  // [date][shift][role][emp_id] = {is_off, sup_time, attendance}

$conn = get_db_connection();
$ss = $conn->prepare("SELECT id FROM office_schedules WHERE store_id=? AND year=? AND month=? AND period=?");
$ss->bind_param('iiis', $store_id, $year, $month, $period);
$ss->execute();
$sr = $ss->get_result()->fetch_assoc();
$ss->close();

$is_confirmed = false;
$confirmed_at = null;

if ($sr) {
    $schedule_id = (int)$sr['id'];

    // load confirmed status (ignore if column missing)
    $cc = $conn->query("SHOW COLUMNS FROM office_schedules LIKE 'confirmed'");
    $has_confirmed_col = $cc && $cc->num_rows > 0;
    if ($has_confirmed_col) {
        $cq = $conn->prepare("SELECT confirmed, confirmed_at FROM office_schedules WHERE id=?");
        $cq->bind_param('i', $schedule_id);
        $cq->execute();
        $cr = $cq->get_result()->fetch_assoc();
        $cq->close();
        $is_confirmed = (bool)($cr['confirmed'] ?? false);
        $confirmed_at = $cr['confirmed_at'] ?? null;
    }

    // check attendance column existence
    $ac = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'attendance'");
    $has_att_col = $ac && $ac->num_rows > 0;
    $att_select  = $has_att_col ? ", si.attendance" : ", 'present' AS attendance";

    $si = $conn->prepare(
        "SELECT si.id AS item_id, si.*, e.name AS emp_name {$att_select}
         FROM office_schedule_items si
         LEFT JOIN office_employees e ON si.employee_id = e.id
         WHERE si.schedule_id = ?
         ORDER BY si.shift, si.job_role, si.employee_id, si.schedule_date"
    );
    $si->bind_param('i', $schedule_id);
    $si->execute();
    foreach ($si->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $sh  = $row['shift'];
        $rol = $row['job_role'];
        $eid = (int)$row['employee_id'];
        $dt  = $row['schedule_date'];
        $already = array_filter($shift_emps[$sh][$rol] ?? [], fn($e) => $e['id'] == $eid);
        if (empty($already)) {
            $shift_emps[$sh][$rol][] = ['id' => $eid, 'name' => $row['emp_name'] ?? ''];
        }
        $day_status[$dt][$sh][$rol][$eid] = [
            'item_id'    => (int)$row['item_id'],
            'is_off'     => (int)$row['is_off'],
            'sup_time'   => $row['supervisor_shift_time'] ?? '',
            'attendance' => $row['attendance'] ?? 'present',
        ];
    }
    $si->close();
}
$conn->close();

$roles  = get_job_roles();
$shifts = ['morning' => 'MORNING', 'mid' => 'MID', 'gy' => 'GY'];
$shift_colors = ['morning' => '#854d0e', 'mid' => '#1e40af', 'gy' => '#6b21a8'];
$shift_times  = ['morning' => '8AM~5PM', 'mid' => '3PM~12AM', 'gy' => '11PM~8AM'];

$att_labels = [
    'present'     => '✓ Present',
    'late'        => 'Late',
    'early_leave' => 'Under Time',
    'sick_leave'  => 'Sick Leave',
    'absent'      => '✗ Absent',
    'resign'      => 'Resign',
    'sil'         => 'SIL',
];

// draft mode leave options
$leave_styles = [
    'present'     => ['label' => 'Day Off',    'bg' => '#fee2e2', 'color' => '#b91c1c'],
    'vacation'    => ['label' => 'Vacation',   'bg' => '#fef9c3', 'color' => '#854d0e'],
    'sick_leave'  => ['label' => 'Sick Leave', 'bg' => '#dbeafe', 'color' => '#1d4ed8'],
    'sil'         => ['label' => 'SIL',        'bg' => '#ccfbf1', 'color' => '#0f766e'],
    'suspension'  => ['label' => 'Suspension', 'bg' => '#f3e8ff', 'color' => '#7e22ce'],
    'early_leave' => ['label' => 'Early Leave','bg' => '#ffedd5', 'color' => '#9a3412'],
];
$att_colors = [
    'present'     => ['bg' => '#dcfce7', 'color' => '#15803d'],
    'late'        => ['bg' => '#fef3c7', 'color' => '#92400e'],
    'early_leave' => ['bg' => '#ffedd5', 'color' => '#9a3412'],
    'sick_leave'  => ['bg' => '#dbeafe', 'color' => '#1d4ed8'],
    'absent'      => ['bg' => '#fee2e2', 'color' => '#dc2626'],
    'resign'      => ['bg' => '#fce7f3', 'color' => '#9d174d'],
    'sil'         => ['bg' => '#ccfbf1', 'color' => '#0f766e'],
];
$shift_bg     = ['morning' => '#fef9c3', 'mid' => '#dbeafe', 'gy' => '#f3e8ff'];
$shift_att_colors = [
    'morning' => ['bg' => '#dcfce7', 'color' => '#15803d'],
    'mid'     => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'gy'      => ['bg' => '#dbeafe', 'color' => '#1e40af'],
];

$emp_by_role = [];
foreach ($roles as $r) {
    $emp_by_role[$r] = get_office_employees($store_id, $r, 'active');
}

// collect already-assigned IDs (for hiding from left panel)
$assigned_ids = [];
foreach ($shifts as $shift_key => $_) {
    foreach ($roles as $role) {
        foreach ($shift_emps[$shift_key][$role] ?? [] as $emp) {
            $assigned_ids[$role][$emp['id']] = true;
        }
    }
}

$prev_ts = mktime(0,0,0,$month-1,1,$year);
$next_ts = mktime(0,0,0,$month+1,1,$year);
$col_total = count($dates) + 1; // name + dates
?>
<style>
.container { max-width:100% !important; padding-left:12px !important; padding-right:12px !important; }
/* schedule page: minimize side padding */
body > div.flex-1 > div { padding-left:4px !important; padding-right:4px !important; }
.emp-card     { cursor:grab; user-select:none; transition:background .1s; }
.emp-card:hover{ background:#eff6ff; }
.emp-card:active{ opacity:.6; cursor:grabbing; }
.shift-hdr    { cursor:copy; transition:background .12s; }
.shift-hdr.over{ outline:2px dashed #22c55e !important; background:#dcfce7 !important; }
.shift-hdr.bad { background:#fee2e2 !important; }
.emp-row td   { vertical-align:middle; }
.emp-row.row-over { background:#eff6ff !important; }
.emp-row.row-over td { border-top:2px solid #3b82f6 !important; }
.drag-handle  { cursor:grab; color:#d1d5db; font-size:11px; }
.drag-handle:hover { color:#6b7280; }
.emp-row.dragging { opacity:0.4; }
.work-dot     { display:block; width:10px; height:10px; background:#22c55e;
                border-radius:50%; margin:auto; cursor:pointer; }
.work-dot:hover{ background:#16a34a; }
.off-badge    { display:inline-block; background:#fee2e2; color:#b91c1c;
                border-radius:4px; padding:1px 6px; font-size:10px; font-weight:700;
                cursor:pointer; white-space:nowrap; }
.off-badge:hover{ background:#fecaca; }
.mini-sel     { width:100%; border-radius:4px; padding:1px 3px; font-size:10px; margin-top:2px; }
</style>

<!-- top controls -->
<div class="flex flex-wrap items-center gap-2 mb-4">
  <h2 class="text-lg font-bold text-gray-800">
    <i class="fa-solid fa-calendar-days mr-1 text-green-600"></i>Day Off Schedule
  </h2>
  <a href="?year=<?php echo date('Y',$prev_ts);?>&month=<?php echo date('n',$prev_ts);?>&period=<?php echo $period;?>"
     class="p-1.5 border border-gray-300 rounded hover:bg-gray-100 text-xs text-gray-500">◀</a>
  <span class="font-semibold text-sm text-gray-700"><?php echo "{$year} / {$month}"; ?></span>
  <a href="?year=<?php echo date('Y',$next_ts);?>&month=<?php echo date('n',$next_ts);?>&period=<?php echo $period;?>"
     class="p-1.5 border border-gray-300 rounded hover:bg-gray-100 text-xs text-gray-500">▶</a>
  <div class="flex rounded-lg border border-gray-300 overflow-hidden ml-1">
    <a href="?year=<?php echo $year;?>&month=<?php echo $month;?>&period=first"
       class="px-3 py-1.5 text-xs font-medium <?php echo $period==='first'?'bg-green-600 text-white':'bg-white text-gray-600 hover:bg-gray-50';?>">1 ~ 15</a>
    <a href="?year=<?php echo $year;?>&month=<?php echo $month;?>&period=second"
       class="px-3 py-1.5 text-xs font-medium border-l border-gray-300 <?php echo $period==='second'?'bg-green-600 text-white':'bg-white text-gray-600 hover:bg-gray-50';?>">
      16 ~ <?php echo $days_in_month;?></a>
  </div>
  <div class="ml-auto flex gap-2 items-center">
    <?php if ($is_confirmed): ?>
      <!-- confirmed -->
      <span style="background:#dcfce7;color:#15803d;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700">
        <i class="fa-solid fa-lock mr-1"></i>Schedule Confirmed
        <?php if ($confirmed_at): ?><span style="font-weight:400;color:#166534;margin-left:4px"><?php echo date('m/d H:i', strtotime($confirmed_at));?></span><?php endif; ?>
      </span>
      <?php if (($_SESSION['role']??'')==='super_admin'): ?>
      <button onclick="unconfirmSchedule()"
              class="px-3 py-1.5 border border-red-300 text-red-500 rounded-lg text-xs hover:bg-red-50">
        <i class="fa-solid fa-lock-open mr-1"></i>Unconfirm
      </button>
      <?php endif; ?>
    <?php else: ?>
      <!-- not confirmed -->
      <button onclick="saveSchedule()"
              class="bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg text-xs font-medium">
        <i class="fa-solid fa-floppy-disk mr-1"></i>Save
      </button>
      <button onclick="confirmSchedule()"
              style="background:#1d4ed8;color:white;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;border:none;cursor:pointer">
        <i class="fa-solid fa-lock mr-1"></i>Confirm Schedule
      </button>
    <?php endif; ?>
    <a href="../exports/print_schedule.php?schedule_id=<?php echo $schedule_id??0;?>&year=<?php echo $year;?>&month=<?php echo $month;?>&period=<?php echo $period;?>"
       target="_blank"
       class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-600 hover:bg-gray-50">
      <i class="fa-solid fa-print mr-1"></i>Print
    </a>
  </div>
</div>
<div id="save_msg" class="hidden mb-3 px-4 py-2 rounded-lg text-xs font-medium"></div>

<!-- main layout -->
<div class="flex gap-3 items-start">

  <!-- left: employee list (floating) -->
  <div id="emp_list_panel" class="w-36 flex-shrink-0 bg-white rounded-xl border border-gray-200 shadow-sm"
       style="max-height:84vh;overflow-y:auto">
    <div class="px-3 py-2 border-b border-gray-100 text-xs font-bold text-gray-500">Employee List</div>
    <?php foreach ($roles as $role): ?>
    <div class="border-b border-gray-50 last:border-0">
      <div style="padding:6px 12px 2px;font-size:11px;font-weight:700;color:#1e1b4b"><?php echo get_job_role_label($role);?></div>
      <?php $emps = $emp_by_role[$role]; ?>
      <?php if (empty($emps)): ?>
        <p class="px-3 pb-2 text-xs text-gray-300">None</p>
      <?php else: ?>
      <div class="px-2 pb-2 space-y-1">
        <?php foreach ($emps as $e): ?>
        <div class="emp-card rounded-lg border border-gray-200 px-2 py-1.5 text-xs text-gray-700 font-medium"
             draggable="true"
             data-id="<?php echo $e['id'];?>"
             data-name="<?php echo htmlspecialchars($e['name'],ENT_QUOTES);?>"
             data-role="<?php echo $role;?>"
             ondragstart="dragStart(event)"
             <?php echo isset($assigned_ids[$role][$e['id']]) ? 'style="display:none"' : ''; ?>>
          <i class="fa-solid fa-user text-gray-300 mr-1 text-xs"></i><?php echo htmlspecialchars($e['name']);?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <p class="px-3 py-2 text-xs text-gray-300 italic">Drag to shift header</p>
  </div>

  <!-- right: role tables -->
  <div id="table_section" class="flex-1 space-y-5" style="overflow-x:auto">
    <?php foreach ($roles as $role): ?>
    <div>
      <!-- role title -->
      <div style="background:#3730a3;color:#ffffff;padding:6px 16px;border-radius:8px 8px 0 0;font-size:11px;font-weight:700;letter-spacing:0.08em">
        <i class="fa-solid fa-id-badge" style="margin-right:4px"></i><?php echo get_job_role_label($role);?>
        <span style="color:#c7d2fe;font-weight:400;margin-left:4px">(<?php echo count($emp_by_role[$role]);?>)</span>
      </div>

      <table class="w-full border border-gray-200 text-xs border-t-0" id="tbl_<?php echo $role;?>">
        <!-- date header -->
        <thead>
          <tr class="bg-gray-50">
            <th class="border border-gray-200 px-2 py-2 text-center text-gray-500 sticky left-0 bg-gray-50 z-10" style="width:216px;min-width:216px;max-width:216px">Name</th>
            <?php foreach ($dates as $d): ?>
            <th class="border border-gray-200 px-1 py-1 text-center" style="min-width:54px"
                class="<?php echo $d['sun']?'text-red-400':($d['sat']?'text-blue-400':'text-gray-500');?>">
              <span class="<?php echo $d['sun']?'text-red-400':($d['sat']?'text-blue-400':'text-gray-500');?>">
                <?php echo $d['day'];?><br>(<?php echo $d['wd'];?>)
              </span>
            </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody id="body_<?php echo $role;?>">
          <?php foreach ($shifts as $shift_key => $shift_label): ?>
          <?php
          $sc   = $shift_colors[$shift_key];
          $sb   = $shift_bg[$shift_key];
          $emps_in_shift = $shift_emps[$shift_key][$role] ?? [];
          ?>

          <!-- shift header row (drop zone) -->
          <tr class="shift-hdr"
              data-shift="<?php echo $shift_key;?>"
              data-role="<?php echo $role;?>"
              ondragover="event.preventDefault(); this.classList.add('over')"
              ondragleave="this.classList.remove('over')"
              ondrop="onDropShift(event, this)">
            <td colspan="<?php echo $col_total;?>"
                style="background:<?php echo $sb;?>;border:1px solid #e5e7eb;padding:4px 10px;font-weight:700;color:<?php echo $sc;?>">
              <?php echo $shift_label;?>
              <span style="font-weight:400;color:#9ca3af;font-size:10px;margin-left:8px">
                <i class="fa-solid fa-arrow-left text-xs"></i> Drag to add employee
              </span>
            </td>
          </tr>

          <!-- assigned employee rows -->
          <?php foreach ($emps_in_shift as $emp): ?>
          <?php
          $eid = $emp['id'];
          $is_supv    = ($role === 'supervisor');
          $is_cashier = ($role === 'cashier');
          ?>
          <tr class="emp-row"
              draggable="true"
              data-shift="<?php echo $shift_key;?>"
              data-role="<?php echo $role;?>"
              data-emp-id="<?php echo $eid;?>"
              data-emp-name="<?php echo htmlspecialchars($emp['name'],ENT_QUOTES);?>"
              ondragstart="dragRowStart(event)"
              ondragover="dragRowOver(event)"
              ondragleave="dragRowLeave(event)"
              ondrop="dropRow(event)"
              ondragend="dragRowEnd(event)">
            <!-- name cell -->
            <td class="border border-gray-200 px-2 py-1.5 sticky left-0 bg-white z-10" style="width:216px;min-width:216px;max-width:216px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <div class="flex items-center gap-1">
                <?php if (!$is_confirmed): ?>
                <i class="fa-solid fa-grip-vertical drag-handle mr-1"></i>
                <?php endif; ?>
                <i class="fa-solid fa-user text-xs emp-info-icon" style="color:<?php echo $sc;?>;cursor:pointer"
                   title="Employee Info" onclick="showEmployeeInfo(<?php echo $eid;?>)"></i>
                <span class="font-semibold text-gray-700"><?php echo htmlspecialchars($emp['name']);?></span>
                <?php if (!$is_confirmed): ?>
                <button onclick="removeEmpRow(this)" class="ml-auto text-gray-300 hover:text-red-500 text-xs">✕</button>
                <?php endif; ?>
              </div>
            </td>
            <!-- date cells -->
            <?php foreach ($dates as $d): ?>
            <?php
            $st         = $day_status[$d['date']][$shift_key][$role][$eid] ?? null;
            $is_off     = $st ? $st['is_off'] : 0;
            $sup_time   = $st ? $st['sup_time'] : '';
            $attendance = $st ? ($st['attendance'] ?? 'present') : 'present';
            $item_id    = $st ? ($st['item_id'] ?? 0) : 0;
            ?>
            <td class="border border-gray-200 p-1 text-center align-top day-td"
                data-date="<?php echo $d['date'];?>">
              <?php if ($is_confirmed): ?>
                <?php $stime = $shift_times[$shift_key] ?? ''; ?>
                <?php if ($is_off): ?>
                  <?php $ls = $leave_styles[$attendance] ?? $leave_styles['present']; ?>
                  <span style="background:<?php echo $ls['bg'];?>;color:<?php echo $ls['color'];?>;border-radius:4px;padding:2px 5px;font-size:9px;font-weight:700;white-space:nowrap"><?php echo htmlspecialchars($ls['label']);?></span>
                <?php elseif ($item_id > 0): ?>
                  <!-- working: shift time + click for attendance popup -->
                  <?php
                  $is_cashier_mid   = $is_cashier && ($shift_key === 'mid');
                  $is_morning_cover = ($shift_key === 'morning') && ($sup_time === '3PM~12AM');
                  $sup_override     = (($is_supv || $is_cashier_mid || $is_morning_cover) && $sup_time);
                  $display_time     = $sup_override ? $sup_time : $stime;
                  if ($attendance === 'present') {
                      if ($sup_override) {
                          if ($is_cashier_mid) {
                              $c = ['bg' => '#c2410c', 'color' => '#ffffff'];
                          } elseif ($is_morning_cover) {
                              $c = ['bg' => '#1d4ed8', 'color' => '#ffffff'];
                          } else {
                              $c = ['bg' => '#1e40af', 'color' => '#ffffff'];
                          }
                      } else {
                          $c = $shift_att_colors[$shift_key] ?? $att_colors['present'];
                      }
                  } else {
                      $c = $att_colors[$attendance] ?? $att_colors['present'];
                  }
                  $btn_text = ($attendance === 'present')
                              ? $display_time
                              : ($att_labels[$attendance] ?? '✓ Present');
                  ?>
                  <div style="display:flex;flex-direction:column;align-items:center;gap:1px">
                    <button class="att-trigger"
                            data-item-id="<?php echo $item_id;?>"
                            data-current="<?php echo $attendance;?>"
                            data-shift-time="<?php echo htmlspecialchars($display_time);?>"
                            <?php if ($sup_override): ?>data-sup-time="1"<?php endif; ?>
                            <?php if ($is_morning_cover): ?>data-mid-cover="1"<?php endif; ?>
                            onclick="toggleAttPanel(this)"
                            style="background:<?php echo $c['bg'];?>;color:<?php echo $c['color'];?>;
                                   border:1px solid <?php echo $c['color'];?>33;border-radius:4px;
                                   padding:3px 4px;font-size:9px;font-weight:700;cursor:pointer;
                                   width:100%;text-align:center;white-space:nowrap;line-height:1.4">
                      <?php echo htmlspecialchars($btn_text);?>
                    </button>
                  </div>
                <?php else: ?>
                  <span style="color:#e5e7eb;font-size:10px">—</span>
                <?php endif; ?>
              <?php elseif ($is_off): ?>
                <!-- edit mode: leave badge -->
                <?php $ls = $leave_styles[$attendance] ?? $leave_styles['present']; ?>
                <button class="leave-trigger"
                        data-is-off="1"
                        data-leave-type="<?php echo htmlspecialchars($attendance ?: 'present');?>"
                        onclick="openLeavePanel(this)"
                        style="background:<?php echo $ls['bg'];?>;color:<?php echo $ls['color'];?>;
                               border:1px solid <?php echo $ls['color'];?>55;border-radius:4px;
                               padding:2px 5px;font-size:9px;font-weight:700;cursor:pointer;
                               white-space:nowrap;width:100%;text-align:center">
                  <?php echo $ls['label'];?>
                </button>
              <?php else: ?>
                <!-- edit mode: working -->
                <?php
                $time_badge_map = [
                    '8AM~5PM'  => ['bg'=>'#fef9c3','color'=>'#854d0e','bdr'=>'#854d0e55'],
                    '3PM~12AM' => ['bg'=>'#dbeafe','color'=>'#1d4ed8','bdr'=>'#1d4ed855'],
                    '11PM~8AM' => ['bg'=>'#fff7ed','color'=>'#c2410c','bdr'=>'#c2410c55'],
                    '8AM~8PM'  => ['bg'=>'#f5f3ff','color'=>'#7e22ce','bdr'=>'#7e22ce55'],
                    '8PM~8AM'  => ['bg'=>'#fce7f3','color'=>'#9d174d','bdr'=>'#9d174d55'],
                    '8AM-8PM'  => ['bg'=>'#f5f3ff','color'=>'#7e22ce','bdr'=>'#7e22ce55'],
                    '8PM-8AM'  => ['bg'=>'#fce7f3','color'=>'#9d174d','bdr'=>'#9d174d55'],
                ];
                $tb = $time_badge_map[$sup_time] ?? ['bg'=>'#f3e8ff','color'=>'#7e22ce','bdr'=>'#c4b5fd55'];
                $badge_bg = $tb['bg']; $badge_color = $tb['color']; $badge_bdr = $tb['bdr'];
                $show_badge = !empty($sup_time);
                ?>
                <?php if ($show_badge): ?>
                <button class="leave-trigger"
                        data-is-off="0"
                        data-leave-type="present"
                        data-override-time="<?php echo htmlspecialchars($sup_time);?>"
                        onclick="openLeavePanel(this)"
                        style="background:<?php echo $badge_bg;?>;color:<?php echo $badge_color;?>;border:1px solid <?php echo $badge_bdr;?>;border-radius:4px;
                               padding:2px 5px;font-size:9px;font-weight:700;cursor:pointer;
                               white-space:nowrap;width:100%;text-align:center">
                  <?php echo htmlspecialchars($sup_time);?>
                </button>
                <?php else: ?>
                <button class="leave-trigger"
                        data-is-off="0"
                        data-leave-type="present"
                        onclick="openLeavePanel(this)"
                        style="background:transparent;border:none;cursor:pointer;padding:4px 2px;width:100%;display:flex;justify-content:center">
                  <div class="work-dot"></div>
                </button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>

          <?php endforeach; // shifts ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; // roles ?>
  </div>
</div>

<!-- draft mode: leave type selection popup -->
<div id="leave_panel" style="display:none;position:fixed;z-index:9999;background:white;
     border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,0.15);
     min-width:110px;overflow:hidden">
  <?php
  $leave_panel_opts = [
      ['value'=>'work',          'label'=>'🟢 Work'],
      ['value'=>'present',       'label'=>'🔴 Day Off'],
      ['value'=>'vacation',      'label'=>'🌴 Vacation'],
      ['value'=>'sick_leave',    'label'=>'💊 Sick Leave'],
      ['value'=>'sil',           'label'=>'💵 SIL'],
      ['value'=>'suspension',    'label'=>'⛔ Suspension'],
      ['value'=>'time_8am_5pm',  'label'=>'⏰ 8AM~5PM'],
      ['value'=>'time_3pm_12am', 'label'=>'⏰ 3PM~12AM'],
      ['value'=>'time_11pm_8am', 'label'=>'⏰ 11PM~8AM'],
      ['value'=>'time_8am_8pm',  'label'=>'⏰ 8AM~8PM'],
      ['value'=>'time_8pm_8am',  'label'=>'⏰ 8PM~8AM'],
  ];
  foreach ($leave_panel_opts as $lopt):
  ?>
  <div class="leave-opt" data-value="<?php echo $lopt['value'];?>"
       onclick="selectLeaveOption(this)"
       style="padding:7px 14px;font-size:11px;font-weight:500;cursor:pointer;
              color:#374151;white-space:nowrap;border-bottom:1px solid #f3f4f6"
       onmouseover="this.style.background='#f3f4f6'"
       onmouseout="this.style.background=''">
    <?php echo htmlspecialchars($lopt['label']);?>
  </div>
  <?php endforeach; ?>
</div>

<!-- shared attendance selection popup (position:fixed to avoid overflow clipping) -->
<div id="att_panel" style="display:none;position:fixed;z-index:9999;background:white;
     border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,0.15);
     min-width:130px;overflow:hidden">
  <?php foreach ($att_labels as $val => $lbl):
    $pc = $att_colors[$val] ?? ['bg'=>'#f9fafb','color'=>'#374151'];
  ?>
  <div class="att-opt" data-value="<?php echo $val;?>"
       onclick="selectAttOption(this)"
       style="padding:7px 14px;font-size:11px;font-weight:500;cursor:pointer;
              color:#374151;white-space:nowrap;border-bottom:1px solid #f3f4f6"
       onmouseover="this.style.background='<?php echo $pc['bg'];?>';this.style.color='<?php echo $pc['color'];?>'"
       onmouseout="this.style.background='';this.style.color='#374151'">
    <?php echo htmlspecialchars($lbl);?>
  </div>
  <?php endforeach; ?>
</div>

<!-- employee info modal -->
<div class="modal fade" id="empInfoModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header border-b border-gray-100 px-4 py-3">
        <h5 class="modal-title text-base font-semibold text-gray-800">
          <i class="fa-solid fa-id-badge mr-2 text-indigo-600"></i>Employee Info
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 py-3" id="emp_info_body">
        <div class="text-center text-sm text-gray-400 py-4">Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
const STORE_ID    = <?php echo $store_id; ?>;
const YEAR        = <?php echo $year; ?>;
const MONTH       = <?php echo $month; ?>;
const PERIOD      = '<?php echo $period; ?>';
const IS_CONFIRMED = <?php echo $is_confirmed ? 'true' : 'false'; ?>;
const DATES     = <?php echo json_encode(array_column($dates,'date')); ?>;
const COL_TOTAL = <?php echo $col_total; ?>;
const empByRole = <?php
    $js = [];
    foreach ($emp_by_role as $r => $list) {
        $js[$r] = array_map(fn($e) => ['id'=>(int)$e['id'],'name'=>$e['name']], $list);
    }
    echo json_encode($js, JSON_UNESCAPED_UNICODE);
?>;
const shiftColors   = {morning:'#854d0e', mid:'#1e40af', gy:'#6b21a8'};
const SHIFT_ATT_COLORS = {
    morning: {bg:'#dcfce7', color:'#15803d'},
    mid:     {bg:'#fef9c3', color:'#854d0e'},
    gy:      {bg:'#dbeafe', color:'#1e40af'},
};

// ─── drag from panel ─────────────────────────────────────────
function dragStart(e) {
    const c = e.currentTarget;
    e.dataTransfer.setData('text/plain', JSON.stringify({
        id: c.dataset.id, name: c.dataset.name, role: c.dataset.role
    }));
}

// ─── row reorder drag ────────────────────────────────────────
function dragRowStart(e) {
    const tr = e.currentTarget;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', JSON.stringify({
        source: 'row',
        empId:  tr.dataset.empId,
        shift:  tr.dataset.shift,
        role:   tr.dataset.role,
    }));
    setTimeout(() => tr.classList.add('dragging'), 0);
}
function dragRowEnd(e) {
    e.currentTarget.classList.remove('dragging');
}
function dragRowOver(e) {
    e.stopPropagation();
    let data; try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch { return; }
    if (data.source !== 'row') return;
    e.preventDefault(); // allow drop only for row drag
    e.currentTarget.classList.add('row-over');
}
function dragRowLeave(e) {
    e.currentTarget.classList.remove('row-over');
}
function dropRow(e) {
    e.preventDefault();
    e.stopPropagation();
    const targetRow = e.currentTarget;
    targetRow.classList.remove('row-over');
    let data; try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch { return; }
    if (data.source !== 'row') return;

    const tbody       = targetRow.closest('tbody');
    const targetShift = targetRow.dataset.shift;
    const targetRole  = targetRow.dataset.role;

    if (data.role !== targetRole) return; // reject different role

    const srcRow = tbody.querySelector(
        `.emp-row[data-shift="${data.shift}"][data-role="${data.role}"][data-emp-id="${data.empId}"]`
    );
    if (!srcRow || srcRow === targetRow) return;

    // same shift → reorder, different shift → move
    if (data.shift !== targetShift) {
        const dup = tbody.querySelector(`.emp-row[data-shift="${targetShift}"][data-role="${targetRole}"][data-emp-id="${data.empId}"]`);
        if (dup) return;

        srcRow.dataset.shift = targetShift;
        const icon = srcRow.querySelector('.fa-user');
        if (icon) icon.style.color = shiftColors[targetShift] || '#374151';
        srcRow.querySelectorAll('td.day-td').forEach(td => {
            td.innerHTML = makeWorkCell(targetRole, targetShift);
        });
    }

    tbody.insertBefore(srcRow, targetRow);
}

// ─── drop on shift header ────────────────────────────────────
function onDropShift(e, hdrRow) {
    e.preventDefault();
    hdrRow.classList.remove('over');
    let emp; try { emp = JSON.parse(e.dataTransfer.getData('text/plain')); } catch { return; }

    const shift = hdrRow.dataset.shift;
    const role  = hdrRow.dataset.role;
    const tbody = hdrRow.closest('tbody');

    // move existing row (row drag)
    if (emp.source === 'row') {
        if (emp.role !== role) return;
        if (emp.shift === shift) return;

        const srcRow = tbody.querySelector(
            `.emp-row[data-shift="${emp.shift}"][data-role="${emp.role}"][data-emp-id="${emp.empId}"]`
        );
        if (!srcRow) return;

        const dup = tbody.querySelector(`.emp-row[data-shift="${shift}"][data-role="${role}"][data-emp-id="${emp.empId}"]`);
        if (dup) { hdrRow.style.outline='2px solid #f87171'; setTimeout(()=>hdrRow.style.outline='',700); return; }

        srcRow.dataset.shift = shift;
        const icon = srcRow.querySelector('.fa-user');
        if (icon) icon.style.color = shiftColors[shift] || '#374151';

        srcRow.querySelectorAll('td.day-td').forEach(td => {
            td.innerHTML = makeWorkCell(emp.role, shift);
        });

        let anchor = hdrRow.nextElementSibling;
        while (anchor && !anchor.classList.contains('shift-hdr')) {
            anchor = anchor.nextElementSibling;
        }
        tbody.insertBefore(srcRow, anchor);
        return;
    }

    // panel card drop → add new employee
    if (emp.role !== role) {
        hdrRow.classList.add('bad');
        setTimeout(() => hdrRow.classList.remove('bad'), 700);
        return;
    }

    const dup = tbody.querySelector(`.emp-row[data-shift="${shift}"][data-role="${role}"][data-emp-id="${emp.id}"]`);
    if (dup) { hdrRow.style.outline='2px solid #f87171'; setTimeout(()=>hdrRow.style.outline='',700); return; }

    const newRow = buildEmpRow(shift, role, emp.id, emp.name);

    let anchor = hdrRow.nextElementSibling;
    while (anchor && !anchor.classList.contains('shift-hdr')) {
        anchor = anchor.nextElementSibling;
    }
    tbody.insertBefore(newRow, anchor);

    // hide from left panel
    const card = document.querySelector(`.emp-card[data-id="${emp.id}"][data-role="${role}"]`);
    if (card) card.style.display = 'none';
}

function buildEmpRow(shift, role, empId, empName) {
    const color = shiftColors[shift] || '#374151';

    const tr = document.createElement('tr');
    tr.className   = 'emp-row';
    tr.draggable   = true;
    tr.dataset.shift   = shift;
    tr.dataset.role    = role;
    tr.dataset.empId   = empId;
    tr.dataset.empName = empName;
    tr.setAttribute('ondragstart',  'dragRowStart(event)');
    tr.setAttribute('ondragover',   'dragRowOver(event)');
    tr.setAttribute('ondragleave',  'dragRowLeave(event)');
    tr.setAttribute('ondrop',       'dropRow(event)');
    tr.setAttribute('ondragend',    'dragRowEnd(event)');

    // name cell
    let html = `<td class="border border-gray-200 px-2 py-1.5 sticky left-0 bg-white z-10" style="width:216px;min-width:216px;max-width:216px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
      <div class="flex items-center gap-1">
        <i class="fa-solid fa-grip-vertical drag-handle mr-1"></i>
        <i class="fa-solid fa-user text-xs emp-info-icon" style="color:${color};cursor:pointer" title="Employee Info" onclick="showEmployeeInfo(${empId})"></i>
        <span class="font-semibold text-gray-700">${empName}</span>
        <button onclick="removeEmpRow(this)" class="ml-auto text-gray-300 hover:text-red-500 text-xs">✕</button>
      </div>
    </td>`;

    // date cells (default: working)
    DATES.forEach(date => {
        html += `<td class="border border-gray-200 p-1 text-center align-top day-td" data-date="${date}">`
              + makeWorkCell(role, shift) + `</td>`;
    });

    tr.innerHTML = html;
    return tr;
}

// ─── remove employee row ─────────────────────────────────────
function removeEmpRow(btn) {
    const tr    = btn.closest('tr');
    const empId = tr.dataset.empId;
    const role  = tr.dataset.role;
    tr.remove();
    // restore in left panel if not assigned to any shift
    const stillAssigned = document.querySelector(`.emp-row[data-emp-id="${empId}"][data-role="${role}"]`);
    if (!stillAssigned) {
        const card = document.querySelector(`.emp-card[data-id="${empId}"][data-role="${role}"]`);
        if (card) card.style.display = '';
    }
}

// ─── draft leave popup ───────────────────────────────────────
const LEAVE_STYLES = {
    work:          {isOff:0, att:'present',    bg:'transparent', color:'#374151'},
    present:       {isOff:1, att:'present',    bg:'#fee2e2',     color:'#b91c1c'},
    vacation:      {isOff:1, att:'vacation',   bg:'#fef9c3',     color:'#854d0e'},
    sick_leave:    {isOff:1, att:'sick_leave', bg:'#dbeafe',     color:'#1d4ed8'},
    sil:           {isOff:1, att:'sil',        bg:'#ccfbf1',     color:'#0f766e'},
    suspension:    {isOff:1, att:'suspension', bg:'#f3e8ff',     color:'#7e22ce'},
    early_leave:   {isOff:1, att:'early_leave',bg:'#ffedd5',     color:'#9a3412'},
    time_8am_5pm:  {isOff:0, att:'present',    bg:'#fef9c3',     color:'#854d0e', supTime:'8AM~5PM'},
    time_3pm_12am: {isOff:0, att:'present',    bg:'#dbeafe',     color:'#1d4ed8', supTime:'3PM~12AM'},
    time_11pm_8am: {isOff:0, att:'present',    bg:'#fff7ed',     color:'#c2410c', supTime:'11PM~8AM'},
    time_8am_8pm:  {isOff:0, att:'present',    bg:'#f5f3ff',     color:'#7e22ce', supTime:'8AM~8PM'},
    time_8pm_8am:  {isOff:0, att:'present',    bg:'#fce7f3',     color:'#9d174d', supTime:'8PM~8AM'},
};
const LEAVE_LABELS = {
    work:'Work', present:'Day Off', vacation:'Vacation', sick_leave:'Sick Leave', sil:'SIL', suspension:'Suspension',
    time_8am_5pm:'8AM~5PM', time_3pm_12am:'3PM~12AM', time_11pm_8am:'11PM~8AM',
    time_8am_8pm:'8AM~8PM', time_8pm_8am:'8PM~8AM',
};

function makeWorkCell(role = '', shift = '') {
    return `<button class="leave-trigger" data-is-off="0" data-leave-type="work" onclick="openLeavePanel(this)"
              style="background:transparent;border:none;cursor:pointer;padding:4px 2px;width:100%;display:flex;justify-content:center">
              <div class="work-dot"></div></button>`;
}
function makeLeaveCell(leaveType) {
    const s   = LEAVE_STYLES[leaveType] || LEAVE_STYLES.present;
    const lbl = LEAVE_LABELS[leaveType] || 'Day Off';
    const timeAttr = s.supTime ? ` data-override-time="${s.supTime}"` : '';
    return `<button class="leave-trigger" data-is-off="${s.isOff}" data-leave-type="${leaveType}"${timeAttr} onclick="openLeavePanel(this)"
              style="background:${s.bg};color:${s.color};border:1px solid ${s.color}55;border-radius:4px;
                     padding:2px 5px;font-size:9px;font-weight:700;cursor:pointer;
                     white-space:nowrap;width:100%;text-align:center">${lbl}</button>`;
}

let _activeLeaveTrigger = null;
function openLeavePanel(btn) {
    const panel = document.getElementById('leave_panel');
    if (_activeLeaveTrigger === btn && panel.style.display !== 'none') {
        panel.style.display = 'none'; _activeLeaveTrigger = null; return;
    }
    _activeLeaveTrigger = btn;
    const cur = btn.dataset.leaveType || 'work';
    panel.querySelectorAll('.leave-opt').forEach(o => {
        o.style.fontWeight = o.dataset.value === cur ? '700' : '500';
        o.style.background = o.dataset.value === cur ? '#f3f4f6' : '';
    });
    const r = btn.getBoundingClientRect();
    panel.style.display = 'block';
    const ph = panel.offsetHeight;
    const pw = panel.offsetWidth;
    let top = r.bottom + 4, left = r.left;
    if (left + pw > window.innerWidth - 8)  left = window.innerWidth - pw - 8;
    if (top  + ph > window.innerHeight - 8) top  = r.top - ph - 4;
    if (top < 8) top = 8;
    panel.style.top = top + 'px'; panel.style.left = left + 'px';
}
function selectLeaveOption(item) {
    document.getElementById('leave_panel').style.display = 'none';
    if (!_activeLeaveTrigger) return;
    const btn     = _activeLeaveTrigger; _activeLeaveTrigger = null;
    const td      = btn.closest('td');
    const role    = btn.closest('tr')?.dataset?.role || '';
    const trShift = btn.closest('tr')?.dataset?.shift || '';
    const value   = item.dataset.value;
    td.innerHTML  = value === 'work' ? makeWorkCell(role, trShift) : makeLeaveCell(value);
}
document.addEventListener('click', e => {
    if (!e.target.classList.contains('leave-trigger') && !e.target.closest('#leave_panel')) {
        document.getElementById('leave_panel').style.display = 'none';
        _activeLeaveTrigger = null;
    }
});

// ─── Save ─────────────────────────────────────────────────────
function collectItems() {
    const items = [];
    document.querySelectorAll('tr.emp-row').forEach(tr => {
        const shift  = tr.dataset.shift;
        const role   = tr.dataset.role;
        const empId  = parseInt(tr.dataset.empId) || null;
        if (!empId) return;
        tr.querySelectorAll('td.day-td').forEach(td => {
            const trigger   = td.querySelector('.leave-trigger');
            const isOff     = trigger ? parseInt(trigger.dataset.isOff || '0') : 0;
            const leaveType = trigger ? (trigger.dataset.leaveType || 'work') : 'work';
            const s         = LEAVE_STYLES[leaveType] || LEAVE_STYLES.work;
            const supSel      = td.querySelector('.sup-sel') || td.querySelector('.mid-cover-sel');
            const overrideTime = (!isOff && trigger?.dataset?.overrideTime) ? trigger.dataset.overrideTime : null;
            items.push({
                date:                 td.dataset.date,
                shift, job_role:      role,
                employee_id:          empId,
                is_off:               isOff,
                attendance:           s.att,
                supervisor_shift_time:(!isOff) ? (supSel?.value || overrideTime || null) : null,
            });
        });
    });
    return items;
}

function showMsg(msg, ok) {
    const el = document.getElementById('save_msg');
    el.textContent = msg;
    el.className = 'mb-3 px-4 py-2 rounded-lg text-xs font-medium ' +
        (ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');
    el.classList.remove('hidden');
    setTimeout(() => el.classList.add('hidden'), 3000);
}

async function saveSchedule() {
    const payload = { store_id:STORE_ID, year:YEAR, month:MONTH, period:PERIOD, items:collectItems() };
    try {
        const res = await fetch('ajax_save_schedule.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const d = await res.json();
        d.success ? showMsg(`Saved (${d.saved_count} items)`, true)
                  : showMsg('Save failed: '+(d.error??''), false);
    } catch { showMsg('Network error', false); }
}

// ─── confirm schedule ─────────────────────────────────────────
async function confirmSchedule() {
    if (!confirm('Confirm the schedule?\nEditing will be disabled and attendance tracking will begin.')) return;

    // step 1: save first to get item_id
    showMsg('Saving...', true);
    const savePayload = { store_id:STORE_ID, year:YEAR, month:MONTH, period:PERIOD, items:collectItems() };
    try {
        const sr  = await fetch('ajax_save_schedule.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify(savePayload)
        });
        const sd = await sr.json();
        if (!sd.success) { showMsg('Save failed: ' + (sd.error ?? ''), false); return; }
    } catch (e) { showMsg('Save error: ' + e.message, false); return; }

    // step 2: confirm
    try {
        const res  = await fetch('ajax_confirm_schedule.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({year:YEAR, month:MONTH, period:PERIOD, action:'confirm'})
        });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch { showMsg('Server error: ' + text.substring(0,200), false); return; }
        if (d.success) { location.reload(); }
        else showMsg('Confirm failed: ' + (d.message || d.error || ''), false);
    } catch (e) { showMsg('Request error: ' + e.message, false); }
}

async function unconfirmSchedule() {
    if (!confirm('Are you sure you want to unconfirm this schedule?')) return;
    try {
        const res  = await fetch('ajax_confirm_schedule.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({year:YEAR, month:MONTH, period:PERIOD, action:'unconfirm'})
        });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch { showMsg('Server error: ' + text.substring(0,200), false); return; }
        if (d.success) { location.reload(); }
        else showMsg('Unconfirm failed: ' + (d.message || d.error || ''), false);
    } catch (e) { showMsg('Request error: ' + e.message, false); }
}

// ─── attendance popup ─────────────────────────────────────────
const ATT_COLORS = {
    present:     {bg:'#dcfce7', color:'#15803d'},
    late:        {bg:'#fef3c7', color:'#92400e'},
    early_leave: {bg:'#ffedd5', color:'#9a3412'},
    sick_leave:  {bg:'#dbeafe', color:'#1d4ed8'},
    absent:      {bg:'#fee2e2', color:'#dc2626'},
    resign:      {bg:'#fce7f3', color:'#9d174d'},
    sil:         {bg:'#ccfbf1', color:'#0f766e'},
};
const ATT_LABELS = {
    present:     '✓ Present',
    late:        'Late',
    early_leave: 'Under Time',
    sick_leave:  'Sick Leave',
    absent:      '✗ Absent',
    resign:      'Resign',
    sil:         'SIL',
};

let _activeTrigger = null;

function toggleAttPanel(btn) {
    const panel = document.getElementById('att_panel');
    if (_activeTrigger === btn && panel.style.display !== 'none') {
        panel.style.display = 'none';
        _activeTrigger = null;
        return;
    }
    _activeTrigger = btn;

    const cur = btn.dataset.current;
    panel.querySelectorAll('.att-opt').forEach(opt => {
        const isSel = opt.dataset.value === cur;
        const c = ATT_COLORS[opt.dataset.value] || {};
        opt.style.background = isSel ? (c.bg    || '#f0fdf4') : '';
        opt.style.color      = isSel ? (c.color || '#15803d') : '#374151';
        opt.style.fontWeight = isSel ? '700' : '500';
    });

    const r = btn.getBoundingClientRect();
    panel.style.display = 'block';
    let top  = r.bottom + 4;
    let left = r.left;
    const pw = 140;
    if (left + pw > window.innerWidth - 8)  left = window.innerWidth - pw - 8;
    if (top  + 240 > window.innerHeight)    top  = r.top - 244;
    panel.style.top  = top  + 'px';
    panel.style.left = left + 'px';
}

function selectAttOption(item) {
    const panel = document.getElementById('att_panel');
    panel.style.display = 'none';
    if (!_activeTrigger) return;

    const btn   = _activeTrigger;
    _activeTrigger = null;
    const value = item.dataset.value;
    const shift = btn.closest('tr')?.dataset?.shift || '';
    let c;
    if (value === 'present') {
        if (btn.dataset.midCover) {
            c = {bg:'#1d4ed8', color:'#ffffff'};
        } else if (btn.dataset.supTime) {
            const isCashier = (btn.closest('tr')?.dataset?.role === 'cashier');
            c = isCashier ? {bg:'#c2410c', color:'#ffffff'} : {bg:'#1e40af', color:'#ffffff'};
        } else {
            c = SHIFT_ATT_COLORS[shift] || ATT_COLORS.present;
        }
    } else {
        c = ATT_COLORS[value] || {bg:'#f9fafb', color:'#374151'};
    }

    // update button: show shift time if present, otherwise attendance label
    btn.dataset.current   = value;
    btn.textContent       = (value === 'present')
                            ? (btn.dataset.shiftTime || ATT_LABELS.present)
                            : (ATT_LABELS[value] || value);
    btn.style.background  = c.bg;
    btn.style.color       = c.color;
    btn.style.borderColor = c.color + '33';

    saveAttendanceValue(parseInt(btn.dataset.itemId), value);
}

async function saveAttendanceValue(itemId, value) {
    if (!itemId || itemId <= 0) {
        showMsg('Save error: Invalid item (save before confirming schedule)', false);
        return;
    }
    try {
        const res  = await fetch('ajax_save_attendance.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({item_id: itemId, attendance: value})
        });
        const text = await res.text();
        let d; try { d = JSON.parse(text); } catch { showMsg('Response error', false); return; }
        if (!d.success) showMsg('Attendance save failed: ' + (d.error ?? ''), false);
    } catch (e) { showMsg('Attendance save error: ' + e.message, false); }
}

// close popup on outside click
document.addEventListener('click', e => {
    if (!e.target.classList.contains('att-trigger') &&
        !e.target.closest('#att_panel')) {
        document.getElementById('att_panel').style.display = 'none';
        _activeTrigger = null;
    }
});

// confirmed mode: disable drag
if (IS_CONFIRMED) {
    document.querySelectorAll('.emp-row').forEach(tr  => { tr.draggable = false; });
    document.querySelectorAll('.shift-hdr').forEach(tr => {
        tr.removeAttribute('ondragover');
        tr.removeAttribute('ondrop');
        tr.removeAttribute('ondragleave');
    });
    document.querySelectorAll('.drag-handle').forEach(el => { el.style.display = 'none'; });
}

// ─── employee info modal ─────────────────────────────────────
async function showEmployeeInfo(empId) {
    const modalEl = document.getElementById('empInfoModal');
    const body    = document.getElementById('emp_info_body');
    body.innerHTML = '<div class="text-center text-sm text-gray-400 py-4">Loading...</div>';
    new bootstrap.Modal(modalEl).show();

    try {
        const res = await fetch(`ajax_employee_info.php?employee_id=${empId}`);
        const d   = await res.json();
        if (!d.success) {
            body.innerHTML = `<div class="text-center text-sm text-red-500 py-4">${d.error ?? 'Failed to load'}</div>`;
            return;
        }
        const e = d.employee;
        const photoHtml = e.photo_url
            ? `<img src="${e.photo_url}" alt="${e.name}" class="w-20 h-20 rounded-full object-cover border border-gray-200">`
            : `<div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center"><i class="fa-solid fa-user text-2xl text-gray-300"></i></div>`;
        const statusBadge = e.status === 'active'
            ? `<span style="background:#dcfce7;color:#15803d;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700">Active</span>`
            : `<span style="background:#fee2e2;color:#b91c1c;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700">Inactive</span>`;

        let extra = '';
        if (e.status === 'inactive') {
            extra += `<div class="text-xs text-gray-500 mt-2">Reason: ${e.inactive_reason ?? '-'}</div>`;
            extra += `<div class="text-xs text-gray-500">Date: ${e.inactive_date ?? '-'}</div>`;
        }

        body.innerHTML = `
            <div class="flex flex-col items-center text-center gap-2">
                ${photoHtml}
                <div class="font-semibold text-gray-800 text-base">${e.name}</div>
                <div class="text-xs text-gray-500">${e.job_role_label}</div>
                ${statusBadge}
                ${extra}
                <div class="text-xs text-gray-400 mt-2">Joined: ${e.created_at ? e.created_at.substring(0,10) : '-'}</div>
            </div>`;
    } catch (err) {
        body.innerHTML = `<div class="text-center text-sm text-red-500 py-4">Request error: ${err.message}</div>`;
    }
}

// ─── floating employee panel ──────────────────────────────────
(function initFloatingPanel() {
    const panel = document.getElementById('emp_list_panel');
    const tableSection = document.getElementById('table_section');
    if (!panel || !tableSection) return;

    const rect   = panel.getBoundingClientRect();
    const panelW = panel.offsetWidth;

    // compensate table before going fixed (margin-left replaces the flex space)
    tableSection.style.marginLeft = (panelW + 12) + 'px'; // w-36 + gap-3

    panel.style.position = 'fixed';
    panel.style.top      = Math.max(rect.top, 8) + 'px';
    panel.style.left     = rect.left + 'px';
    panel.style.width    = panelW + 'px';
    panel.style.zIndex   = '20';

    // recalculate left on resize
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('aside');
        if (!sidebar) return;
        const sr = sidebar.getBoundingClientRect();
        panel.style.left = (sr.right + 4) + 'px'; // sidebar right + padding override
    });
})();
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
