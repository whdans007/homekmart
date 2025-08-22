<?php
// 출력 버퍼링 시작하여 예기치 않은 출력 방지
ob_start();

try {
    require_once __DIR__ . '/../config/db_config.php';
    require_once __DIR__ . '/../lib/session_helper.php';
    require_once __DIR__ . '/../lib/permission_helper.php';
} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '필요한 라이브러리를 불러올 수 없습니다: ' . $e->getMessage()]);
    exit;
}

// 세션 시작 (세션이 시작되지 않은 경우)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 출력 버퍼 정리
ob_clean();
header('Content-Type: application/json');

// 권한 체크
if (!is_logged_in() || !has_permission('product_management')) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 필수 파라미터 검증 (간편등록용)
$name_ko = trim($_POST['name_ko'] ?? '');
$name_en = trim($_POST['name_en'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$pieces_per_box = $_POST['pieces_per_box'] ?? 1;
$description = trim($_POST['description'] ?? '');

// 디버깅 로그
error_log("신규상품 간편등록 요청 - name_en: $name_en, sku: $sku");

// 필수 필드 검증 (영어명만 필수)
if (empty($name_en)) {
    echo json_encode(['success' => false, 'message' => '상품명(영어)은 필수 입력 항목입니다.']);
    exit;
}

if (empty($sku)) {
    echo json_encode(['success' => false, 'message' => 'SKU는 필수 입력 항목입니다.']);
    exit;
}

// 카테고리와 바코드 필드 제거로 관련 검증 불필요

// 숫자 필드 검증
if (!is_numeric($pieces_per_box) || $pieces_per_box < 1) {
    echo json_encode(['success' => false, 'message' => '박스당 개수는 1 이상의 숫자여야 합니다.']);
    exit;
}

// 원가/판매가 필드는 점포별 관리로 제거됨

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // products 테이블 구조 확인
    try {
        $table_check_sql = "SHOW TABLES LIKE 'products'";
        $table_check_stmt = $pdo->prepare($table_check_sql);
        $table_check_stmt->execute();
        $products_table_exists = $table_check_stmt->fetch();
        
        if (!$products_table_exists) {
            echo json_encode(['success' => false, 'message' => 'products 테이블이 존재하지 않습니다.', 'debug' => 'NO_PRODUCTS_TABLE']);
            exit;
        }
        
        // products 테이블 컬럼 확인
        $columns_check_sql = "SHOW COLUMNS FROM products";
        $columns_check_stmt = $pdo->prepare($columns_check_sql);
        $columns_check_stmt->execute();
        $columns = $columns_check_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $column_names = array_column($columns, 'Field');
        error_log("products 테이블 컬럼: " . implode(', ', $column_names));
        
        // 최소 필수 컬럼 확인 (실제 테이블 구조에 맞게)
        $required_columns = ['name_en', 'sku'];
        $missing_columns = array_diff($required_columns, $column_names);
        
        if (!empty($missing_columns)) {
            error_log("누락된 컬럼: " . implode(', ', $missing_columns));
            echo json_encode(['success' => false, 'message' => '필수 컬럼이 누락되었습니다: ' . implode(', ', $missing_columns), 'debug' => 'MISSING_COLUMNS']);
            exit;
        }
        
        // 사용 가능한 컬럼 목록과 NULL 허용 여부 저장
        $available_columns = array_flip($column_names);
        $column_info = [];
        foreach ($columns as $column) {
            $column_info[$column['Field']] = [
                'null' => ($column['Null'] === 'YES'),
                'default' => $column['Default']
            ];
        }
        
        // 디버깅: 컬럼 정보 로그
        error_log("컬럼 NULL 허용 정보: " . print_r($column_info, true));
        
    } catch (PDOException $e) {
        error_log("테이블 구조 확인 오류: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '테이블 구조 확인에 실패했습니다.', 'debug' => 'TABLE_CHECK_ERROR']);
        exit;
    }

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // SKU 중복 검사
    $check_sku_sql = "SELECT COUNT(*) FROM products WHERE sku = ?";
    $check_sku_stmt = $pdo->prepare($check_sku_sql);
    $check_sku_stmt->execute([$sku]);
    
    if ($check_sku_stmt->fetchColumn() > 0) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'message' => '이미 존재하는 SKU입니다. 다른 SKU를 사용해주세요.']);
        exit;
    }

    // 바코드 필드 제거로 중복 검사 불필요

    // 카테고리 필드 제거로 관련 검증 불필요

    // 동적 INSERT 쿼리 생성 (실제 테이블 구조에 맞게)
    $insert_columns = [];
    $insert_values = [];
    $insert_params = [];
    
    // 필수 컬럼들
    $insert_columns[] = 'name_en';
    $insert_values[] = '?';
    $insert_params[] = $name_en;
    
    $insert_columns[] = 'sku';
    $insert_values[] = '?';
    $insert_params[] = $sku;
    
    // name_ko 컬럼 (존재하는 경우 항상 추가)
    if (isset($available_columns['name_ko'])) {
        $insert_columns[] = 'name_ko';
        $insert_values[] = '?';
        // NOT NULL이면 빈 문자열, NULL 허용이면 빈 값은 NULL로
        if (!$column_info['name_ko']['null'] && empty($name_ko)) {
            $insert_params[] = ''; // NOT NULL이므로 빈 문자열
        } else {
            $insert_params[] = $name_ko ?: null; // NULL 허용이므로 null 가능
        }
    }
    
    if (isset($available_columns['pieces_per_box'])) {
        $insert_columns[] = 'pieces_per_box';
        $insert_values[] = '?';
        $insert_params[] = $pieces_per_box;
    }
    
    // description 컬럼 (존재하는 경우 항상 추가)
    if (isset($available_columns['description'])) {
        $insert_columns[] = 'description';
        $insert_values[] = '?';
        // NOT NULL이면 빈 문자열, NULL 허용이면 빈 값은 NULL로
        if (!$column_info['description']['null'] && empty($description)) {
            $insert_params[] = ''; // NOT NULL이므로 빈 문자열
        } else {
            $insert_params[] = $description ?: null; // NULL 허용이므로 null 가능
        }
    }
    
    // 시스템 컬럼들 (존재하는 경우만 추가)
    if (isset($available_columns['status'])) {
        $insert_columns[] = 'status';
        $insert_values[] = '?';
        $insert_params[] = 'active';
    }
    
    if (isset($available_columns['created_at'])) {
        $insert_columns[] = 'created_at';
        $insert_values[] = 'NOW()';
    }
    
    if (isset($available_columns['updated_at'])) {
        $insert_columns[] = 'updated_at';
        $insert_values[] = 'NOW()';
    }
    
    if (isset($available_columns['created_by_user_id']) && isset($_SESSION['user_id'])) {
        $insert_columns[] = 'created_by_user_id';
        $insert_values[] = '?';
        $insert_params[] = $_SESSION['user_id'];
    }
    
    if (isset($available_columns['last_modified_by_user_id']) && isset($_SESSION['user_id'])) {
        $insert_columns[] = 'last_modified_by_user_id';
        $insert_values[] = '?';
        $insert_params[] = $_SESSION['user_id'];
    }
    
    // INSERT 쿼리 생성
    $insert_sql = "INSERT INTO products (" . implode(', ', $insert_columns) . ") VALUES (" . implode(', ', $insert_values) . ")";
    
    $insert_stmt = $pdo->prepare($insert_sql);
    $insert_result = $insert_stmt->execute($insert_params);

    if (!$insert_result) {
        $pdo->rollback();
        echo json_encode(['success' => false, 'message' => '상품 등록에 실패했습니다.']);
        exit;
    }

    // 등록된 상품 ID 가져오기
    $product_id = $pdo->lastInsertId();

    // 등록된 상품 정보 조회 (간편등록이므로 카테고리 정보 제외)
    $product_info_sql = "SELECT * FROM products WHERE id = ?";
    $product_info_stmt = $pdo->prepare($product_info_sql);
    $product_info_stmt->execute([$product_id]);
    $product_info = $product_info_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 카테고리명이 필요한 경우를 위해 빈 문자열로 설정
    $product_info['category_name'] = '';

    // 트랜잭션 커밋
    $pdo->commit();

    // 디버깅 로그
    $debug_msg = date('Y-m-d H:i:s') . " - 신규 상품 등록 성공 - product_id: " . $product_id . 
                 ", name_ko: " . $name_ko . ", sku: " . $sku . ", user_id: " . $_SESSION['user_id'] . "\n";
    file_put_contents(__DIR__ . '/debug_log.txt', $debug_msg, FILE_APPEND | LOCK_EX);

    echo json_encode([
        'success' => true,
        'message' => '상품이 성공적으로 등록되었습니다.',
        'product' => $product_info,
        'product_id' => $product_id
    ]);

} catch (PDOException $e) {
    // 트랜잭션 롤백
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    // 상세한 디버깅 로그
    error_log("상품 등록 PDO 오류: " . $e->getMessage());
    error_log("SQL 상태: " . $e->getCode());
    error_log("파일: " . $e->getFile() . ", 라인: " . $e->getLine());
    error_log("사용된 파라미터: " . print_r($insert_params ?? [], true));
    error_log("실행된 SQL: " . ($insert_sql ?? 'N/A'));
    
    // 에러 메시지 파싱
    $error_message = '데이터베이스 오류가 발생했습니다.';
    $debug_info = '';
    
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        if (strpos($e->getMessage(), 'sku') !== false) {
            $error_message = '이미 존재하는 SKU입니다.';
        } elseif (strpos($e->getMessage(), 'barcode') !== false) {
            $error_message = '이미 존재하는 바코드입니다.';
        } else {
            $error_message = '중복된 데이터가 존재합니다.';
        }
    } elseif (strpos($e->getMessage(), "doesn't exist") !== false) {
        $error_message = '데이터베이스 테이블 구조에 문제가 있습니다.';
        $debug_info = 'TABLE_ERROR';
    } elseif (strpos($e->getMessage(), 'cannot be null') !== false) {
        $error_message = '필수 데이터가 누락되었습니다.';
        $debug_info = 'NULL_ERROR';
    } elseif (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        $error_message = '연관 데이터 오류가 발생했습니다.';
        $debug_info = 'FOREIGN_KEY_ERROR';
    }
    
    echo json_encode([
        'success' => false, 
        'message' => $error_message,
        'debug' => $debug_info,
        'error_code' => $e->getCode(),
        'sql_error' => $e->getMessage() // 개발 환경용 상세 오류
    ]);
} catch (Exception $e) {
    // 트랜잭션 롤백
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("상품 등록 일반 오류: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '오류가 발생했습니다: ' . $e->getMessage()
    ]);
}
?>