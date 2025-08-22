<?php
/**
 * 데이터베이스 마이그레이션 도구
 * 로컬 데이터베이스의 모든 데이터를 추출하여 운영 서버용 SQL 파일 생성
 */

set_time_limit(300); // 5분 타임아웃
ini_set('memory_limit', '512M');

echo "<!DOCTYPE html>";
echo "<html lang='ko'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>데이터베이스 마이그레이션 도구</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; line-height: 1.6; }";
echo ".container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 1200px; }";
echo ".sql-output { background: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; font-size: 12px; max-height: 500px; overflow-y: auto; }";
echo ".success { color: #22c55e; }";
echo ".error { color: #ef4444; }";
echo ".warning { color: #f59e0b; }";
echo ".info { color: #3b82f6; }";
echo ".section { margin: 20px 0; padding: 15px; border-left: 4px solid #3b82f6; background: #f8f9ff; }";
echo ".download-link { display: inline-block; padding: 10px 15px; background: #22c55e; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }";
echo ".download-link:hover { background: #16a34a; }";
echo "</style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h1>🔄 데이터베이스 마이그레이션 도구</h1>";

try {
    // 로컬 데이터베이스 연결 - 다양한 설정 시도
    $db_configs = [
        ['localhost', 'root', 'Alswhd77**&&', 'min'],   // 실제 비밀번호
        ['localhost', 'root', '', 'min'],           // 기본 설정
        ['localhost', 'root', 'root', 'min'],       // root 비밀번호
        ['127.0.0.1', 'root', 'Alswhd77**&&', 'min'],  // IP 주소 + 비밀번호
        ['localhost', 'root', 'Alswhd77**&&', 'if0_39723369_min'], // 운영 DB명
    ];
    
    $local_conn = null;
    $used_config = null;
    
    foreach ($db_configs as $config) {
        $local_conn = new mysqli($config[0], $config[1], $config[2], $config[3]);
        if (!$local_conn->connect_error) {
            $used_config = $config;
            break;
        }
    }
    
    if (!$local_conn || $local_conn->connect_error) {
        echo "<p class='error'>❌ 로컬 데이터베이스 연결 실패</p>";
        echo "<div class='section'>";
        echo "<h2>🔧 연결 설정 확인</h2>";
        echo "<p>다음 설정들을 시도했습니다:</p>";
        echo "<ul>";
        foreach ($db_configs as $i => $config) {
            echo "<li>호스트: {$config[0]}, 사용자: {$config[1]}, 비밀번호: " . ($config[2] ? "'{$config[2]}'" : '없음') . ", DB: {$config[3]}</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='section'>";
        echo "<h2>💡 해결 방법</h2>";
        echo "<h3>방법 1: XAMPP 사용자</h3>";
        echo "<ol>";
        echo "<li>XAMPP Control Panel에서 MySQL 'Admin' 버튼 클릭</li>";
        echo "<li>phpMyAdmin에서 SQL 탭 선택</li>";
        echo "<li>다음 명령어 실행:</li>";
        echo "</ol>";
        echo "<pre style='background:#f0f0f0; padding:10px; border-radius:4px;'>";
        echo "-- 현재 데이터베이스 확인\n";
        echo "SHOW DATABASES;\n\n";
        echo "-- 데이터베이스가 'min'이 아니라면\n";
        echo "CREATE DATABASE IF NOT EXISTS `min` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        echo "</pre>";
        
        echo "<h3>방법 2: 수동 백업</h3>";
        echo "<ol>";
        echo "<li>phpMyAdmin에서 해당 데이터베이스 선택</li>";
        echo "<li>상단 '내보내기' 탭 클릭</li>";
        echo "<li>'빠른' 방식, SQL 형식 선택</li>";
        echo "<li>'실행' 버튼으로 .sql 파일 다운로드</li>";
        echo "<li>다운로드한 파일을 수정하여 운영 서버에 사용</li>";
        echo "</ol>";
        
        echo "<h3>방법 3: 명령줄 도구</h3>";
        echo "<p>Windows 명령 프롬프트에서:</p>";
        echo "<pre style='background:#f0f0f0; padding:10px; border-radius:4px;'>";
        echo "cd C:\\xampp\\mysql\\bin\n";
        echo "mysqldump -u root -p min > migration.sql\n";
        echo "# 비밀번호 입력 후 migration.sql 파일 생성됨";
        echo "</pre>";
        echo "</div>";
        
        exit;
    }
    
    $local_conn->set_charset('utf8mb4');
    echo "<p class='success'>✅ 로컬 데이터베이스 연결 성공</p>";
    echo "<p class='info'>사용된 설정: 호스트={$used_config[0]}, 사용자={$used_config[1]}, DB={$used_config[3]}</p>";
    
    // 모든 테이블 목록 가져오기
    $tables_result = $local_conn->query("SHOW TABLES");
    $tables = [];
    
    if ($tables_result) {
        while ($row = $tables_result->fetch_array()) {
            $tables[] = $row[0];
        }
        
        echo "<div class='section'>";
        echo "<h2>📋 발견된 테이블 목록 (" . count($tables) . "개)</h2>";
        echo "<ul>";
        foreach ($tables as $table) {
            // 테이블 행 수 확인
            $count_result = $local_conn->query("SELECT COUNT(*) as count FROM `{$table}`");
            $count = $count_result ? $count_result->fetch_assoc()['count'] : 0;
            echo "<li><strong>{$table}</strong>: {$count}개 레코드</li>";
        }
        echo "</ul>";
        echo "</div>";
        
        // 전체 데이터베이스 덤프 생성
        $dump_sql = "";
        $dump_sql .= "-- ===================================================================\n";
        $dump_sql .= "-- HOME K MART 데이터베이스 전체 마이그레이션\n";
        $dump_sql .= "-- 생성일: " . date('Y-m-d H:i:s') . "\n";
        $dump_sql .= "-- 소스: 로컬 데이터베이스 (min)\n";
        $dump_sql .= "-- 대상: 운영 서버 (if0_39723369_min)\n";
        $dump_sql .= "-- ===================================================================\n\n";
        
        $dump_sql .= "-- 기존 데이터베이스 삭제 및 재생성\n";
        $dump_sql .= "DROP DATABASE IF EXISTS `if0_39723369_min`;\n";
        $dump_sql .= "CREATE DATABASE IF NOT EXISTS `if0_39723369_min` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
        $dump_sql .= "USE `if0_39723369_min`;\n\n";
        
        $dump_sql .= "-- 외래키 체크 비활성화 (빠른 삽입)\n";
        $dump_sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $dump_sql .= "SET AUTOCOMMIT = 0;\n";
        $dump_sql .= "START TRANSACTION;\n\n";
        
        $total_records = 0;
        
        // 1단계: 모든 테이블 구조 생성
        echo "<div class='section'>";
        echo "<h2>🔧 1단계: 테이블 구조 생성</h2>";
        
        foreach ($tables as $table) {
            $create_result = $local_conn->query("SHOW CREATE TABLE `{$table}`");
            if ($create_result) {
                $create_row = $create_result->fetch_array();
                $create_sql = $create_row[1];
                
                // 테이블명이 이미 포함되어 있으므로 그대로 사용
                $dump_sql .= "-- 테이블 구조: {$table}\n";
                $dump_sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $dump_sql .= $create_sql . ";\n\n";
                
                echo "<p class='info'>✓ {$table} 구조 추가</p>";
            }
        }
        echo "</div>";
        
        // 2단계: 데이터 삽입 (의존성 순서 고려)
        echo "<div class='section'>";
        echo "<h2>📊 2단계: 데이터 삽입</h2>";
        
        // 의존성 순서에 따른 테이블 정렬
        $priority_tables = [
            'stores', 'brands', 'categories', 'suppliers', 'users',
            'products', 'inventory', 'margin_rules',
            'purchases', 'purchase_items',
            'store_transfers', 'store_transfer_items',
            'wholesale_customers', 'wholesale_products', 'wholesale_sales', 'wholesale_sale_items',
            'delivery_addresses', 'shopping_cart',
            'price_change_history', 'system_settings'
        ];
        
        // 우선순위 테이블 먼저 처리
        $processed_tables = [];
        foreach ($priority_tables as $priority_table) {
            if (in_array($priority_table, $tables)) {
                $dump_sql .= generateInsertStatements($local_conn, $priority_table);
                $processed_tables[] = $priority_table;
                
                $count_result = $local_conn->query("SELECT COUNT(*) as count FROM `{$priority_table}`");
                $count = $count_result ? $count_result->fetch_assoc()['count'] : 0;
                $total_records += $count;
                
                echo "<p class='info'>✓ {$priority_table}: {$count}개 레코드</p>";
            }
        }
        
        // 나머지 테이블 처리
        foreach ($tables as $table) {
            if (!in_array($table, $processed_tables)) {
                $dump_sql .= generateInsertStatements($local_conn, $table);
                
                $count_result = $local_conn->query("SELECT COUNT(*) as count FROM `{$table}`");
                $count = $count_result ? $count_result->fetch_assoc()['count'] : 0;
                $total_records += $count;
                
                echo "<p class='info'>✓ {$table}: {$count}개 레코드</p>";
            }
        }
        echo "</div>";
        
        $dump_sql .= "\n-- 외래키 체크 재활성화\n";
        $dump_sql .= "COMMIT;\n";
        $dump_sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $dump_sql .= "SET AUTOCOMMIT = 1;\n\n";
        
        $dump_sql .= "-- 마이그레이션 완료 확인\n";
        $dump_sql .= "SELECT 'Migration completed successfully!' as status,\n";
        $dump_sql .= "       (SELECT COUNT(*) FROM stores) as stores_count,\n";
        $dump_sql .= "       (SELECT COUNT(*) FROM users) as users_count,\n";
        $dump_sql .= "       (SELECT COUNT(*) FROM products) as products_count,\n";
        $dump_sql .= "       (SELECT COUNT(*) FROM inventory) as inventory_count;\n";
        
        // 파일 저장
        $filename = 'sql/full_migration_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = __DIR__ . '/' . $filename;
        
        if (file_put_contents($filepath, $dump_sql)) {
            $filesize = round(filesize($filepath) / 1024 / 1024, 2);
            
            echo "<div class='section'>";
            echo "<h2>🎉 마이그레이션 파일 생성 완료</h2>";
            echo "<p class='success'>✅ 파일 경로: {$filename}</p>";
            echo "<p class='info'>📊 총 {$total_records}개 레코드, {$filesize}MB</p>";
            echo "<a href='{$filename}' download class='download-link'>💾 SQL 파일 다운로드</a>";
            echo "</div>";
            
            // SQL 미리보기 (처음 50줄)
            echo "<div class='section'>";
            echo "<h2>👀 SQL 파일 미리보기 (처음 50줄)</h2>";
            $lines = explode("\n", $dump_sql);
            $preview = implode("\n", array_slice($lines, 0, 50));
            echo "<div class='sql-output'>" . htmlspecialchars($preview) . "\n\n... (총 " . count($lines) . "줄)</div>";
            echo "</div>";
            
        } else {
            echo "<p class='error'>❌ 파일 저장 실패</p>";
        }
        
    } else {
        echo "<p class='error'>❌ 테이블 목록 조회 실패</p>";
    }
    
    $local_conn->close();
    
} catch (Exception $e) {
    echo "<p class='error'>❌ 오류 발생: " . $e->getMessage() . "</p>";
}

/**
 * 테이블의 INSERT 구문 생성
 */
function generateInsertStatements($conn, $table) {
    $sql = "-- 데이터 삽입: {$table}\n";
    
    $result = $conn->query("SELECT * FROM `{$table}`");
    if (!$result || $result->num_rows == 0) {
        $sql .= "-- (데이터 없음)\n\n";
        return $sql;
    }
    
    // 컬럼 정보 가져오기
    $columns_result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    
    $column_list = '`' . implode('`, `', $columns) . '`';
    $values = [];
    
    while ($row = $result->fetch_assoc()) {
        $row_values = [];
        foreach ($columns as $column) {
            $value = $row[$column];
            if ($value === null) {
                $row_values[] = 'NULL';
            } else {
                $row_values[] = "'" . $conn->real_escape_string($value) . "'";
            }
        }
        $values[] = '(' . implode(', ', $row_values) . ')';
    }
    
    // 배치 단위로 INSERT 구문 생성 (1000개씩)
    $batch_size = 1000;
    $batches = array_chunk($values, $batch_size);
    
    foreach ($batches as $batch) {
        $sql .= "INSERT INTO `{$table}` ({$column_list}) VALUES\n";
        $sql .= implode(",\n", $batch) . ";\n\n";
    }
    
    return $sql;
}

echo "<div class='section'>";
echo "<h2>📖 사용 방법</h2>";
echo "<ol>";
echo "<li><strong>SQL 파일 다운로드</strong>: 위의 다운로드 버튼 클릭</li>";
echo "<li><strong>InfinityFree phpMyAdmin 접속</strong>: 운영 서버 DB 관리 도구</li>";
echo "<li><strong>SQL 파일 실행</strong>: 전체 내용을 복사하여 실행</li>";
echo "<li><strong>검증</strong>: deployment_test.php로 결과 확인</li>";
echo "</ol>";
echo "</div>";

echo "<div class='section'>";
echo "<h2>⚠️ 주의사항</h2>";
echo "<ul>";
echo "<li>이 작업은 운영 서버의 <strong>기존 데이터를 모두 삭제</strong>합니다</li>";
echo "<li>실행 전 운영 서버의 중요 데이터가 있다면 백업하세요</li>";
echo "<li>파일 크기가 클 수 있으니 phpMyAdmin의 업로드 제한을 확인하세요</li>";
echo "<li>실행 시간이 오래 걸릴 수 있습니다 (데이터 양에 따라)</li>";
echo "</ul>";
echo "</div>";

echo "</div>";
echo "</body>";
echo "</html>";
?>