<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

// PhpSpreadsheet 라이브러리가 있는지 확인
$has_phpspreadsheet = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        $has_phpspreadsheet = true;
        use PhpOffice\PhpSpreadsheet\IOFactory;
        use PhpOffice\PhpSpreadsheet\Cell\DataType;
        use PhpOffice\PhpSpreadsheet\Shared\Date;
    }
}

if (!is_logged_in() || !($_SESSION['role'] === 'super_admin')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '접근 권한이 없습니다.'];
    header('Location: settings.php');
    exit;
}

if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp_path = $_FILES['excel_file']['tmp_name'];
    $file_name = $_FILES['excel_file']['name'];
    $file_size = $_FILES['excel_file']['size'];
    $file_type = $_FILES['excel_file']['type'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

    $allowed_ext = ['xlsx', 'xls'];
    if (!in_array($file_ext, $allowed_ext)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '엑셀 파일(.xlsx, .xls)만 업로드 가능합니다.'];
        header('Location: settings.php');
        exit;
    }

    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $inserted_count = 0;
        $updated_count = 0;
        $error_count = 0;
        $errors = [];

        // PhpSpreadsheet를 사용할 수 있는 경우
        if ($has_phpspreadsheet) {
            $spreadsheet = IOFactory::load($file_tmp_path);
            $sheet = $spreadsheet->getActiveSheet();
            $highest_row = $sheet->getHighestRow();
            
            // 헤더 확인
            $headers = [];
            for ($col = 'A'; $col <= 'Z'; $col++) {
                $headerValue = $sheet->getCell($col . '1')->getValue();
                if ($headerValue) {
                    $headers[$col] = strtolower(trim($headerValue));
                }
            }

            // 필수 컬럼 매핑
            $columnMapping = [];
            $requiredColumns = [
                'item_code' => ['item code', 'sku', '상품코드', '상품 코드'],
                'item_name' => ['item name', 'name', '상품명', '제품명'],
                'cost_price' => ['u. cost', 'unit cost', 'cost', '단가', '원가'],
                'retail_price' => ['retail', 'selling price', 'price', '소매가', '판매가']
            ];

            foreach ($headers as $col => $header) {
                foreach ($requiredColumns as $field => $variations) {
                    foreach ($variations as $variation) {
                        if (strpos($header, strtolower($variation)) !== false) {
                            $columnMapping[$field] = $col;
                            break 2;
                        }
                    }
                }
            }

            // 필수 컬럼 확인
            $missingColumns = [];
            foreach ($requiredColumns as $field => $variations) {
                if (!isset($columnMapping[$field])) {
                    $missingColumns[] = $variations[0];
                }
            }

            if (!empty($missingColumns)) {
                throw new Exception('필수 컬럼이 누락되었습니다: ' . implode(', ', $missingColumns));
            }

            $pdo->beginTransaction();

            // 데이터 처리 (2행부터)
            for ($row = 2; $row <= $highest_row; $row++) {
                try {
                    // 데이터 추출
                    $itemCode = trim($sheet->getCell($columnMapping['item_code'] . $row)->getValue());
                    $itemName = trim($sheet->getCell($columnMapping['item_name'] . $row)->getValue());
                    $costPrice = (float)$sheet->getCell($columnMapping['cost_price'] . $row)->getValue();
                    $retailPrice = (float)$sheet->getCell($columnMapping['retail_price'] . $row)->getValue();

                    // 필수 데이터 확인
                    if (empty($itemCode)) {
                        $errors[] = "{$row}행: 상품코드가 비어있습니다.";
                        $error_count++;
                        continue;
                    }
                    if (empty($itemName)) {
                        $errors[] = "{$row}행: 상품명이 비어있습니다.";
                        $error_count++;
                        continue;
                    }
                    if ($retailPrice <= 0) {
                        $errors[] = "{$row}행: 판매가가 올바르지 않습니다.";
                        $error_count++;
                        continue;
                    }

                    // 기존 상품 확인 (SKU로)
                    $checkStmt = $pdo->prepare("SELECT id FROM products WHERE sku = ?");
                    $checkStmt->execute([$itemCode]);
                    $existingProduct = $checkStmt->fetch();

                    if ($existingProduct) {
                        // 기존 상품 업데이트
                        $updateStmt = $pdo->prepare("
                            UPDATE products 
                            SET name_ko = ?, name_en = ?, cost_price = ?, selling_price = ?, 
                                last_modified_by_user_id = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE sku = ?
                        ");
                        $updateStmt->execute([
                            $itemName, $itemName, $costPrice, $retailPrice, $_SESSION['user_id'], $itemCode
                        ]);
                        $updated_count++;
                    } else {
                        // 새 상품 추가
                        $insertStmt = $pdo->prepare("
                            INSERT INTO products (sku, name_ko, name_en, cost_price, selling_price, 
                                                is_active, last_modified_by_user_id) 
                            VALUES (?, ?, ?, ?, ?, 1, ?)
                        ");
                        $insertStmt->execute([
                            $itemCode, $itemName, $itemName, $costPrice, $retailPrice, $_SESSION['user_id']
                        ]);
                        $inserted_count++;
                    }

                } catch (Exception $e) {
                    $errors[] = "{$row}행: " . $e->getMessage();
                    $error_count++;
                }
            }

        } else {
            // PhpSpreadsheet가 없는 경우 - 간단한 오류 메시지
            throw new Exception('Excel 파일 처리를 위한 PhpSpreadsheet 라이브러리가 설치되지 않았습니다. 시스템 관리자에게 문의하세요.');
        }

        if ($error_count > 0) {
            $pdo->rollback();
            $_SESSION['flash'] = [
                'type' => 'error', 
                'message' => "일부 데이터 처리에 실패했습니다. 성공: " . ($inserted_count + $updated_count) . "개, 실패: {$error_count}개"
            ];
        } else {
            $pdo->commit();
            $_SESSION['flash'] = [
                'type' => 'success', 
                'message' => "상품 데이터 가져오기가 완료되었습니다. (추가: {$inserted_count}개, 업데이트: {$updated_count}개)"
            ];
        }

    } catch (\Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => '파일 처리 중 오류가 발생했습니다: ' . $e->getMessage()];
    }

} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '파일 업로드 중 오류가 발생했습니다.'];
}

header('Location: settings.php');
exit;
