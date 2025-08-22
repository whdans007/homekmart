<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('brand.add') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 브랜드 관리 권한 확인
if (!has_permission('brand_management')) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('brand.access_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$brand_name_ko = '';
$brand_name_en = '';
$logo_url = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $brand_name_ko = trim($_POST['name_ko'] ?? '');
    $brand_name_en = trim($_POST['name_en'] ?? '');
    $logo_url = trim($_POST['logo_url'] ?? '');

    if (empty($brand_name_en)) {
        $errors[] = t('brand.name_en_required');
    }
    if (!empty($logo_url) && !filter_var($logo_url, FILTER_VALIDATE_URL)) {
        $errors[] = t('brand.invalid_url');
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id FROM brands WHERE name_en = ?");
            $stmt->execute([$brand_name_en]);
            if ($stmt->fetch()) {
                $errors[] = t('brand.name_en_exists');
            } else {
                $insert_stmt = $pdo->prepare("INSERT INTO brands (name_ko, name_en, logo_url) VALUES (?, ?, ?)");
                $insert_stmt->execute([$brand_name_ko, $brand_name_en ?: null, $logo_url ?: null]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => str_replace('{name}', htmlspecialchars($brand_name_ko ?: $brand_name_en), t('brand.added_successfully_brand'))];
                header("Location: brand_management.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = str_replace('{error}', $e->getMessage(), t('brand.database_error'));
        }
    }
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="brand_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>
                <?php echo t('brand.back_to_brand_list'); ?>
            </a>
        </div>

        <div class="bg-white shadow-xl rounded-2xl">
            <div class="py-8 px-4 sm:px-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold tracking-tight text-gray-900"><?php echo t('brand.new_brand'); ?></h1>
                    <p class="mt-2 text-sm text-gray-600"><?php echo t('brand.enter_info'); ?></p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('brand.solve_errors'); ?></h3>
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

                <form action="add_brand.php" method="post" class="space-y-6">
                    <div class="grid grid-cols-1 gap-y-6 sm:grid-cols-2 sm:gap-x-4">
                        <div class="sm:col-span-2">
                            <label for="name_ko" class="block text-sm font-medium text-gray-700"><?php echo t('brand.name_ko'); ?></label>
                            <div class="mt-1 flex rounded-md shadow-sm">
                                <div class="relative flex-grow focus-within:z-10">
                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                        <i class="fas fa-tag text-gray-400"></i>
                                    </div>
                                    <input type="text" id="name_ko" name="name_ko" class="block w-full rounded-none rounded-l-md border-gray-300 pl-10 focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($brand_name_ko); ?>" placeholder="<?php echo t('brand.name_ko'); ?>">
                                </div>
                                <button type="button" id="convert_to_en_btn" class="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                                    <i class="fas fa-language"></i>
                                    <span><?php echo t('brand.convert_to_english'); ?></span>
                                </button>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="name_en" class="block text-sm font-medium text-gray-700"><?php echo t('brand.name_en'); ?></label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                    <i class="fas fa-spell-check text-gray-400"></i>
                                </div>
                                <input type="text" id="name_en" name="name_en" class="block w-full rounded-md border-gray-300 pl-10 focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($brand_name_en); ?>" required placeholder="Brand Name (English)">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="logo_url" class="block text-sm font-medium text-gray-700"><?php echo t('brand.logo_url'); ?> <span class="text-gray-500">(<?php echo t('forms.optional'); ?>)</span></label>
                        <div class="mt-1 relative rounded-md shadow-sm">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-link text-gray-400"></i>
                            </div>
                            <input type="url" id="logo_url" name="logo_url" class="block w-full rounded-md border-gray-300 pl-10 focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($logo_url); ?>" placeholder="https://example.com/logo.png">
                        </div>
                    </div>

                    <div class="pt-5 border-t border-gray-200">
                        <div class="flex justify-end gap-x-3">
                            <a href="brand_management.php" class="rounded-md bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"><?php echo t('common.cancel'); ?></a>
                            <button type="submit" class="inline-flex justify-center rounded-md bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>
                                <?php echo t('brand.add'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

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
