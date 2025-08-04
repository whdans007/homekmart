<?php
$page_title = "카테고리 추가 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 카테고리 관리 권한 확인
if (!has_permission('category_management')) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$category_name = '';
$parent_id = null;
$all_categories = [];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 상위 카테고리 선택을 위해 모든 카테고리 목록을 가져옵니다.
    $all_categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $category_name = trim($_POST['name'] ?? '');
        $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

        if (empty($category_name)) {
            $errors[] = "카테고리명을 입력해주세요.";
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->execute([$category_name]);
            if ($stmt->fetch()) {
                $errors[] = "이미 존재하는 카테고리명입니다.";
            } else {
                $insert_stmt = $pdo->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
                $insert_stmt->execute([$category_name, $parent_id]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => "카테고리 '" . htmlspecialchars($category_name) . "'이(가) 성공적으로 추가되었습니다."];
                header("Location: category_management.php");
                exit;
            }
        }
    }
} catch (PDOException $e) {
    $errors[] = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="category_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>
                카테고리 목록으로 돌아가기
            </a>
        </div>

        <div class="bg-white shadow-xl rounded-2xl">
            <div class="py-8 px-4 sm:px-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold tracking-tight text-gray-900">새 카테고리 추가</h1>
                    <p class="mt-2 text-sm text-gray-600">새로운 카테고리의 정보를 입력해주세요.</p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
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

                <form action="add_category.php" method="post" class="space-y-6">
                    <div class="grid grid-cols-1 gap-y-6 sm:grid-cols-2 sm:gap-x-4">
                        <div class="sm:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700">카테고리명</label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <i class="fas fa-sitemap text-gray-400"></i>
                                </div>
                                <input type="text" id="name" name="name" class="block w-full rounded-md border-gray-300 pl-10 focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($category_name); ?>" required placeholder="카테고리명">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="parent_id" class="block text-sm font-medium text-gray-700">상위 카테고리 <span class="text-gray-500">(선택 사항)</span></label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <i class="fas fa-folder-open text-gray-400"></i>
                                </div>
                                <select id="parent_id" name="parent_id" class="block w-full rounded-md border-gray-300 pl-10 focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5">
                                    <option value="">-- 상위 카테고리 없음 --</option>
                                    <?php foreach ($all_categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" <?php echo ($parent_id == $cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="pt-5 border-t border-gray-200">
                        <div class="flex justify-end gap-x-3">
                            <a href="category_management.php" class="rounded-md bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">취소</a>
                            <button type="submit" class="inline-flex justify-center rounded-md bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>
                                카테고리 추가
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
