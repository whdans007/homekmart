<?php
// Design Ref: §7.3 — 컬럼 매핑 설정 UI
$page_title = '컬럼 설정';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/order_helper.php';
ord_require_admin();

$vendorId = (int)($_GET['vendor_id'] ?? 0);
if (!$vendorId) { header('Location: ' . ORD_BASE . '/vendors.php'); exit; }

$conn = get_ord_db();
$stmt = $conn->prepare("SELECT * FROM order_vendors WHERE id = ?");
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$vendor) { ord_set_flash('error', '업체를 찾을 수 없습니다.'); header('Location: ' . ORD_BASE . '/vendors.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM order_vendor_column_maps WHERE vendor_id = ?");
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$colMap = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 최신 재고 파일로 헤더 미리보기
$stmt = $conn->prepare("SELECT stored_filepath FROM order_vendor_inventories WHERE vendor_id = ? AND is_current = 1 ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param('i', $vendorId);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

$headerPreview = [];
if ($inv && file_exists(dirname(__DIR__) . '/' . $inv['stored_filepath'])) {
    try {
        $sheetIndex = (int)($_POST['sheet_index'] ?? $colMap['sheet_index'] ?? 0);
        $headerRow  = (int)($_POST['header_row']  ?? $colMap['header_row']  ?? 1);
        $headerPreview = ord_get_header_preview(dirname(__DIR__) . '/' . $inv['stored_filepath'], $sheetIndex, $headerRow);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    ord_verify_csrf();
    $data = [
        'vendor_id'           => $vendorId,
        'sheet_index'         => (int)($_POST['sheet_index'] ?? 0),
        'sheet_name'          => trim($_POST['sheet_name'] ?? ''),
        'header_row'          => (int)($_POST['header_row'] ?? 1),
        'product_name_col'    => strtoupper(trim($_POST['product_name_col'] ?? '')),
        'product_name_en_col' => strtoupper(trim($_POST['product_name_en_col'] ?? '')) ?: null,
        'quantity_col'        => strtoupper(trim($_POST['quantity_col'] ?? '')),
        'unit_price_col'      => strtoupper(trim($_POST['unit_price_col'] ?? '')) ?: null,
        'unit_price_pcs_col'  => strtoupper(trim($_POST['unit_price_pcs_col'] ?? '')) ?: null,
        'order_unit_col'      => strtoupper(trim($_POST['order_unit_col'] ?? '')) ?: null,
        'unit_qty_col'        => strtoupper(trim($_POST['unit_qty_col'] ?? '')) ?: null,
        'brand_col'           => strtoupper(trim($_POST['brand_col'] ?? '')) ?: null,
        'remark_col'          => strtoupper(trim($_POST['remark_col'] ?? '')) ?: null,
        'expiry_col'          => strtoupper(trim($_POST['expiry_col'] ?? '')) ?: null,
        'notes'               => trim($_POST['notes'] ?? ''),
    ];

    if (!$data['product_name_col'] || !$data['quantity_col']) {
        ord_set_flash('error', '상품명(한글) 컬럼과 수량 컬럼은 필수입니다.');
    } else {
        try {
            if ($colMap) {
                $stmt = $conn->prepare("UPDATE order_vendor_column_maps SET sheet_index=?,sheet_name=?,header_row=?,product_name_col=?,product_name_en_col=?,quantity_col=?,unit_price_col=?,unit_price_pcs_col=?,order_unit_col=?,unit_qty_col=?,brand_col=?,remark_col=?,expiry_col=?,notes=? WHERE vendor_id=?");
                $stmt->bind_param('isisssssssssssi', $data['sheet_index'], $data['sheet_name'], $data['header_row'], $data['product_name_col'], $data['product_name_en_col'], $data['quantity_col'], $data['unit_price_col'], $data['unit_price_pcs_col'], $data['order_unit_col'], $data['unit_qty_col'], $data['brand_col'], $data['remark_col'], $data['expiry_col'], $data['notes'], $vendorId);
            } else {
                $stmt = $conn->prepare("INSERT INTO order_vendor_column_maps (vendor_id,sheet_index,sheet_name,header_row,product_name_col,product_name_en_col,quantity_col,unit_price_col,unit_price_pcs_col,order_unit_col,unit_qty_col,brand_col,remark_col,expiry_col,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('iisisssssssssss', $vendorId, $data['sheet_index'], $data['sheet_name'], $data['header_row'], $data['product_name_col'], $data['product_name_en_col'], $data['quantity_col'], $data['unit_price_col'], $data['unit_price_pcs_col'], $data['order_unit_col'], $data['unit_qty_col'], $data['brand_col'], $data['remark_col'], $data['expiry_col'], $data['notes']);
            }
            $stmt->execute();
            $stmt->close();
            $conn->close();
            ord_set_flash('success', '컬럼 설정이 저장되었습니다.');
            header('Location: ' . ORD_BASE . '/vendors.php');
            exit;
        } catch (Exception $e) {
            $errMsg = $e->getMessage();
            if (str_contains($errMsg, 'product_name_en_col')) {
                ord_set_flash('error', 'DB 컬럼이 없습니다. 먼저 /order/run_add_name_en_col.php 를 실행해주세요.');
            } else {
                ord_set_flash('error', '저장 실패: ' . $errMsg);
            }
        }
    }
}
$conn->close();

$val = fn($key, $default = '') => htmlspecialchars($_POST[$key] ?? $colMap[$key] ?? $default);
$colLetters = array_merge([''], range('A', 'Z'), ['AA','AB','AC','AD','AE','AF','AG','AH','AI','AJ']);
?>

<div class="max-w-2xl">
    <div class="flex items-center mb-6">
        <a href="<?php echo ORD_BASE; ?>/vendors.php" class="text-indigo-600 hover:text-indigo-800 mr-3"><i class="fas fa-arrow-left"></i></a>
        <h1 class="text-xl font-bold text-gray-800"><i class="fas fa-columns mr-2 text-indigo-600"></i><?php echo htmlspecialchars($vendor['name']); ?> — 컬럼 설정</h1>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <form method="post" class="space-y-5">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(ord_csrf_token()); ?>">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">시트 번호 (0부터 시작)</label>
                    <input type="number" name="sheet_index" min="0" value="<?php echo $val('sheet_index', '0'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">헤더 행 번호</label>
                    <input type="number" name="header_row" min="1" value="<?php echo $val('header_row', '1'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">시트 이름 <span class="text-gray-400 font-normal">(선택 — 시트 번호가 맞지 않을 때 이름으로 찾음)</span></label>
                    <input type="text" name="sheet_name" value="<?php echo $val('sheet_name'); ?>" placeholder="예: Sheet1, 발주리스트"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
            </div>

            <?php if ($headerPreview): ?>
            <div class="bg-gray-50 rounded-lg p-3 text-sm">
                <p class="font-medium text-gray-600 mb-2"><i class="fas fa-table mr-1"></i>헤더 미리보기</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($headerPreview as $col => $label): if ($label === '') continue; ?>
                    <span class="inline-flex items-center px-2 py-1 bg-white border border-gray-200 rounded text-xs">
                        <strong class="text-indigo-600 mr-1"><?php echo $col; ?>:</strong>
                        <?php echo htmlspecialchars($label); ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php elseif (!$inv): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm text-yellow-700">
                <i class="fas fa-info-circle mr-2"></i>재고 파일을 먼저 업로드하면 헤더 미리보기가 표시됩니다.
            </div>
            <?php endif; ?>

            <?php
            $colFields = [
                ['name' => 'product_name_col',    'label' => '상품명 (한글) 컬럼', 'required' => true],
                ['name' => 'product_name_en_col', 'label' => '상품명 (영어) 컬럼', 'required' => false],
                ['name' => 'quantity_col',        'label' => '수량(발주) 컬럼',    'required' => true],
                ['name' => 'unit_price_col',      'label' => 'BOX PRICE 컬럼',    'required' => false],
                ['name' => 'unit_price_pcs_col',  'label' => 'PCS PRICE 컬럼',    'required' => false],
                ['name' => 'order_unit_col',      'label' => '발주단위 컬럼',      'required' => false],
                ['name' => 'unit_qty_col',        'label' => '입수량 컬럼',        'required' => false],
                ['name' => 'brand_col',           'label' => '브랜드 컬럼',        'required' => false],
                ['name' => 'remark_col',          'label' => 'REMARK 컬럼',       'required' => false],
                ['name' => 'expiry_col',          'label' => 'EXPIRY 컬럼',       'required' => false],
            ];
            ?>
            <div class="grid grid-cols-2 gap-4">
                <?php foreach ($colFields as $f): ?>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <?php echo $f['label']; ?>
                        <?php if ($f['required']): ?><span class="text-red-500"> *</span><?php endif; ?>
                    </label>
                    <select name="<?php echo $f['name']; ?>" <?php echo $f['required'] ? 'required' : ''; ?>
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
                        <?php foreach ($colLetters as $c): ?>
                        <option value="<?php echo $c; ?>" <?php echo ($val($f['name']) === $c) ? 'selected' : ''; ?>>
                            <?php echo $c === '' ? '— 선택 안 함 —' : $c . (isset($headerPreview[$c]) && $headerPreview[$c] !== '' ? ' (' . htmlspecialchars($headerPreview[$c]) . ')' : ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">메모</label>
                <input type="text" name="notes" value="<?php echo $val('notes'); ?>" placeholder="선택 사항"
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="flex space-x-3 pt-2">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">저장</button>
                <a href="<?php echo ORD_BASE; ?>/vendors.php" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">취소</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
