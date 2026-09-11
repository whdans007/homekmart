<?php
/** mall_fresh_products에 kg/pcs/pack 기준 단위를 저장하는 컬럼을 추가합니다. */
require_once __DIR__ . '/../../config/db_config.php';
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die('DB 연결 실패: ' . htmlspecialchars($conn->connect_error, ENT_QUOTES, 'UTF-8')); }
$conn->set_charset(DB_CHARSET);

$result = $conn->query("SHOW COLUMNS FROM `mall_fresh_products` LIKE 'unit_type'");
$alreadyDone = (bool)($result && $result->num_rows > 0);
$ran = false;
$status = $alreadyDone ? 'SKIP' : 'READY';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    $ran = true;
    if ($alreadyDone) {
        $status = 'SKIP';
    } else {
        $sql = "ALTER TABLE `mall_fresh_products`
                ADD COLUMN `unit_type` ENUM('kg','pcs','pack') NOT NULL DEFAULT 'kg'
                COMMENT '상품 판매 기준 단위' AFTER `pkg_pieces_per_box`";
        if ($conn->query($sql)) { $status = 'OK'; } else { $status = 'ERROR'; $error = $conn->error; }
    }
}
$conn->close();
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>신선상품 기준 단위 마이그레이션</title><style>body{font-family:-apple-system,sans-serif;background:#f9fafb;padding:2rem}.card{max-width:640px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem}.status{font-weight:700}.error{color:#991b1b}button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:0;border-radius:6px;font-weight:700;cursor:pointer}</style></head>
<body><div class="card"><h1>신선상품 기준 단위 마이그레이션</h1><p>mall_fresh_products.unit_type: <span class="status"><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span></p>
<?php if ($error): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<?php if (!$ran): ?><form method="post"><input type="hidden" name="action" value="run"><button onclick="return confirm('마이그레이션을 실행하시겠습니까?')">마이그레이션 실행</button></form><?php else: ?><p>OK 또는 SKIP이면 완료되었습니다. 이 파일을 서버에서 삭제해 주세요.</p><?php endif; ?>
</div></body></html>
