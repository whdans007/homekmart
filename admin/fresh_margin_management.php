<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('mall_fresh_products.margin_management_title') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/fresh_product_common.php';
require_once __DIR__ . '/../lib/fresh_margin_helper.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'><div class='flex'><div class='flex-shrink-0'><i class='fas fa-exclamation-circle text-red-400'></i></div><div class='ml-3'><p class='text-sm text-red-800'>" . t('messages.permission_denied') . "</p></div></div></div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

$conn = get_db_connection();
$categoryOptions = fresh_category_options();
$transactionStarted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedMargins = $_POST['margin'] ?? [];
        if (!is_array($submittedMargins)) {
            throw new InvalidArgumentException(t('mall_fresh_products.margin_invalid_rate'));
        }

        $validatedMargins = [];
        foreach ($categoryOptions as $category => $label) {
            $rate = $submittedMargins[$category] ?? null;
            if (!is_scalar($rate) || !is_numeric($rate) || (float)$rate < 0 || (float)$rate > 500) {
                throw new InvalidArgumentException(t('mall_fresh_products.margin_invalid_rate'));
            }
            $validatedMargins[$category] = (float)$rate;
        }

        $updatedBy = (int)($_SESSION['user_id'] ?? 0) ?: null;
        $conn->begin_transaction();
        $transactionStarted = true;
        foreach ($validatedMargins as $category => $rate) {
            save_fresh_margin_rate($category, $rate, $updatedBy, $conn);
        }
        $conn->commit();
        fresh_admin_flash('success', t('mall_fresh_products.margin_save_success'));
    } catch (InvalidArgumentException $e) {
        fresh_admin_flash('error', $e->getMessage());
    } catch (mysqli_sql_exception $e) {
        if ($transactionStarted) {
            try {
                $conn->rollback();
            } catch (mysqli_sql_exception $rollbackError) {
                error_log('fresh_margin_management.php rollback error: ' . $rollbackError->getMessage());
            }
        }
        error_log('fresh_margin_management.php save error: ' . $e->getMessage());
        fresh_admin_flash('error', t('mall_fresh_products.margin_save_failed'));
    }
    $conn->close();
    fresh_admin_redirect('fresh_margin_management.php');
}

$marginRates = get_all_fresh_margin_rates($conn);
$conn->close();
$flash = fresh_admin_take_flash();

function fresh_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<div class="w-full px-2 sm:px-3 md:px-4 py-8">
    <div class="mb-4">
        <h1 class="text-lg font-bold text-gray-800"><i class="fas fa-percent mr-2"></i><?php echo fresh_h(t('mall_fresh_products.margin_management_title')); ?></h1>
        <p class="mt-1 text-sm text-gray-500"><?php echo fresh_h(t('mall_fresh_products.margin_management_desc')); ?></p>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 px-4 py-3 text-sm rounded-md border <?php echo $flash['type'] === 'error' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-green-50 text-green-700 border-green-200'; ?>"><?php echo fresh_h($flash['message']); ?></div>
    <?php endif; ?>

    <section class="bg-white rounded-lg border border-gray-200 p-4 max-w-3xl">
        <form method="post" class="space-y-4">
            <?php foreach ($categoryOptions as $category => $label): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 md:gap-4 items-center">
                    <label for="margin-<?php echo fresh_h($category); ?>" class="block text-sm font-semibold text-gray-700"><?php echo fresh_h($label); ?></label>
                    <div>
                        <label for="margin-<?php echo fresh_h($category); ?>" class="block text-xs font-semibold text-gray-600 mb-1"><?php echo fresh_h(t('mall_fresh_products.margin_rate_percent_label')); ?></label>
                        <input type="number" id="margin-<?php echo fresh_h($category); ?>" name="margin[<?php echo fresh_h($category); ?>]" value="<?php echo fresh_h($marginRates[$category]); ?>" min="0" max="500" step="0.01" required class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="pt-2 flex justify-end">
                <button type="submit" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-md"><i class="fas fa-save mr-2"></i><?php echo fresh_h(t('common.save')); ?></button>
            </div>
        </form>
    </section>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
