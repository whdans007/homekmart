<?php
/**
 * 쇼핑몰 관리 시스템 테스트를 위한 데이터베이스 상태 확인
 */

require_once '../config/db_config.php';

try {
    $conn = get_db_connection();
    echo "<h2>🔍 데이터베이스 연결 상태 확인</h2>\n";
    echo "<p>✅ 데이터베이스 연결 성공!</p>\n";
    echo "<p>서버 정보: " . $conn->server_info . "</p>\n";
    echo "<p>데이터베이스: " . DB_NAME . "</p>\n";
    echo "<hr>\n";

    // 점포 데이터 확인
    echo "<h3>📊 점포 데이터 현황</h3>\n";
    
    // stores 테이블 구조 먼저 확인
    $desc_sql = "DESCRIBE stores";
    $desc_result = $conn->query($desc_sql);
    $available_columns = [];
    if ($desc_result) {
        echo "<p>사용 가능한 컬럼:</p><ul>";
        while ($col = $desc_result->fetch_assoc()) {
            $available_columns[] = $col['Field'];
            echo "<li>{$col['Field']} ({$col['Type']})</li>";
        }
        echo "</ul>";
    }
    
    // 실제 존재하는 컬럼들로 쿼리 구성
    $basic_columns = ['id', 'name'];
    $optional_columns = ['address', 'manager', 'is_active', 'status', 'created_at'];
    
    $select_columns = $basic_columns;
    foreach ($optional_columns as $col) {
        if (in_array($col, $available_columns)) {
            $select_columns[] = $col;
        }
    }
    
    $store_sql = "SELECT " . implode(', ', $select_columns) . " FROM stores ORDER BY id";
    $store_result = $conn->query($store_sql);
    
    if ($store_result && $store_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
        echo "<tr style='background: #f0f0f0;'>";
        
        // 헤더 동적 생성
        $column_labels = [
            'id' => 'ID',
            'name' => '점포명',
            'address' => '주소',
            'manager' => '매니저',
            'is_active' => '활성화',
            'status' => '상태',
            'created_at' => '생성일'
        ];
        
        foreach ($select_columns as $col) {
            $label = isset($column_labels[$col]) ? $column_labels[$col] : $col;
            echo "<th>{$label}</th>";
        }
        echo "</tr>\n";
        
        while ($store = $store_result->fetch_assoc()) {
            echo "<tr>";
            foreach ($select_columns as $col) {
                $value = $store[$col] ?? '';
                
                // 특별 처리
                if ($col === 'is_active') {
                    $value = $value ? '✅ 활성' : '❌ 비활성';
                } elseif ($col === 'created_at' && $value) {
                    $value = date('Y-m-d', strtotime($value));
                }
                
                echo "<td>{$value}</td>";
            }
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p>❌ 점포 데이터가 없습니다.</p>\n";
    }

    // 상품 데이터 확인
    echo "<h3>📦 상품 데이터 현황</h3>\n";
    $product_sql = "SELECT 
        COUNT(*) as total_products,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_products,
        COUNT(DISTINCT category_id) as categories_count,
        AVG(selling_price) as avg_price,
        MIN(selling_price) as min_price,
        MAX(selling_price) as max_price
    FROM products";
    
    $product_result = $conn->query($product_sql);
    if ($product = $product_result->fetch_assoc()) {
        echo "<ul>\n";
        echo "<li>전체 상품 수: <strong>{$product['total_products']}개</strong></li>\n";
        echo "<li>활성 상품 수: <strong>{$product['active_products']}개</strong></li>\n";
        echo "<li>카테고리 수: <strong>{$product['categories_count']}개</strong></li>\n";
        echo "<li>평균 가격: <strong>₩" . number_format($product['avg_price']) . "</strong></li>\n";
        echo "<li>가격 범위: ₩" . number_format($product['min_price']) . " ~ ₩" . number_format($product['max_price']) . "</li>\n";
        echo "</ul>\n";
    }

    // 점포별 상품 할당 현황 - 먼저 테이블 존재 확인
    echo "<h3>🏪 점포별 상품 할당 현황</h3>\n";
    
    // 사용 가능한 테이블들 확인
    $tables_sql = "SHOW TABLES";
    $tables_result = $conn->query($tables_sql);
    $available_tables = [];
    if ($tables_result) {
        while ($table = $tables_result->fetch_array()) {
            $available_tables[] = $table[0];
        }
    }
    
    echo "<p>사용 가능한 테이블들:</p><ul>";
    foreach ($available_tables as $table) {
        if (strpos($table, 'store') !== false || strpos($table, 'product') !== false || strpos($table, 'inventory') !== false) {
            echo "<li><strong>{$table}</strong></li>";
        }
    }
    echo "</ul>";
    
    // store_products나 inventory 테이블 확인
    if (in_array('store_products', $available_tables)) {
        $store_product_sql = "SELECT 
            sp.store_id,
            s.name as store_name,
            COUNT(sp.product_id) as assigned_products,
            COUNT(CASE WHEN sp.is_featured = 1 THEN 1 END) as featured_products,
            COUNT(CASE WHEN sp.is_available = 1 THEN 1 END) as available_products
        FROM store_products sp
        LEFT JOIN stores s ON sp.store_id = s.id
        GROUP BY sp.store_id, s.name
        ORDER BY sp.store_id";
        $sp_result = $conn->query($store_product_sql);
    } elseif (in_array('inventory', $available_tables)) {
        $store_product_sql = "SELECT 
            i.store_id,
            s.name as store_name,
            COUNT(i.product_id) as assigned_products,
            COUNT(CASE WHEN i.quantity > 0 THEN 1 END) as available_products,
            SUM(i.quantity) as total_quantity
        FROM inventory i
        LEFT JOIN stores s ON i.store_id = s.id
        GROUP BY i.store_id, s.name
        ORDER BY i.store_id";
        $sp_result = $conn->query($store_product_sql);
    } else {
        $sp_result = false;
        echo "<p>⚠️ 점포별 상품 관리 테이블(store_products 또는 inventory)을 찾을 수 없습니다.</p>";
    }
    if ($sp_result && $sp_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
        
        // inventory 테이블인지 store_products 테이블인지에 따라 헤더 다르게 설정
        if (in_array('inventory', $available_tables) && !in_array('store_products', $available_tables)) {
            echo "<tr style='background: #f0f0f0;'><th>점포ID</th><th>점포명</th><th>등록상품</th><th>재고있음</th><th>총재고량</th></tr>\n";
        } else {
            echo "<tr style='background: #f0f0f0;'><th>점포ID</th><th>점포명</th><th>할당상품</th><th>특별상품</th><th>판매가능</th></tr>\n";
        }
        
        while ($sp = $sp_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$sp['store_id']}</td>";
            echo "<td>{$sp['store_name']}</td>";
            echo "<td>{$sp['assigned_products']}</td>";
            
            if (isset($sp['total_quantity'])) {
                // inventory 테이블 결과
                echo "<td>{$sp['available_products']}</td>";
                echo "<td>" . number_format($sp['total_quantity']) . "</td>";
            } else {
                // store_products 테이블 결과
                echo "<td>" . ($sp['featured_products'] ?? '0') . "</td>";
                echo "<td>{$sp['available_products']}</td>";
            }
            echo "</tr>\n";
        }
        echo "</table>\n";
    } elseif ($sp_result !== false) {
        echo "<p>⚠️ 점포별 상품 할당 데이터가 없습니다.</p>\n";
    }

    // 카테고리 데이터 확인
    echo "<h3>📂 카테고리 현황</h3>\n";
    
    // categories 테이블 구조 확인
    $cat_desc_sql = "DESCRIBE categories";
    $cat_desc_result = $conn->query($cat_desc_sql);
    $cat_columns = [];
    if ($cat_desc_result) {
        echo "<p>카테고리 테이블 구조:</p><ul>";
        while ($col = $cat_desc_result->fetch_assoc()) {
            $cat_columns[] = $col['Field'];
            echo "<li>{$col['Field']} ({$col['Type']})</li>";
        }
        echo "</ul>";
    }
    
    // 실제 존재하는 컬럼으로 쿼리 구성
    $cat_select = ['c.id'];
    $possible_name_columns = ['name_kr', 'name_en', 'name', 'category_name', 'title'];
    
    foreach ($possible_name_columns as $name_col) {
        if (in_array($name_col, $cat_columns)) {
            $cat_select[] = "c.{$name_col}";
        }
    }
    
    $category_sql = "SELECT 
        " . implode(', ', $cat_select) . ",
        COUNT(p.id) as product_count
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id AND (p.status = 'active' OR p.status IS NULL)
    GROUP BY " . implode(', ', $cat_select) . "
    ORDER BY product_count DESC
    LIMIT 10";
    
    $category_result = $conn->query($category_sql);
    if ($category_result && $category_result->num_rows > 0) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>\n";
        echo "<tr style='background: #f0f0f0;'><th>ID</th>";
        
        // 헤더 동적 생성
        $name_labels = [
            'name_kr' => '한글명',
            'name_en' => '영문명',
            'name' => '카테고리명',
            'category_name' => '카테고리명',
            'title' => '제목'
        ];
        
        foreach ($possible_name_columns as $name_col) {
            if (in_array($name_col, $cat_columns)) {
                $label = $name_labels[$name_col] ?? $name_col;
                echo "<th>{$label}</th>";
            }
        }
        echo "<th>상품수</th></tr>\n";
        
        while ($cat = $category_result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$cat['id']}</td>";
            
            foreach ($possible_name_columns as $name_col) {
                if (in_array($name_col, $cat_columns)) {
                    $value = $cat[$name_col] ?? '';
                    echo "<td>{$value}</td>";
                }
            }
            echo "<td>{$cat['product_count']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

    echo "<hr>\n";
    echo "<h3>🚀 테스트 준비 완료</h3>\n";
    echo "<p>이제 다음 링크들로 쇼핑몰 관리 시스템을 테스트할 수 있습니다:</p>\n";
    echo "<ul>\n";
    echo "<li><a href='shop_dashboard.php' target='_blank'>📊 통합 대시보드</a></li>\n";
    echo "<li><a href='store_product_planner.php' target='_blank'>🏪 점포별 상품 배치</a></li>\n";
    echo "<li><a href='shop_category_manager.php' target='_blank'>📂 카테고리별 관리</a></li>\n";
    echo "<li><a href='shop_store_compare.php' target='_blank'>⚖️ 점포 비교 분석</a></li>\n";
    echo "</ul>\n";

} catch (Exception $e) {
    echo "<h2>❌ 오류 발생</h2>\n";
    echo "<p>오류 메시지: " . $e->getMessage() . "</p>\n";
    echo "<p>파일: " . $e->getFile() . "</p>\n";
    echo "<p>라인: " . $e->getLine() . "</p>\n";
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<style>
body { font-family: 'Malgun Gothic', sans-serif; margin: 20px; }
table { margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f0f0f0; }
tr:nth-child(even) { background-color: #f9f9f9; }
a { color: #dc2626; text-decoration: none; }
a:hover { text-decoration: underline; }
</style>