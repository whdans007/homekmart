<?php
require_once __DIR__ . '/../config/db_config.php';
$conn = get_db_connection();

$sql = "ALTER TABLE office_schedule_items
        MODIFY COLUMN attendance
        ENUM('present','sick_leave','vacation','absent','suspension','early_leave','late')
        NOT NULL DEFAULT 'present'";

$ok = $conn->query($sql);
if ($ok) @unlink(__FILE__);
$conn->close();
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>지각 마이그레이션</title>
<style>body{font-family:sans-serif;max-width:500px;margin:60px auto;text-align:center}
.ok{color:#15803d;font-size:1.2rem}.fail{color:#dc2626}</style></head><body>
<?php if ($ok): ?>
<p class="ok">✅ 지각(late) 항목 추가 완료. 이 파일은 자동 삭제되었습니다.</p>
<?php else: ?>
<p class="fail">❌ 실패: <?php echo htmlspecialchars($conn->error ?? ''); ?></p>
<?php endif; ?>
</body></html>
