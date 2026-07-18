<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';

// 이미 로그인했다면 대시보드로 보냅니다.
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$errors = [];
$success_message = '';
$username = '';
$full_name = '';
$email = '';
$store_id = null;
$stores = [];
$pdo = null;

$conn = null;
try {
    $conn = get_db_connection();

    // 지점 목록 가져오기
    $result = $conn->query("SELECT id, name FROM stores ORDER BY name ASC");
    while ($row = $result->fetch_assoc()) {
        $stores[] = $row;
    }

} catch (Exception $e) {
    error_log("register.php DB error: " . $e->getMessage());
    $errors[] = t('messages.database_error');
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $conn) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $store_id = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

    // 유효성 검사
    if (empty($username)) $errors[] = t('forms.username_required');
    if (empty($full_name)) $errors[] = t('forms.full_name_required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = t('forms.invalid_email');
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = t('forms.password_complexity');
    }
    if ($password !== $password_confirm) $errors[] = t('forms.password_mismatch');

    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->bind_param("ss", $username, $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = t('forms.duplicate_user');
            } else {
                $count_result = $conn->query("SELECT COUNT(id) FROM users");
                $user_count = $count_result->fetch_row()[0];
                $role = ($user_count == 0) ? 'super_admin' : 'user';
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $insert_stmt = $conn->prepare(
                    "INSERT INTO users (username, full_name, email, password, store_id, role) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insert_stmt->bind_param("ssssis", $username, $full_name, $email, $hashed_password, $store_id, $role);
                $insert_stmt->execute();
                $insert_stmt->close();

                $success_message = t('auth.register_success');
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("register.php insert error: " . $e->getMessage());
            $errors[] = t('messages.database_error');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo get_language(); ?>" class="h-full bg-gray-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - <?php echo t('auth.register'); ?></title>
    <?php if (!empty($success_message)): ?>
    <meta http-equiv="refresh" content="3;url=login.php">
    <?php endif; ?>
    <link href="css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .form-input {
            display: block;
            width: 100%;
            padding: 0.875rem 1rem;
            font-size: 0.875rem;
            color: #111827;
            background-color: transparent;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            appearance: none;
            -webkit-appearance: none;
            transition: border-color 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #2563eb;
        }
        .form-label {
            position: absolute;
            top: 0.875rem;
            left: 1rem;
            font-size: 0.875rem;
            color: #6b7280;
            background-color: #ffffff;
            padding: 0 0.25rem;
            transition: all 0.2s ease-in-out;
            pointer-events: none;
        }
        .form-input:focus + .form-label,
        .form-input:not(:placeholder-shown) + .form-label {
            top: -0.75rem;
            left: 0.75rem;
            font-size: 0.75rem;
            color: #2563eb;
        }
        select.form-input {
            padding-right: 2.5rem;
        }
        .lang-bar { display: flex; justify-content: flex-end; }
        .lang-select {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.35rem 0.6rem;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .lang-select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.25); }
    </style>
</head>
<body class="h-full font-sans text-gray-900 antialiased">
    <div class="min-h-full flex flex-col justify-center items-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
        <div class="w-full max-w-md space-y-6">
            <div class="lang-bar">
                <select id="language-switcher" class="lang-select">
                    <option value="ko" <?php echo get_language() === 'ko' ? 'selected' : ''; ?>>한국어</option>
                    <option value="en" <?php echo get_language() === 'en' ? 'selected' : ''; ?>>English</option>
                </select>
            </div>
            <div class="text-center">
                <a href="index.php" class="inline-block">
                    <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center shadow-md hover:bg-primary-700 transition-all duration-300 transform hover:scale-110">
                        <i class="fas fa-store text-white text-3xl"></i>
                    </div>
                </a>
                <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900">
                    <?php echo t('auth.register_subtitle'); ?>
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    <?php echo t('auth.have_account'); ?>
                    <a href="login.php" class="font-medium text-primary-600 hover:text-primary-500">
                        <?php echo t('auth.login_now'); ?>
                    </a>
                </p>
            </div>

            <div class="bg-white py-8 px-4 shadow-md rounded-lg sm:px-10">
                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('messages.fix_errors'); ?></h3>
                                <div class="mt-2 text-sm text-red-700">
                                    <ul role="list" class="list-disc pl-5 space-y-1">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($success_message)): ?>
                    <div class="rounded-md bg-green-50 p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-check-circle text-green-400 text-lg"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                <form class="space-y-2" action="register.php" method="post">
                    <div class="form-group">
                        <input id="username" name="username" type="text" placeholder=" " value="<?php echo htmlspecialchars($username); ?>" required class="form-input">
                        <label for="username" class="form-label"><i class="fas fa-user mr-2 text-gray-400"></i><?php echo t('auth.username'); ?></label>
                    </div>
                    <div class="form-group">
                        <input id="full_name" name="full_name" type="text" placeholder=" " value="<?php echo htmlspecialchars($full_name); ?>" required class="form-input">
                        <label for="full_name" class="form-label"><i class="fas fa-id-card mr-2 text-gray-400"></i><?php echo t('user.full_name'); ?></label>
                    </div>
                    <div class="form-group">
                        <input id="email" name="email" type="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required class="form-input">
                        <label for="email" class="form-label"><i class="fas fa-envelope mr-2 text-gray-400"></i><?php echo t('auth.email'); ?></label>
                    </div>
                    <div class="form-group">
                        <select id="store_id" name="store_id" class="form-input">
                            <option value="" disabled <?php echo ($store_id === null) ? 'selected' : ''; ?>> </option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo ($store_id == $store['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="store_id" class="form-label"><i class="fas fa-store-alt mr-2 text-gray-400"></i><?php echo t('auth.store_optional'); ?></label>
                    </div>
                    <div class="form-group">
                        <input id="password" name="password" type="password" placeholder=" " required class="form-input">
                        <label for="password" class="form-label"><i class="fas fa-lock mr-2 text-gray-400"></i><?php echo t('auth.password'); ?></label>
                        <p class="mt-2 text-xs text-gray-500"><?php echo t('user.password_requirements'); ?></p>
                    </div>
                    <div class="form-group">
                        <input id="password_confirm" name="password_confirm" type="password" placeholder=" " required class="form-input">
                        <label for="password_confirm" class="form-label"><i class="fas fa-check-double mr-2 text-gray-400"></i><?php echo t('auth.password_confirm'); ?></label>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200 transform hover:scale-105">
                            <i class="fas fa-user-plus mr-2"></i>
                            <?php echo t('auth.create_account'); ?>
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const languageSwitcher = document.getElementById('language-switcher');
        if (languageSwitcher) {
            languageSwitcher.addEventListener('change', function() {
                const selectedLang = this.value;
                fetch('ajax_set_language.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'language=' + encodeURIComponent(selectedLang)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Language change failed: ' + data.message);
                        this.value = '<?php echo get_language(); ?>';
                    }
                })
                .catch(error => {
                    console.error('Language change error:', error);
                    this.value = '<?php echo get_language(); ?>';
                });
            });
        }
    });
    </script>
</body>
</html>