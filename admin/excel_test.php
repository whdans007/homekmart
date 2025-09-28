<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/excel_test_error.log');

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/partials/header.php';

if (!is_logged_in() || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-100 border border-red-200 text-red-800 p-4 rounded'>접근이 거부되었습니다.</div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$message = '';
$save_result = '';
$excel_preview = [];

function read_excel_rows($path, $ext) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if ($ext === 'xlsx') {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        if (method_exists($reader, 'setReadDataOnly')) $reader->setReadDataOnly(true);
    } elseif ($ext === 'csv') {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
    } else {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    }
    $sheet = $reader->load($path)->getActiveSheet();
    $rows = [];
    // 이 레포의 경량 PhpSpreadsheet는 getRowIterator가 없으므로 좌표로 직접 읽습니다.
    // 헤더는 1행이라고 가정하고, 2행부터 읽습니다. B=SKU, C=상품명, G=원가, H=판매가
    $highest = (int)$sheet->getHighestRow();
    for ($r = 2; $r <= $highest; $r++) {
        $sku  = trim((string)$sheet->getCell('B' . $r)->getValue());
        $name = trim((string)$sheet->getCell('C' . $r)->getValue());
        $cost = (float)$sheet->getCell('G' . $r)->getValue();
        $sell = (float)$sheet->getCell('H' . $r)->getValue();
        if ($cost < 0) $cost = 0; if ($sell < 0) $sell = 0;
        if ($sku !== '' && $name !== '') {
            $rows[] = [$sku, $name, $cost, $sell];
        }
    }
    return $rows;
}

// 저장 처리 (세션 저장 데이터 사용)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_data'])) {
    $rows = isset($_SESSION['excel_upload_rows']) && is_array($_SESSION['excel_upload_rows'])
        ? $_SESSION['excel_upload_rows']
        : [];
    $store_id = (int)($_POST['target_store_id'] ?? ($_SESSION['store_id'] ?? 1));
    $new_count = 0; $existing_count = 0; $errors = 0; $logs = [];

    try {
        $conn = get_db_connection();
        $conn->autocommit(false);
        // 기본 카테고리 폴백
        $default_category_id = 1;
        try {
            $stmt = $conn->prepare("SELECT id FROM categories ORDER BY id LIMIT 1");
            if ($stmt) { $stmt->execute(); $r=$stmt->get_result(); if ($r && $r->num_rows > 0) $default_category_id = (int)$r->fetch_assoc()['id']; $stmt->close(); }
        } catch (Throwable $e) { error_log('default_category error: '.$e->getMessage()); }

        foreach ($rows as $i => $row) {
            [$sku,$name,$cost,$sell] = $row;
            if (!$sku || !$name) { $errors++; $logs[] = ($i+1)."행: 필수값 누락"; continue; }

            // 상품 존재 확인
            $check = $conn->prepare("SELECT id FROM products WHERE sku = ?");
            $check->bind_param('s', $sku);
            $check->execute(); $res = $check->get_result();
            $product_id = null;
            if ($res && $res->num_rows > 0) {
                $product_id = (int)$res->fetch_assoc()['id'];
                $existing_count++;
            } else {
                // 신규 상품 생성 (가격은 products에 저장하지 않음)
                $ins = $conn->prepare("INSERT INTO products (sku, name_ko, name_en, category_id, is_active, last_modified_by_user_id, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())");
                $nm = $name; $uid = (int)($_SESSION['user_id'] ?? 0);
                $ins->bind_param('sssii', $sku, $nm, $nm, $default_category_id, $uid);
                $ins->execute();
                $product_id = $ins->insert_id; $ins->close();
                $new_count++;
            }
            $check->close();

            if ($product_id) {
                // 지점별 가격 upsert
                $up = $conn->prepare("INSERT INTO inventory (product_id, store_id, selling_price, quantity, cost_price) VALUES (?, ?, ?, 0, ?) ON DUPLICATE KEY UPDATE selling_price = VALUES(selling_price), cost_price = VALUES(cost_price)");
                $up->bind_param('iidd', $product_id, $store_id, $sell, $cost);
                $up->execute(); $up->close();
            }
        }

        $conn->commit();
        $save_result = "업로드 완료\n- 신규 상품: {$new_count}개\n- 기존 상품(지점가격 업데이트): {$existing_count}개\n- 오류: {$errors}개";
        if ($logs) $save_result .= "\n\n처리 로그:\n".implode("\n", $logs);
        $conn->close();
    } catch (Throwable $e) {
        $save_result = '저장 중 오류: '.$e->getMessage();
    }
}

// 업로드 처리(미리보기)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $f = $_FILES['excel_file'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        try { $excel_preview = read_excel_rows($f['tmp_name'], $ext); }
        catch (Throwable $e) { $message = '파일 읽기 오류: '.$e->getMessage(); }
    } else {
        $message = '파일 업로드 실패';
    }
    // 세션에 원본 데이터 저장하여 화면에 대용량 JSON을 출력하지 않음
    if (!empty($excel_preview)) {
        $_SESSION['excel_upload_rows'] = $excel_preview;
        $_SESSION['excel_upload_time'] = time();
    }
}
?>

<div class="max-w-4xl mx-auto p-4 space-y-6">
    <h1 class="text-2xl font-bold">엑셀 업로드(지점별 가격 갱신)</h1>

    <?php if (!empty($message)): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-3 rounded"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if (!empty($save_result)): ?>
        <pre class="bg-green-50 border border-green-200 text-green-800 p-3 rounded whitespace-pre-wrap"><?php echo htmlspecialchars($save_result); ?></pre>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="space-y-3">
        <div>
            <label class="block text-sm font-medium">엑셀 파일(.xlsx, .csv)</label>
            <input type="file" name="excel_file" accept=".xlsx,.csv" required class="mt-1" />
        </div>
        <button class="px-4 py-2 bg-blue-600 text-white rounded">업로드 및 미리보기</button>
    </form>

    <?php if (!empty($excel_preview)): ?>
        <?php
        // 미리보기 요약(전체 행수/기존/신규 카운트) 계산
        $total_preview_rows = count($excel_preview);
        $existing_preview_count = 0;
        $new_preview_count = 0;
        try {
            $skus = array_values(array_unique(array_map(function($r){ return (string)$r[0]; }, $excel_preview)));
            if (!empty($skus)) {
                $db = get_db_connection();
                $existing_set = [];
                // 대량 IN 조회를 위해 500개 단위로 분할
                $chunks = array_chunk($skus, 500);
                foreach ($chunks as $chunk) {
                    $escaped = array_map(function($s) use ($db){ return "'".$db->real_escape_string($s)."'"; }, $chunk);
                    $sql = "SELECT sku FROM products WHERE sku IN (".implode(',', $escaped).")";
                    if ($res = $db->query($sql)) {
                        while ($row = $res->fetch_assoc()) { $existing_set[$row['sku']] = true; }
                        $res->close();
                    }
                }
                $db->close();
                $existing_preview_count = count($existing_set);
                $new_preview_count = max(0, count($skus) - $existing_preview_count);
            }
        } catch (Throwable $e) {
            // 요약 계산 실패는 치명적이지 않으므로 무시
        }
        ?>
        <div class="bg-blue-50 border border-blue-200 p-3 rounded text-sm">
            <div>총 업로드 행: <strong><?php echo number_format($total_preview_rows); ?></strong> (화면에는 상위 10건만 표시)</div>
            <div>기존 상품(지점가격만 갱신): <strong><?php echo number_format($existing_preview_count); ?></strong></div>
            <div>신규 상품(상품 생성 + 지점가격 저장): <strong><?php echo number_format($new_preview_count); ?></strong></div>
        </div>
        <hr />
        <h2 class="text-xl font-semibold">미리보기(상위 10행)</h2>
        <table class="w-full text-sm border">
            <thead class="bg-gray-50">
                <tr>
                    <th class="border p-1">#</th>
                    <th class="border p-1">SKU</th>
                    <th class="border p-1">상품명(영문)</th>
                    <th class="border p-1">원가</th>
                    <th class="border p-1">판매가</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($excel_preview, 0, 10) as $i => $r): ?>
                <tr>
                    <td class="border p-1"><?php echo $i+1; ?></td>
                    <td class="border p-1"><?php echo htmlspecialchars($r[0]); ?></td>
                    <td class="border p-1"><?php echo htmlspecialchars($r[1]); ?></td>
                    <td class="border p-1 text-right"><?php echo number_format($r[2],2); ?></td>
                    <td class="border p-1 text-right"><?php echo number_format($r[3],2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" class="mt-4 space-y-2" onsubmit="return confirm('해당 지점의 가격을 갱신합니다. 진행할까요?');">
            <input type="hidden" name="save_data" value="1" />
            <label class="block text-sm font-medium">대상 지점</label>
            <select name="target_store_id" class="border p-1">
                <?php
                $cur_id = $_SESSION['store_id'] ?? 1; $conn2 = get_db_connection(); $rs = $conn2->query("SELECT id, name FROM stores ORDER BY name");
                while ($row = $rs->fetch_assoc()): $sel = ($row['id'] == $cur_id) ? 'selected' : '';?>
                    <option value="<?php echo (int)$row['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($row['name']); ?></option>
                <?php endwhile; $conn2->close(); ?>
            </select>
            <div>
                <button class="px-4 py-2 bg-green-600 text-white rounded">저장</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
