<?php
// Design Ref: §4.3 — 오늘 출퇴근 현황 대시보드. get_today_attendance() 피벗 쿼리 사용.
$page_title      = 'Attendance — Today';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();

// 테스트용: 오늘 출퇴근 기록 초기화
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_attendance') {
    $conn = get_db_connection();
    $stmt = $conn->prepare("DELETE FROM office_attendance_logs WHERE store_id = ? AND DATE(event_time) = CURDATE()");
    $stmt->bind_param('i', $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
    header('Location: index.php');
    exit;
}

$today    = date('Y-m-d');
$rows     = get_today_attendance($store_id);

// 상태 계산
function att_status(array $row): array {
    $ci = $row['clock_in'];
    $bs = $row['break_start'];
    $be = $row['break_end'];
    $co = $row['clock_out'];

    if (!$ci) return ['label' => '미출근', 'color' => 'gray'];
    if ($co)  return ['label' => '퇴근',   'color' => 'blue'];
    if ($bs && !$be) return ['label' => '브레이크', 'color' => 'yellow'];
    return ['label' => '근무중', 'color' => 'green'];
}

function badge(string $label, string $color): string {
    $map = [
        'gray'   => 'bg-gray-100 text-gray-500',
        'green'  => 'bg-green-100 text-green-700',
        'yellow' => 'bg-yellow-100 text-yellow-700',
        'blue'   => 'bg-blue-100 text-blue-700',
    ];
    $cls = $map[$color] ?? $map['gray'];
    return "<span class=\"inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {$cls}\">{$label}</span>";
}

function fmt_time(?string $dt): string {
    return $dt ? date('H:i', strtotime($dt)) : '—';
}
?>

<div class="mb-5 flex items-center justify-between">
  <div>
    <h1 class="text-xl font-bold text-gray-800">
      <i class="fa-solid fa-clock text-blue-600 mr-2"></i>Today's Attendance
    </h1>
    <p class="text-sm text-gray-500 mt-0.5"><?= date('Y년 n월 j일 (D)', strtotime($today)) ?></p>
  </div>
  <div class="flex items-center gap-3">
    <form method="POST" onsubmit="return confirm('오늘의 출퇴근 기록을 모두 삭제하시겠습니까? (테스트용)')">
      <input type="hidden" name="action" value="reset_attendance">
      <button type="submit" class="text-sm text-red-500 hover:text-red-700">
        <i class="fa-solid fa-rotate-left mr-1"></i>오늘 기록 초기화 (테스트용)
      </button>
    </form>
    <a href="report.php" class="text-sm text-blue-600 hover:text-blue-800">
      <i class="fa-solid fa-chart-bar mr-1"></i>월별 리포트
    </a>
  </div>
</div>

<!-- 요약 카드 -->
<?php
$total   = count($rows);
$present = count(array_filter($rows, fn($r) => $r['clock_in'] && !$r['clock_out']));
$out     = count(array_filter($rows, fn($r) => !empty($r['clock_out'])));
$absent  = count(array_filter($rows, fn($r) => !$r['clock_in']));
?>
<div class="grid grid-cols-4 gap-3 mb-6">
  <?php foreach ([
    ['전체', $total,   'fa-users',           'text-gray-600',  'bg-gray-50'],
    ['근무중', $present,'fa-person-running',  'text-green-600', 'bg-green-50'],
    ['퇴근',  $out,    'fa-right-from-bracket','text-blue-600', 'bg-blue-50'],
    ['미출근', $absent, 'fa-circle-xmark',    'text-red-400',   'bg-red-50'],
  ] as [$label, $count, $icon, $tc, $bg]): ?>
  <div class="<?= $bg ?> rounded-xl px-4 py-3 flex items-center gap-3">
    <i class="fa-solid <?= $icon ?> text-xl <?= $tc ?>"></i>
    <div>
      <p class="text-2xl font-bold <?= $tc ?>"><?= $count ?></p>
      <p class="text-xs text-gray-500"><?= $label ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- 직원별 현황 테이블 -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
  <table class="w-full text-sm">
    <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
      <tr>
        <th class="px-4 py-2.5 text-left">직원</th>
        <th class="px-4 py-2.5 text-left">직무</th>
        <th class="px-4 py-2.5 text-center">상태</th>
        <th class="px-4 py-2.5 text-center">출근</th>
        <th class="px-4 py-2.5 text-center">브레이크</th>
        <th class="px-4 py-2.5 text-center">퇴근</th>
        <th class="px-4 py-2.5 text-center">실근무</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-100">
      <?php foreach ($rows as $r):
        $st = att_status($r);
        // 현재 실근무시간 계산 (퇴근 전이면 현재시각 기준)
        $events = [];
        if ($r['clock_in'])    $events[] = ['event_type' => 'clock_in',    'event_time' => $r['clock_in']];
        if ($r['break_start']) $events[] = ['event_type' => 'break_start', 'event_time' => $r['break_start']];
        if ($r['break_end'])   $events[] = ['event_type' => 'break_end',   'event_time' => $r['break_end']];
        // 퇴근 안했으면 현재 시각을 임시 clock_out으로 계산
        $co_time = $r['clock_out'] ?: ($r['clock_in'] ? date('Y-m-d H:i:s') : null);
        if ($co_time) $events[] = ['event_type' => 'clock_out', 'event_time' => $co_time];
        $summary = $r['clock_in'] ? calc_work_summary($events) : null;
        $net_h   = $summary ? sprintf('%dh %02dm', intdiv($summary['net_minutes'], 60), $summary['net_minutes'] % 60) : '—';
        $brk_str = ($r['break_start'] && $r['break_end'])
            ? fmt_time($r['break_start']) . '~' . fmt_time($r['break_end'])
            : ($r['break_start'] ? fmt_time($r['break_start']) . '~' : '—');
      ?>
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-2.5 font-medium text-gray-800">
          <?= htmlspecialchars($r['name']) ?>
        </td>
        <td class="px-4 py-2.5 text-gray-500 text-xs">
          <?= htmlspecialchars(get_job_role_label($r['job_role'])) ?>
        </td>
        <td class="px-4 py-2.5 text-center">
          <?= badge($st['label'], $st['color']) ?>
        </td>
        <td class="px-4 py-2.5 text-center font-mono text-xs text-gray-700">
          <?= fmt_time($r['clock_in']) ?>
        </td>
        <td class="px-4 py-2.5 text-center font-mono text-xs text-gray-500">
          <?= $brk_str ?>
        </td>
        <td class="px-4 py-2.5 text-center font-mono text-xs text-gray-700">
          <?= fmt_time($r['clock_out']) ?>
        </td>
        <td class="px-4 py-2.5 text-center text-xs font-medium
          <?= ($summary && $summary['overtime_minutes'] > 0) ? 'text-orange-600' : 'text-gray-600' ?>">
          <?= $net_h ?>
          <?php if ($summary && $summary['overtime_minutes'] > 0): ?>
            <span class="text-orange-400 ml-1">
              +<?= intdiv($summary['overtime_minutes'],60) ?>h<?= $summary['overtime_minutes']%60 ?>m OT
            </span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
