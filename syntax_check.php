<?php
// 구문 오류 체크
header('Content-Type: text/plain');

echo "PHP Syntax Check\n";
echo "================\n\n";

$files_to_check = [
    'api_products.php',
    'api_products_safe.php', 
    'api_products_fixed.php',
    'api_categories.php'
];

foreach ($files_to_check as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        echo "Checking $file...\n";
        
        // 구문 체크
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg(__DIR__ . '/' . $file) . " 2>&1", $output, $return_var);
        
        if ($return_var === 0) {
            echo "✓ Syntax OK\n";
        } else {
            echo "✗ Syntax Error:\n";
            foreach ($output as $line) {
                echo "  $line\n";
            }
        }
        echo "\n";
    } else {
        echo "✗ File not found: $file\n\n";
    }
}

echo "Check complete.\n";
?>