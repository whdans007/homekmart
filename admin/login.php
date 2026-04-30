<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

// 이미 로그인했다면 대시보드로 보냅니다.
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = t('forms.required_field');
    } else {
        try {
            $conn = get_db_connection();

            $stmt = $conn->prepare("SELECT id, username, full_name, password, role FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($uid, $uname, $ufull_name, $upassword, $urole);
            $stmt->fetch();
            $stmt->close();

            $user = $uid ? [
                'id'        => $uid,
                'username'  => $uname,
                'full_name' => $ufull_name,
                'password'  => $upassword,
                'role'      => $urole,
            ] : null;

            // 사용자가 존재하고 비밀번호가 일치하는지 확인합니다.
            if ($user && password_verify($password, $user['password'])) {
                // 로그인 성공: 세션에 사용자 정보 저장
                session_regenerate_id(true); // 보안을 위해 세션 ID 갱신
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                // "로그인 상태 유지" 처리
                if (!empty($_POST['remember_me'])) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30일 후 만료

                    $update_stmt = $conn->prepare("UPDATE users SET remember_token = ?, remember_token_expiry = ? WHERE id = ?");
                    $hashed_token = password_hash($token, PASSWORD_DEFAULT);
                    $update_stmt->bind_param("ssi", $hashed_token, $expiry, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $cookie_value = base64_encode($user['id'] . ':' . $token);
                    setcookie('remember_me', $cookie_value, time() + (86400 * 30), '/', '', isset($_SERVER['HTTPS']), true); // HttpOnly
                }

                $conn->close();
                header("Location: index.php");
                exit();
            } else {
                $error_message = t('auth.invalid_credentials');
            }
            $conn->close();
        } catch (Exception $e) {
            error_log("login.php DB error: " . $e->getMessage());
            // 개발 환경에서는 상세 오류 표시, 운영에서는 일반 메시지
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $error_message = '데이터베이스 오류: ' . htmlspecialchars($e->getMessage());
            } else {
                $error_message = t('messages.database_error');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - <?php echo t('auth.login'); ?></title>
    <link href="css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="h-full">
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <!-- Language Switcher -->
            <div class="flex justify-end mb-4">
                <select id="language-switcher" class="text-sm border border-gray-300 rounded-md px-2 py-1 bg-white focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                    <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
                </select>
            </div>
            
            <div class="flex justify-center">
                <div class="w-16 h-16 bg-primary-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-store text-white text-2xl"></i>
                </div>
            </div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                <?php echo t('company.name'); ?>
            </h2>
            <p class="mt-2 text-center text-sm text-gray-600">
                <?php echo t('auth.login'); ?>
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">
                <?php if (!empty($error_message)): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-circle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" action="login.php" method="post">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">
                            <?php echo t('auth.username'); ?>
                        </label>
                        <div class="mt-1">
                            <input id="username" name="username" type="text" required 
                                   class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                   placeholder="<?php echo t('auth.username'); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">
                            <?php echo t('auth.password'); ?>
                        </label>
                        <div class="mt-1">
                            <input id="password" name="password" type="password" required 
                                   class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                   placeholder="<?php echo t('auth.password'); ?>">
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input id="remember_me" name="remember_me" type="checkbox" 
                                   class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                            <label for="remember_me" class="ml-2 block text-sm text-gray-900">
                                <?php echo t('auth.remember_me'); ?>
                            </label>
                        </div>
                    </div>

                    <div>
                        <button type="submit" 
                                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            <?php echo t('auth.login'); ?>
                        </button>
                    </div>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600">
                        Don't have an account?
                        <a href="register.php" class="font-medium text-primary-600 hover:text-primary-500">
                            <?php echo t('auth.register'); ?>
                        </a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Language Switcher for Login Page
    document.addEventListener('DOMContentLoaded', function() {
        const languageSwitcher = document.getElementById('language-switcher');
        if (languageSwitcher) {
            languageSwitcher.addEventListener('change', function() {
                const selectedLang = this.value;
                
                // AJAX로 언어 변경
                fetch('ajax_set_language.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'language=' + encodeURIComponent(selectedLang)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 페이지 새로고침으로 변경된 언어 적용
                        window.location.reload();
                    } else {
                        alert('Language change failed: ' + data.message);
                        // 실패시 이전 선택으로 되돌리기
                        this.value = '<?php echo get_language(); ?>';
                    }
                })
                .catch(error => {
                    console.error('Language change error:', error);
                    alert('An error occurred while changing language.');
                    this.value = '<?php echo get_language(); ?>';
                });
            });
        }
    });
    </script>
</body>
</html>