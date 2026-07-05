<?php
// 센터 업체 출고 기능 되돌리기 - 마이그레이션으로 생성된 테이블 확인/삭제
// 실행 후 반드시 이 파일을 삭제하세요.
require_once __DIR__ . '/partials/header.php';

if ($_SESSION['role'] !== 'super_admin') {
    echo '<div style="font-family:sans-serif;padding:20px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;">';
    echo '<strong>❌ 권한 없음:</strong> super_admin만 실행할 수 있습니다.';
    echo '</div>';
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$tables = ['center_vendor_outbound_items', 'center_vendor_outbounds'];
$action = $_GET['action'] ?? '';
$messages = [];

foreach ($tables as $table) {
    $check = $conn->query("
        SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
    ");
    $exists = $check->fetch_assoc()['cnt'] > 0;

    if (!$exists) {
        $messages[] = ['type' => 'skip', 'text' => "$table : 존재하지 않음"];
        continue;
    }

    $countResult = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
    $rowCount = $countResult->fetch_assoc()['cnt'];

    if ($action === 'drop') {
        $ok = $conn->query("DROP TABLE IF EXISTS `$table`");
        $messages[] = ['type' => $ok ? 'success' : 'error', 'text' => "$table : 삭제 " . ($ok ? '완료' : ('실패 - ' . $conn->error))];
    } else {
        $messages[] = ['type' => 'info', 'text' => "$table : 존재함 (행 수: $rowCount)"];
    }
}

$conn->close();
?>

<div class="container mx-auto px-4 py-8 max-w-2xl">
    <div class="bg-white shadow-sm rounded-lg border p-6">
        <h1 class="text-xl font-semibold text-gray-900 mb-2">
            <i class="fas fa-database mr-2 text-indigo-500"></i>
            센터 업체 출고 테이블 확인/삭제
        </h1>
        <p class="text-sm text-gray-500 mb-6">center_vendor_outbounds / center_vendor_outbound_items 테이블 상태를 확인하고 삭제합니다.</p>

        <div class="space-y-3">
            <?php foreach ($messages as $msg): ?>
                <?php
                $colors = [
                    'skip' => ['bg-gray-50', 'border-gray-200', 'text-gray-600', '⏭'],
                    'info' => ['bg-blue-50', 'border-blue-200', 'text-blue-800', 'ℹ️'],
                    'success' => ['bg-green-50', 'border-green-200', 'text-green-800', '✅'],
                    'error' => ['bg-red-50', 'border-red-200', 'text-red-800', '❌'],
                ];
                [$bg, $border, $text, $icon] = $colors[$msg['type']];
                ?>
                <div class="flex items-center gap-3 p-3 <?= $bg ?> border <?= $border ?> rounded-md">
                    <span class="text-lg"><?= $icon ?></span>
                    <div class="text-sm font-medium <?= $text ?>"><?= htmlspecialchars($msg['text']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($action !== 'drop'): ?>
        <div class="mt-6 pt-4 border-t border-gray-200">
            <p class="text-sm text-gray-600 mb-3">테이블이 존재하고 삭제를 원하시면 아래 버튼을 누르세요. (행 수가 0이 아닌 경우 데이터 백업을 먼저 확인하세요)</p>
            <a href="?action=drop" class="inline-block px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700">테이블 삭제</a>
        </div>
        <?php else: ?>
        <div class="mt-6 pt-4 border-t border-gray-200">
            <div class="p-4 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
                완료되었습니다. 보안을 위해 이 파일(<code>check_center_vendor_outbound_tables.php</code>)을 삭제하세요.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
