<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '내 지점 정보 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 점장(branch_manager) 이상만 접근 가능 (지점 변경 승인과 동일한 등급 기준)
// Ref: lib/permission_helper.php - current_user_level(), LEVEL_BRANCH_MANAGER
if (current_user_level() < LEVEL_BRANCH_MANAGER) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

$is_super_admin = ($_SESSION['role'] === 'super_admin');

// super_admin은 ?id= 로 임의 지점을 조회할 수 있지만, 그 외(점장/CEO 등)는
// 항상 본인 소속 지점(store_id)만 조회/수정 가능 (URL 조작으로 타 지점 접근 방지)
if ($is_super_admin && !empty($_GET['id']) && filter_var($_GET['id'], FILTER_VALIDATE_INT)) {
    $store_id = (int)$_GET['id'];
} else {
    $store_id = (int)($current_store_id ?? 0);
}

$errors = [];
$store = null;

if ($store_id <= 0) {
    $errors[] = '소속된 지점 정보가 없습니다. 관리자에게 문의해주세요.';
} else {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM stores WHERE id = ?");
        $stmt->execute([$store_id]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$store) {
            $errors[] = '지점을 찾을 수 없습니다.';
        }
    } catch (PDOException $e) {
        $errors[] = "데이터베이스 연결에 실패했습니다: " . $e->getMessage();
    }
}

if ($store && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
                header("Location: my_store.php" . ($is_super_admin ? "?id=$store_id" : ""));
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
        }
    }
}

$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">내 지점 정보</h1>
    <p class="mt-1 text-sm text-gray-500">소속 지점의 정보를 확인하고 수정할 수 있습니다.</p>
</div>

<?php if ($flash): ?>
    <div class="mb-6 rounded-md <?php echo $flash['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> p-4">
        <p class="text-sm <?php echo $flash['type'] === 'success' ? 'text-green-700' : 'text-red-700'; ?>"><?php echo htmlspecialchars($flash['message']); ?></p>
    </div>
<?php endif; ?>

<?php if (!empty($errors) && !$store): ?>
    <div class="rounded-md bg-red-50 p-4 max-w-xl">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-times-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <ul role="list" class="list-disc pl-5 space-y-1 text-sm text-red-700">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
<?php elseif ($store): ?>
    <form action="my_store.php<?php echo $is_super_admin ? '?id=' . $store_id : ''; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6 max-w-xl">
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
            <div class="mt-6">
                <label for="name" class="block text-sm font-medium text-gray-700">지점명</label>
                <input type="text" id="name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['name']); ?>" required>
            </div>
            <div class="mt-6">
                <label for="company_name" class="block text-sm font-medium text-gray-700">실제 회사명 (상호)</label>
                <input type="text" id="company_name" name="company_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($store['company_name'] ?? ''); ?>" placeholder="사업자등록상 상호 / 법인명">
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
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                <i class="fas fa-save mr-2"></i>
                정보 저장
            </button>
        </div>
    </form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
