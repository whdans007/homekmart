<?php
// 가장 간단한 미리보기 버전
// 모든 출력 버퍼링 (헤더 설정 전에)
ob_start();

// 에러 출력 억제
ini_set('display_errors', 0);
error_reporting(0);

session_start();

// JSON 헤더 설정
header('Content-Type: application/json; charset=utf-8');

try {
    // 기본 검증
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST 요청만 허용됩니다.');
    }

    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('파일 업로드에 실패했습니다.');
    }

    $uploadedFile = $_FILES['excel_file'];
    $fileName = $uploadedFile['name'];
    $fileTmpName = $uploadedFile['tmp_name'];

    // 파일 확장자 확인
    $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($fileExtension, ['xlsx', 'xls'])) {
        throw new Exception('Excel 파일(.xlsx, .xls)만 업로드할 수 있습니다.');
    }

    // 파일 존재 확인
    if (!file_exists($fileTmpName)) {
        throw new Exception('업로드된 파일을 찾을 수 없습니다.');
    }

    // 파일 크기 확인
    $fileSize = filesize($fileTmpName);
    if ($fileSize === false || $fileSize === 0) {
        throw new Exception('파일이 비어있거나 읽을 수 없습니다.');
    }

    // 실제 Excel 파일 읽기 시도
    $headers = [];
    $data = [];
    $method = 'Unknown';
    $note = '';

    // PhpSpreadsheet 라이브러리 사용 시도
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmpName);
            $worksheet = $spreadsheet->getActiveSheet();
            
            // 헤더 읽기 (2행, B~I열) - 안전한 문자열 처리
            for ($col = 'B'; $col <= 'I'; $col++) {
                $cellValue = $worksheet->getCell($col . '2')->getValue();
                $cleanValue = '';
                if ($cellValue !== null && $cellValue !== '') {
                    // 값을 문자열로 변환
                    $cleanValue = (string)$cellValue;
                    // UTF-8로 변환 (더 관대하게)
                    if (!mb_check_encoding($cleanValue, 'UTF-8')) {
                        $cleanValue = mb_convert_encoding($cleanValue, 'UTF-8', 'CP949,EUC-KR,UTF-8,auto');
                    }
                    // 위험한 제어문자만 제거 (한글, 영어, 숫자, 기본 기호는 유지)
                    $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleanValue);
                    // 기본적인 정리
                    $cleanValue = trim($cleanValue);
                    // 너무 긴 경우 자르기
                    $cleanValue = mb_substr($cleanValue, 0, 50);
                }
                $headers[] = $cleanValue ?: "컬럼 " . $col;
            }
            
            // 데이터 읽기 (3~10행, B~I열) - 더 관대한 데이터 읽기
            for ($row = 3; $row <= 10; $row++) {
                $rowData = [];
                $hasData = false;
                
                for ($col = 'B'; $col <= 'I'; $col++) {
                    $cell = $worksheet->getCell($col . $row);
                    $cellValue = $cell->getValue();
                    $displayValue = $cell->getDisplayValue(); // 표시되는 값도 확인
                    $formattedValue = $cell->getFormattedValue(); // 포맷된 값도 확인
                    
                    $cleanValue = '';
                    
                    // 여러 방법으로 값 가져오기 시도
                    $rawValue = $cellValue;
                    if ($rawValue === null || $rawValue === '') {
                        $rawValue = $displayValue;
                    }
                    if ($rawValue === null || $rawValue === '') {
                        $rawValue = $formattedValue;
                    }
                    
                    if ($rawValue !== null && $rawValue !== '') {
                        // 값을 문자열로 변환
                        $cleanValue = (string)$rawValue;
                        // UTF-8로 변환 (더 관대하게)
                        if (!mb_check_encoding($cleanValue, 'UTF-8')) {
                            $cleanValue = mb_convert_encoding($cleanValue, 'UTF-8', 'CP949,EUC-KR,UTF-8,auto');
                        }
                        // 위험한 제어문자만 제거 (한글, 영어, 숫자, 기본 기호는 유지)
                        $cleanValue = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleanValue);
                        // 기본적인 정리
                        $cleanValue = trim($cleanValue);
                        // 너무 긴 경우 자르기
                        $cleanValue = mb_substr($cleanValue, 0, 100);
                    }
                    
                    $rowData[] = $cleanValue;
                    if (!empty($cleanValue)) {
                        $hasData = true;
                    }
                }
                
                // 빈 행도 포함해서 구조 확인 (디버깅용)
                $data[] = $rowData;
            }
            
            $method = 'PhpSpreadsheet Library';
            $note = '실제 Excel 파일에서 데이터를 읽었습니다. (2행: 헤더, 3~10행: 데이터, B~I열)';
            
        } catch (Exception $e) {
            // PhpSpreadsheet 실패 시 샘플 데이터
            $headers = ['바코드 (PhpSpreadsheet 오류)', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'];
            $data = [
                ['PhpSpreadsheet 오류', substr($e->getMessage(), 0, 50), '5000', '7000', '오류', '테스트', '0', 'Library 실패'],
                ['1234567890124', '샘플 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '샘플 데이터'],
                ['1234567890125', '샘플 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', '샘플 데이터']
            ];
            $method = 'PhpSpreadsheet Error - Sample Data';
            $note = 'PhpSpreadsheet 오류로 인해 샘플 데이터를 표시합니다: ' . $e->getMessage();
        }
    } else if (class_exists('COM') && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        try {
            $excel = new COM("Excel.Application");
            $excel->Visible = false;
            $excel->DisplayAlerts = false;
            
            $absolutePath = realpath($fileTmpName);
            $workbook = $excel->Workbooks->Open($absolutePath);
            $worksheet = $workbook->Worksheets(1);
            
            // 헤더 읽기 (2행, B~I열)
            for ($col = 2; $col <= 9; $col++) {
                $cellValue = $worksheet->Cells(2, $col)->Value;
                $headers[] = $cellValue ? trim($cellValue) : "컬럼 " . ($col-1);
            }
            
            // 데이터 읽기 (3~10행, B~I열)
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
                
                if ($hasData) {
                    $data[] = $rowData;
                }
            }
            
            $workbook->Close();
            $excel->Quit();
            unset($excel);
            
            $method = 'COM Excel Object';
            $note = '실제 Excel 파일에서 데이터를 읽었습니다. (2행: 헤더, 3~10행: 데이터, B~I열)';
            
        } catch (Exception $e) {
            // COM 실패 시 샘플 데이터 사용
            $headers = ['바코드 (COM 오류)', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'];
            $data = [
                ['COM 오류 발생', $e->getMessage(), '5000', '7000', '오류', '테스트', '0', 'COM 실패'],
                ['1234567890124', '샘플 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '샘플 데이터'],
                ['1234567890125', '샘플 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', '샘플 데이터']
            ];
            $method = 'COM Error - Sample Data';
            $note = 'COM 오류로 인해 샘플 데이터를 표시합니다: ' . $e->getMessage();
        }
    } else {
        // COM 사용 불가 시 샘플 데이터
        $headers = ['바코드 (시스템 제한)', '상품명', '단가', '판매가', '카테고리', '브랜드', '재고', '설명'];
        $data = [
            ['시스템 제한', 'COM 객체 없음', '5000', '7000', '제한', '시스템', '0', 'COM 미지원'],
            ['1234567890124', '샘플 상품 2', '3000', '4500', '생활용품', '브랜드B', '50', '샘플 데이터'],
            ['1234567890125', '샘플 상품 3', '10000', '15000', '전자제품', '브랜드C', '25', '샘플 데이터']
        ];
        $method = 'System Limitation';
        $note = 'COM 객체를 사용할 수 없어 샘플 데이터를 표시합니다. 실제 Excel 처리를 위해서는 PhpSpreadsheet 라이브러리 설치가 필요합니다.';
    }

    // 성공 응답 - 더 안전한 JSON 인코딩
    ob_clean();
    
    // 데이터를 최종 검증 (이미 정리된 데이터이므로 최소한만 처리)
    $cleanedHeaders = [];
    foreach ($headers as $header) {
        // 이미 처리된 데이터이므로 UTF-8 체크만
        if (!mb_check_encoding($header, 'UTF-8')) {
            $header = mb_convert_encoding($header, 'UTF-8', 'CP949,EUC-KR,UTF-8,auto');
        }
        $cleanedHeaders[] = $header;
    }
    
    $cleanedData = [];
    foreach ($data as $row) {
        $cleanedRow = [];
        foreach ($row as $cell) {
            // 이미 처리된 데이터이므로 UTF-8 체크만
            if (!mb_check_encoding($cell, 'UTF-8')) {
                $cell = mb_convert_encoding($cell, 'UTF-8', 'CP949,EUC-KR,UTF-8,auto');
            }
            $cleanedRow[] = $cell;
        }
        $cleanedData[] = $cleanedRow;
    }
    
    $responseData = [
        'success' => true,
        'fileName' => mb_convert_encoding($fileName, 'UTF-8', 'auto'),
        'headers' => $cleanedHeaders,
        'data' => $cleanedData,
        'method' => $method,
        'note' => mb_convert_encoding($note, 'UTF-8', 'auto'),
        'fileInfo' => [
            'size' => $fileSize,
            'extension' => $fileExtension,
            'tmpPath' => $fileTmpName,
            'com_available' => class_exists('COM'),
            'phpspreadsheet_available' => file_exists(__DIR__ . '/../vendor/autoload.php'),
            'php_os' => PHP_OS,
            'php_version' => PHP_VERSION
        ]
    ];
    
    $response = json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    
    if ($response === false) {
        // JSON 인코딩 실패 시 더 자세한 오류 정보
        $jsonError = json_last_error();
        $jsonErrorMsg = json_last_error_msg();
        throw new Exception("JSON 인코딩 실패 - 코드: {$jsonError}, 메시지: {$jsonErrorMsg}");
    }
    
    echo $response;

} catch (Exception $e) {
    ob_clean();
    
    // 안전한 에러 메시지 처리
    $errorMessage = mb_convert_encoding($e->getMessage(), 'UTF-8', 'auto');
    $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage);
    
    $errorData = [
        'success' => false,
        'message' => $errorMessage,
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'json_last_error' => json_last_error(),
            'json_last_error_msg' => json_last_error_msg(),
            'has_phpspreadsheet' => file_exists(__DIR__ . '/../vendor/autoload.php'),
            'has_com' => class_exists('COM'),
            'file_info' => isset($_FILES['excel_file']) ? [
                'name' => $_FILES['excel_file']['name'],
                'size' => $_FILES['excel_file']['size'],
                'type' => $_FILES['excel_file']['type'],
                'error' => $_FILES['excel_file']['error']
            ] : null
        ]
    ];
    
    $errorResponse = json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    echo $errorResponse ?: '{"success":false,"message":"Critical JSON encoding failure"}';
    
} catch (Throwable $e) {
    ob_clean();
    
    $errorMessage = mb_convert_encoding($e->getMessage(), 'UTF-8', 'auto');
    $errorMessage = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $errorMessage);
    
    $errorData = [
        'success' => false,
        'message' => 'Critical error: ' . $errorMessage,
        'debug' => [
            'file' => __FILE__,
            'line' => $e->getLine(),
            'type' => get_class($e),
            'memory_usage' => memory_get_usage(true),
            'php_version' => PHP_VERSION
        ]
    ];
    
    $errorResponse = json_encode($errorData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    echo $errorResponse ?: '{"success":false,"message":"Fatal JSON encoding failure"}';
}

// 버퍼 종료 및 출력
ob_end_flush();
exit();
?>