<?php
// H Mart 디자인 테스트 페이지 - is_active 컬럼 없이 작동
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>";
echo "<html><head><title>H Mart 테스트 (수정됨)</title></head><body>";
echo "<h1>H Mart 쇼핑몰 테스트 (수정됨)</h1>";

try {
    // 1. 기본 설정
    require_once __DIR__ . '/../config/db_config.php';
    echo "<p>✅ Config 파일 로드 성공</p>";
    
    // 2. 데이터베이스 연결
    $conn = get_db_connection();
    echo "<p>✅ 데이터베이스 연결 성공</p>";
    
    // 3. 점포 조회 테스트 (is_active 조건 제거)
    $stores_query = "SELECT * FROM stores ORDER BY name ASC";
    $stores_result = $conn->query($stores_query);
    $stores = $stores_result->fetch_all(MYSQLI_ASSOC);
    echo "<p>✅ 점포 조회 성공 - " . count($stores) . "개 점포</p>";
    
    // 4. 선택된 점포 테스트 (is_active 조건 제거)
    $selected_store_id = 1;
    $store_stmt = $conn->prepare("SELECT * FROM stores WHERE id = ?");
    $store_stmt->bind_param("i", $selected_store_id);
    $store_stmt->execute();
    $store_result = $store_stmt->get_result();
    $current_store = $store_result->fetch_assoc();
    $store_stmt->close();
    
    if ($current_store) {
        echo "<p>✅ 현재 점포: " . htmlspecialchars($current_store['name']) . "</p>";
    } else {
        echo "<p>❌ 점포를 찾을 수 없습니다</p>";
    }
    
    // 5. 진열 섹션 조회 테스트
    $check_sections = $conn->query("SHOW TABLES LIKE 'display_sections'");
    if ($check_sections->num_rows > 0) {
        echo "<p>✅ display_sections 테이블 존재</p>";
        
        $sections_query = "
            SELECT ds.*, 
                   COUNT(pd.id) as product_count
            FROM display_sections ds
            LEFT JOIN product_displays pd ON ds.id = pd.section_id 
                AND pd.store_id = ? 
                AND pd.is_active = 1
            WHERE ds.is_active = 1 AND ds.show_on_main = 1
            GROUP BY ds.id
            ORDER BY ds.display_order ASC
        ";
        $sections_stmt = $conn->prepare($sections_query);
        $sections_stmt->bind_param("i", $selected_store_id);
        $sections_stmt->execute();
        $sections_result = $sections_stmt->get_result();
        $sections = $sections_result->fetch_all(MYSQLI_ASSOC);
        $sections_stmt->close();
        
        echo "<p>✅ 진열 섹션 조회 성공 - " . count($sections) . "개 섹션</p>";
    } else {
        echo "<p>⚠️ display_sections 테이블이 없습니다 (쇼핑몰 시스템 미설정)</p>";
    }
    
    // 6. CSS 파일 확인
    $css_file = __DIR__ . '/css/shop.css';
    if (file_exists($css_file)) {
        echo "<p>✅ shop.css 파일 존재</p>";
    } else {
        echo "<p>❌ shop.css 파일 없음: " . $css_file . "</p>";
    }
    
    // 7. JS 파일 확인
    $js_file = __DIR__ . '/js/shop.js';
    if (file_exists($js_file)) {
        echo "<p>✅ shop.js 파일 존재</p>";
    } else {
        echo "<p>⚠️ shop.js 파일 없음 (선택사항): " . $js_file . "</p>";
    }
    
    echo "<h2>점포 목록:</h2>";
    echo "<ul>";
    foreach ($stores as $store) {
        echo "<li>" . htmlspecialchars($store['name']) . " (ID: " . $store['id'] . ")</li>";
    }
    echo "</ul>";
    
    echo "<p>✅ 모든 기본 체크 완료</p>";
    echo "<p><a href='index_hmart_fixed.php'>수정된 H Mart 쇼핑몰로 이동</a></p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<p>❌ 오류 발생: " . $e->getMessage() . "</p>";
    echo "<p>오류 위치: " . $e->getFile() . " 라인 " . $e->getLine() . "</p>";
    echo "<p>오류 스택:</p><pre>" . $e->getTraceAsString() . "</pre>";
} catch (Error $e) {
    echo "<p>❌ PHP 오류 발생: " . $e->getMessage() . "</p>";
    echo "<p>오류 위치: " . $e->getFile() . " 라인 " . $e->getLine() . "</p>";
}

echo "</body></html>";
?>