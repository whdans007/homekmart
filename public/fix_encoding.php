<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db_config.php';

echo "<!DOCTYPE html><html><head><title>데이터베이스 수정 (v6 - 최종)</title>";
echo "<style>body { font-family: sans-serif; line-height: 1.6; padding: 2em; } pre { background-color: #f0f0f0; padding: 1em; border-radius: 5px; } h2 { border-bottom: 1px solid #ccc; padding-bottom: 5px; margin-top: 2em; } .success { color: green; font-weight: bold; } .error { color: red; font-weight: bold; } .info { color: blue; } .warning { color: orange; font-weight: bold; }</style>";
echo "</head><body>";
echo "<h1>'products' 테이블 데이터 수정 및 인코딩 변경 (v6 - 최종)</h1>";

$conn = null;
$dropped_keys = [];

function fix_duplicates($conn, $column_name) {
    echo "<h3>- '{$column_name}' 컬럼 중복 처리</h3>";
    
    // 1. Find non-empty duplicates
    $find_sql = "SELECT `{$column_name}`, COUNT(*) as count FROM products WHERE `{$column_name}` IS NOT NULL AND `{$column_name}` != '' GROUP BY `{$column_name}` HAVING count > 1";
    $duplicates_result = $conn->query($find_sql);
    
    if ($duplicates_result === false) {
        throw new Exception("중복 검색 중 오류 발생: " . $conn->error);
    }

    if ($duplicates_result->num_rows > 0) {
        $update_stmt = $conn->prepare("UPDATE products SET `{$column_name}` = ? WHERE id = ?");
        while($row = $duplicates_result->fetch_assoc()) {
            $duplicate_value = $row[$column_name];
            echo "<p class='warning'>중복된 '{$column_name}' 값 '" . htmlspecialchars($duplicate_value) . "' 발견. 수정을 시작합니다.</p>";
            
            $products_sql = $conn->prepare("SELECT id FROM products WHERE `{$column_name}` = ? ORDER BY id");
            $products_sql->bind_param('s', $duplicate_value);
            $products_sql->execute();
            $products_result = $products_sql->get_result();
            $products = $products_result->fetch_all(MYSQLI_ASSOC);
            
            $is_first = true;
            foreach($products as $product) {
                if ($is_first) {
                    $is_first = false;
                    continue;
                }
                $new_value = $duplicate_value . '_dup_' . $product['id'];
                $update_stmt->bind_param('si', $new_value, $product['id']);
                $update_stmt->execute();
            }
            $products_sql->close();
        }
        $update_stmt->close();
        echo "<p class='success'>중복 값 수정 완료.</p>";
    } else {
        echo "<p class='info'>비어있지 않은 중복 '{$column_name}' 값이 없습니다.</p>";
    }

    // 2. Fix empty/NULL values
    $fix_empty_sql = "UPDATE products SET `{$column_name}` = CONCAT('temp_{$column_name}_', id) WHERE `{$column_name}` = '' OR `{$column_name}` IS NULL";
    if ($conn->query($fix_empty_sql) === false) {
        throw new Exception("빈 '{$column_name}' 값 수정 중 오류 발생: " . $conn->error);
    }
    echo "<p>빈 '{$column_name}' 값을 임시 고유값으로 업데이트했습니다. (영향 받은 행: " . $conn->affected_rows . ")</p>";
}


try {
    $conn = get_db_connection();
    if (!$conn) throw new Exception("데이터베이스에 연결할 수 없습니다.");
    echo "<p>데이터베이스 연결 성공.</p>";

    // --- 1단계: 모든 UNIQUE 제약 조건 임시 제거 ---
    echo "<h2>1단계: 모든 UNIQUE 제약 조건 임시 제거</h2>";
    $sql_show_create = "SHOW CREATE TABLE products";
    $result_show = $conn->query($sql_show_create);
    $row_show = $result_show->fetch_assoc();
    $create_table_sql = $row_show['Create Table'];

    if (preg_match_all("/UNIQUE KEY `(.*?)` \(`(.*?)`\)/", $create_table_sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key_name = $match[1];
            $column_name = $match[2];
            
            echo "<p class='info'>'{$column_name}' 컬럼의 UNIQUE KEY '{$key_name}'를 찾았습니다. 제약 조건을 임시로 제거합니다.</p>";
            $sql_drop_key = "ALTER TABLE products DROP INDEX `{$key_name}`";
            if ($conn->query($sql_drop_key) === TRUE) {
                $dropped_keys[] = ['name' => $key_name, 'column' => $column_name];
                echo "<p class='success'>UNIQUE KEY '{$key_name}' 제거 성공.</p>";
            } else {
                throw new Exception("UNIQUE KEY '{$key_name}' 제거 실패: " . $conn->error);
            }
        }
    }
    if (empty($dropped_keys)) echo "<p class='warning'>제거할 UNIQUE KEY를 찾지 못했습니다.</p>";

    // --- 2단계: 테이블 문자 인코딩 변경 ---
    echo "<h2>2단계: 테이블 문자 인코딩 변경</h2>";
    $sql_alter = "ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    echo "<p>테이블 문자 인코딩 변경을 시도합니다...</p>";
    if ($conn->query($sql_alter) === TRUE) {
        echo "<p class='success'>테이블 인코딩 변경 성공.</p>";
    } else {
        throw new Exception("테이블 인코딩 변경 실패: " . $conn->error);
    }
    
    // --- 3단계: 모든 중복 데이터 수정 ---
    echo "<h2>3단계: 모든 중복 데이터 수정</h2>";
    fix_duplicates($conn, 'sku');
    fix_duplicates($conn, 'barcode');
    
    // --- 4단계: UNIQUE 제약 조건 복구 ---
    echo "<h2>4단계: UNIQUE 제약 조건 복구</h2>";
    if (!empty($dropped_keys)) {
        foreach($dropped_keys as $key) {
            $key_name = $key['name'];
            $column_name = $key['column'];
            echo "<p class='info'>'{$key_name}' 이름으로 '{$column_name}' 컬럼에 UNIQUE 제약 조건을 다시 추가합니다.</p>";
            $sql_add_key = "ALTER TABLE products ADD UNIQUE `{$key_name}` (`{$column_name}`)";
            if (!$conn->query($sql_add_key)) {
                echo "<p class='error'>UNIQUE KEY '{$key_name}' 추가 실패: " . htmlspecialchars($conn->error) . "</p>";
                echo "<p class='warning'>문제가 계속되면 데이터베이스를 직접 확인해주세요.</p>";
            } else {
                echo "<p class='success'>UNIQUE KEY '{$key_name}' 추가 성공.</p>";
            }
        }
        echo "<p class='success'><strong>최종 성공:</strong> 모든 작업이 완료되었습니다.</p>";
        echo "<p>이제 엑셀 업로드를 다시 시도하여 글자 깨짐 문제가 해결되었는지 확인해 보세요.</p>";
        echo "<p class='warning'><strong>중요: 문제가 해결되었으니, 보안을 위해 이 파일(public/fix_encoding.php)을 즉시 삭제해 주세요.</strong></p>";

    } else {
        echo "<p class='warning'>복구할 UNIQUE KEY가 없습니다. 수동으로 확인이 필요할 수 있습니다.</p>";
    }

} catch (Exception $e) {
    echo "<p class='error'>스크립트 실행 중 예외가 발생했습니다: " . htmlspecialchars($e->getMessage()) . "</p>";
} finally {
    if ($conn) {
        $conn->close();
        echo "<p>데이터베이스 연결이 닫혔습니다.</p>";
    }
}
echo "</body></html>";
?> 