<?php
// Design Ref: docs/02-design/features/expiry-management.design.md §5.4 점검기록
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/expiry_helper.php';

if (!has_permission('product_management')) {
    $_SESSION['flash'] = [
        'type' => 'error',
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$conn = get_db_connection();
$user_id = (int)($_SESSION['user_id'] ?? 0);

// 유통기한별 로트의 총 수량을 점포 전체 재고(inventory)에 동기화
// (admin/ajax_update_lot_inventory.php와 동일한 동기화 방식)
function sync_inventory_total($conn, $product_id, $store_id) {
    $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) AS total FROM inventory_expirations WHERE product_id = ? AND store_id = ?");
    $sum_stmt->bind_param("ii", $product_id, $store_id);
    $sum_stmt->execute();
    $total = (int)$sum_stmt->get_result()->fetch_assoc()['total'];
    $sum_stmt->close();

    $check_stmt = $conn->prepare("SELECT id FROM inventory WHERE product_id = ? AND store_id = ? LIMIT 1");
    $check_stmt->bind_param("ii", $product_id, $store_id);
    $check_stmt->execute();
    $exists = $check_stmt->get_result()->num_rows > 0;
    $check_stmt->close();

    if ($exists) {
        $upd = $conn->prepare("UPDATE inventory SET quantity = ? WHERE product_id = ? AND store_id = ?");
        $upd->bind_param("iii", $total, $product_id, $store_id);
        $upd->execute();
        $upd->close();
    } else {
        $ins = $conn->prepare("INSERT IGNORE INTO inventory (product_id, store_id, quantity, cost_price, selling_price) VALUES (?, ?, ?, 0, 0)");
        $ins->bind_param("iii", $product_id, $store_id, $total);
        $ins->execute();
        $ins->close();
    }
}

$flash_message = null;
$flash_type = null;

// POST 처리 (자체 제출 패턴)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $product_id = (int)($_POST['product_id'] ?? 0);
        $expiration_date = $_POST['expiration_date'] ?? '';
        $quantity = (int)($_POST['quantity'] ?? 0);

        if (!$product_id || !$expiration_date || $quantity < 0) {
            $flash_type = 'error';
            $flash_message = '상품, 유통기한, 수량을 모두 올바르게 입력해주세요.';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("
                    UPDATE inventory_expirations
                    SET expiration_date = ?, quantity = ?, registered_by = ?, registered_at = NOW()
                    WHERE id = ? AND store_id = ?
                ");
                $stmt->bind_param("siiii", $expiration_date, $quantity, $user_id, $id, $current_store_id);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO inventory_expirations (store_id, product_id, expiration_date, quantity, registered_by, registered_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), registered_by = VALUES(registered_by), registered_at = VALUES(registered_at)
                ");
                $stmt->bind_param("iisii", $current_store_id, $product_id, $expiration_date, $quantity, $user_id);
                $stmt->execute();
                $stmt->close();
            }
            sync_inventory_total($conn, $product_id, $current_store_id);
            $flash_type = 'success';
            $flash_message = '유통기한 정보가 저장되었습니다.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $get_stmt = $conn->prepare("SELECT product_id FROM inventory_expirations WHERE id = ? AND store_id = ?");
        $get_stmt->bind_param("ii", $id, $current_store_id);
        $get_stmt->execute();
        $row = $get_stmt->get_result()->fetch_assoc();
        $get_stmt->close();

        if ($row) {
            $del_stmt = $conn->prepare("DELETE FROM inventory_expirations WHERE id = ? AND store_id = ?");
            $del_stmt->bind_param("ii", $id, $current_store_id);
            $del_stmt->execute();
            $del_stmt->close();
            sync_inventory_total($conn, $row['product_id'], $current_store_id);
            $flash_type = 'success';
            $flash_message = '삭제되었습니다.';
        } else {
            $flash_type = 'error';
            $flash_message = '삭제할 항목을 찾을 수 없습니다.';
        }
    } elseif ($action === 'save_settings') {
        $warning_days = (int)($_POST['warning_days'] ?? 60);
        $alert_days = (int)($_POST['alert_days'] ?? 30);
        $result = save_expiry_settings($conn, $warning_days, $alert_days, $user_id);
        $flash_type = $result['success'] ? 'success' : 'error';
        $flash_message = $result['success'] ? '임계값 설정이 저장되었습니다.' : $result['error'];
    } elseif ($action === 'dispose') {
        $reason = $_POST['reason'] ?? 'expired';
        $reason_note = $reason === 'other' ? trim($_POST['reason_note'] ?? '') : null;

        if ($reason === 'other' && $reason_note === '') {
            $flash_type = 'error';
            $flash_message = "사유를 '기타'로 선택한 경우 상세 내용을 입력해주세요.";
        } else {
            $result = register_disposal($conn, [
                'store_id' => $current_store_id,
                'product_id' => (int)($_POST['product_id'] ?? 0),
                'inventory_expiration_id' => (int)($_POST['inventory_expiration_id'] ?? 0),
                'quantity' => (int)($_POST['quantity'] ?? 0),
                'reason' => $reason,
                'reason_note' => $reason_note,
                'user_id' => $user_id,
            ]);
            $flash_type = $result['success'] ? 'success' : 'error';
            $flash_message = $result['success'] ? '폐기 등록이 완료되었습니다.' : $result['error'];
        }
    }
}

$settings = get_expiry_settings($conn);

// 필터 파라미터
$status_filter = $_GET['status'] ?? '';
$registered_by_filter = (int)($_GET['registered_by'] ?? 0);

// 목록 조회
$sql = "
    SELECT ie.id, ie.expiration_date, ie.quantity, ie.registered_by, ie.registered_at,
           p.id AS product_id, p.sku, p.name_ko, p.name_en,
           u.username AS registered_by_name
    FROM inventory_expirations ie
    JOIN products p ON ie.product_id = p.id
    LEFT JOIN users u ON ie.registered_by = u.id
    WHERE ie.store_id = ?
      AND ie.quantity > 0
      AND ie.expiration_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
";
$params = [$current_store_id];
$types = "i";

if ($registered_by_filter > 0) {
    $sql .= " AND ie.registered_by = ? ";
    $params[] = $registered_by_filter;
    $types .= "i";
}
$sql .= " ORDER BY ie.expiration_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = get_expiry_status($row['expiration_date'], $settings);
    if ($status_filter === '' || $row['status'] === $status_filter) {
        $rows[] = $row;
    }
}
$stmt->close();

// 등록자 목록 (필터용)
$registrants = [];
$reg_stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username
    FROM inventory_expirations ie
    JOIN users u ON ie.registered_by = u.id
    WHERE ie.store_id = ?
    ORDER BY u.username
");
$reg_stmt->bind_param("i", $current_store_id);
$reg_stmt->execute();
$reg_result = $reg_stmt->get_result();
while ($r = $reg_result->fetch_assoc()) {
    $registrants[] = $r;
}
$reg_stmt->close();

$status_labels = [
    'expired' => '경과',
    'alert' => '알림임박',
    'warning' => '관찰중',
    'normal' => '정상',
];
$status_colors = [
    'expired' => 'background:#fee2e2;color:#991b1b;',
    'alert' => 'background:#ffedd5;color:#9a3412;',
    'warning' => 'background:#fef9c3;color:#854d0e;',
    'normal' => 'background:#f3f4f6;color:#4b5563;',
];

// 임박상품(아직 유통기한이 남은 항목) / 경과상품(이미 지난 항목) 분리 표시
$rows_imminent = array_values(array_filter($rows, fn($r) => $r['status'] !== 'expired'));
$rows_expired = array_values(array_filter($rows, fn($r) => $r['status'] === 'expired'));

function render_expiry_rows($rows, $status_labels, $status_colors) {
    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="6" class="text-center py-8 text-gray-500">해당 항목이 없습니다.</td></tr>';
        return ob_get_clean();
    }
    foreach ($rows as $row):
        $remaining = (new DateTime())->diff(new DateTime($row['expiration_date']))->format('%r%a');
        ?>
        <tr>
            <td class="px-4 py-3">
                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['name_ko']); ?></div>
                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['name_en'] ?? ''); ?></div>
                <div class="text-xs text-gray-400 font-mono"><?php echo htmlspecialchars($row['sku']); ?></div>
            </td>
            <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($row['expiration_date']); ?></td>
            <td class="px-4 py-3 text-center">
                <span class="px-2 py-1 text-xs font-semibold rounded-full" style="<?php echo $status_colors[$row['status']]; ?>">
                    <?php echo $status_labels[$row['status']]; ?> (<?php echo $remaining; ?>일)
                </span>
            </td>
            <td class="px-4 py-3 text-right text-sm text-gray-700"><?php echo number_format($row['quantity']); ?></td>
            <td class="px-4 py-3 text-sm text-gray-700">
                <div><?php echo htmlspecialchars($row['registered_by_name'] ?? '-'); ?></div>
                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($row['registered_at'] ?? '-'); ?></div>
            </td>
            <td class="px-4 py-3 text-center text-sm">
                <?php if ($row['status'] === 'expired'): ?>
                <button type="button" class="text-red-600 hover:text-red-900 font-medium mr-3"
                    onclick="openDisposeModal(<?php echo (int)$row['id']; ?>, <?php echo (int)$row['product_id']; ?>, '<?php echo htmlspecialchars($row['name_ko'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['sku'], ENT_QUOTES); ?>', '<?php echo $row['expiration_date']; ?>', <?php echo (int)$row['quantity']; ?>)">폐기등록</button>
                <?php endif; ?>
                <button type="button" class="text-primary-600 hover:text-primary-900 mr-3"
                    onclick="openEditModal(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['name_ko'], ENT_QUOTES); ?>', '<?php echo $row['expiration_date']; ?>', <?php echo (int)$row['quantity']; ?>)">수정</button>
                <form method="post" class="inline" onsubmit="return confirm('삭제하시겠습니까?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                    <button type="submit" class="text-red-600 hover:text-red-900">삭제</button>
                </form>
            </td>
        </tr>
        <?php
    endforeach;
    return ob_get_clean();
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">유통기한 점검기록</h1>
            <p class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars($current_store_name); ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <?php require __DIR__ . '/partials/expiry_nav.php'; ?>
            <button type="button" onclick="document.getElementById('settingsModal').classList.remove('hidden')" class="inline-flex items-center px-3 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                <i class="fas fa-cog mr-2"></i>설정
            </button>
        </div>
    </div>

    <?php if ($flash_message): ?>
    <div class="mb-4 rounded-md p-4 <?php echo $flash_type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'; ?>">
        <?php echo htmlspecialchars($flash_message); ?>
    </div>
    <?php endif; ?>

    <!-- 바코드 스캔 등록 -->
    <div class="mb-4 bg-white p-4 rounded-lg shadow-sm ring-1 ring-gray-200">
        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-barcode mr-1"></i>바코드 스캔 등록</label>
        <input type="text" id="barcode-input" placeholder="바코드를 스캔하세요" autofocus autocomplete="off" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        <div id="scan-error" class="hidden mt-2 text-sm text-red-600"></div>
        <div id="scan-result" class="hidden mt-3 flex flex-wrap items-end gap-3 bg-gray-50 p-3 rounded-md">
            <div>
                <div class="text-sm font-semibold text-gray-900" id="scan-product-name"></div>
                <div class="text-xs text-gray-500 font-mono" id="scan-product-sku"></div>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">유통기한</label>
                <input type="date" id="scan-expiration-date" min="1900-01-01" max="2099-12-31" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">수량</label>
                <input type="number" id="scan-quantity" min="0" class="w-24 px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <button type="button" id="scan-register-btn" class="px-4 py-2 bg-primary-600 text-white rounded-md text-sm hover:bg-primary-700">등록</button>
            <button type="button" id="scan-cancel-btn" class="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">취소</button>
        </div>
    </div>

    <!-- 필터 -->
    <form method="get" class="mb-4 flex flex-wrap items-center gap-2 bg-white p-4 rounded-lg shadow-sm ring-1 ring-gray-200">
        <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            <option value="">상태: 전체</option>
            <?php foreach ($status_labels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $status_filter === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        <select name="registered_by" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
            <option value="0">등록자: 전체</option>
            <?php foreach ($registrants as $r): ?>
                <option value="<?php echo $r['id']; ?>" <?php echo $registered_by_filter == $r['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['username']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="px-4 py-2 bg-gray-700 text-white rounded-md text-sm hover:bg-gray-800">검색</button>
    </form>

    <!-- 목록: 좌 임박상품 / 우 경과상품 -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- 임박상품 (좌) -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-200">
            <div class="px-4 py-3 border-b border-gray-200" style="background:#fffbeb;">
                <h3 class="text-sm font-semibold" style="color:#92400e;">임박상품 <span class="text-gray-500 font-normal">(<?php echo count($rows_imminent); ?>건)</span></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">상품명 / SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">유통기한</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase">잔여일수</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">수량</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">등록정보</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase">관리</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php echo render_expiry_rows($rows_imminent, $status_labels, $status_colors); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 경과상품 (우) -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-200">
            <div class="px-4 py-3 border-b border-gray-200" style="background:#fef2f2;">
                <h3 class="text-sm font-semibold" style="color:#991b1b;">경과상품 <span class="text-gray-500 font-normal">(<?php echo count($rows_expired); ?>건)</span></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">상품명 / SKU</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">유통기한</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase">잔여일수</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-700 uppercase">수량</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase">등록정보</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase">관리</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        <?php echo render_expiry_rows($rows_expired, $status_labels, $status_colors); ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 유통기한 수정 모달 (바코드 스캔 등록은 화면 상단 스캔 영역에서 즉시 처리) -->
<div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">유통기한 수정</h3>
            <button type="button" onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form method="post" id="addForm">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="form-id" value="0">
            <input type="hidden" name="product_id" id="form-product-id" value="">

            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">상품</label>
                <div id="selected-product" class="text-sm font-medium text-gray-900"></div>
            </div>

            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">유통기한</label>
                <input type="date" name="expiration_date" id="form-expiration-date" min="1900-01-01" max="2099-12-31" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">수량</label>
                <input type="number" name="quantity" id="form-quantity" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeAddModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">취소</button>
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md text-sm hover:bg-primary-700">저장</button>
            </div>
        </form>
    </div>
</div>

<!-- 폐기 등록 모달 (경과상품 전용) -->
<div id="disposeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-lg shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">폐기 등록</h3>
            <button type="button" onclick="closeDisposeModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form method="post" id="disposeForm" onsubmit="return confirm('선택한 수량만큼 폐기 처리하고 재고에서 차감합니다. 계속할까요?');">
            <input type="hidden" name="action" value="dispose">
            <input type="hidden" name="inventory_expiration_id" id="dispose-lot-id" value="">
            <input type="hidden" name="product_id" id="dispose-product-id" value="">

            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">상품</label>
                <div id="dispose-product-info" class="text-sm font-medium text-gray-900"></div>
                <div id="dispose-expiration-info" class="text-xs text-gray-500"></div>
            </div>

            <div class="mb-3 max-w-xs">
                <label class="block text-sm font-medium text-gray-700 mb-1">폐기 수량</label>
                <input type="number" name="quantity" id="dispose-quantity" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <p id="dispose-qty-hint" class="text-xs text-gray-500 mt-1"></p>
            </div>

            <div class="mb-3 max-w-xs">
                <label class="block text-sm font-medium text-gray-700 mb-1">사유</label>
                <select name="reason" id="dispose-reason" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="expired">유통기한경과</option>
                    <option value="damaged">파손</option>
                    <option value="other">기타</option>
                </select>
            </div>

            <div class="mb-4 hidden" id="dispose-reason-note-wrapper">
                <label class="block text-sm font-medium text-gray-700 mb-1">사유 상세</label>
                <input type="text" name="reason_note" id="dispose-reason-note" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeDisposeModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">취소</button>
                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700">
                    <i class="fas fa-trash mr-2"></i>폐기 등록
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 임계값 설정 모달 -->
<div id="settingsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">임계값 설정</h3>
            <button type="button" onclick="document.getElementById('settingsModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times text-xl"></i></button>
        </div>
        <form method="post" onsubmit="return validateSettings();">
            <input type="hidden" name="action" value="save_settings">
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">관찰 기준일 (일)</label>
                <input type="number" name="warning_days" id="warning_days" min="1" value="<?php echo (int)$settings['warning_days']; ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">알림(배지) 기준일 (일)</label>
                <input type="number" name="alert_days" id="alert_days" min="1" value="<?php echo (int)$settings['alert_days']; ?>" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('settingsModal').classList.add('hidden')" class="px-4 py-2 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-50">취소</button>
                <button type="submit" class="px-4 py-2 bg-primary-600 text-white rounded-md text-sm hover:bg-primary-700">저장</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
    document.getElementById('addForm').reset();
    document.getElementById('form-id').value = '0';
    document.getElementById('form-product-id').value = '';
    document.getElementById('selected-product').textContent = '';
}

function openEditModal(id, name, expDate, qty) {
    document.getElementById('form-id').value = id;
    document.getElementById('form-expiration-date').value = expDate;
    document.getElementById('form-quantity').value = qty;
    document.getElementById('selected-product').textContent = name + ' (상품 변경 불가 - 수정 시 유지)';
    document.getElementById('addModal').classList.remove('hidden');
}

function closeDisposeModal() {
    document.getElementById('disposeModal').classList.add('hidden');
    document.getElementById('disposeForm').reset();
    document.getElementById('dispose-reason-note-wrapper').classList.add('hidden');
    document.getElementById('dispose-reason-note').required = false;
}

function openDisposeModal(lotId, productId, name, sku, expDate, remainingQty) {
    document.getElementById('dispose-lot-id').value = lotId;
    document.getElementById('dispose-product-id').value = productId;
    document.getElementById('dispose-product-info').textContent = name + ' (' + sku + ')';
    document.getElementById('dispose-expiration-info').textContent = '유통기한: ' + expDate + ' / 잔여 수량: ' + remainingQty + '개';
    const qtyInput = document.getElementById('dispose-quantity');
    qtyInput.max = remainingQty;
    qtyInput.value = remainingQty;
    document.getElementById('dispose-qty-hint').textContent = '잔여 수량: ' + remainingQty + '개';
    document.getElementById('disposeModal').classList.remove('hidden');
}

document.getElementById('dispose-reason').addEventListener('change', function() {
    const wrapper = document.getElementById('dispose-reason-note-wrapper');
    const input = document.getElementById('dispose-reason-note');
    if (this.value === 'other') {
        wrapper.classList.remove('hidden');
        input.required = true;
    } else {
        wrapper.classList.add('hidden');
        input.required = false;
    }
});

function validateSettings() {
    const warning = parseInt(document.getElementById('warning_days').value, 10);
    const alertDays = parseInt(document.getElementById('alert_days').value, 10);
    if (alertDays > warning) {
        alert('알림 일수는 관찰 일수보다 클 수 없습니다.');
        return false;
    }
    return true;
}

// 바코드 스캔 즉시 등록
const barcodeInput = document.getElementById('barcode-input');
const scanResult = document.getElementById('scan-result');
const scanError = document.getElementById('scan-error');
const scanProductName = document.getElementById('scan-product-name');
const scanProductSku = document.getElementById('scan-product-sku');
const scanExpirationDate = document.getElementById('scan-expiration-date');
const scanQuantity = document.getElementById('scan-quantity');
let scannedProductId = null;

function resetScanPanel() {
    scanResult.classList.add('hidden');
    scanError.classList.add('hidden');
    scannedProductId = null;
    scanExpirationDate.value = '';
    scanQuantity.value = '';
}

barcodeInput.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = this.value.trim();
    this.value = '';
    if (!code) return;

    scanError.classList.add('hidden');
    fetch(`ajax_search_products.php?term=${encodeURIComponent(code)}`)
        .then(res => res.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                scanResult.classList.add('hidden');
                scannedProductId = null;
                scanError.textContent = '상품을 찾을 수 없습니다: ' + code;
                scanError.classList.remove('hidden');
                return;
            }
            const p = data[0];
            scannedProductId = p.id;
            scanProductName.textContent = (p.name_ko || '') + (p.name_en ? ' / ' + p.name_en : '');
            scanProductSku.textContent = p.sku || '';
            scanExpirationDate.value = '';
            scanQuantity.value = '';
            scanResult.classList.remove('hidden');
            scanExpirationDate.focus();
        })
        .catch(() => {
            scanResult.classList.add('hidden');
            scannedProductId = null;
            scanError.textContent = '조회 중 오류가 발생했습니다.';
            scanError.classList.remove('hidden');
        });
});

document.getElementById('scan-register-btn').addEventListener('click', function() {
    if (!scannedProductId) return;
    if (!scanExpirationDate.value) {
        alert('유통기한을 입력해주세요.');
        scanExpirationDate.focus();
        return;
    }
    const qty = parseInt(scanQuantity.value, 10);
    if (isNaN(qty) || qty < 0) {
        alert('수량을 올바르게 입력해주세요.');
        scanQuantity.focus();
        return;
    }
    document.getElementById('form-id').value = '0';
    document.getElementById('form-product-id').value = scannedProductId;
    document.getElementById('form-expiration-date').value = scanExpirationDate.value;
    document.getElementById('form-quantity').value = qty;
    document.getElementById('addForm').submit();
});

document.getElementById('scan-cancel-btn').addEventListener('click', function() {
    resetScanPanel();
    barcodeInput.value = '';
    barcodeInput.focus();
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
