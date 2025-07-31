<?php
// 에러 출력 억제하고 로그로만 기록
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 모든 출력을 버퍼링
ob_start();

// JSON 응답을 위한 헤더 설정
header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Config file error: ' . $e->getMessage()]);
    exit;
}

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 접근 권한 확인 (임시로 완화)
if (!function_exists('is_logged_in')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Session helper function not found']);
    exit;
}

if (!is_logged_in()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if (!isset($_SESSION['role']) || !($_SESSION['role'] === 'super_admin')) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'super_admin 권한이 필요합니다. 현재 권한: ' . ($_SESSION['role'] ?? 'none')]);
    exit;
}

// POST 요청이 아닌 경우
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

// 파일 업로드 확인
if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '파일 업로드에 실패했습니다.']);
    exit;
}

$uploadedFile = $_FILES['excel_file'];
$fileName = $uploadedFile['name'];
$fileTmpName = $uploadedFile['tmp_name'];

// 파일 확장자 확인
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($fileExtension, ['xlsx', 'xls'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Excel 파일(.xlsx, .xls)만 업로드할 수 있습니다.']);
    exit;
}

try {
    // PhpSpreadsheet 라이브러리가 있는지 확인
    $has_phpspreadsheet = false;
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $has_phpspreadsheet = true;
        }
    }

    // 디버깅 정보 추가
    error_log("PhpSpreadsheet available: " . ($has_phpspreadsheet ? 'Yes' : 'No'));
    error_log("File path: " . $fileTmpName);
    error_log("File exists: " . (file_exists($fileTmpName) ? 'Yes' : 'No'));
    error_log("File size: " . filesize($fileTmpName));
    error_log("PHP Version: " . PHP_VERSION);

    if (!$has_phpspreadsheet) {
        // Windows에서 COM 객체 사용해서 Excel 파일 읽기
        if (class_exists('COM') && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            try {
                // COM 객체 초기화 시도
                $excel = new COM("Excel.Application");
                if (!$excel) {
                    throw new Exception("Excel COM 객체를 생성할 수 없습니다.");
                }
                
                $excel->Visible = false;
                $excel->DisplayAlerts = false;
                
                // 파일 경로를 절대 경로로 변환
                $absolutePath = realpath($fileTmpName);
                if (!$absolutePath) {
                    throw new Exception("파일 경로를 확인할 수 없습니다: " . $fileTmpName);
                }
                
                $workbook = $excel->Workbooks->Open($absolutePath);
                if (!$workbook) {
                    throw new Exception("Excel 파일을 열 수 없습니다.");
                }
                
                $worksheet = $workbook->Worksheets(1);
                if (!$worksheet) {
                    throw new Exception("워크시트에 접근할 수 없습니다.");
                }
                
                // 헤더 읽기 (2행, B열부터 I열까지)
                $headers = [];
                for ($col = 2; $col <= 9; $col++) { // B열(2)부터 I열(9)까지
                    $cellValue = $worksheet->Cells(2, $col)->Value;
                    $headers[] = $cellValue ? trim($cellValue) : '';
                }
                
                // 데이터 읽기 (3행부터 10행까지, B열부터 I열까지)
                $rows = [];
                for ($row = 3; $row <= 10; $row++) {
                    $rowData = [];
                    $hasData = false;
                    for ($col = 2; $col <= 9; $col++) {
                        $cellValue = $worksheet->Cells($row, $col)->Value;
                        $value = $cellValue ? trim($cellValue) : '';
                        $rowData[] = $value;
                        if (!empty($value)) {
                            $hasData = true;
                        }
                    }
                    // 빈 행이 아닌 경우에만 추가
                    if ($hasData) {
                        $rows[] = $rowData;
                    }
                }
                
                $workbook->Close();
                $excel->Quit();
                unset($excel);
                
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'fileName' => $fileName,
                    'headers' => $headers,
                    'data' => $rows,
                    'method' => 'COM Excel Object',
                    'note' => '실제 Excel 파일의 데이터입니다. (2행: 헤더, 3~10행: 데이터, B~I열)'
                ]);
                
            } catch (Exception $e) {
                // COM 실패 시 기본 샘플 데이터
                $sampleHeaders = ['바코드', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'];
                $sampleData = [
                    ['COM 오류로 인한 샘플', '샘플 상품 1', '5000', '7000', '식품', '브랜드A', '100', 'COM 실패'],
                    ['8801234567891', '샘플 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '샘플 데이터'],
                    ['8801234567892', '샘플 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', '테스트 데이터']
                ];
                
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'fileName' => $fileName,
                    'headers' => $sampleHeaders,
                    'data' => $sampleData,
                    'method' => 'COM Error - Sample Data',
                    'note' => 'COM 객체 오류: ' . $e->getMessage() . ' - 샘플 데이터를 표시합니다.'
                ]);
            }
        } else {
            // COM도 사용할 수 없는 경우
            $sampleHeaders = ['바코드', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'];
            $sampleData = [
                ['시스템 제한으로 샘플', '샘플 상품 1', '5000', '7000', '식품', '브랜드A', '100', '실제 파일 읽기 불가'],
                ['8801234567891', '샘플 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '라이브러리 필요'],
                ['8801234567892', '샘플 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', 'PhpSpreadsheet 권장']
            ];
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'fileName' => $fileName,
                'headers' => $sampleHeaders,
                'data' => $sampleData,
                'method' => 'System Limitation',
                'note' => 'Excel 파일 처리를 위한 라이브러리(PhpSpreadsheet)나 COM 객체를 사용할 수 없습니다. 실제 파일 내용 대신 샘플 데이터를 표시합니다.'
            ]);
        }
    } else {
        // PhpSpreadsheet 사용
        use PhpOffice\PhpSpreadsheet\IOFactory;
        
        $spreadsheet = IOFactory::load($fileTmpName);
        $sheet = $spreadsheet->getActiveSheet();
        
        // 헤더 (2행, B열부터 I열까지)
        $headers = [];
        for ($col = 'B'; $col <= 'I'; $col++) {
            $cellValue = $sheet->getCell($col . '2')->getValue();
            $headers[] = $cellValue ? trim($cellValue) : '';
        }
        
        // 데이터 (3행부터 10행까지, B열부터 I열까지)
        $rows = [];
        for ($row = 3; $row <= 10; $row++) {
            $rowData = [];
            for ($col = 'B'; $col <= 'I'; $col++) {
                $cellValue = $sheet->getCell($col . $row)->getValue();
                $rowData[] = $cellValue ? trim($cellValue) : '';
            }
            // 빈 행이 아닌 경우에만 추가
            if (array_filter($rowData)) {
                $rows[] = $rowData;
            }
        }
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'fileName' => $fileName,
            'headers' => $headers,
            'data' => $rows,
            'method' => 'PhpSpreadsheet'
        ]);
    }

} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => '파일 처리 중 오류가 발생했습니다: ' . $e->getMessage()]);
}

// 버퍼 정리
ob_end_flush();
?>