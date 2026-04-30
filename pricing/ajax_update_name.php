<?php
// 상품명 변경 - 인증 불필요 (독립형 도구)
require_once __DIR__ . '/../config/db_config.php';
header('Content-Type: application/json; charset=utf-8');

function ensureHistoryTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_name_history (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id  INT UNSIGNED NOT NULL,
            language    ENUM('en','ko') NOT NULL,
            old_name    VARCHAR(255) NOT NULL DEFAULT '',
            new_name    VARCHAR(255) NOT NULL DEFAULT '',
            changed_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product (product_id),
            INDEX idx_changed (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

$product_id  = (int)($_POST['product_id'] ?? 0);
$language    = trim($_POST['language'] ?? '');
$product_name = trim($_POST['product_name'] ?? '');

if (!$product_id || !in_array($language, ['en', 'ko'], true) || $product_name === '') {
    echo json_encode(['success' => false, 'message' => '필수 정보가 누락되었습니다.']);
    exit;
}

if (mb_strlen($product_name) > 255) {
    echo json_encode(['success' => false, 'message' => '상품명은 255자 이내로 입력해주세요.']);
    exit;
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 상품 존재 확인
    $stmt = $pdo->prepare("SELECT id, name_en, name_ko FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        echo json_encode(['success' => false, 'message' => '존재하지 않는 상품입니다.']);
        exit;
    }

    $column = $language === 'en' ? 'name_en' : 'name_ko';
    $old_name = $product[$column] ?? '';

    // updated_at 컬럼 존재 여부 확인
    $hasUpdatedAt = (bool)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='products' AND column_name='updated_at'"
    )->fetchColumn();

    $sql = "UPDATE products SET {$column} = ?" . ($hasUpdatedAt ? ", updated_at = NOW()" : "") . " WHERE id = ?";
    $upd = $pdo->prepare($sql);
    $upd->execute([$product_name, $product_id]);

    // 변경 내용이 있을 때만 히스토리 기록
    if ($old_name !== $product_name) {
        ensureHistoryTable($pdo);
        $hist = $pdo->prepare(
            "INSERT INTO product_name_history (product_id, language, old_name, new_name, changed_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $hist->execute([$product_id, $language, $old_name, $product_name]);
    }

    $langText = $language === 'en' ? '영어' : '한글';
    echo json_encode([
        'success'  => true,
        'message'  => "{$langText} 상품명이 변경되었습니다.",
        'data' => [
            'product_id' => $product_id,
            'language'   => $language,
            'new_name'   => $product_name,
        ]
    ]);

} catch (Throwable $e) {
    error_log('pricing/ajax_update_name.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류가 발생했습니다.']);
}
