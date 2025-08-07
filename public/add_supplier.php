<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('supplier.add') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// Check supplier management permission
if (!has_permission('supplier_management')) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('supplier.access_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

require_once __DIR__ . '/../config/db_config.php';

$errors = [];
$name = '';
$phone = '';
$memo = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $memo = trim($_POST['memo'] ?? '');

    if (empty($name)) {
        $errors[] = t('supplier.name_required');
    }

    if (empty($errors)) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("INSERT INTO suppliers (name, phone, memo) VALUES (?, ?, ?)");
            $stmt->execute([$name, $phone, $memo]);

            $_SESSION['flash'] = ['type' => 'success', 'message' => str_replace('{name}', htmlspecialchars($name), t('supplier.added_successfully'))];
            header("Location: supplier_management.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = str_replace('{error}', $e->getMessage(), t('supplier.database_error'));
        }
    }
}
?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <a href="supplier_management.php" class="inline-flex items-center text-sm font-medium text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-2"></i>
                <?php echo t('supplier.back_to_list'); ?>
            </a>
        </div>

        <div class="bg-white shadow-xl rounded-2xl">
            <div class="py-8 px-4 sm:px-10">
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold tracking-tight text-gray-900"><?php echo t('supplier.new_supplier'); ?></h1>
                    <p class="mt-2 text-sm text-gray-600"><?php echo t('supplier.enter_info'); ?></p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-times-circle text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo t('supplier.solve_errors'); ?></h3>
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

                <form action="add_supplier.php" method="post" class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700"><?php echo t('supplier.name'); ?></label>
                        <div class="mt-1">
                            <input type="text" id="name" name="name" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($name); ?>" required placeholder="<?php echo t('supplier.name'); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700"><?php echo t('supplier.phone'); ?></label>
                        <div class="mt-1">
                            <input type="text" id="phone" name="phone" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" value="<?php echo htmlspecialchars($phone); ?>" placeholder="<?php echo t('supplier.phone'); ?>">
                        </div>
                    </div>

                    <div>
                        <label for="memo" class="block text-sm font-medium text-gray-700"><?php echo t('supplier.memo'); ?></label>
                        <div class="mt-1">
                            <textarea id="memo" name="memo" rows="4" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm py-2.5" placeholder="<?php echo t('supplier.memo'); ?>"><?php echo htmlspecialchars($memo); ?></textarea>
                        </div>
                    </div>

                    <div class="pt-5 border-t border-gray-200">
                        <div class="flex justify-end gap-x-3">
                            <a href="supplier_management.php" class="rounded-md bg-white py-2 px-4 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"><?php echo t('common.cancel'); ?></a>
                            <button type="submit" class="inline-flex justify-center rounded-md bg-primary-600 py-2 px-4 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                                <i class="fas fa-plus mr-2"></i>
                                <?php echo t('supplier.add'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>