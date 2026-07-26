<?php
require_once __DIR__ . '/../lib/auth.php';
header('Content-Type: application/json; charset=utf-8');

kw_require_staff();

$type    = $_POST['type']    ?? '';
$name_en = trim($_POST['name_en'] ?? '');
$name_ko = trim($_POST['name_ko'] ?? '') ?: null;
$token   = $_POST['csrf_token'] ?? '';

if (!hash_equals($_SESSION['kw_csrf'] ?? '', $token)) {
    echo json_encode(['success' => false, 'message' => 'Security error: Please try again.']);
    exit;
}

if ($name_en === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter the English name.']);
    exit;
}

if (!in_array($type, ['brand', 'category'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

try {
    $conn = get_lc_db();

    if ($type === 'brand') {
        $st = $conn->prepare("INSERT INTO kw_brands (name_en, name_ko) VALUES (?, ?)");
    } else {
        $st = $conn->prepare("INSERT INTO kw_categories (name_en, name_ko) VALUES (?, ?)");
    }

    $st->bind_param('ss', $name_en, $name_ko);
    $st->execute();
    $new_id = $conn->insert_id;
    $st->close();
    $conn->close();

    echo json_encode(['success' => true, 'id' => $new_id, 'name_en' => $name_en, 'name_ko' => $name_ko]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
}
