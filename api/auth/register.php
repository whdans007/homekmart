<?php
/**
 * 회원가입 API
 * POST /api/auth/register
 */

require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Method not allowed');
}

$data = getRequestBody();

// 필수 필드 검증
validateRequired($data, ['email', 'password', 'full_name', 'phone']);
validateEmail($data['email']);

$email = trim($data['email']);
$password = $data['password'];
$full_name = trim($data['full_name']);
$phone = trim($data['phone']);
$username = $data['username'] ?? strtolower(str_replace(' ', '', $full_name));
$preferred_language = $data['preferred_language'] ?? 'en';

// 비밀번호 강도 확인 (최소 6자)
if (strlen($password) < 6) {
    apiError(400, 'Password must be at least 6 characters long');
}

try {
    $pdo = getApiDbConnection();

    // 이메일 중복 확인
    $check_sql = "SELECT id FROM users WHERE email = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$email]);

    if ($check_stmt->fetch()) {
        apiError(409, 'Email already exists');
    }

    // 사용자 생성
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $insert_sql = "
        INSERT INTO users (
            username, email, password, full_name, phone,
            role, auth_provider, preferred_language,
            is_delivery_available, created_at
        ) VALUES (?, ?, ?, ?, ?, 'user', 'email', ?, TRUE, NOW())
    ";

    $stmt = $pdo->prepare($insert_sql);
    $stmt->execute([
        $username,
        $email,
        $hashed_password,
        $full_name,
        $phone,
        $preferred_language
    ]);

    $user_id = $pdo->lastInsertId();

    // JWT 토큰 생성
    $token = generateJWT($user_id, $email, 'user');

    // 사용자 정보 조회
    $user_sql = "SELECT id, username, email, full_name, phone, role, preferred_language, created_at
                 FROM users WHERE id = ?";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch();

    apiSuccess([
        'token' => $token,
        'user' => $user
    ], 'Registration successful');

} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    apiError(500, 'Registration failed');
}
?>
