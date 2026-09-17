<?php
session_start();
require_once __DIR__ . '/../lib/lang_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$language = $_POST['language'] ?? '';

if (set_language($language)) {
    echo json_encode([
        'success' => true,
        'message' => 'Language changed successfully',
        'language' => $language
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported language'
    ]);
}
?>
