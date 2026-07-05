<?php
require_once __DIR__ . '/../lib/office_helper.php';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = (int)($_GET['month'] ?? date('n'));
$period      = in_array($_GET['period'] ?? '', ['first','second']) ? $_GET['period'] : 'first';
$store_id    = get_office_store_id();

// 단일 JOIN 쿼리로 전체 데이터 로드 (N+1 방지)
// data[$role][$shift][$emp_id] = ['name'=>..., 'days'=>[date=>{is_off,sup_time,attendance}]]
$data = [];
if ($schedule_id) {
    $conn = get_db_connection();

    // attendance 컬럼 존재 여부 확인
    $ac = $conn->query("SHOW COLUMNS FROM office_schedule_items LIKE 'attendance'");
    $has_att = $ac && $ac->num_rows > 0;
    $att_sel = $has_att ? 'si.attendance' : "'present' AS attendance";

    $stmt = $conn->prepare(
        "SELECT si.schedule_date, si.shift, si.job_role, si.employee_id,
                si.is_off, si.supervisor_shift_time, {$att_sel},
                e.name AS emp_name
         FROM office_schedule_items si
         LEFT JOIN office_employees e ON si.employee_id = e.id
         WHERE si.schedule_id = ?
         ORDER BY si.job_role, si.shift, e.name, si.schedule_date"
    );
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $role  = $r['job_role'];
        $shift = $r['shift'];
        $eid   = (int)$r['employee_id'];
        $dt    = $r['schedule_date'];
        if (!isset($data[$role][$shift][$eid])) {
            $data[$role][$shift][$eid] = ['name' => $r['emp_name'] ?? '', 'days' => []];
        }
        $data[$role][$shift][$eid]['days'][$dt] = [
            'is_off'     => (int)$r['is_off'],
            'sup_time'   => $r['supervisor_shift_time'] ?? '',
            'attendance' => $r['attendance'] ?? 'present',
        ];
    }
    $stmt->close();
    $conn->close();
}

$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
$date_from = $period === 'first' ? 1 : 16;
$date_to   = $period === 'first' ? 15 : $days_in_month;
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

$roles       = get_job_roles();
$shifts      = ['morning' => 'MORNING', 'mid' => 'MID', 'gy' => 'GY'];
$shift_times = ['morning' => '8AM~5PM', 'mid' => '3PM~12AM', 'gy' => '11PM~8AM'];

// 확정 화면과 동일한 색상 팔레트
$shift_att_colors = [
    'morning' => ['bg' => '#dcfce7', 'color' => '#15803d'],
    'mid'     => ['bg' => '#fef9c3', 'color' => '#854d0e'],
    'gy'      => ['bg' => '#dbeafe', 'color' => '#1e40af'],
];
$att_colors = [
    'present'     => ['bg' => '#dcfce7', 'color' => '#15803d'],
    'late'        => ['bg' => '#fef3c7', 'color' => '#92400e'],
    'early_leave' => ['bg' => '#ffedd5', 'color' => '#9a3412'],
    'sick_leave'  => ['bg' => '#dbeafe', 'color' => '#1d4ed8'],
    'absent'      => ['bg' => '#fee2e2', 'color' => '#dc2626'],
    'resign'      => ['bg' => '#fce7f3', 'color' => '#9d174d'],
];
$att_labels = [
    'present'     => '✓ Present',
    'late'        => 'Late',
    'early_leave' => 'Under Time',
    'sick_leave'  => 'Sick Leave',
    'absent'      => '✗ Absent',
    'resign'      => 'Resign',
];
$leave_styles = [
    'present'     => ['label' => 'Day Off',    'bg' => '#fee2e2', 'color' => '#b91c1c'],
    'vacation'    => ['label' => 'Vacation',   'bg' => '#fef9c3', 'color' => '#854d0e'],
    'sick_leave'  => ['label' => 'Sick Leave', 'bg' => '#dbeafe', 'color' => '#1d4ed8'],
    'sil'         => ['label' => 'SIL',        'bg' => '#ccfbf1', 'color' => '#0f766e'],
    'suspension'  => ['label' => 'Suspension', 'bg' => '#f3e8ff', 'color' => '#7e22ce'],
    'early_leave' => ['label' => 'Under Time', 'bg' => '#ffedd5', 'color' => '#9a3412'],
    'absent'      => ['label' => 'Absent',     'bg' => '#fee2e2', 'color' => '#dc2626'],
    'resign'      => ['label' => 'Resign',     'bg' => '#fce7f3', 'color' => '#9d174d'],
];
$role_colors = [
    'cashier'      => ['bg' => '#1e293b', 'color' => '#ffffff', 'border' => '#0f172a'],
    'patcher'      => ['bg' => '#0f766e', 'color' => '#ffffff', 'border' => '#0d5f58'],
    'butcher'      => ['bg' => '#b91c1c', 'color' => '#ffffff', 'border' => '#991b1b'],
    'driver'       => ['bg' => '#1d4ed8', 'color' => '#ffffff', 'border' => '#1e40af'],
    'merchandiser' => ['bg' => '#7c3aed', 'color' => '#ffffff', 'border' => '#6d28d9'],
    'supervisor'   => ['bg' => '#b45309', 'color' => '#ffffff', 'border' => '#92400e'],
    'admin'        => ['bg' => '#374151', 'color' => '#ffffff', 'border' => '#1f2937'],
];
$time_color_map = [
    '8AM~5PM'  => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#854d0e'],
    '3PM~12AM' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#1d4ed8'],
    '11PM~8AM' => ['bg' => '#fff7ed', 'color' => '#c2410c', 'border' => '#c2410c'],
    '8AM~8PM'  => ['bg' => '#f5f3ff', 'color' => '#7e22ce', 'border' => '#7e22ce'],
    '8PM~8AM'  => ['bg' => '#fce7f3', 'color' => '#9d174d', 'border' => '#9d174d'],
    '8AM-8PM'  => ['bg' => '#f5f3ff', 'color' => '#7e22ce', 'border' => '#7e22ce'],
    '8PM-8AM'  => ['bg' => '#fce7f3', 'color' => '#9d174d', 'border' => '#9d174d'],
];

$period_label = $period === 'first' ? '1~15' : "16~{$days_in_month}";
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>Day Off Schedule <?php echo "{$year}/{$month} ({$period_label})"; ?></title>
<style>
  * { box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
  body { font-family: 'Malgun Gothic', Arial, sans-serif; font-size: 11px; margin: 10px; }
  h2   { font-size: 16px; text-align: center; margin: 0 0 14px; font-weight: 800; letter-spacing: .02em; }

  .role-block  { margin-bottom: 20px; break-inside: avoid; }
  .role-title  { font-size: 14px; font-weight: 800;
                 padding: 7px 14px; border-radius: 6px 6px 0 0; margin: 0;
                 letter-spacing: .04em; }

  .shift-hdr-row td { font-size: 11px; font-weight: 700; padding: 5px 12px;
                      text-align: left; border-left: none; border-right: none; }
  .shift-hdr-row.shift-morning td { background: #fef3c7; color: #92400e; border-top: 2px solid #d97706; }
  .shift-hdr-row.shift-mid     td { background: #dbeafe; color: #1e3a8a; border-top: 2px solid #3b82f6; }
  .shift-hdr-row.shift-gy      td { background: #ede9fe; color: #4c1d95; border-top: 2px solid #8b5cf6; }

  table { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 0; }
  th, td { border: 1px solid #d1d5db; padding: 4px 3px; text-align: center; vertical-align: middle; }
  th { background: #f3f4f6; font-weight: 700; font-size: 10px; }
  th.name-col, td.name-col { width: 144px; }
  td.name-col { text-align: left; padding-left: 7px; font-weight: 700; font-size: 11px;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .sun { color: #dc2626; font-weight: 700; }
  .sat { color: #2563eb; font-weight: 700; }
  .cell-badge { display:inline-block; border-radius:4px; padding:2px 3px;
                font-size:8px; font-weight:700; white-space:nowrap;
                border-width:1px; border-style:solid; line-height:1.4; }
  .no-emp    { color: #9ca3af; font-size: 10px; padding: 5px; }

  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    body { margin: 0; font-size: 10px; }
    .role-block { page-break-inside: avoid; }
  }
</style>
</head>
<body>
<h2>Day Off Schedule — <?php echo "{$year}/{$month} ({$period_label})"; ?></h2>

<?php foreach ($roles as $role): ?>
<?php
$role_label   = get_job_role_label($role);
$has_any      = false;
foreach ($shifts as $sk => $_) {
    if (!empty($data[$role][$sk])) { $has_any = true; break; }
}
if (!$has_any) continue; // 배정 직원 없는 직무는 생략
?>
<div class="role-block">
  <?php $rc = $role_colors[$role] ?? ['bg'=>'#374151','color'=>'#ffffff','border'=>'#1f2937']; ?>
  <div class="role-title"
       style="background:<?php echo $rc['bg'];?>;color:<?php echo $rc['color'];?>;border-bottom:3px solid <?php echo $rc['border'];?>">
    <?php echo htmlspecialchars($role_label); ?>
  </div>

  <!-- 직무당 테이블 1개 — 날짜 헤더 1번만 출력 -->
  <table>
    <thead>
      <tr>
        <th class="name-col">Name</th>
        <?php foreach ($dates as $d): ?>
        <th class="<?php echo $d['sun']?'sun':($d['sat']?'sat':''); ?>">
          <?php echo $d['day']; ?><br>(<?php echo $d['wd']; ?>)
        </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($shifts as $shift_key => $shift_label): ?>
      <?php $emps = $data[$role][$shift_key] ?? []; ?>
      <?php if (empty($emps)) continue; ?>

      <!-- 근무 헤더 행 -->
      <tr class="shift-hdr-row shift-<?php echo $shift_key; ?>">
        <td colspan="<?php echo count($dates) + 1; ?>"><?php echo $shift_label; ?></td>
      </tr>

      <!-- 직원 행들 -->
      <?php foreach ($emps as $eid => $emp): ?>
      <tr>
        <td class="name-col"><?php echo htmlspecialchars($emp['name'] ?: '—'); ?></td>
        <?php foreach ($dates as $d): ?>
        <?php $s = $emp['days'][$d['date']] ?? null; ?>
        <td>
          <?php if (!$s): ?>
            <span style="color:#d1d5db">—</span>
          <?php elseif ($s['is_off']): ?>
            <?php $ls = $leave_styles[$s['attendance']] ?? $leave_styles['present']; ?>
            <span class="cell-badge"
                  style="background:<?php echo $ls['bg'];?>;color:<?php echo $ls['color'];?>;
                         border-color:<?php echo $ls['color'];?>55">
              <?php echo htmlspecialchars($ls['label']); ?>
            </span>
          <?php else: ?>
            <?php
            $sup_override   = !empty($s['sup_time']);
            $display_time   = $sup_override ? $s['sup_time'] : ($shift_times[$shift_key] ?? '');
            $att            = $s['attendance'] ?? 'present';

            if ($att === 'present') {
                if ($sup_override) {
                    $c = $time_color_map[$s['sup_time']] ?? ['bg' => '#1d4ed8', 'color' => '#ffffff', 'border' => '#1e3a8a'];
                } else {
                    $base = $shift_att_colors[$shift_key] ?? $att_colors['present'];
                    $c = array_merge($base, ['border' => $base['color']]);
                }
                $cell_text = $display_time;
            } else {
                $base = $att_colors[$att] ?? $att_colors['present'];
                $c    = array_merge($base, ['border' => $base['color']]);
                $cell_text = $att_labels[$att] ?? $att;
            }
            $extra_style = $sup_override
                ? 'font-size:9px;padding:3px 4px;box-shadow:0 0 0 2px ' . $c['border'] . ';'
                : '';
            ?>
            <span class="cell-badge"
                  style="background:<?php echo $c['bg'];?>;color:<?php echo $c['color'];?>;
                         border-color:<?php echo $c['border'];?>;<?php echo $extra_style;?>">
              <?php echo htmlspecialchars($cell_text); ?>
            </span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; // emps ?>

      <?php endforeach; // shifts ?>
    </tbody>
  </table>
</div>
<?php endforeach; // roles ?>

<script>window.onload = () => window.print();</script>
</body>
</html>
