<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('wholesale.add_customer') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

// 도매판매 권한 확인
if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = [
        'type' => 'error', 
        'message' => t('messages.permission_denied')
    ];
    header('Location: shop.php');
    exit;
}

$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 검증
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $memo = trim($_POST['memo'] ?? '');
    
    if (empty($name)) {
        $errors[] = t('add_wholesale_customer.name_required');
    }
    
    if (empty($errors)) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // 중복 거래처명 확인
            $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM wholesale_customers WHERE name = ? AND is_active = 1");
            $check_stmt->execute([$name]);
            
            if ($check_stmt->fetchColumn() > 0) {
                $errors[] = t('add_wholesale_customer.name_already_exists');
            } else {
                // 거래처 추가
                $stmt = $pdo->prepare("
                    INSERT INTO wholesale_customers (name, phone, address, memo, is_active, created_at) 
                    VALUES (?, ?, ?, ?, 1, NOW())
                ");
                
                if ($stmt->execute([$name, $phone, $address, $memo])) {
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'message' => t('add_wholesale_customer.save_success')
                    ];
                    header('Location: wholesale_customer_management.php');
                    exit;
                } else {
                    $errors[] = t('add_wholesale_customer.save_error');
                }
            }
        } catch (PDOException $e) {
            $errors[] = t('add_wholesale_customer.database_error') . $e->getMessage();
        }
    }
}

// 플래시 메시지 표시
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

?>

<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="max-w-2xl mx-auto">
        <div class="mb-6">
            <nav class="flex" aria-label="Breadcrumb">
                <ol class="flex items-center space-x-2">
                    <li>
                        <a href="wholesale_customer_management.php" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-users mr-1"></i>
                            <?php echo t('navigation.wholesale_customer_management'); ?>
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                            <span class="text-gray-600"><?php echo t('wholesale.add_customer'); ?></span>
                        </div>
                    </li>
                </ol>
            </nav>
        </div>

        <div class="bg-white shadow-sm rounded-lg border">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-xl font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2 text-primary-500"></i>
                    <?php echo t('wholesale.add_customer'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars(t('add_wholesale_customer.page_description')); ?></p>
            </div>

            <div class="px-6 py-4">
                <?php if (isset($flash)): ?>
                    <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $flash['type'] === 'error' ? 'fa-exclamation-triangle text-red-400' : 'fa-check-circle text-green-400'; ?>"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>">
                                    <?php echo htmlspecialchars($flash['message']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-red-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800"><?php echo htmlspecialchars(t('add_wholesale_customer.solve_errors')); ?></h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_name'); ?> <span class="text-red-500"><?php echo t('add_wholesale_customer.required_field'); ?></span>
                        </label>
                        <input type="text" name="name" id="name" required
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="<?php echo htmlspecialchars(t('add_wholesale_customer.name_placeholder')); ?>">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_phone'); ?>
                        </label>
                        <input type="tel" name="phone" id="phone"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                               placeholder="<?php echo htmlspecialchars(t('add_wholesale_customer.phone_placeholder')); ?>">
                    </div>

                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_address'); ?>
                        </label>
                        <textarea name="address" id="address" rows="3"
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="<?php echo htmlspecialchars(t('add_wholesale_customer.address_placeholder')); ?>"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label for="memo" class="block text-sm font-medium text-gray-700">
                            <?php echo t('wholesale.customer_memo'); ?>
                        </label>
                        <textarea name="memo" id="memo" rows="4"
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                                  placeholder="<?php echo htmlspecialchars(t('add_wholesale_customer.memo_placeholder')); ?>"><?php echo htmlspecialchars($_POST['memo'] ?? ''); ?></textarea>
                    </div>

                    <div class="flex justify-end space-x-4 pt-4">
                        <a href="wholesale_customer_management.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-arrow-left mr-2"></i>
                            <?php echo t('common.cancel'); ?>
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>
                            <?php echo t('common.save'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>