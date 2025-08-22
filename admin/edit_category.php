<?php
$page_title = "카테고리 수정 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 카테고리 관리 권한 확인
if (!has_permission('category_management')) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$category_id = $_GET['id'] ?? null;
if (!$category_id || !filter_var($category_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 카테고리 ID입니다.'];
    header('Location: category_management.php');
    exit;
}

$errors = [];
$category = null;
$all_categories = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 수정할 카테고리 정보 가져오기
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$category_id]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$category) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '카테고리를 찾을 수 없습니다.'];
        header('Location: category_management.php');
        exit;
    }

    // 자기 자신을 제외한 모든 카테고리 목록 가져오기 (상위 카테고리 선택용)
    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE id != ? ORDER BY name ASC");
    $stmt->execute([$category_id]);
    $all_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>데이터베이스 연결에 실패했습니다: " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category['name'] = trim($_POST['name'] ?? '');
    $category['parent_id'] = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    if (empty($category['name'])) {
        $errors[] = "카테고리명을 입력해주세요.";
    }

    if ($category['parent_id'] === (int)$category_id) {
        $errors[] = "자기 자신을 상위 카테고리로 지정할 수 없습니다.";
    }

    if (empty($errors)) {
        try {
            // 이름 중복 확인
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $stmt->execute([$category['name'], $category_id]);
            if ($stmt->fetch()) {
                $errors[] = "이미 존재하는 카테고리명입니다.";
            } else {
                $update_stmt = $pdo->prepare("UPDATE categories SET name = ?, parent_id = ? WHERE id = ?");
                $update_stmt->execute([$category['name'], $category['parent_id'], $category_id]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => "카테고리 정보가 성공적으로 수정되었습니다."];
                header("Location: category_management.php");
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
    <a href="category_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        카테고리 목록으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">카테고리 정보 수정</h1>
</div>

<form action="edit_category.php?id=<?php echo $category_id; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6 max-w-xl">
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
        <h2 class="text-lg font-medium leading-6 text-gray-900">카테고리 정보</h2>
        <p class="mt-1 text-sm text-gray-500">카테고리의 이름과 상위 카테고리를 수정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="name" class="block text-sm font-medium text-gray-700">카테고리명</label>
                <input type="text" id="name" name="name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($category['name']); ?>" required>
            </div>
            <div class="sm:col-span-3">
                <label for="parent_id" class="block text-sm font-medium text-gray-700">상위 카테고리</label>
                <select id="parent_id" name="parent_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                    <option value="">-- 상위 카테고리 없음 --</option>
                    <?php foreach ($all_categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($category['parent_id'] == $cat['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="category_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">취소</a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            정보 저장
        </button>
    </div>
</form>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
