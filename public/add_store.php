<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('store.add') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 총괄관리자만 접근 가능
if ($_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('store.access_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$store_name = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $store_name = trim($_POST['name'] ?? '');

    if (empty($store_name)) {
        $errors[] = t('store.name_required');
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id FROM stores WHERE name = ?");
            $stmt->execute([$store_name]);
            if ($stmt->fetch()) {
                $errors[] = t('store.already_exists');
            } else {
                $insert_stmt = $pdo->prepare("INSERT INTO stores (name) VALUES (?)");
                $insert_stmt->execute([$store_name]);

                $_SESSION['flash'] = ['type' => 'success', 'message' => str_replace('{name}', htmlspecialchars($store_name), t('store.added_successfully'))];
                header("Location: store_management.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = str_replace('{error}', $e->getMessage(), t('store.database_error'));
        }
    }
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="store_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>
                <?php echo t('store.back_to_list'); ?>
            </a>
        </div>

        <div class="bg-white shadow-xl rounded-2xl">
            <div class="py-8 px-4 sm:px-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold tracking-tight text-gray-900"><?php echo t('store.new_store'); ?></h1>
                    <p class="mt-2 text-sm text-gray-600"><?php echo t('store.enter_info'); ?></p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('store.solve_errors'); ?></h3>
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

                <form action="add_store.php" method="post" class="space-y-6">
                    <div>
                        <label for="name" class="sr-only"><?php echo t('store.name'); ?></label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <i class="fas fa-store-alt text-gray-400"></i>
                            </div>
                            <input type="text" id="name" name="name" class="block w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-3" value="<?php echo htmlspecialchars($store_name); ?>" required placeholder="<?php echo t('store.name'); ?>">
                        </div>
                    </div>

                    <div class="pt-5 border-t border-gray-200">
                        <div class="flex justify-end gap-x-3">
                            <a href="store_management.php" class="rounded-md bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"><?php echo t('common.cancel'); ?></a>
                            <button type="submit" class="inline-flex justify-center rounded-md bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>
                                <?php echo t('store.add'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
