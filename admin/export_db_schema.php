<?php
require_once __DIR__ . '/../config/db_config.php';

$conn = get_db_connection();

// 모든 테이블 목록 가져오기
$tables_result = $conn->query('SHOW TABLES');
$tables = [];
while ($row = $tables_result->fetch_array()) {
    $tables[] = $row[0];
}

$output = "=== HOME K MART 데이터베이스 테이블 구조 ===" . PHP_EOL . PHP_EOL;
$output .= "데이터베이스: u622428657_homekmart" . PHP_EOL;
$output .= "생성일: " . date('Y-m-d H:i:s') . PHP_EOL;
$output .= "총 테이블 수: " . count($tables) . PHP_EOL . PHP_EOL;

foreach ($tables as $table) {
    $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
    $output .= "테이블: " . $table . PHP_EOL;
    $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;

    // 테이블 구조
    $desc_result = $conn->query("DESCRIBE `$table`");
    $output .= PHP_EOL . "컬럼 정보:" . PHP_EOL;
    $output .= sprintf("%-30s %-20s %-10s %-10s %-10s %s" . PHP_EOL, 'Field', 'Type', 'Null', 'Key', 'Default', 'Extra');
    $output .= str_repeat('-', 100) . PHP_EOL;

    while ($col = $desc_result->fetch_assoc()) {
        $output .= sprintf("%-30s %-20s %-10s %-10s %-10s %s" . PHP_EOL,
            $col['Field'],
            $col['Type'],
            $col['Null'],
            $col['Key'],
            $col['Default'] ?? 'NULL',
            $col['Extra']
        );
    }

    // 인덱스 정보
    $index_result = $conn->query("SHOW INDEX FROM `$table`");
    if ($index_result->num_rows > 0) {
        $output .= PHP_EOL . "인덱스 정보:" . PHP_EOL;
        $indexes = [];
        while ($idx = $index_result->fetch_assoc()) {
            $key_name = $idx['Key_name'];
            if (!isset($indexes[$key_name])) {
                $indexes[$key_name] = [
                    'unique' => $idx['Non_unique'] == 0,
                    'columns' => []
                ];
            }
            $indexes[$key_name]['columns'][] = $idx['Column_name'];
        }

        foreach ($indexes as $name => $info) {
            $type = $info['unique'] ? 'UNIQUE' : 'INDEX';
            $output .= "  " . $type . " " . $name . " (" . implode(', ', $info['columns']) . ")" . PHP_EOL;
        }
    }

    $output .= PHP_EOL . PHP_EOL;
}

// 파일로 저장
$filename = __DIR__ . '/../claudedocs/database_schema.txt';
file_put_contents($filename, $output);

echo "데이터베이스 스키마가 저장되었습니다: " . $filename . PHP_EOL;
echo "총 테이블 수: " . count($tables) . PHP_EOL;

$conn->close();
