<?php
require_once __DIR__ . '/../lib/office_helper.php';

$schedule_id = (int)($_GET['schedule_id'] ?? 0);
$year        = (int)($_GET['year']  ?? date('Y'));
$month       = (int)($_GET['month'] ?? date('n'));
$period      = in_array($_GET['period'] ?? '', ['first','second']) ? $_GET['period'] : 'first';
$store_id    = get_office_store_id();

$saved_items = [];
if ($schedule_id) {
    $conn = get_db_connection();
    $stmt = $conn->prepare("SELECT * FROM office_schedule_items WHERE schedule_id=? ORDER BY schedule_date, shift, job_role");
    $stmt->bind_param('i', $schedule_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    foreach ($rows as $r) {
        $saved_items[$r['schedule_date']][$r['shift']][$r['job_role']] = $r;
    }
}

$days_in_month = (int)date('t', mktime(0,0,0,$month,1,$year));
$date_from = $period === 'first' ? 1 : 16;
$date_to   = $period === 'first' ? 15 : $days_in_month;
$dates = [];
for ($d = $date_from; $d <= $date_to; $d++) {
    $ts = mktime(0,0,0,$month,$d,$year);
    $dates[] = ['day'=>$d,'date'=>sprintf('%04d-%02d-%02d',$year,$month,$d),
                'wd'=>['일','월','화','수','목','금','토'][date('w',$ts)],
                'sun'=>date('w',$ts)==0,'sat'=>date('w',$ts)==6];
}

$roles  = get_job_roles();
$shifts = ['morning'=>'MORNING','mid'=>'MID','gy'=>'GY'];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>휴무계획서 <?php echo "{$year}년 {$month}월 " . ($period==='first'?'1~15일':'16~'.$days_in_month.'일'); ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Malgun Gothic', sans-serif; font-size: 10px; margin: 10px; }
  h2 { font-size: 14px; text-align: center; margin-bottom: 12px; }
  h3 { font-size: 11px; background: #333; color: #fff; padding: 4px 8px; margin: 12px 0 0 0; }
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  th, td { border: 1px solid #ccc; padding: 3px 2px; text-align: center; vertical-align: middle; }
  th { background: #f5f5f5; font-weight: bold; }
  .role-col { width: 52px; text-align: left; padding-left: 4px; font-weight: bold; }
  .sun { color: #dc2626; }
  .sat { color: #2563eb; }
  .off { color: #dc2626; font-weight: bold; }
  .sup-time { color: #7c3aed; font-size: 9px; }
  @media print {
    @page { size: A4 landscape; margin: 8mm; }
    body { margin: 0; }
  }
</style>
</head>
<body>
<h2>휴무계획서 — <?php echo "{$year}년 {$month}월 " . ($period==='first'?'1~15일':'16~'.$days_in_month.'일'); ?></h2>

<?php foreach ($shifts as $shift_key => $shift_label): ?>
<h3><?php echo $shift_label; ?> 근무</h3>
<table>
  <thead>
    <tr>
      <th class="role-col">직무</th>
      <?php foreach ($dates as $d): ?>
      <th class="<?php echo $d['sun']?'sun':($d['sat']?'sat':''); ?>">
        <?php echo $d['day']; ?><br>(<?php echo $d['wd']; ?>)
      </th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($roles as $role): ?>
    <tr>
      <td class="role-col"><?php echo get_job_role_label($role); ?></td>
      <?php foreach ($dates as $d): ?>
      <?php $s = $saved_items[$d['date']][$shift_key][$role] ?? null; ?>
      <td>
        <?php if ($s): ?>
          <?php if ($s['is_off']): ?>
            <span class="off">휴무</span>
            <?php if ($s['supervisor_shift_time']): ?>
            <br><span class="sup-time"><?php echo htmlspecialchars($s['supervisor_shift_time']); ?></span>
            <?php endif; ?>
          <?php else: ?>
            <?php
            // 직원명 조회 (간단히 id만 저장됨 — 이름 조회)
            $emp_name = '';
            if ($s['employee_id']) {
                $ec = get_db_connection();
                $es = $ec->prepare("SELECT name FROM office_employees WHERE id=?");
                $es->bind_param('i', $s['employee_id']);
                $es->execute();
                $er = $es->get_result()->fetch_assoc();
                $emp_name = $er ? $er['name'] : '';
                $es->close(); $ec->close();
            }
            echo htmlspecialchars($emp_name ?: '—');
            ?>
          <?php endif; ?>
        <?php else: ?>—<?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>

<script>window.onload = () => window.print();</script>
</body>
</html>
