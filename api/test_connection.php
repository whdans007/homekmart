<?php
/**
 * API 연결 테스트
 */

require_once __DIR__ . '/config.php';

try {
    // 데이터베이스 연결 테스트
    $pdo = getApiDbConnection();

    // 기본 쿼리 테스트
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $user_count = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM products");
    $product_count = $stmt->fetch()['count'];

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM stores");
    $store_count = $stmt->fetch()['count'];

    // 성공 응답
    apiSuccess([
        'database' => 'connected',
        'stats' => [
            'users' => (int)$user_count,
            'products' => (int)$product_count,
            'stores' => (int)$store_count
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], 'API connection test successful');

} catch (Exception $e) {
    apiError(500, 'Connection test failed', $e->getMessage());
}
?>
