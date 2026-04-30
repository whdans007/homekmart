#!/bin/bash

# PHP를 통해 데이터베이스 구조 확인
php -r "
require_once 'config/db_config.php';
\$conn = get_db_connection();

echo \"=== store_order_list_items 테이블 구조 ===\n\n\";

\$result = \$conn->query('DESCRIBE store_order_list_items');
if (\$result) {
    while(\$row = \$result->fetch_assoc()) {
        echo str_pad(\$row['Field'], 20) . ' | ' . str_pad(\$row['Type'], 30) . ' | ' . (\$row['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . \"\n\";
    }
} else {
    echo '테이블을 찾을 수 없습니다\n';
}

echo \"\n=== 필드 확인 ===\n\";
\$remarks_check = \$conn->query(\"SHOW COLUMNS FROM store_order_list_items LIKE 'remarks'\");
\$order_status_check = \$conn->query(\"SHOW COLUMNS FROM store_order_list_items LIKE 'order_status'\");

echo 'remarks 컬럼: ' . (\$remarks_check->num_rows > 0 ? '존재 (삭제 필요)' : '없음 ✓') . \"\n\";
echo 'order_status 컬럼: ' . (\$order_status_check->num_rows > 0 ? '존재 ✓' : '없음 (생성 필요)') . \"\n\";

\$conn->close();
"
