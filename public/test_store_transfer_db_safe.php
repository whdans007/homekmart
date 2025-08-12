<?php
require_once __DIR__ . '/../config/db_config.php';

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>점간이동 데이터베이스 테스트 (안전한 버전)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>점간이동 데이터베이스 테스트 (안전한 버전)</h1>
    
    <?php
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "<p class='success'>✓ 데이터베이스 연결 성공</p>";
        
        // 안전한 스키마 실행
        $schema_file = __DIR__ . '/../sql/store_transfer_schema_safe.sql';
        if (file_exists($schema_file)) {
            $schema_sql = file_get_contents($schema_file);
            
            echo "<h2>안전한 스키마 실행</h2>";
            echo "<p class='info'>스키마 파일: store_transfer_schema_safe.sql (외래키 제약조건 없음)</p>";
            
            // SQL 구문을 세미콜론으로 분리하여 실행
            $statements = array_filter(
                array_map('trim', explode(';', $schema_sql)), 
                function($stmt) {
                    return !empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^$/', $stmt);
                }
            );
            
            echo "<p class='info'>실행할 SQL 구문 수: " . count($statements) . "</p>";
            
            foreach ($statements as $i => $statement) {
                if (!empty($statement)) {
                    try {
                        $result = $pdo->exec($statement);
                        echo "<p class='success'>✓ SQL 구문 " . ($i+1) . " 실행 완료</p>";
                    } catch (PDOException $e) {
                        // 이미 존재하는 테이블인 경우는 무시
                        if (strpos($e->getMessage(), 'already exists') !== false) {
                            echo "<p class='info'>ℹ SQL 구문 " . ($i+1) . " (테이블 이미 존재)</p>";
                        } else {
                            echo "<p class='error'>✗ SQL 구문 " . ($i+1) . " 실행 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
                        }
                    }
                }
            }
        } else {
            echo "<p class='error'>✗ 스키마 파일을 찾을 수 없습니다: $schema_file</p>";
        }
        
        echo "<h2>테이블 확인</h2>";
        
        // 테이블 존재 확인
        $tables = ['store_transfers', 'store_transfer_items'];
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            if ($stmt->fetch()) {
                echo "<p class='success'>✓ 테이블 '{$table}' 존재 확인</p>";
                
                // 테이블 구조 확인
                $columns = $pdo->query("DESCRIBE {$table}")->fetchAll(PDO::FETCH_ASSOC);
                echo "<table>";
                echo "<tr><th>컬럼</th><th>타입</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                foreach ($columns as $column) {
                    echo "<tr>";
                    echo "<td>{$column['Field']}</td>";
                    echo "<td>{$column['Type']}</td>";
                    echo "<td>{$column['Null']}</td>";
                    echo "<td>{$column['Key']}</td>";
                    echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>✗ 테이블 '{$table}' 존재하지 않음</p>";
            }
        }
        
        echo "<h2>점포 정보</h2>";
        
        // 점포 정보 확인
        $stores_stmt = $pdo->query("SELECT id, name FROM stores ORDER BY id LIMIT 10");
        $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($stores)) {
            echo "<ul>";
            foreach ($stores as $store) {
                echo "<li>ID: {$store['id']}, 이름: " . htmlspecialchars($store['name']) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p class='error'>✗ 점포 정보가 없습니다</p>";
        }
        
        echo "<h2>재고 정보 샘플</h2>";
        
        // inventory 테이블에서 샘플 데이터 확인
        $inventory_stmt = $pdo->query("
            SELECT i.product_id, i.store_id, i.cost_price, i.quantity, p.sku, p.name_ko, p.name_en 
            FROM inventory i 
            JOIN products p ON i.product_id = p.id 
            WHERE i.quantity > 0 AND i.cost_price > 0
            ORDER BY i.store_id, i.product_id
            LIMIT 5
        ");
        $inventory = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($inventory)) {
            echo "<table>";
            echo "<tr><th>SKU</th><th>상품명</th><th>점포ID</th><th>원가</th><th>재고</th></tr>";
            foreach ($inventory as $item) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($item['sku']) . "</td>";
                echo "<td>" . htmlspecialchars($item['name_en'] ?: $item['name_ko']) . "</td>";
                echo "<td>" . $item['store_id'] . "</td>";
                echo "<td>₩" . number_format($item['cost_price'], 2) . "</td>";
                echo "<td>" . $item['quantity'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>✗ 이동 가능한 재고가 없습니다</p>";
        }
        
        echo "<h2>권한 테스트</h2>";
        
        // 권한 함수 테스트
        require_once __DIR__ . '/../lib/permission_helper.php';
        
        if (function_exists('has_permission')) {
            echo "<p class='success'>✓ has_permission() 함수 로드됨</p>";
            
            // 점간이동 권한 테스트 (임시로 super_admin 역할 가정)
            session_start();
            $_SESSION['role'] = 'super_admin';
            $_SESSION['user_id'] = 1;
            
            if (has_permission('store_transfer_management')) {
                echo "<p class='success'>✓ store_transfer_management 권한 확인</p>";
            } else {
                echo "<p class='error'>✗ store_transfer_management 권한 없음</p>";
            }
        } else {
            echo "<p class='error'>✗ has_permission() 함수 로드 실패</p>";
        }
        
        echo "<h2>테스트 완료</h2>";
        echo "<p class='success'>점간이동 시스템이 준비되었습니다!</p>";
        echo "<p><a href='store_transfers.php'>점간이동 등록 페이지로 이동</a></p>";
        echo "<p><a href='test_transfer_api.php'>API 테스트 실행</a></p>";
        
    } catch (PDOException $e) {
        echo "<p class='error'>데이터베이스 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
    } catch (Exception $e) {
        echo "<p class='error'>일반 오류: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>
</body>
</html>