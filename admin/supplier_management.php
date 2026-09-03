<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = t('supplier.management') . ' - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';

// 공급처 관리 권한 확인
if (!has_permission('supplier_management') && $_SESSION['role'] !== 'super_admin') {
    echo "<div class='bg-red-50 border border-red-200 rounded-md p-4 mb-6'>
            <div class='flex'>
                <div class='flex-shrink-0'>
                    <i class='fas fa-exclamation-circle text-red-400'></i>
                </div>
                <div class='ml-3'>
                    <p class='text-sm text-red-800'><?php echo t('messages.permission_denied'); ?></p>
                </div>
            </div>
          </div>";
    require_once __DIR__ . '/partials/footer.php';
    exit;
}

// Flash message system
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

require_once __DIR__ . '/../config/db_config.php';

$suppliers = [];
$error_message = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT id, name, phone, memo, created_at FROM suppliers ORDER BY id DESC");
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = t('supplier.load_error') . ": " . $e->getMessage();
}
?>

<div class="w-full px-2 sm:px-3 md:px-4 py-8">

<?php if ($flash): ?>
    <div class="mb-6 <?php echo $flash['type'] === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?> rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle text-green-400' : 'fa-exclamation-circle text-red-400'; ?>"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm <?php echo $flash['type'] === 'success' ? 'text-green-800' : 'text-red-800'; ?>"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error_message): ?>
    <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-400"></i>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Suppliers Table -->
    <form action="merge_suppliers.php" method="post" id="supplierForm">
    <div class="bg-white shadow-lg rounded-lg overflow-hidden ring-1 ring-gray-400">
        <div class="px-6 py-4 border-b border-gray-200 bg-white flex flex-wrap gap-3 justify-between items-center">
            <h3 class="text-lg leading-6 font-semibold text-gray-900">
                <?php echo t('supplier.list'); ?>
                <span id="supplier_count" class="text-sm font-normal text-gray-500">(총 <?php echo count($suppliers); ?>개)</span>
            </h3>
            <div class="flex gap-2 items-center flex-1 min-w-0 justify-end">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                    <input type="text" id="supplier_search" placeholder="업체명 / 전화번호 / 메모 검색"
                           oninput="filterSuppliers(this.value)"
                           class="pl-9 pr-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-primary-400 focus:border-primary-400 w-64">
                </div>
                <button type="submit" id="merge_btn" disabled
                        class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-gray-300 cursor-not-allowed transition-colors duration-200 whitespace-nowrap">
                    <i class="fas fa-code-merge mr-2"></i>선택 항목 통합 (<span id="merge_count">0</span>)
                </button>
                <a href="add_supplier.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors duration-200 whitespace-nowrap">
                    <i class="fas fa-plus mr-2"></i><?php echo t('supplier.add'); ?>
                </a>
            </div>
        </div>
        <p class="px-6 py-2 text-xs text-gray-500 bg-amber-50 border-b border-amber-100">
            <i class="fas fa-circle-info mr-1"></i>같은 회사인데 이름이 다르게(오타 등) 등록된 공급처가 있다면 체크박스로 2개 이상 선택 후 "선택 항목 통합"을 눌러 하나로 합칠 수 있습니다.
        </p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th scope="col" class="px-4 py-4 text-center">
                            <input type="checkbox" id="select_all" class="rounded border-gray-300">
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">ID</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.name'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.phone'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.memo'); ?></th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider"><?php echo t('supplier.created_at'); ?></th>
                        <th scope="col" class="relative px-6 py-4">
                            <span class="sr-only"><?php echo t('common.actions'); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white" id="supplier_tbody">
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php $search_str = strtolower(($supplier['name'] ?? '') . ' ' . ($supplier['phone'] ?? '') . ' ' . ($supplier['memo'] ?? '')); ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50 transition-colors duration-150"
                            data-search="<?php echo htmlspecialchars($search_str, ENT_QUOTES); ?>">
                            <td class="px-4 py-4 text-center">
                                <input type="checkbox" name="ids[]" value="<?php echo (int)$supplier['id']; ?>" class="supplier-checkbox rounded border-gray-300">
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($supplier['id']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($supplier['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo nl2br(htmlspecialchars($supplier['memo'] ?? '-')); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d', strtotime($supplier['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex space-x-2">
                                    <a href="edit_supplier.php?id=<?php echo $supplier['id']; ?>" 
                                       class="text-primary-600 hover:text-primary-900 transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i><?php echo t('common.edit'); ?>
                                    </a>
                                    <a href="delete_supplier.php?id=<?php echo $supplier['id']; ?>" 
                                       class="text-red-600 hover:text-red-900 transition-colors duration-200"
                                       onclick="return confirm('<?php echo addslashes(t('supplier.confirm_delete')); ?>');"> 
                                        <i class="fas fa-trash mr-1"></i><?php echo t('common.delete'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="search_empty" style="display:none">
                        <td colspan="7" class="px-6 py-8 text-center text-sm text-gray-400">
                            <i class="fas fa-search mr-1"></i>검색 결과가 없습니다.
                        </td>
                    </tr>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                                <div class="flex flex-col items-center">
                                    <i class="fas fa-truck text-4xl text-gray-300 mb-4"></i>
                                    <p><?php echo t('supplier.no_suppliers'); ?></p>
                                    <a href="add_supplier.php" class="mt-2 text-primary-600 hover:text-primary-500">
                                        <?php echo t('supplier.add_first_supplier'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </form>
<?php endif; ?>

<script>
function filterSuppliers(q) {
    q = q.toLowerCase().trim();
    const rows = document.querySelectorAll('#supplier_tbody tr[data-search]');
    let visible = 0;
    rows.forEach(row => {
        const match = !q || row.dataset.search.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const total = <?php echo count($suppliers); ?>;
    document.getElementById('supplier_count').textContent =
        q ? `(${visible} / ${total}개)` : `(총 ${total}개)`;
    const empty = document.getElementById('search_empty');
    if (empty) empty.style.display = (q && visible === 0) ? '' : 'none';
}

// ── 공급처 통합 선택 ──────────────────────────────────────────
const selectAll   = document.getElementById('select_all');
const mergeBtn    = document.getElementById('merge_btn');
const mergeCount  = document.getElementById('merge_count');

function checkboxes() {
    return Array.from(document.querySelectorAll('.supplier-checkbox'));
}

function updateMergeBtn() {
    const checked = checkboxes().filter(cb => cb.checked).length;
    mergeCount.textContent = checked;
    const enabled = checked >= 2;
    mergeBtn.disabled = !enabled;
    mergeBtn.classList.toggle('bg-gray-300', !enabled);
    mergeBtn.classList.toggle('cursor-not-allowed', !enabled);
    mergeBtn.classList.toggle('bg-primary-600', enabled);
    mergeBtn.classList.toggle('hover:bg-primary-700', enabled);
}

if (selectAll) {
    selectAll.addEventListener('change', () => {
        checkboxes().forEach(cb => { cb.checked = selectAll.checked; });
        updateMergeBtn();
    });
}
checkboxes().forEach(cb => cb.addEventListener('change', updateMergeBtn));
updateMergeBtn();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>