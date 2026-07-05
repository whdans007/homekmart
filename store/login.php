<?php
require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// 이미 로그인된 점포 사용자라면 바로 주문 페이지로
if (!empty($_SESSION['user_id']) && !empty($_SESSION['store_id'])) {
    header('Location: ' . STORE_BASE . '/order.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        try {
            $conn = get_store_db();
            $st = $conn->prepare(
                "SELECT u.id, u.username, u.full_name, u.password, u.role, u.store_id
                 FROM users u
                 WHERE u.username = ? LIMIT 1"
            );
            $st->bind_param('s', $username);
            $st->execute();
            $user = $st->get_result()->fetch_assoc();
            $st->close();
            $conn->close();

            if ($user && password_verify($password, $user['password'])) {
                if (empty($user['store_id'])) {
                    $error = 'This account is not linked to any store. Please contact the logistics center administrator.';
                } else {
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role']      = $user['role'];
                    $_SESSION['store_id']  = $user['store_id'];
                    header('Location: ' . STORE_BASE . '/order.php');
                    exit;
                }
            } else {
                $error = 'Incorrect username or password.';
            }
        } catch (Exception $e) {
            $error = 'DB Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please enter your username and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Store Login</title>
<link rel="icon" href="data:,">
<link href="<?php echo STORE_WEB_ROOT; ?>/admin/css/style.css" rel="stylesheet">
</head>
<body class="min-h-screen bg-gray-50 flex items-center justify-center px-4">
<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <div class="w-14 h-14 bg-teal-600 rounded-2xl flex items-center justify-center mx-auto mb-3">
            <i class="fas fa-store text-white text-2xl"></i>
        </div>
        <h1 class="text-xl font-bold text-gray-900">Store Order System</h1>
        <p class="text-sm text-gray-500 mt-1">For Store Staff Only</p>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4 text-sm text-red-700">
        <i class="fas fa-exclamation-circle mr-1"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="post" class="bg-white rounded-xl border border-gray-200 p-6 space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
            <input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                   autofocus autocomplete="username"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" autocomplete="current-password"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
        </div>
        <button type="submit"
                class="w-full py-3 bg-teal-600 text-white font-semibold rounded-lg hover:bg-teal-700 transition-colors">
            Login
        </button>
    </form>
</div>
</body>
</html>
