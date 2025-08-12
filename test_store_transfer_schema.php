<?php
require_once __DIR__ . '/config/db_config.php';

echo "점간이동 데이터베이스 스키마 테스트\n";
echo "================================\n";

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "데이터베이스 연결 성공\n\n";
    
    // 스키마 실행
    $schema_sql = file_get_contents(__DIR__ . '/sql/store_transfer_schema.sql');
    
    // SQL 구문을 세미콜론으로 분리
    $statements = array_filter(
        array_map('trim', explode(';', $schema_sql)), 
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            echo "실행 중: " . substr(str_replace(["\n", "\r"], ' ', $statement), 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    
    echo "\n스키마 실행 완료!\n\n";
    
    // 테이블 존재 확인
    $tables = ['store_transfers', 'store_transfer_items'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if ($stmt->fetch()) {
            echo "✓ 테이블 '{$table}' 생성 확인\n";
            
            // 테이블 구조 확인
            $columns = $pdo->query("DESCRIBE {$table}")->fetchAll(PDO::FETCH_ASSOC);
            echo "  컬럼 수: " . count($columns) . "\n";
        } else {
            echo "✗ 테이블 '{$table}' 생성 실패\n";
        }
    }
    
    echo "\n";
    
    // 점포 정보 확인
    $stores_stmt = $pdo->query("SELECT id, name FROM stores LIMIT 5");
    $stores = $stores_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "사용 가능한 점포:\n";
    foreach ($stores as $store) {
        echo "  - ID: {$store['id']}, 이름: {$store['name']}\n";
    }
    
    echo "\n";
    
    // inventory 테이블에서 샘플 데이터 확인
    $inventory_stmt = $pdo->query("
        SELECT i.product_id, i.store_id, i.cost_price, i.quantity, p.sku, p.name_ko, p.name_en 
        FROM inventory i 
        JOIN products p ON i.product_id = p.id 
        WHERE i.quantity > 0 AND i.cost_price > 0
        LIMIT 3
    ");
    $inventory = $inventory_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "이동 가능한 샘플 상품:\n";
    foreach ($inventory as $item) {
        echo "  - SKU: {$item['sku']}, 상품: {$item['name_en']}, 점포: {$item['store_id']}, 원가: ₩" . number_format($item['cost_price'], 2) . ", 재고: {$item['quantity']}\n";
    }
    
    echo "\n테스트 완료!\n";
    echo "점간이동 시스템을 사용할 준비가 되었습니다.\n";
    
} catch (PDOException $e) {
    echo "오류 발생: " . $e->getMessage() . "\n";
    echo "파일: " . $e->getFile() . "\n";
    echo "줄: " . $e->getLine() . "\n";
}
?>