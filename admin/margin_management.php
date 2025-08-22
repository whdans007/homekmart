<?php
$page_title = "마진 관리 - HOME K MART";
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 총괄관리자 또는 일반관리자만 접근 가능
if (!in_array($_SESSION['role'], ['super_admin', 'admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$pdo = null;
$error_message = '';
$success_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // POST 요청 처리 (마진 규칙 추가/수정)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_margin_rule'])) {
        $category_id = $_POST['category_id'];
        $margin_percentage = $_POST['margin_percentage'];

        // 이미 해당 카테고리에 규칙이 있는지 확인
        $stmt = $pdo->prepare("SELECT id FROM margin_rules WHERE category_id = ?");
        $stmt->execute([$category_id]);
        
        if ($stmt->fetch()) {
            $error_message = "이미 해당 카테고리에 대한 마진 규칙이 존재합니다.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO margin_rules (category_id, margin_percentage) VALUES (?, ?)");
            if ($stmt->execute([$category_id, $margin_percentage])) {
                $success_message = "새로운 마진 규칙이 추가되었습니다.";
            } else {
                $error_message = "마진 규칙 추가에 실패했습니다.";
            }
        }
    }

    // GET 요청 처리 (마진 규칙 삭제)
    if (isset($_GET['delete_id'])) {
        $delete_id = $_GET['delete_id'];
        $stmt = $pdo->prepare("DELETE FROM margin_rules WHERE id = ?");
        if ($stmt->execute([$delete_id])) {
            $success_message = "마진 규칙이 삭제되었습니다.";
        } else {
            $error_message = "마진 규칙 삭제에 실패했습니다.";
        }
    }

    // 카테고리 목록 가져오기
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 마진 규칙 목록 가져오기
    $sql = "
        SELECT mr.id, c.name as category_name, mr.margin_percentage, mr.updated_at
        FROM margin_rules mr
        JOIN categories c ON mr.category_id = c.id
        ORDER BY c.name ASC
    ";
    $margin_rules = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // 만약 margin_rules 테이블이 없어서 에러가 발생한 경우, 안내 메시지를 표시합니다.
    if ($e->getCode() == '42S02') { // 'Base table or view not found' SQLSTATE
        $error_message = "마진 관리 기능이 아직 활성화되지 않았습니다. <a href='setup_margin_rules.php' class='font-bold text-primary-600 hover:underline'>여기를 클릭</a>하여 데이터베이스 테이블을 생성해주세요.";
    } else {
        $error_message = "데이터베이스 오류가 발생했습니다: " . $e->getMessage();
    }
} catch (Exception $e) {
    $error_message = "오류가 발생했습니다: " . $e->getMessage();
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-900">마진 규칙 관리</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="bg-green-50 border border-green-200 rounded-md p-4 mb-6">
            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
            <p class="text-sm text-red-800"><?php echo $error_message; // HTML 포함 가능하므로 htmlspecialchars 제외 ?></p>
        </div>
    <?php endif; ?>

    <!-- 새 마진 규칙 추가 폼 -->
    <div class="bg-white shadow-lg rounded-lg p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">새 마진 규칙 추가</h2>
        <form action="margin_management.php" method="post" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label for="category_id" class="block text-sm font-medium text-gray-700">카테고리</label>
                <select id="category_id" name="category_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5">
                    <option value="">카테고리 선택</option>
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="margin_percentage" class="block text-sm font-medium text-gray-700">마진율 (%)</label>
                <input type="number" step="0.01" min="0" id="margin_percentage" name="margin_percentage" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" placeholder="예: 30">
            </div>
            <div class="md:col-span-2 flex justify-start">
                <button type="submit" name="add_margin_rule" class="inline-flex items-center justify-center rounded-md border border-transparent bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    <i class="fas fa-plus mr-2"></i> 규칙 추가
                </button>
            </div>
        </form>
    </div>


    <!-- 마진 규칙 목록 -->
    <div class="bg-white shadow-lg rounded-lg overflow-hidden border border-gray-300">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border-collapse border border-gray-300">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">카테고리</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">마진율 (%)</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider border border-gray-300">최근 수정일</th>
                        <th scope="col" class="relative px-6 py-3 border border-gray-300">
                            <span class="sr-only">작업</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($margin_rules)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-12 border border-gray-300">
                                <i class="fas fa-info-circle text-5xl text-gray-400"></i>
                                <h2 class="mt-4 text-lg font-medium text-gray-900">마진 규칙이 없습니다.</h2>
                                <p class="mt-1 text-sm text-gray-500">새로운 마진 규칙을 추가해보세요.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($margin_rules as $rule): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 border border-gray-300"><?php echo htmlspecialchars($rule['category_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo htmlspecialchars($rule['margin_percentage']); ?>%</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 border border-gray-300"><?php echo date('Y-m-d H:i', strtotime($rule['updated_at'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium border border-gray-300">
                                    <a href="margin_management.php?delete_id=<?php echo $rule['id']; ?>" class="text-red-600 hover:text-red-900 ml-4" onclick="return confirm('정말로 이 마진 규칙을 삭제하시겠습니까?');">삭제</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pdo = null;
require_once __DIR__ . '/partials/footer.php';
?>