<?php
// 간소화된 상품 검색 AJAX 엔드포인트
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("log_errors", 1);

// 디버깅 로그
error_log("Simple AJAX 시작 - " . date("Y-m-d H:i:s"));

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache, must-revalidate");

try {
    // 기본 파일 인클루드
    require_once __DIR__ . "/../config/db_config.php";
    require_once __DIR__ . "/../lib/session_helper.php";
    
    error_log("파일 인클루드 완료");
    
    // 세션 시작
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // 로그인 확인
    if (empty($_SESSION["user_id"])) {
        error_log("로그인되지 않은 상태");
        echo json_encode(["error" => "로그인이 필요합니다."]);
        exit;
    }
    
    error_log("사용자 ID: " . $_SESSION["user_id"]);
    
    // 검색어 받기
    $term = $_GET["term"] ?? "";
    $term = trim($term);
    
    error_log("검색어: " . $term);
    
    if (empty($term) || strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }
    
    // 데이터베이스 연결
    $conn = get_db_connection();
    if (\!$conn) {
        error_log("DB 연결 실패");
        echo json_encode(["error" => "데이터베이스 연결 실패"]);
        exit;
    }
    
    error_log("데이터베이스 연결 성공");
    
    // 간단한 상품 검색 쿼리
    $searchTerm = "%" . $term . "%";
    $sql = "SELECT 
                p.id, 
                p.sku, 
                p.name_ko, 
                p.name_en, 
                p.barcode, 
                p.cost_price,
                p.selling_price,
                p.pieces_per_box
            FROM products p 
            WHERE p.name_ko LIKE ? 
               OR p.name_en LIKE ? 
               OR p.sku LIKE ? 
               OR p.barcode = ?
            LIMIT 10";
    
    error_log("쿼리 실행");
    
    $stmt = $conn->prepare($sql);
    if (\!$stmt) {
        error_log("쿼리 준비 실패: " . $conn->error);
        echo json_encode(["error" => "쿼리 준비 실패: " . $conn->error]);
        exit;
    }
    
    $stmt->bind_param("ssss", $searchTerm, $searchTerm, $searchTerm, $term);
    
    if (\!$stmt->execute()) {
        error_log("쿼리 실행 실패: " . $stmt->error);
        echo json_encode(["error" => "쿼리 실행 실패: " . $stmt->error]);
        exit;
    }
    
    $result = $stmt->get_result();
    $products = [];
    
    while ($row = $result->fetch_assoc()) {
        if ($row["barcode"] === $term) {
            $row["exact_match"] = true;
        }
        $products[] = $row;
    }
    
    error_log("검색 결과 수: " . count($products));
    
    $stmt->close();
    $conn->close();
    
    echo json_encode($products);
    
} catch (Exception $e) {
    error_log("오류 발생: " . $e->getMessage());
    echo json_encode(["error" => $e->getMessage()]);
}
?>
