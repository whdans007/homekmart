<?php
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';

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

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 지점 목록 가져오기
    $stores = $pdo->query("SELECT id, name FROM stores ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $errors[] = "데이터베이스에 연결할 수 없어 지점 목록을 불러오지 못했습니다.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $pdo) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $store_id = !empty($_POST['store_id']) ? (int)$_POST['store_id'] : null;

    // 유효성 검사
    if (empty($username)) $errors[] = "아이디를 입력해주세요.";
    if (empty($full_name)) $errors[] = "이름을 입력해주세요.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "올바른 이메일 형식이 아닙니다.";
    if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = "비밀번호는 8자 이상의 영문, 숫자를 포함해야 합니다.";
    }
    if ($password !== $password_confirm) $errors[] = "비밀번호가 일치하지 않습니다.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $errors[] = "이미 사용 중인 아이디 또는 이메일입니다.";
            } else {
                $user_count = $pdo->query("SELECT COUNT(id) FROM users")->fetchColumn();
                $role = ($user_count == 0) ? 'super_admin' : 'user';
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $insert_stmt = $pdo->prepare(
                    "INSERT INTO users (username, full_name, email, password, store_id, role) VALUES (?, ?, ?, ?, ?, ?)"
                );
                $insert_stmt->execute([$username, $full_name, $email, $hashed_password, $store_id, $role]);

                $success_message = "회원가입이 완료되었습니다. 3초 후 로그인 페이지로 이동합니다.";
            }
        } catch (PDOException $e) {
            $errors[] = "회원가입 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko" class="h-full bg-gray-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HOME K MART - 회원가입</title>
    <?php if (!empty($success_message)): ?>
    <meta http-equiv="refresh" content="3;url=login.php">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;500;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Noto Sans KR"', 'sans-serif'],
                    },
                    colors: {
                        primary: { 50:'#eff6ff', 100:'#dbeafe', 200:'#bfdbfe', 300:'#93c5fd', 400:'#60a5fa', 500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8', 800:'#1e40af', 900:'#1e3a8a' }
                    }
                }
            }
        }
    </script>
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
    </style>
</head>
<body class="h-full font-sans text-gray-900 antialiased">
    <div class="min-h-full flex flex-col justify-center items-center py-12 px-4 sm:px-6 lg:px-8 bg-gray-50">
        <div class="w-full max-w-md space-y-6">
            <div class="text-center">
                <a href="index.php" class="inline-block">
                    <div class="w-16 h-16 bg-primary-600 rounded-2xl flex items-center justify-center shadow-md hover:bg-primary-700 transition-all duration-300 transform hover:scale-110">
                        <i class="fas fa-store text-white text-3xl"></i>
                    </div>
                </a>
                <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900">
                    새로운 계정을 등록하세요
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    이미 계정이 있으신가요?
                    <a href="login.php" class="font-medium text-primary-600 hover:text-primary-500">
                        로그인하기
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
                                <h3 class="text-sm font-medium text-red-800">다음 오류를 해결해주세요.</h3>
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
                        <label for="username" class="form-label"><i class="fas fa-user mr-2 text-gray-400"></i>아이디</label>
                    </div>
                    <div class="form-group">
                        <input id="full_name" name="full_name" type="text" placeholder=" " value="<?php echo htmlspecialchars($full_name); ?>" required class="form-input">
                        <label for="full_name" class="form-label"><i class="fas fa-id-card mr-2 text-gray-400"></i>이름</label>
                    </div>
                    <div class="form-group">
                        <input id="email" name="email" type="email" placeholder=" " value="<?php echo htmlspecialchars($email); ?>" required class="form-input">
                        <label for="email" class="form-label"><i class="fas fa-envelope mr-2 text-gray-400"></i>이메일 주소</label>
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
                        <label for="store_id" class="form-label"><i class="fas fa-store-alt mr-2 text-gray-400"></i>소속 지점 (선택)</label>
                    </div>
                    <div class="form-group">
                        <input id="password" name="password" type="password" placeholder=" " required class="form-input">
                        <label for="password" class="form-label"><i class="fas fa-lock mr-2 text-gray-400"></i>비밀번호</label>
                        <p class="mt-2 text-xs text-gray-500">8자 이상의 영문, 숫자를 포함해야 합니다.</p>
                    </div>
                    <div class="form-group">
                        <input id="password_confirm" name="password_confirm" type="password" placeholder=" " required class="form-input">
                        <label for="password_confirm" class="form-label"><i class="fas fa-check-double mr-2 text-gray-400"></i>비밀번호 확인</label>
                    </div>
                    
                    <div class="pt-4">
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-all duration-200 transform hover:scale-105">
                            <i class="fas fa-user-plus mr-2"></i>
                            계정 만들기
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>