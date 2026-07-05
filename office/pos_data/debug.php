<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<pre>';

// Step 1: header.php
echo "Step 1: Loading header...\n";
$page_title      = 'Debug';
$css_base        = '../../admin/';
$office_nav_base = '../';

try {
    require_once __DIR__ . '/../partials/header.php';
    echo "Step 1: OK\n";
} catch (Throwable $e) {
    echo "Step 1 ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
    exit;
}

// Step 2: autoload
echo "Step 2: Checking autoload...\n";
$autoload = __DIR__ . '/../../vendor/autoload.php';
echo "Path: $autoload\n";
echo "Exists: " . (file_exists($autoload) ? 'YES' : 'NO') . "\n";

// Step 3: PhpSpreadsheet
if (file_exists($autoload)) {
    echo "Step 3: Loading PhpSpreadsheet...\n";
    try {
        require_once $autoload;
        echo "Step 3: OK\n";
        echo "IOFactory exists: " . (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory') ? 'YES' : 'NO') . "\n";
    } catch (Throwable $e) {
        echo "Step 3 ERROR: " . $e->getMessage() . "\n";
    }
}

echo "PHP version: " . PHP_VERSION . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo '</pre>';

require_once __DIR__ . '/../partials/footer.php';
