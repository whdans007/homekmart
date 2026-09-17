<?php
require_once __DIR__ . '/config/db.php'; // LC_BASE 먼저 정의
require_once __DIR__ . '/lib/auth.php';

lc_session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . LC_BASE . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = t('logistics.login.missing_credentials');
    } else {
        try {
            $conn = get_lc_db();
            $stmt = $conn->prepare(
                "SELECT id, username, full_name, password, role, store_id
                 FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name']= $user['full_name'];
                $_SESSION['role']     = $user['role'];
                $_SESSION['store_id'] = $user['store_id'];
                // is_logistics 세션 캐시 초기화 (permission_helper가 재계산)
                unset($_SESSION['is_logistics']);

                header('Location: ' . LC_BASE . '/index.php');
                exit;
            } else {
                $error = t('logistics.login.invalid_credentials');
            }
        } catch (Exception $e) {
            error_log('logistics login error: ' . $e->getMessage());
            $error = t('logistics.login.database_error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('logistics.login.title')); ?></title>
    <link rel="icon" href="data:,">
    <link href="<?php echo LC_WEB_ROOT; ?>/admin/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
</head>
<body class="h-full">
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <div class="flex justify-center">
                <div class="w-16 h-16 bg-teal-600 rounded-full flex items-center justify-center">
                    <i class="fas fa-warehouse text-white text-2xl"></i>
                </div>
            </div>
            <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                <?php echo htmlspecialchars(t('logistics.login.center_name')); ?>
            </h2>
            <p class="mt-2 text-center text-sm text-gray-600">
                <?php echo htmlspecialchars(t('logistics.login.subtitle')); ?>
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="bg-white py-8 px-4 shadow sm:rounded-lg sm:px-10">

                <?php if ($error): ?>
                <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4 flex items-start">
                    <i class="fas fa-exclamation-circle text-red-400 mt-0.5 mr-3 flex-shrink-0"></i>
                    <p class="text-sm text-red-800"><?php echo htmlspecialchars($error); ?></p>
                </div>
                <?php endif; ?>

                <form method="post" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('logistics.login.username')); ?></label>
                        <div class="mt-1">
                            <input id="username" name="username" type="text" required autocomplete="username"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('logistics.login.password')); ?></label>
                        <div class="mt-1">
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md placeholder-gray-400 focus:outline-none focus:ring-teal-500 focus:border-teal-500 sm:text-sm">
                        </div>
                    </div>

                    <div>
                        <button type="submit"
                                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-teal-600 hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500 transition-colors">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            <?php echo htmlspecialchars(t('logistics.login.submit')); ?>
                        </button>
                    </div>
                </form>

                <div class="mt-6 text-center">
                    <a href="/admin/login.php" class="text-sm text-gray-500 hover:text-gray-700">
                        <i class="fas fa-arrow-left mr-1"></i><?php echo htmlspecialchars(t('logistics.login.admin_login')); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
