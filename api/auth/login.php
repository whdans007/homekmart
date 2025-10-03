<?php
/**
 * 로그인 API
 * POST /api/auth/login
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$data = getRequestBody();

// 필수 필드 검증
validateRequired($data, ['email', 'password']);
validateEmail($data['email']);

$email = trim($data['email']);
$password = $data['password'];

try {
    $pdo = getApiDbConnection();

    // 사용자 조회
    $sql = "SELECT id, username, email, password, full_name, phone, role,
                   auth_provider, preferred_language, is_delivery_available
            FROM users
            WHERE email = ? AND auth_provider = 'email'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        apiError(401, 'Invalid email or password');
    }

    // 비밀번호 검증
    if (!password_verify($password, $user['password'])) {
        apiError(401, 'Invalid email or password');
    }

    // 배달 서비스 이용 가능 여부 확인
    if (!$user['is_delivery_available']) {
        apiError(403, 'Delivery service is not available for this account');
    }

    // JWT 토큰 생성
    $token = generateJWT($user['id'], $user['email'], $user['role']);

    // 비밀번호 제거
    unset($user['password']);

    apiSuccess([
        'token' => $token,
        'user' => $user
    ], 'Login successful');

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    apiError(500, 'Login failed');
}
?>
