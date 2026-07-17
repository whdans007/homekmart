<?php
$page_title = "지점 수정 - HOME K MART";
require_once __DIR__ . '/partials/system_header.php';

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/system_footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$store_id = $_GET['id'] ?? null;
if (!$store_id || !filter_var($store_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 지점 ID입니다.'];
    header('Location: store_management.php');
    exit;
}

$errors = [];
$store = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$store) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '지점을 찾을 수 없습니다.'];
        header('Location: store_management.php');
        exit;
    }
} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>데이터베이스 연결에 실패했습니다: " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/system_footer.php';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $store['name'] = trim($_POST['name'] ?? '');
    $store['company_name'] = trim($_POST['company_name'] ?? '');
    $store['phone'] = trim($_POST['phone'] ?? '');
    $store['address'] = trim($_POST['address'] ?? '');
    $store['bank_account'] = trim($_POST['bank_account'] ?? '');

    if (empty($store['name'])) {
        $errors[] = "지점명을 입력해주세요.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM stores WHERE name = ? AND id != ?");
            $stmt->execute([$store['name'], $store_id]);
            if ($stmt->fetch()) {
                $errors[] = "이미 존재하는 지점명입니다.";
            } else {
                $update_stmt = $pdo->prepare("UPDATE stores SET name = ?, company_name = ?, phone = ?, address = ?, bank_account = ? WHERE id = ?");
                $update_stmt->execute([
                    $store['name'],
                    $store['company_name'] !== '' ? $store['company_name'] : null,
                    $store['phone'] !== '' ? $store['phone'] : null,
                    $store['address'] !== '' ? $store['address'] : null,
                    $store['bank_account'] !== '' ? $store['bank_account'] : null,
                    $store_id,
                ]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => "지점 정보가 성공적으로 수정되었습니다."];
                header("Location: store_management.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}
?>

<!-- Page header -->
<div class="mb-8">
    <a href="store_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        지점 목록으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">지점 정보 수정</h1>
</div>

<form action="edit_store.php?id=<?php echo $store_id; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6 max-w-xl">
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
        <h2 class="text-lg font-medium leading-6 text-gray-900">지점 정보</h2>
        <p class="mt-1 text-sm text-gray-500">지점의 이름을 수정합니다.</p>
        <div class="mt-6">
            <label for="name" class="block text-sm font-medium text-gray-700">지점명</label>
            <input type="text" id="name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['name']); ?>" required>
        </div>
        <div class="mt-6">
            <label for="company_name" class="block text-sm font-medium text-gray-700">실제 회사명 (상호)</label>
            <input type="text" id="company_name" name="company_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['company_name'] ?? ''); ?>" placeholder="사업자등록상 상호 / 법인명">
            <p class="mt-1 text-xs text-gray-500">세금계산서 등에 사용되는 실제 회사명을 입력하세요.</p>
        </div>
        <div class="mt-6">
            <label for="phone" class="block text-sm font-medium text-gray-700">전화번호</label>
            <input type="text" id="phone" name="phone" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['phone'] ?? ''); ?>" placeholder="지점 전화번호">
        </div>
        <div class="mt-6">
            <label for="address" class="block text-sm font-medium text-gray-700">주소</label>
            <input type="text" id="address" name="address" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['address'] ?? ''); ?>" placeholder="지점 주소">
        </div>
        <div class="mt-6">
            <label for="bank_account" class="block text-sm font-medium text-gray-700">계좌번호</label>
            <input type="text" id="bank_account" name="bank_account" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['bank_account'] ?? ''); ?>" placeholder="은행명 및 계좌번호">
        </div>
    </div>

    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="store_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">취소</a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            정보 저장
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/partials/system_footer.php'; ?>