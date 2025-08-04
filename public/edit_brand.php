<?php
$page_title = "브랜드 수정 - HOME K MART";
require_once __DIR__ . '/partials/header.php';

// 브랜드 관리 권한 확인
if (!has_permission('brand_management')) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>이 페이지에 접근할 권한이 없습니다.</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$brand_id = $_GET['id'] ?? null;
if (!$brand_id || !filter_var($brand_id, FILTER_VALIDATE_INT)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => '유효하지 않은 브랜드 ID입니다.'];
    header('Location: brand_management.php');
    exit;
}

$errors = [];
$brand = null;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("SELECT * FROM brands WHERE id = ?");
    $stmt->execute([$brand_id]);
    $brand = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$brand) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => '브랜드를 찾을 수 없습니다.'];
        header('Location: brand_management.php');
        exit;
    }
} catch (PDOException $e) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>데이터베이스 연결에 실패했습니다: " . htmlspecialchars($e->getMessage()) . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $brand['name_ko'] = trim($_POST['name_ko'] ?? '');
    $brand['name_en'] = trim($_POST['name_en'] ?? '');
    $brand['logo_url'] = trim($_POST['logo_url'] ?? '');

    if (empty($brand['name_ko'])) {
        $errors[] = "브랜드명(한글)을 입력해주세요.";
    }
    if (!empty($brand['logo_url']) && !filter_var($brand['logo_url'], FILTER_VALIDATE_URL)) {
        $errors[] = "유효한 URL 형식이 아닙니다.";
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM brands WHERE name_ko = ? AND id != ?");
            $stmt->execute([$brand['name_ko'], $brand_id]);
            if ($stmt->fetch()) {
                $errors[] = "이미 존재하는 브랜드명입니다.";
            } else {
                $update_stmt = $pdo->prepare("UPDATE brands SET name_ko = ?, name_en = ?, logo_url = ? WHERE id = ?");
                $update_stmt->execute([$brand['name_ko'], $brand['name_en'] ?: null, $brand['logo_url'] ?: null, $brand_id]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => "브랜드 정보가 성공적으로 수정되었습니다."];
                header("Location: brand_management.php");
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
    <a href="brand_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 mb-4">
        <i class="fas fa-arrow-left mr-2"></i>
        브랜드 목록으로 돌아가기
    </a>
    <h1 class="text-3xl font-bold text-gray-900">브랜드 정보 수정</h1>
</div>

<form action="edit_brand.php?id=<?php echo $brand_id; ?>" method="post" class="space-y-8 bg-white shadow sm:rounded-lg p-6">
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
        <h2 class="text-lg font-medium leading-6 text-gray-900">브랜드 정보</h2>
        <p class="mt-1 text-sm text-gray-500">브랜드의 한글, 영문 이름 및 로고를 수정합니다.</p>
        <div class="mt-6 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <label for="name_ko" class="block text-sm font-medium text-gray-700">브랜드명 (한글)</label>
                <div class="mt-1 flex rounded-md shadow-sm">
                    <input type="text" id="name_ko" name="name_ko" class="flex-1 min-w-0 block w-full px-3 py-2 rounded-none rounded-l-md focus:ring-primary-500 focus:border-primary-500 sm:text-sm border-gray-300" value="<?php echo htmlspecialchars($brand['name_ko']); ?>" required>
                    <button type="button" id="convert_to_en_btn" class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-50 text-gray-500 text-sm hover:bg-gray-100">
                        <i class="fas fa-language mr-1"></i> 영문 변환
                    </button>
                </div>
            </div>
            <div class="sm:col-span-3">
                <label for="name_en" class="block text-sm font-medium text-gray-700">브랜드명 (영문)</label>
                <input type="text" id="name_en" name="name_en" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($brand['name_en'] ?? ''); ?>">
            </div>
            <div class="sm:col-span-6">
                <label for="logo_url" class="block text-sm font-medium text-gray-700">로고 URL</label>
                <div class="mt-1 flex items-center gap-x-3">
                    <?php if (!empty($brand['logo_url'])): ?>
                        <img src="<?php echo htmlspecialchars($brand['logo_url']); ?>" alt="Logo preview" class="h-12 w-12 rounded-lg object-cover">
                    <?php else: ?>
                        <div class="h-12 w-12 rounded-lg bg-gray-100 flex items-center justify-center">
                            <i class="fas fa-image text-gray-400"></i>
                        </div>
                    <?php endif; ?>
                    <input type="url" id="logo_url" name="logo_url" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-primary-500 focus:border-primary-500 sm:text-sm" value="<?php echo htmlspecialchars($brand['logo_url'] ?? ''); ?>" placeholder="https://example.com/logo.png">
                </div>
            </div>
        </div>
    </div>

    <div class="pt-8 border-t border-gray-200 flex justify-end gap-x-3">
        <a href="brand_management.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">취소</a>
        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
            <i class="fas fa-save mr-2"></i>
            정보 저장
        </button>
    </div>
</form>

<script src="js/kroman.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const nameKoInput = document.getElementById('name_ko');
    const nameEnInput = document.getElementById('name_en');
    const convertBtn = document.getElementById('convert_to_en_btn');

    convertBtn.addEventListener('click', function() {
        const koreanText = nameKoInput.value;
        if (koreanText) {
            const englishText = kroman.parse(koreanText.normalize('NFC'));
            // "ddu"를 "ttu"로 변환 (대소문자 구분 없이)
            let transformedText = englishText.replace(/ddu/gi, 'ttu');
            // 모두 대문자로 변환하고 하이픈 제거
            nameEnInput.value = transformedText.toUpperCase().replace(/-/g, '');
        }
    });
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
