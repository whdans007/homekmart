<?php
require_once __DIR__ . '/../config/db_config.php';
$conn = get_db_connection();

// supervisor_shift_time ENUM에 11PM~8AM 추가 (캐쉬어 야간)
$sql1 = "ALTER TABLE office_schedule_items
          MODIFY COLUMN supervisor_shift_time
            ENUM('8AM-8PM','8PM-8AM','11PM~8AM') NULL
            COMMENT '교대시간 (슈퍼바이저/캐쉬어)'";

// attendance ENUM에 sil 추가 (유급 휴가)
$sql2 = "ALTER TABLE office_schedule_items
          MODIFY COLUMN attendance
            ENUM('present','sick_leave','vacation','sil','absent','suspension','early_leave','late')
            NOT NULL DEFAULT 'present'";

$ok1 = $conn->query($sql1);
$ok2 = $conn->query($sql2);
$conn->close();

if ($ok1 && $ok2) @unlink(__FILE__);
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>마이그레이션</title>
<style>body{font-family:sans-serif;max-width:500px;margin:60px auto;text-align:center}
.ok{color:#15803d;font-size:1.1rem}.fail{color:#dc2626}.item{margin:8px 0}</style></head><body>
<p class="item <?php echo $ok1?'ok':'fail';?>">
  <?php echo $ok1 ? '✅ supervisor_shift_time: 11PM~8AM 추가 완료' : '❌ supervisor_shift_time 실패'; ?>
</p>
<p class="item <?php echo $ok2?'ok':'fail';?>">
  <?php echo $ok2 ? '✅ attendance: SIL 추가 완료' : '❌ attendance SIL 실패'; ?>
</p>
<?php if ($ok1 && $ok2): ?>
<p style="color:#6b7280;font-size:12px;margin-top:16px">이 파일은 자동 삭제되었습니다.</p>
<?php endif; ?>
</body></html>
