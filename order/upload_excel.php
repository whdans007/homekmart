<?php
$page_title = '재고 업로드';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/order_helper.php';
// 재고 업로드는 발주 시스템 접근 권한자(매니저 이상) 누구나 가능 — header.php의 ord_require_manager()로 게이트됨

$conn = get_ord_db();
$stmt = $conn->prepare("SELECT v.id, v.name FROM order_vendors v JOIN order_vendor_column_maps cm ON cm.vendor_id = v.id WHERE v.is_active = 1 ORDER BY v.name");
$stmt->execute();
$vendors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$inlineError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ord_verify_csrf();
    $vendorId = (int)($_POST['vendor_id'] ?? 0);

    if (!$vendorId) {
        $inlineError = '업체를 선택해주세요.';
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $inlineError = '파일을 선택해주세요.';
    } elseif ($_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrMap = [
            UPLOAD_ERR_INI_SIZE   => '파일이 너무 큽니다 (서버 upload_max_filesize 초과).',
            UPLOAD_ERR_FORM_SIZE  => '파일이 너무 큽니다 (폼 MAX_FILE_SIZE 초과).',
            UPLOAD_ERR_PARTIAL    => '파일이 일부만 업로드되었습니다.',
            UPLOAD_ERR_CANT_WRITE => '서버에 파일을 저장할 수 없습니다.',
            UPLOAD_ERR_EXTENSION  => '서버 확장 모듈이 업로드를 차단했습니다.',
        ];
        $inlineError = $uploadErrMap[$_FILES['excel_file']['error']] ?? '파일 업로드 오류 (코드: ' . $_FILES['excel_file']['error'] . ')';
    } else {
        $origExt = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($origExt, ['xlsx', 'xls', 'csv'], true)) {
            $inlineError = '지원하지 않는 파일 형식입니다: ' . $origExt;
        } else {
            $stmt = $conn->prepare("SELECT * FROM order_vendor_column_maps WHERE vendor_id = ?");
            $stmt->bind_param('i', $vendorId);
            $stmt->execute();
            $colMap = $stmt->get_result()->fetch_object();
            $stmt->close();

            if (!$colMap) {
                $inlineError = '이 업체의 컬럼 설정이 없습니다. 먼저 컬럼을 설정해주세요.';
            } else {
                try {
                    $origName = $_FILES['excel_file']['name'];
                    $paths    = ord_get_upload_path($vendorId, $origName);
                    if (!move_uploaded_file($_FILES['excel_file']['tmp_name'], $paths['path'])) {
                        throw new Exception('파일 저장에 실패했습니다. 업로드 디렉토리 권한을 확인하세요.');
                    }

                    $items     = ord_parse_visible_rows($paths['path'], $colMap);
                    $parsedCnt = count($items);

                    // 기존 업체 자료 완전 삭제 (DB + 물리 파일)
                    // 1) 삭제 대상 물리 파일 경로 수집
                    $oldFiles = [];
                    $selOld = $conn->prepare("SELECT stored_filepath FROM order_vendor_inventories WHERE vendor_id = ?");
                    $selOld->bind_param('i', $vendorId);
                    $selOld->execute();
                    $oldRes = $selOld->get_result();
                    while ($oldRow = $oldRes->fetch_assoc()) {
                        if (!empty($oldRow['stored_filepath'])) {
                            $oldFiles[] = $oldRow['stored_filepath'];
                        }
                    }
                    $selOld->close();

                    // 2) 장바구니 항목 먼저 삭제 (FK: order_cart_items → order_vendor_inventory_items, CASCADE 없음)
                    $delCart = $conn->prepare("DELETE c FROM order_cart_items c JOIN order_vendor_inventory_items i ON c.inventory_item_id = i.id WHERE i.vendor_id = ?");
                    $delCart->bind_param('i', $vendorId);
                    $delCart->execute();
                    $delCart->close();

                    // 3) 재고 메타 삭제 (재고 아이템은 ON DELETE CASCADE로 자동 삭제)
                    $delInv = $conn->prepare("DELETE FROM order_vendor_inventories WHERE vendor_id = ?");
                    $delInv->bind_param('i', $vendorId);
                    $delInv->execute();
                    $delInv->close();

                    // 4) 기존 물리 파일 삭제 (신규 파일은 고유 파일명이라 영향 없음)
                    $webRoot = dirname(__DIR__);
                    foreach ($oldFiles as $rel) {
                        $absOld = $webRoot . '/' . ltrim($rel, '/');
                        if (is_file($absOld)) {
                            @unlink($absOld);
                        }
                    }

                    $relPath = 'uploads/order_inventories/' . $vendorId . '/' . $paths['filename'];
                    $stmt = $conn->prepare("INSERT INTO order_vendor_inventories (vendor_id, original_filename, stored_filepath, upload_date, uploaded_by, row_count) VALUES (?,?,?,CURDATE(),?,?)");
                    $stmt->bind_param('issii', $vendorId, $origName, $relPath, ord_current_user_id(), $parsedCnt);
                    $stmt->execute();
                    $invId = $conn->insert_id;
                    $stmt->close();

                    $insStmt = $conn->prepare("INSERT INTO order_vendor_inventory_items (inventory_id, vendor_id, `row_number`, product_name, product_name_en, unit_price, unit_price_pcs, order_unit, unit_qty, brand, remark, expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    foreach ($items as $item) {
                        $insStmt->bind_param('iiissddsisss', $invId, $vendorId, $item['row_number'], $item['product_name'], $item['product_name_en'], $item['unit_price'], $item['unit_price_pcs'], $item['order_unit'], $item['unit_qty'], $item['brand'], $item['remark'], $item['expiry_date']);
                        $insStmt->execute();
                    }
                    $insStmt->close();

                    $conn->close();
                    ord_set_flash('success', $parsedCnt . '개 상품이 재고에 등록되었습니다.');
                    header('Location: ' . ORD_BASE . '/upload_inventory.php');
                    exit;
                } catch (Throwable $e) {
                    $inlineError = '오류: ' . $e->getMessage();
                }
            }
        }
    }
}

$conn->close();
?>

<div class="flex items-center justify-between mb-4">
    <h2 class="text-xl font-bold text-gray-800">
        <i class="fas fa-upload mr-2 text-indigo-600"></i>재고 업로드
    </h2>
    <a href="<?php echo ORD_BASE; ?>/upload_inventory.php" class="text-sm text-gray-500 hover:text-gray-700">
        <i class="fas fa-arrow-left mr-1"></i>목록으로
    </a>
</div>

<?php if ($inlineError): ?>
<div class="mb-4 px-4 py-3 rounded-lg border bg-red-50 border-red-200 text-red-800 text-sm flex items-center">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($inlineError); ?>
</div>
<?php endif; ?>

<div class="max-w-xl bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <form method="post" enctype="multipart/form-data" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">업체 선택 <span class="text-red-500">*</span></label>
            <input type="hidden" name="vendor_id" id="vendor_id_input" value="" required>
            <?php if (empty($vendors)): ?>
            <p class="text-xs text-yellow-600">
                <i class="fas fa-exclamation-triangle mr-1"></i>컬럼 설정이 완료된 업체가 없습니다.
                <a href="<?php echo ORD_BASE; ?>/vendors.php" class="underline">업체 관리</a>에서 먼저 설정해주세요.
            </p>
            <?php else: ?>
            <div class="flex flex-wrap gap-2" id="vendor_btn_group">
                <?php foreach ($vendors as $v): ?>
                <button type="button"
                        data-id="<?php echo $v['id']; ?>"
                        onclick="selectVendorBtn(this)"
                        class="vendor-btn px-4 py-2 rounded-lg border text-sm font-medium transition-colors
                               border-gray-300 bg-white text-gray-600 hover:border-indigo-400 hover:text-indigo-600">
                    <?php echo htmlspecialchars($v['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <p id="vendor_required_msg" class="hidden mt-1 text-xs text-red-500">업체를 선택해주세요.</p>
            <?php endif; ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">엑셀 파일 <span class="text-red-500">*</span></label>
            <input type="file" name="excel_file" required accept=".xlsx,.xls,.csv"
                   class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:border file:border-gray-300 file:rounded-lg file:bg-gray-50 file:text-gray-700 file:hover:bg-gray-100">
            <p class="mt-1 text-xs text-gray-400">지원 형식: .xlsx, .xls, .csv</p>
        </div>

        <button type="submit" onclick="return checkVendor()"
                class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
            <i class="fas fa-upload mr-2"></i>업로드
        </button>
    </form>
</div>

<script>
function selectVendorBtn(el) {
    document.querySelectorAll('.vendor-btn').forEach(b => {
        b.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600');
        b.classList.add('bg-white', 'text-gray-600', 'border-gray-300');
    });
    el.classList.remove('bg-white', 'text-gray-600', 'border-gray-300');
    el.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600');
    document.getElementById('vendor_id_input').value = el.dataset.id;
    document.getElementById('vendor_required_msg').classList.add('hidden');
}
function checkVendor() {
    if (!document.getElementById('vendor_id_input').value) {
        document.getElementById('vendor_required_msg').classList.remove('hidden');
        return false;
    }
    return true;
}
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
