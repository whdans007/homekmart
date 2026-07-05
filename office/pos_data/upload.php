<?php
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '120');

$page_title      = 'Upload POS Sales Data';
$css_base        = '../../admin/';
$office_nav_base = '../';
require_once __DIR__ . '/../partials/header.php';

$store_id = get_office_store_id();

$expected_headers = [
    'DATE','TIME','STORE ID','SI NO.','ITEMCODE','ITEMNAME',
    'SUPPLIER','DEPARTMENT','BOX','PCS','UNIT COST','TOTAL COST',
    'SELLING PRICE','DISCOUNT','TOTAL SALES','GROSS PROFIT',
    'SC DISCOUNT','PWD DISCOUNT','LESS VAT','NET SALES','CASHIER','PAYMENT FORM'
];

$error   = '';
$success = '';
$preview = [];
$total   = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    if ($_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $error = '파일 업로드 오류가 발생했습니다. (code: ' . $_FILES['excel_file']['error'] . ')';
    } else {
        // PhpSpreadsheet는 POST 시에만 로드
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($autoload)) {
            $error = 'PhpSpreadsheet를 찾을 수 없습니다. (' . $autoload . ')';
        } else {
            require_once $autoload;

            $tmp      = $_FILES['excel_file']['tmp_name'];
            $filename = basename($_FILES['excel_file']['name']);

            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                $sheet       = $spreadsheet->getActiveSheet();

                $cols = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V'];

                // Row 2 헤더 읽기 — getValue() (이 프로젝트의 스텁 버전에서 유일하게 지원)
                $actual_headers = [];
                foreach ($cols as $col) {
                    $actual_headers[] = trim((string)$sheet->getCell($col . '2')->getValue());
                }

                if ($actual_headers !== $expected_headers) {
                    $diff = [];
                    foreach ($expected_headers as $i => $exp) {
                        if (($actual_headers[$i] ?? '') !== $exp) {
                            $diff[] = $cols[$i] . "열: [{$exp}] → [" . ($actual_headers[$i] ?? '') . "]";
                        }
                    }
                    $error = '헤더가 일치하지 않습니다:<br>' . implode('<br>', $diff);
                } else {
                    // Row 3+ 파싱
                    $rows_data  = [];
                    $highestRow = $sheet->getHighestRow();
                    for ($rowNum = 3; $rowNum <= $highestRow; $rowNum++) {
                        $vals = [];
                        foreach ($cols as $col) {
                            $vals[] = $sheet->getCell($col . $rowNum)->getValue();
                        }
                        if (array_filter($vals, fn($v) => $v !== null && $v !== '') === []) continue;
                        $rows_data[] = $vals;
                    }
                    $total = count($rows_data);

                    if ($total === 0) {
                        $error = '데이터 행이 없습니다.';
                    } else {
                        $conn = get_db_connection();
                        $by   = (int)($_SESSION['user_id'] ?? 0) ?: null;

                        $ins = $conn->prepare(
                            "INSERT INTO pos_sales_uploads (store_id, file_name, row_count, uploaded_by)
                             VALUES (?,?,?,?)"
                        );
                        $ins->bind_param('isii', $store_id, $filename, $total, $by);
                        $ins->execute();
                        $upload_id = (int)$conn->insert_id;
                        $ins->close();

                        $dstmt = $conn->prepare(
                            "INSERT INTO pos_sales_data
                             (upload_id, row_no, sale_date, sale_time, pos_store_id, si_no,
                              item_code, item_name, supplier, department,
                              box, pcs, unit_cost, total_cost, selling_price, discount,
                              total_sales, gross_profit, sc_discount, pwd_discount, less_vat, net_sales,
                              cashier, payment_form)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                        );

                        $conn->autocommit(false);
                        $row_no = 0;
                        foreach ($rows_data as $v) {
                            $row_no++;
                            $str = fn($x) => ($x === null || $x === '') ? null : (string)$x;
                            $dec = fn($x) => ($x === null || $x === '') ? null : (float)str_replace(',', '', (string)$x);
                            $s0=$str($v[0]);  $s1=$str($v[1]);  $s2=$str($v[2]);  $s3=$str($v[3]);
                            $s4=$str($v[4]);  $s5=$str($v[5]);  $s6=$str($v[6]);  $s7=$str($v[7]);
                            $d8=$dec($v[8]);  $d9=$dec($v[9]);  $d10=$dec($v[10]); $d11=$dec($v[11]);
                            $d12=$dec($v[12]); $d13=$dec($v[13]); $d14=$dec($v[14]); $d15=$dec($v[15]);
                            $d16=$dec($v[16]); $d17=$dec($v[17]); $d18=$dec($v[18]); $d19=$dec($v[19]);
                            $s20=$str($v[20] ?? null); $s21=$str($v[21] ?? null);
                            $dstmt->bind_param('iissssssssddddddddddddss',
                                $upload_id, $row_no,
                                $s0,$s1,$s2,$s3,$s4,$s5,$s6,$s7,
                                $d8,$d9,$d10,$d11,$d12,$d13,$d14,$d15,$d16,$d17,$d18,$d19,
                                $s20,$s21
                            );
                            $dstmt->execute();
                        }
                        $conn->commit();
                        $conn->autocommit(true);
                        $dstmt->close();
                        $conn->close();

                        $success = "{$filename} 업로드 완료 — {$total}건 저장됐습니다.";
                        $preview = array_slice($rows_data, 0, 5);
                    }
                }
            } catch (\Exception $e) {
                $error = '오류: ' . htmlspecialchars($e->getMessage());
                if (isset($conn)) {
                    try { $conn->rollback(); } catch (\Exception $e2) {}
                    $conn->autocommit(true);
                    $conn->close();
                }
            }
        }
    }
}
?>

<div class="max-w-xl mx-auto">
  <div class="flex items-center gap-3 mb-6">
    <a href="index.php" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-arrow-left"></i></a>
    <h2 class="text-xl font-bold text-gray-800">
      <i class="fa-solid fa-upload mr-2 text-blue-600"></i>Upload POS Sales Data
    </h2>
  </div>

  <?php if ($error): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl p-4 mb-4 text-sm">
    <i class="fa-solid fa-circle-exclamation mr-1"></i><?php echo $error; ?>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div class="bg-green-50 border border-green-200 text-green-700 rounded-xl p-4 mb-4 text-sm">
    <i class="fa-solid fa-circle-check mr-1"></i><?php echo htmlspecialchars($success); ?>
    <a href="index.php" class="ml-2 underline font-medium">목록으로</a>
  </div>
  <?php endif; ?>

  <form method="POST" enctype="multipart/form-data"
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">
        엑셀 파일 (.xlsx) <span class="text-red-500">*</span>
      </label>
      <input type="file" name="excel_file" accept=".xlsx"
             class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-600 file:text-white hover:file:bg-blue-700 cursor-pointer"
             required>
      <p class="text-xs text-gray-400 mt-1.5">Row 2: 헤더 / Row 3~: 데이터 형식의 .xlsx 파일</p>
    </div>

    <button type="submit"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2.5 rounded-lg text-sm font-medium">
      <i class="fa-solid fa-upload mr-1"></i>업로드 및 저장
    </button>
  </form>

  <?php if (!empty($preview)): ?>
  <div class="mt-6">
    <h3 class="text-sm font-semibold text-gray-700 mb-2">미리보기 (처음 5행)</h3>
    <div class="overflow-x-auto bg-white rounded-xl shadow-sm border border-gray-100">
      <table class="min-w-full text-xs divide-y divide-gray-100">
        <thead class="bg-gray-50">
          <tr>
            <?php foreach ($expected_headers as $h): ?>
            <th class="px-2 py-2 text-left text-gray-500 font-semibold whitespace-nowrap"><?php echo htmlspecialchars($h); ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($preview as $row): ?>
          <tr>
            <?php foreach ($row as $cell): ?>
            <td class="px-2 py-1.5 text-gray-700 whitespace-nowrap"><?php echo htmlspecialchars((string)($cell ?? '')); ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400 mt-1">전체 <?php echo number_format($total); ?>건 중 5건 표시</p>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
