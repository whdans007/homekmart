<?php
// Design Ref: §4.4 — 일별/월별 실근무 리포트. calc_work_summary() 사용.
$page_title      = 'Attendance — Monthly Report';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();
$year     = (int)($_GET['year']  ?? date('Y'));
$month    = (int)($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int)date('n');

// 월별 이벤트 로그 조회
$raw = get_monthly_attendance($store_id, $year, $month);

// employee_id → name 맵, 날짜별 이벤트 구조화
// $by_emp[$emp_id]['name'] = '홍길동'
// $by_emp[$emp_id]['days'][$date][] = {event_type, event_time}
$by_emp = [];
foreach ($raw as $r) {
    $eid  = (int)$r['employee_id'];
    $date = $r['work_date'];
    if (!isset($by_emp[$eid])) {
        $by_emp[$eid] = ['name' => $r['name'], 'job_role' => $r['job_role'], 'days' => []];
    }
    $by_emp[$eid]['days'][$date][] = ['event_type' => $r['event_type'], 'event_time' => $r['event_time']];
}

// 월간 합계 계산
$emp_totals = [];
foreach ($by_emp as $eid => $emp) {
    $total_net = $total_ot = $total_days = 0;
    foreach ($emp['days'] as $date => $events) {
        $s = calc_work_summary($events);
        if ($s['net_minutes'] > 0) $total_days++;
        $total_net += $s['net_minutes'];
        $total_ot  += $s['overtime_minutes'];
    }
    $emp_totals[$eid] = ['days' => $total_days, 'net' => $total_net, 'ot' => $total_ot];
}

// 해당 월 날짜 목록
$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));

function fmt_mins(int $mins): string {
    if ($mins <= 0) return '—';
    return sprintf('%dh%02dm', intdiv($mins, 60), $mins % 60);
}
function fmt_time2(?string $dt): string {
    return $dt ? date('H:i', strtotime($dt)) : '';
}
?>

<div class="mb-5 flex items-center justify-between flex-wrap gap-2">
  <h1 class="text-xl font-bold text-gray-800">
    <i class="fa-solid fa-chart-bar text-blue-600 mr-2"></i>Attendance Monthly Report
  </h1>

  <form method="GET" class="flex items-center gap-2">
    <select name="year" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
      <?php for ($y = date('Y'); $y >= date('Y')-2; $y--): ?>
        <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <select name="month" class="border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= $m ?>월</option>
      <?php endfor; ?>
    </select>
    <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
      조회
    </button>
    <?php if (!empty($by_emp)): ?>
    <a href="export.php?year=<?= $year ?>&month=<?= $month ?>"
       class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">
      <i class="fa-solid fa-file-excel mr-1"></i>Excel
    </a>
    <?php endif; ?>
  </form>
</div>

<?php if (empty($by_emp)): ?>
<div class="bg-white rounded-xl border border-gray-200 px-5 py-10 text-center text-sm text-gray-400">
  <?= $year ?>년 <?= $month ?>월 출근 기록이 없습니다.
</div>
<?php else: ?>

<!-- 월간 요약 테이블 -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-6">
  <div class="px-4 py-3 border-b border-gray-100 text-sm font-semibold text-gray-700">
    <?= $year ?>년 <?= $month ?>월 월간 요약
  </div>
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
      <tr>
        <th class="px-4 py-2 text-left">직원</th>
        <th class="px-4 py-2 text-left">직무</th>
        <th class="px-4 py-2 text-center">출근일수</th>
        <th class="px-4 py-2 text-center">총 실근무</th>
        <th class="px-4 py-2 text-center">오버타임</th>
        <th class="px-4 py-2 text-center">정규근무</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($by_emp as $eid => $emp):
        $t = $emp_totals[$eid];
        $reg = max(0, $t['net'] - $t['ot']);
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2 font-medium text-gray-800"><?= htmlspecialchars($emp['name']) ?></td>
        <td class="px-4 py-2 text-gray-500 text-xs"><?= htmlspecialchars(get_job_role_label($emp['job_role'])) ?></td>
        <td class="px-4 py-2 text-center"><?= $t['days'] ?>일</td>
        <td class="px-4 py-2 text-center font-mono text-xs"><?= fmt_mins($t['net']) ?></td>
        <td class="px-4 py-2 text-center font-mono text-xs <?= $t['ot']>0?'text-orange-600 font-semibold':'' ?>">
          <?= $t['ot'] > 0 ? fmt_mins($t['ot']) : '—' ?>
        </td>
        <td class="px-4 py-2 text-center font-mono text-xs"><?= fmt_mins($reg) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- 직원별 일별 상세 -->
<?php foreach ($by_emp as $eid => $emp): ?>
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
  <div class="px-4 py-2.5 border-b border-gray-100 flex items-center justify-between">
    <span class="text-sm font-semibold text-gray-700">
      <?= htmlspecialchars($emp['name']) ?>
      <span class="text-xs text-gray-400 ml-1"><?= htmlspecialchars(get_job_role_label($emp['job_role'])) ?></span>
    </span>
    <span class="text-xs text-gray-400">
      출근 <?= $emp_totals[$eid]['days'] ?>일 /
      실근무 <?= fmt_mins($emp_totals[$eid]['net']) ?> /
      OT <?= $emp_totals[$eid]['ot']>0 ? fmt_mins($emp_totals[$eid]['ot']) : '없음' ?>
    </span>
  </div>
  <table class="w-full text-xs">
    <thead class="bg-gray-50 text-gray-400 uppercase tracking-wide">
      <tr>
        <th class="px-3 py-2 text-left">날짜</th>
        <th class="px-3 py-2 text-center">출근</th>
        <th class="px-3 py-2 text-center">브레이크</th>
        <th class="px-3 py-2 text-center">퇴근</th>
        <th class="px-3 py-2 text-center">실근무</th>
        <th class="px-3 py-2 text-center">OT</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-50">
      <?php for ($d = 1; $d <= $days_in_month; $d++):
        $date   = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $events = $emp['days'][$date] ?? [];
        if (empty($events)) continue;

        $ci = $bs = $be = $co = null;
        foreach ($events as $e) {
            switch ($e['event_type']) {
                case 'clock_in':    $ci = $e['event_time']; break;
                case 'break_start': $bs = $e['event_time']; break;
                case 'break_end':   $be = $e['event_time']; break;
                case 'clock_out':   $co = $e['event_time']; break;
            }
        }
        $s    = calc_work_summary($events);
        $dow  = ['일','월','화','수','목','금','토'][date('w', strtotime($date))];
        $is_w = in_array(date('w', strtotime($date)), [0, 6]);
      ?>
      <tr class="hover:bg-gray-50 <?= $is_w ? 'text-red-400' : '' ?>">
        <td class="px-3 py-1.5 font-medium">
          <?= $d ?>일 (<?= $dow ?>)
        </td>
        <td class="px-3 py-1.5 text-center font-mono"><?= fmt_time2($ci) ?></td>
        <td class="px-3 py-1.5 text-center font-mono text-gray-400">
          <?= $bs ? fmt_time2($bs) . ($be ? '~'.fmt_time2($be) : '~') : '—' ?>
        </td>
        <td class="px-3 py-1.5 text-center font-mono"><?= fmt_time2($co) ?: '—' ?></td>
        <td class="px-3 py-1.5 text-center font-mono"><?= fmt_mins($s['net_minutes']) ?></td>
        <td class="px-3 py-1.5 text-center font-mono <?= $s['overtime_minutes']>0?'text-orange-500 font-semibold':'' ?>">
          <?= $s['overtime_minutes'] > 0 ? '+'.fmt_mins($s['overtime_minutes']) : '—' ?>
        </td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
