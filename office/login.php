<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../config/db_config.php';

// 이미 로그인 + 오피스 권한 있으면 대시보드로
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = '아이디와 비밀번호를 입력하세요.';
    } else {
        try {
            $conn = get_db_connection();

            // is_active, permissions 컬럼 존재 여부 확인 후 쿼리 구성
            $col_res  = $conn->query("SHOW COLUMNS FROM users LIKE 'is_active'");
            $has_active = $col_res && $col_res->num_rows > 0;
            $col_res2 = $conn->query("SHOW COLUMNS FROM users LIKE 'permissions'");
            $has_perms  = $col_res2 && $col_res2->num_rows > 0;

            $select = "SELECT id, username, full_name, password, role, store_id"
                    . ($has_perms ? ", permissions" : "")
                    . " FROM users WHERE username = ?"
                    . ($has_active ? " AND is_active = 1" : "");

            $stmt = $conn->prepare($select);
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                // super_admin / office_staff 또는 accounting_management 권한 확인
                $allowed = false;
                if (in_array($user['role'], ['super_admin', 'office_staff', 'admin'])) {
                    $allowed = true;
                } elseif ($has_perms) {
                    $perms = json_decode($user['permissions'] ?? '{}', true);
                    if (!empty($perms['accounting_management'])) {
                        $allowed = true;
                    }
                }

                if (!$allowed) {
                    $error = '오피스 관리 접근 권한이 없습니다.';
                } else {
                    // 로그인 성공
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
                    $_SESSION['role']      = $user['role'];
                    $_SESSION['store_id']  = $user['store_id'];

                    // 로그인 상태 유지
                    if (!empty($_POST['remember_me'])) {
                        $token  = bin2hex(random_bytes(32));
                        $expiry = date('Y-m-d H:i:s', time() + 86400 * 30);
                        $hashed = password_hash($token, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE users SET remember_token=?, remember_token_expiry=? WHERE id=?");
                        $upd->bind_param('ssi', $hashed, $expiry, $user['id']);
                        $upd->execute();
                        $upd->close();
                        $cookie_val = base64_encode($user['id'] . ':' . $token);
                        setcookie('remember_me', $cookie_val, time() + 86400 * 30, '/', '', isset($_SERVER['HTTPS']), true);
                    }

                    $conn->close();
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            }
            $conn->close();
        } catch (Exception $e) {
            error_log('Office login error: ' . $e->getMessage());
            $error = '데이터베이스 오류가 발생했습니다.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full bg-gray-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>HOME K MART — 오피스 관리 로그인</title>
  <link href="../admin/css/style.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
        integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
        crossorigin="anonymous" referrerpolicy="no-referrer"/>
</head>
<body class="h-full">
  <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">

      <div class="flex justify-center mb-4">
        <div class="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center shadow-lg">
          <i class="fa-solid fa-building-columns text-white text-2xl"></i>
        </div>
      </div>

      <h2 class="text-center text-3xl font-extrabold text-gray-900">HOME K MART</h2>
      <p class="mt-2 text-center text-sm text-gray-500">오피스 관리 시스템</p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
      <div class="bg-white py-8 px-6 shadow-md rounded-xl">

        <?php if ($error !== ''): ?>
        <div class="mb-5 bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3">
          <i class="fa-solid fa-circle-exclamation text-red-400 mt-0.5"></i>
          <p class="text-sm text-red-700"><?php echo htmlspecialchars($error); ?></p>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-5">
          <div>
            <label for="username" class="block text-sm font-medium text-gray-700 mb-1">아이디</label>
            <input id="username" name="username" type="text" required autofocus
                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="아이디 입력">
          </div>

          <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">비밀번호</label>
            <input id="password" name="password" type="password" required
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm
                          focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="비밀번호 입력">
          </div>

          <div class="flex items-center">
            <input id="remember_me" name="remember_me" type="checkbox"
                   class="h-4 w-4 text-blue-600 border-gray-300 rounded">
            <label for="remember_me" class="ml-2 text-sm text-gray-600">로그인 상태 유지</label>
          </div>

          <button type="submit"
                  class="w-full flex justify-center items-center gap-2 py-2.5 px-4
                         bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium
                         rounded-lg shadow-sm transition-colors duration-200">
            <i class="fa-solid fa-right-to-bracket"></i>
            로그인
          </button>
        </form>

      </div>
    </div>
  </div>
</body>
</html>
