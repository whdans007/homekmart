<?php
session_start();
require_once '../config/db_config.php';
require_once '../lib/permission_helper.php';

// 관리자 권한 확인
if (!isset($_SESSION['user_id']) || !has_permission('admin_access')) {
    die("접근 권한이 없습니다.");
}

$conn = get_db_connection();

echo "<h2>VAT 관련 컬럼 설정</h2>";

try {
    // 1. products 테이블에 is_vat_applicable 컬럼 추가
    echo "<h3>1. products 테이블 컬럼 확인</h3>";
    
    $columns = $conn->query("DESCRIBE products");
    $has_vat_column = false;
    while ($column = $columns->fetch_assoc()) {
        if ($column['Field'] === 'is_vat_applicable') {
            $has_vat_column = true;
            break;
        }
    }
    
    if (!$has_vat_column) {
        $conn->query("ALTER TABLE products ADD COLUMN is_vat_applicable TINYINT(1) DEFAULT 1 COMMENT 'VAT 적용 여부 (1=적용, 0=비적용)' AFTER category_id");
        echo "✅ is_vat_applicable 컬럼 추가 완료<br>";
    } else {
        echo "ℹ️ is_vat_applicable 컬럼이 이미 존재합니다<br>";
    }
    
    // 2. 기존 데이터 업데이트
    echo "<h3>2. 기존 데이터 업데이트</h3>";
    
    $result = $conn->query("UPDATE products SET is_vat_applicable = 1 WHERE is_vat_applicable IS NULL");
    $updated_count = $conn->affected_rows;
    echo "✅ 기존 상품 VAT 적용 상품으로 설정: {$updated_count}개<br>";
    
    
    // 4. 인덱스 추가
    echo "<h3>3. 인덱스 설정</h3>";
    
    try {
        $conn->query("CREATE INDEX idx_products_vat_applicable ON products(is_vat_applicable)");
        echo "✅ 인덱스 추가 완료<br>";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), "Duplicate key name") !== false) {
            echo "ℹ️ 인덱스가 이미 존재합니다<br>";
        } else {
            echo "⚠️ 인덱스 추가 실패: " . $e->getMessage() . "<br>";
        }
    }
    
    // 5. 결과 확인
    echo "<h3>4. 결과 확인</h3>";
    
    $stats_result = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_vat_applicable = 1 THEN 1 ELSE 0 END) as vat_applicable,
        SUM(CASE WHEN is_vat_applicable = 0 THEN 1 ELSE 0 END) as vat_exempt
        FROM products");
    
    $stats = $stats_result->fetch_assoc();
    
    echo "<div style='background-color: #f0f9ff; border: 1px solid #0ea5e9; padding: 15px; border-radius: 8px; margin-top: 10px;'>";
    echo "<h4>📊 상품 현황</h4>";
    echo "총 상품: <strong>{$stats['total']}개</strong><br>";
    echo "VAT 적용 상품: <strong>{$stats['vat_applicable']}개</strong><br>";
    echo "VAT 비적용 상품: <strong>{$stats['vat_exempt']}개</strong><br>";
    echo "</div>";
    
    // 6. VAT 비적용 상품 목록 출력
    if ($stats['vat_exempt'] > 0) {
        echo "<h3>5. VAT 비적용 상품 목록</h3>";
        
        $vat_exempt_result = $conn->query("SELECT id, name_ko, is_vat_applicable FROM products WHERE is_vat_applicable = 0 ORDER BY name_ko");
        
        echo "<table style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
        echo "<tr style='background-color: #f9fafb;'>";
        echo "<th style='border: 1px solid #d1d5db; padding: 8px; text-align: left;'>상품 ID</th>";
        echo "<th style='border: 1px solid #d1d5db; padding: 8px; text-align: left;'>상품명</th>";
        echo "<th style='border: 1px solid #d1d5db; padding: 8px; text-align: center;'>VAT 적용</th>";
        echo "</tr>";
        
        while ($row = $vat_exempt_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td style='border: 1px solid #d1d5db; padding: 8px;'>{$row['id']}</td>";
            echo "<td style='border: 1px solid #d1d5db; padding: 8px;'>{$row['name_ko']}</td>";
            echo "<td style='border: 1px solid #d1d5db; padding: 8px; text-align: center;'>";
            echo $row['is_vat_applicable'] == 0 ? "<span style='color: #dc2626;'>비적용</span>" : "<span style='color: #16a34a;'>적용</span>";
            echo "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<div style='background-color: #f0fdf4; border: 1px solid #16a34a; padding: 15px; border-radius: 8px; margin-top: 20px;'>";
    echo "<h4>✅ 설정 완료</h4>";
    echo "VAT 관련 컬럼 설정이 성공적으로 완료되었습니다.<br>";
    echo "이제 상품 등록 시 VAT 적용 여부를 선택할 수 있고, 매입 시 상품별로 적절한 VAT 처리가 됩니다.";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='background-color: #fef2f2; border: 1px solid #dc2626; padding: 15px; border-radius: 8px; margin-top: 10px;'>";
    echo "<h4>❌ 오류 발생</h4>";
    echo "오류: " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

$conn->close();
?>

<div style="margin-top: 30px; padding: 20px; background-color: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px;">
    <h4>🔄 다음 단계</h4>
    <ol>
        <li>이 페이지를 웹 브라우저에서 한 번 실행해주세요</li>
        <li><strong>중요:</strong> 쌀, 미곡류 등 VAT 비적용 상품은 <a href="product_management.php">상품 관리</a>에서 개별적으로 "VAT 비적용"으로 설정해주세요</li>
        <li><a href="add_product.php">상품 등록 페이지</a>에서 VAT 적용 여부 선택 확인</li>
        <li><a href="add_purchase.php">매입 등록 페이지</a>에서 VAT 처리 로직 확인</li>
        <li>이 파일은 실행 후 삭제해도 됩니다</li>
    </ol>
</div>