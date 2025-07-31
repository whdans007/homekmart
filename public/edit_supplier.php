<?php
$page_title = "공급처 수정 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$supplier_id = $_GET['id'] ?? null;
if (!$supplier_id || !filter_var($supplier_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 공급처 ID입니다.'];
    header('Location: supplier_management.php');
    exit;
}

$errors = [];
$supplier = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '공급처를 찾을 수 없습니다.'];
        header('Location: supplier_management.php');
        exit;
    }
} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>데이터베이스 연결에 실패했습니다: " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $supplier['name'] = trim($_POST['name'] ?? '');
    $supplier['phone'] = trim($_POST['phone'] ?? '');
    $supplier['memo'] = trim($_POST['memo'] ?? '');

    if (empty($supplier['name'])) {
        $errors[] = "거래처명을 입력해주세요.";
    }

    if (empty($errors)) {
        try {
            $update_stmt = $pdo->prepare("UPDATE suppliers SET name = ?, phone = ?, memo = ? WHERE id = ?");
            $update_stmt->execute([$supplier['name'], $supplier['phone'], $supplier['memo'], $supplier_id]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => "공급처 정보가 성공적으로 수정되었습니다."];
            header("Location: supplier_management.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="supplier_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        공급처 목록으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">공급처 정보 수정</h1>
</div>

<form action="edit_supplier.php?id=<?php echo $supplier_id; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6">
    <?php if (!empty($errors)): ?>
        <div class="rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-times-circle text-red-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-red-800">문제가 발생했습니다.</h3>
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

    <div>
        <h2 class="text-lg font-medium leading-6 text-gray-900">공급처 정보</h2>
        <p class="mt-1 text-sm text-gray-500">공급처의 이름, 전화번호, 메모를 수정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="name" class="block text-sm font-medium text-gray-700">거래처명</label>
                <input type="text" id="name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($supplier['name']); ?>" required>
            </div>
            <div class="sm:col-span-3">
                <label for="phone" class="block text-sm font-medium text-gray-700">전화번호</label>
                <input type="text" id="phone" name="phone" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
            </div>
            <div class="sm:col-span-6">
                <label for="memo" class="block text-sm font-medium text-gray-700">중요사항 메모</label>
                <textarea id="memo" name="memo" rows="4" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm"><?php echo htmlspecialchars($supplier['memo'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="supplier_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">취소</a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            정보 저장
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/partials/footer.php'; ?>