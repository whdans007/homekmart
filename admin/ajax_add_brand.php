<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

header('Content-Type: application/json');

// 로그인 및 권한 확인
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['name'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$name = trim($_POST['name']);

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 중복 확인
    $stmt = $pdo->prepare("SELECT id FROM brands WHERE name_ko = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '이미 존재하는 브랜드입니다.']);
        exit;
    }

    // 새 브랜드 추가
    $stmt = $pdo->prepare("INSERT INTO brands (name_ko) VALUES (?)");
    $stmt->execute([$name]);
    $newBrandId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => '새로운 브랜드가 추가되었습니다.',
        'data' => [
            'id' => $newBrandId,
            'name_ko' => $name
        ]
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류: ' . $e->getMessage()]);
}