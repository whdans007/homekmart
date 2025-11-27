<?php
// ID=1 데이터 확인 스크립트
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db_config.php';

try {
    $conn = get_db_connection();
    if (!$conn) {
        die("데이터베이스 연결 실패: " . mysqli_connect_error());
    }
} catch (Exception $e) {
    die("연결 오류: " . $e->getMessage());
}

echo "<h2>ID=1 데이터 확인</h2>";
echo "<style>
table { border-collapse: collapse; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background-color: #4CAF50; color: white; }
.missing { background-color: #ffcccc; }
.exists { background-color: #ccffcc; }
</style>";

// 확인할 주요 테이블 목록
$important_tables = [
    'users' => ['id', 'username', 'role'],
    'stores' => ['id', 'store_name', 'address'],
    'brands' => ['id', 'brand_name'],
    'categories' => ['id', 'category_name'],
    'suppliers' => ['id', 'supplier_name'],
    'products' => ['id', 'product_name', 'barcode'],
    'inventory' => ['id', 'product_id', 'store_id', 'quantity'],
    'purchases' => ['id', 'supplier_id', 'total_amount'],
    'wholesale_customers' => ['id', 'customer_name'],
    'barcode_sequences' => ['id', 'current_value', 'prefix']
];

echo "<table>";
echo "<tr><th>테이블명</th><th>ID=1 존재 여부</th><th>데이터 미리보기</th></tr>";

foreach ($important_tables as $table => $columns) {
    try {
        $column_list = implode(', ', $columns);
        $query = "SELECT $column_list FROM `$table` WHERE id = 1 LIMIT 1";
        $result = @$conn->query($query);

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $row_class = 'exists';
            $status = '✅ 존재';
            $preview = htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE));
        } else {
            $row_class = 'missing';
            $status = '❌ 없음';

            // 최소 ID 확인
            $min_result = @$conn->query("SELECT MIN(id) as min_id FROM `$table`");
            if ($min_result && $min_result->num_rows > 0) {
                $min_row = $min_result->fetch_assoc();
                $preview = "최소 ID: " . ($min_row['min_id'] ?? 'NULL');
            } else {
                $preview = "테이블 비어있음";
            }
        }

        echo "<tr class='$row_class'>";
        echo "<td><strong>$table</strong></td>";
        echo "<td>$status</td>";
        echo "<td style='max-width: 500px; overflow: auto;'><small>$preview</small></td>";
        echo "</tr>";
    } catch (Exception $e) {
        echo "<tr class='missing'>";
        echo "<td><strong>$table</strong></td>";
        echo "<td>⚠️ 오류</td>";
        echo "<td>" . htmlspecialchars($e->getMessage()) . "</td>";
        echo "</tr>";
    }
}

echo "</table>";

// 테이블별 ID 범위 확인
echo "<h3>테이블별 ID 범위</h3>";
echo "<table>";
echo "<tr><th>테이블명</th><th>최소 ID</th><th>최대 ID</th><th>총 행 수</th><th>ID 갭 여부</th></tr>";

foreach (array_keys($important_tables) as $table) {
    $stats_query = "SELECT MIN(id) as min_id, MAX(id) as max_id, COUNT(*) as total FROM `$table`";
    $stats_result = $conn->query($stats_query);

    if ($stats_result && $stats_result->num_rows > 0) {
        $stats = $stats_result->fetch_assoc();
        $min_id = $stats['min_id'] ?? 'NULL';
        $max_id = $stats['max_id'] ?? 'NULL';
        $total = $stats['total'];

        // ID 갭 확인 (최소 ID가 1이 아닌 경우)
        $gap_warning = ($min_id != 1 && $min_id != 'NULL') ? '⚠️ ID=1 누락' : '';

        echo "<tr>";
        echo "<td>$table</td>";
        echo "<td>$min_id</td>";
        echo "<td>$max_id</td>";
        echo "<td>$total</td>";
        echo "<td style='color: red;'>$gap_warning</td>";
        echo "</tr>";
    }
}

echo "</table>";

$conn->close();
?>
