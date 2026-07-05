<?php
$page_title = 'Product Master - Logistics Center';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';

lc_require_staff();

$search   = trim($_GET['search'] ?? '');
$cat_id   = (int)($_GET['cat'] ?? 0);
$brand_id = (int)($_GET['brand'] ?? 0);
$status   = ($_GET['status'] ?? '') === 'inactive' ? 'inactive' : ''; // '' = 전체(활성 우선), 'inactive' = 비활성만
$page     = max(1, (int)($_GET['page'] ?? 1));
$limit    = 10;
$offset   = ($page - 1) * $limit;

// 현재 리스트 상태(검색/필터/페이지) — 수정 화면 진입 시 함께 넘겨 돌아올 때 복원
$list_query = http_build_query(['search' => $search, 'cat' => $cat_id, 'brand' => $brand_id, 'status' => $status, 'page' => $page]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    lc_verify_csrf();
    $pid = (int)($_POST['product_id'] ?? 0);
    if ($pid > 0) {
        $conn = get_lc_db();
        $st = $conn->prepare("UPDATE lc_products SET is_active = 1 - is_active WHERE id = ?");
        $st->bind_param('i', $pid);
        $st->execute();
        $conn->close();
    }
    header('Location: ' . LC_BASE . '/products.php?' . http_build_query(['search'=>$search,'cat'=>$cat_id,'brand'=>$brand_id,'status'=>$status]));
    exit;
}

try {
    $conn = get_lc_db();
    $brands     = $conn->query("SELECT id, name_en, name_ko FROM lc_brands ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);
    $categories = $conn->query("SELECT id, name_en, name_ko FROM lc_categories ORDER BY name_en ASC")->fetch_all(MYSQLI_ASSOC);

    $conds = []; $params = []; $types = '';
    if ($search) {
        $conds[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ? OR p.barcode_box LIKE ? OR p.barcode_logistics LIKE ?)";
        $params[] = "%$search%"; $params[] = "%$search%";
        $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
        $types .= 'sssss';
    }
    if ($cat_id)   { $conds[] = "p.category_id = ?"; $params[] = $cat_id;   $types .= 'i'; }
    if ($brand_id) { $conds[] = "p.brand_id = ?";    $params[] = $brand_id; $types .= 'i'; }
    if ($status === 'inactive') { $conds[] = "p.is_active = 0"; } // 비활성(DEACTIVE) 상품만
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $cnt = $conn->prepare("SELECT COUNT(*) FROM lc_products p $where");
    if ($params) { $cnt->bind_param($types, ...$params); }
    $cnt->execute();
    $total = (int)$cnt->get_result()->fetch_row()[0]; $cnt->close();
    $total_pages = max(1, (int)ceil($total / $limit));

    $sql = "SELECT p.*, b.name_en AS brand_name, c.name_en AS category_name
            FROM lc_products p
            LEFT JOIN lc_brands b ON p.brand_id = b.id
            LEFT JOIN lc_categories c ON p.category_id = c.id
            $where ORDER BY p.is_active DESC, p.id DESC LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $products = $st->get_result()->fetch_all(MYSQLI_ASSOC); $st->close();
    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage();
    $products = []; $total = 0; $total_pages = 1; $brands = []; $categories = [];
}
?>

<style>
/* products.php 전용: main 스크롤 비활성화, flex 레이아웃으로 뷰포트 꽉 채움 */
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-3 overflow-hidden">

<div class="flex items-center justify-between shrink-0">
    <h2 class="text-xl font-bold text-gray-900">Product Master</h2>
    <a href="<?php echo LC_BASE; ?>/product_add.php"
       class="inline-flex items-center px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
        <i class="fas fa-plus mr-2"></i>Register Product
    </a>
</div>

<form method="get" class="bg-white rounded-lg border border-gray-200 px-3 py-2 shrink-0">
    <div class="flex flex-wrap items-center gap-2">
        <input type="text" id="barcodeSearchInput" name="search" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search by name or barcode"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500 w-48">
        <select name="cat" style="width:9rem" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $cat_id == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name_en'] . ($c['name_ko'] ? ' ('.$c['name_ko'].')' : '')); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="brand" style="width:9rem" class="border border-gray-300 rounded-md px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
            <option value="0">All Brands</option>
            <?php foreach ($brands as $b): ?>
            <option value="<?php echo $b['id']; ?>" <?php echo $brand_id == $b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name_en'] . ($b['name_ko'] ? ' ('.$b['name_ko'].')' : '')); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>">
        <button type="submit" class="px-3 py-1.5 bg-teal-600 text-white text-sm rounded-md hover:bg-teal-700"><i class="fas fa-search mr-1"></i>Search</button>
        <a href="<?php echo LC_BASE; ?>/products.php" class="px-3 py-1.5 bg-gray-100 text-gray-600 text-sm rounded-md hover:bg-gray-200">Reset</a>
        <?php $deactive_qs = http_build_query(['search'=>$search, 'cat'=>$cat_id, 'brand'=>$brand_id, 'status'=> $status === 'inactive' ? '' : 'inactive']); ?>
        <a href="<?php echo LC_BASE; ?>/products.php?<?php echo $deactive_qs; ?>"
           class="px-3 py-1.5 text-sm rounded-md transition-colors <?php echo $status === 'inactive' ? 'bg-red-600 text-white hover:bg-red-700' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>"
           title="비활성(DEACTIVE) 상품만 보기"><i class="fas fa-ban mr-1"></i>DEACTIVE</a>
        <a href="<?php echo LC_BASE; ?>/export_products.php?<?php echo http_build_query(['search'=>$search,'cat'=>$cat_id,'brand'=>$brand_id,'status'=>$status]); ?>"
           class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-md hover:bg-green-700"><i class="fas fa-file-excel mr-1"></i>Excel Download</a>
        <button type="button" onclick="openPrintPreview()"
           class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"><i class="fas fa-print mr-1"></i>Print</button>
        <div class="ml-auto flex gap-2">
            <a href="<?php echo LC_BASE; ?>/category_manage.php" class="px-3 py-1.5 border border-gray-300 text-gray-600 text-xs rounded-md hover:bg-gray-50"><i class="fas fa-folder mr-1"></i>Category Management</a>
            <a href="<?php echo LC_BASE; ?>/brand_manage.php" class="px-3 py-1.5 border border-gray-300 text-gray-600 text-xs rounded-md hover:bg-gray-50"><i class="fas fa-tag mr-1"></i>Brand Management</a>
        </div>
    </div>
</form>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<div id="tableCard" class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 text-sm text-gray-500 shrink-0">Total <?php echo number_format($total); ?> products</div>
    <div id="tableScrollBody" class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Image</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Category</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Brand</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Product Name</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Capacity</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Unit</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Units per Box</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Unit Barcode</th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium">Box Barcode</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Expiry Required</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Status</th>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium">Manage</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($products)): ?>
            <tr><td colspan="12" class="px-4 py-10 text-center text-gray-400">
                <?php if ($search): ?>
                <i class="fas fa-search text-3xl mb-3 block text-gray-300"></i>
                <p class="mb-1"><span class="font-medium text-gray-600">"<?php echo htmlspecialchars($search); ?>"</span> No search results found.</p>
                <p class="text-xs text-gray-400 mb-4">Check product name, barcodes, or logistics code.</p>
                <button type="button"
                        onclick="openConfirmRegister('<?php echo htmlspecialchars(addslashes($search)); ?>')"
                        class="inline-flex items-center px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                    <i class="fas fa-plus mr-2"></i>Register as New Product
                </button>
                <?php else: ?>
                <i class="fas fa-box-open text-3xl mb-2 block text-gray-300"></i>
                No products registered.
                <?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
            <tr data-edit-url="<?php echo htmlspecialchars(LC_BASE . '/product_edit.php?id=' . $p['id'] . '&ret=' . urlencode($list_query)); ?>"
                class="hover:bg-gray-50 cursor-pointer <?php echo !$p['is_active'] ? 'opacity-50' : ''; ?>">
                <td class="px-4 py-3 text-center">
                    <?php if (!empty($p['image_path'])): $img_url = LC_BASE . '/' . $p['image_path']; ?>
                    <button type="button"
                            onclick="openImageLightbox('<?php echo htmlspecialchars(addslashes($img_url), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($p['name_en']), ENT_QUOTES); ?>')"
                            class="inline-block w-10 h-10 rounded border border-gray-200 overflow-hidden bg-gray-50 hover:ring-2 hover:ring-teal-400 align-middle" title="Click to enlarge">
                        <img src="<?php echo htmlspecialchars($img_url); ?>" alt="" class="w-full h-full object-cover">
                    </button>
                    <?php else: ?>
                    <span class="inline-flex items-center justify-center w-10 h-10 rounded border border-gray-100 bg-gray-50 text-gray-300 align-middle"><i class="fas fa-image text-xs"></i></span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($p['category_name'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($p['brand_name'] ?? '-'); ?></td>
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($p['name_en']); ?></div>
                    <?php if ($p['name_ko']): ?>
                    <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($p['name_ko']); ?></div>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($p['capacity'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-gray-600 text-xs"><?php echo htmlspecialchars($p['unit']); ?></td>
                <td class="px-4 py-3 text-gray-600 text-xs text-center"><?php echo $p['pieces_per_box'] > 1 ? $p['pieces_per_box'] : '-'; ?></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($p['barcode_unit'] ?? '-'); ?></td>
                <td class="px-4 py-3 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($p['barcode_box'] ?? '-'); ?></td>
                <td class="px-4 py-3 text-center">
                    <button type="button"
                            onclick="toggleExpiry(<?php echo $p['id']; ?>, this)"
                            data-val="<?php echo (int)$p['requires_expiry']; ?>"
                            title="Click to change"
                            class="expiry-toggle inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium transition-colors
                                   <?php echo $p['requires_expiry'] ? 'bg-orange-100 text-orange-700 hover:bg-orange-200' : 'bg-gray-100 text-gray-400 hover:bg-gray-200'; ?>">
                        <span class="toggle-dot w-2 h-2 rounded-full <?php echo $p['requires_expiry'] ? 'bg-orange-500' : 'bg-gray-300'; ?>"></span>
                        <span class="toggle-label"><?php echo $p['requires_expiry'] ? 'Required' : 'Optional'; ?></span>
                    </button>
                </td>
                <td class="px-4 py-3 text-center">
                    <span class="text-xs px-2 py-1 rounded-full <?php echo $p['is_active'] ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'; ?>">
                        <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-center">
                    <div class="flex items-center justify-center gap-2">
                        <a href="<?php echo LC_BASE; ?>/product_edit.php?id=<?php echo $p['id']; ?>&ret=<?php echo urlencode($list_query); ?>" class="js-edit-link text-teal-600 hover:text-teal-800 text-xs"><i class="fas fa-edit"></i></a>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                            <button type="submit" class="text-gray-400 hover:text-gray-600 text-xs" title="<?php echo $p['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                <i class="fas <?php echo $p['is_active'] ? 'fa-ban' : 'fa-check-circle'; ?>"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <?php
        $window = 10;
        $block_start = (int)(floor(($page - 1) / $window) * $window) + 1;
        $block_end   = min($total_pages, $block_start + $window - 1);
        $qs = ['search' => $search, 'cat' => $cat_id, 'brand' => $brand_id, 'status' => $status];
    ?>
    <div id="tablePagination" class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($block_start > 1): ?>
        <a href="?page=<?php echo $block_start - $window; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Previous 10 pages">
            <i class="fas fa-angle-double-left text-xs"></i>
        </a>
        <?php endif; ?>

        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-left text-xs"></i>
        </a>
        <?php endif; ?>

        <?php for ($i = $block_start; $i <= $block_end; $i++): ?>
        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($qs); ?>"
           class="flex items-center justify-center rounded font-medium transition-colors
                  <?php echo $i === $page
                      ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300'
                      : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
            <?php echo $i; ?>
        </a>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors">
            <i class="fas fa-chevron-right text-xs"></i>
        </a>
        <?php endif; ?>

        <?php if ($block_end < $total_pages): ?>
        <a href="?page=<?php echo $block_end + 1; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="Next 10 pages">
            <i class="fas fa-angle-double-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 확인 모달 -->
<div id="confirmRegisterModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-40"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center w-12 h-12 rounded-full bg-yellow-100 mb-4">
                <i class="fas fa-barcode text-yellow-600 text-xl"></i>
            </div>
            <h3 class="text-base font-semibold text-gray-900 mb-2">No Registered Product Found</h3>
            <p class="text-sm text-gray-500 mb-1">Scanned Barcode: <span id="confirmBarcodeText" class="font-mono font-medium text-gray-700"></span></p>
            <p class="text-sm text-gray-500 mb-6">Would you like to register it as a new product?</p>
            <div class="flex gap-3">
                <button type="button" onclick="openRegisterModal()"
                        class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
                    <i class="fas fa-plus mr-1"></i>Yes
                </button>
                <button type="button" onclick="closeConfirmModal()"
                        class="flex-1 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                    No
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 프린트 미리보기 모달 -->
<div id="printPreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-5xl mx-4 flex flex-col" style="height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-print text-blue-600 mr-2"></i>Print Preview</h3>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printPreviewFrame()"
                        class="px-4 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i>Print
                </button>
                <button type="button" onclick="closePrintPreview()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="flex-1 min-h-0">
            <iframe id="printPreviewFrame" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>

<!-- 상품 등록 모달 -->
<div id="productRegisterModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-2xl mx-4 flex flex-col" style="max-height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-plus-circle text-teal-600 mr-2"></i>Register Product</h3>
            <button type="button" onclick="closeRegisterModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
        </div>
        <div class="overflow-y-auto px-6 py-5 flex-1">
            <div id="regModalError" class="hidden bg-red-50 border border-red-200 rounded-lg p-3 mb-4 text-sm text-red-700"></div>
            <form id="regModalForm" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">

                <div class="border border-teal-200 bg-teal-50 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-teal-800 mb-1"><i class="fas fa-magic mr-1"></i>Import from Existing Product</h4>
                    <p class="text-xs text-teal-700 mb-2">Search by barcode or product name to auto-fill the Korean/English name and units per box.</p>
                    <div class="relative">
                        <input type="text" id="regShopProductSearch" autocomplete="off" placeholder="Search barcode or product name..."
                               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        <div id="regShopProductDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-56 overflow-y-auto">
                            <ul id="regShopProductList" class="py-1"></ul>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Product Name</h4>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">English Name <span class="text-red-500">*</span></label>
                            <div class="flex gap-2">
                                <input type="text" name="name_en" id="regNameEn" placeholder="E.g.: Shin Ramyun, Choco Pie"
                                       class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <button type="button" onclick="regTranslateToKo()"
                                        id="regTransToKoBtn"
                                        class="px-3 py-2 bg-green-500 text-white text-xs font-semibold rounded-md hover:bg-green-600 whitespace-nowrap">Translate ▶ Korean</button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Korean Name <span class="text-xs text-gray-400 font-normal">(Optional)</span></label>
                            <div class="flex gap-2">
                                <input type="text" name="name_ko" id="regNameKo" placeholder="E.g.: Shin Ramyun, Choco Pie"
                                       class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                <button type="button" onclick="regRomanizeKoreanName()"
                                        id="regRomanizeBtn" title="한글을 영문 발음(로마자)으로 변환"
                                        class="px-3 py-2 text-xs font-semibold rounded-md whitespace-nowrap transition-colors"
                                        style="background:#f3e8ff;color:#7e22ce;">발음 ▶ English</button>
                                <button type="button" onclick="regTranslateToEn()"
                                        id="regTransToEnBtn"
                                        class="px-3 py-2 bg-blue-500 text-white text-xs font-semibold rounded-md hover:bg-blue-600 whitespace-nowrap">Translate ▶ English</button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Capacity <span class="text-xs text-gray-400 font-normal">(E.g.: 500ml, 1kg, 20ea)</span></label>
                            <input type="text" name="capacity" id="regCapacity" placeholder="E.g.: 500ml, 1kg, 20ea"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-image text-gray-400 mr-1"></i>Product Image <span class="text-xs text-gray-400 font-normal">(Optional)</span></label>
                            <div class="flex items-start gap-3">
                                <div class="w-20 h-20 rounded-lg border border-gray-200 bg-gray-50 flex items-center justify-center overflow-hidden shrink-0">
                                    <img id="regImgPreview" src="" alt="" class="w-full h-full object-cover hidden">
                                    <span id="regImgPlaceholder" class="text-gray-300 text-2xl"><i class="fas fa-image"></i></span>
                                </div>
                                <div class="flex-1">
                                    <input type="file" name="image" id="regImageInput" accept="image/jpeg,image/png,image/webp,image/gif"
                                           class="block w-full text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-teal-50 file:text-teal-700 hover:file:bg-teal-100">
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG, WEBP, GIF · max 5MB.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Classification</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Brand</label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input type="hidden" name="brand_id" id="regBrandId">
                                    <input type="text" id="regBrandSearch" autocomplete="off" placeholder="Search brand..."
                                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <div id="regBrandDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                        <ul id="regBrandList" class="py-1"></ul>
                                    </div>
                                </div>
                                <button type="button" onclick="openQuickCreateInModal('brand')" title="Add New Brand"
                                        class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 text-sm"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <input type="hidden" name="category_id" id="regCatId">
                                    <input type="text" id="regCatSearch" autocomplete="off" placeholder="Search category..."
                                           class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                                    <div id="regCatDropdown" class="hidden absolute z-10 top-full left-0 right-0 mt-0.5 bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                                        <ul id="regCatList" class="py-1"></ul>
                                    </div>
                                </div>
                                <button type="button" onclick="openQuickCreateInModal('category')" title="Add New Category"
                                        class="shrink-0 px-3 py-2 bg-teal-50 border border-teal-300 text-teal-700 rounded-md hover:bg-teal-100 text-sm"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-3">Unit</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Unit</label>
                            <input type="text" name="unit" value="BOX"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Units per Box</label>
                            <input type="number" name="pieces_per_box" id="regPiecesPerBox" value="1" min="1"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </div>
                </div>

                <div class="border-b border-gray-100 pb-4">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Barcode / Code</h4>
                    <p class="text-xs text-gray-400 mb-3">Enter only applicable codes.</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-barcode text-gray-400 mr-1"></i>Barcode</label>
                            <input type="text" name="barcode_unit" id="regBarcodeUnit" placeholder="Product Barcode"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-box text-gray-400 mr-1"></i>Box Code</label>
                            <input type="text" name="barcode_box" placeholder="Box Unit Barcode"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-warehouse text-gray-400 mr-1"></i>Logistics Code</label>
                            <input type="text" name="barcode_logistics" placeholder="Logistics Center Code"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Stock Threshold</label>
                        <input type="number" name="min_stock" value="0" min="0"
                               class="w-32 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
                    </div>
                    <div class="flex items-start gap-3 p-3 bg-orange-50 border border-orange-200 rounded-lg">
                        <input type="checkbox" name="requires_expiry" id="regRequiresExpiry" value="1"
                               class="mt-0.5 w-4 h-4 text-orange-500 border-gray-300 rounded focus:ring-orange-400">
                        <label for="regRequiresExpiry" class="cursor-pointer">
                            <span class="text-sm font-medium text-gray-800">Expiry Date Required</span>
                            <p class="text-xs text-gray-500 mt-0.5">When checked, expiry date must be entered when receiving this product.</p>
                        </label>
                    </div>
                </div>
            </form>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex gap-3 shrink-0">
            <button type="button" id="regSubmitBtn" onclick="submitRegisterModal()"
                    class="flex-1 px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700">
                <i class="fas fa-save mr-2"></i>Register
            </button>
            <button type="button" onclick="closeRegisterModal()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">Cancel</button>
        </div>
    </div>
</div>

<!-- 이미지 확대 라이트박스 -->
<div id="imageLightbox" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4" onclick="closeImageLightbox()">
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden flex flex-col border-2 border-gray-300 ring-1 ring-black/5" style="width:26rem;max-width:92vw;" onclick="event.stopPropagation()">
        <!-- 헤더: 제목 + 닫기 -->
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
            <span id="lightboxTitle" class="text-sm font-semibold text-gray-800 truncate pr-2"></span>
            <button type="button" onclick="closeImageLightbox()" title="Close"
                    class="text-gray-400 hover:text-gray-700 text-lg flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- 고정 크기 이미지 영역 -->
        <div class="bg-gray-50 flex items-center justify-center" style="height:22rem;">
            <img id="lightboxImg" src="" alt="" class="max-w-full max-h-full object-contain">
        </div>
        <!-- 푸터: 닫기 버튼 -->
        <div class="px-4 py-3 border-t border-gray-100 flex justify-end">
            <button type="button" onclick="closeImageLightbox()"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200">
                Close
            </button>
        </div>
    </div>
</div>
<script>
    window.openImageLightbox = function(src, alt) {
        if (!src) return;
        var img = document.getElementById('lightboxImg');
        img.src = src; img.alt = alt || '';
        document.getElementById('lightboxTitle').textContent = alt || '';
        document.getElementById('imageLightbox').classList.remove('hidden');
    };
    window.closeImageLightbox = function() {
        document.getElementById('imageLightbox').classList.add('hidden');
        document.getElementById('lightboxImg').src = '';
    };
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeImageLightbox();
    });
</script>
<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var CSRF    = '<?php echo htmlspecialchars(lc_csrf_token()); ?>';
    var _regBrands = <?php echo json_encode(array_values($brands), JSON_UNESCAPED_UNICODE); ?>;
    var _regCats   = <?php echo json_encode(array_values($categories), JSON_UNESCAPED_UNICODE); ?>;

    window.toggleExpiry = function(pid, btn) {
        btn.disabled = true;

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('product_id', pid);

        fetch(LC_BASE + '/ajax/toggle_product_expiry.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { alert(data.message || 'An error occurred.'); return; }
                var val = data.requires_expiry;
                btn.dataset.val = val;
                var dot   = btn.querySelector('.toggle-dot');
                var label = btn.querySelector('.toggle-label');
                if (val) {
                    btn.className = btn.className.replace('bg-gray-100 text-gray-400 hover:bg-gray-200', 'bg-orange-100 text-orange-700 hover:bg-orange-200');
                    dot.className   = dot.className.replace('bg-gray-300', 'bg-orange-500');
                    label.textContent = 'Required';
                } else {
                    btn.className = btn.className.replace('bg-orange-100 text-orange-700 hover:bg-orange-200', 'bg-gray-100 text-gray-400 hover:bg-gray-200');
                    dot.className   = dot.className.replace('bg-orange-500', 'bg-gray-300');
                    label.textContent = 'Optional';
                }
            })
            .catch(function() { alert('Request failed.'); })
            .finally(function() { btn.disabled = false; });
    };

    // ─── 행 클릭 → 수정 화면 (리스트 상태/스크롤 저장 후 이동) ──────────
    var scrollBody = document.getElementById('tableScrollBody');

    function saveListScroll() {
        try { sessionStorage.setItem('lc_products_scroll', scrollBody ? scrollBody.scrollTop : 0); } catch (e) {}
    }

    document.querySelectorAll('tr[data-edit-url]').forEach(function(tr) {
        tr.addEventListener('click', function(e) {
            // 버튼/링크/폼/입력 등 인터랙티브 요소 클릭은 행 이동에서 제외
            if (e.target.closest('button, a, form, input, label, select')) return;
            saveListScroll();
            window.location.href = tr.dataset.editUrl;
        });
    });

    // 연필(수정) 링크 클릭 시에도 스크롤 위치 저장
    document.querySelectorAll('a.js-edit-link').forEach(function(a) {
        a.addEventListener('click', saveListScroll);
    });

    // 수정 화면에서 돌아왔을 때 스크롤 위치 복원
    (function() {
        var saved = null;
        try { saved = sessionStorage.getItem('lc_products_scroll'); } catch (e) {}
        if (saved !== null && scrollBody) {
            scrollBody.scrollTop = parseInt(saved, 10) || 0;
            try { sessionStorage.removeItem('lc_products_scroll'); } catch (e) {}
        }
    })();

    // ─── 바코드 스캐너 감지 ───────────────────────────────────────────
    var searchInput = document.getElementById('barcodeSearchInput');
    var _keyTimes = [], _pendingBarcode = '';

    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            _keyTimes.push(Date.now());
            if (_keyTimes.length > 30) _keyTimes.shift();
        });

        searchInput.addEventListener('keyup', function(e) {
            if (e.key !== 'Enter') return;
            var val = searchInput.value.trim();
            if (!val || _keyTimes.length < 2) return;

            // 스캐너 판단: 마지막 N키 평균 간격 < 50ms
            var gaps = 0, cnt = 0;
            for (var i = 1; i < _keyTimes.length; i++) {
                gaps += _keyTimes[i] - _keyTimes[i-1];
                cnt++;
            }
            var avgGap = cnt > 0 ? gaps / cnt : 999;
            _keyTimes = [];

            if (avgGap >= 60) return; // 사람이 직접 타이핑한 것으로 판단 → 일반 검색

            // 스캐너 입력으로 판단 → AJAX로 먼저 확인
            e.preventDefault();
            _pendingBarcode = val;

            fetch(LC_BASE + '/ajax/search_product_by_barcode.php?barcode=' + encodeURIComponent(val))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        // 결과 있음 → 일반 폼 제출
                        searchInput.closest('form').submit();
                    } else {
                        // 결과 없음 → 확인 모달
                        openConfirmRegister(val);
                    }
                })
                .catch(function() {
                    searchInput.closest('form').submit();
                });
        });
    }

    // ─── 확인 모달 ────────────────────────────────────────────────────
    window.openConfirmRegister = function(barcode) {
        _pendingBarcode = barcode;
        document.getElementById('confirmBarcodeText').textContent = barcode;
        document.getElementById('confirmRegisterModal').classList.remove('hidden');
    };

    window.closeConfirmModal = function() {
        document.getElementById('confirmRegisterModal').classList.add('hidden');
    };

    // ─── 등록 모달 ────────────────────────────────────────────────────
    window.openRegisterModal = function() {
        closeConfirmModal();
        document.getElementById('regModalForm').reset();
        document.getElementById('regModalError').classList.add('hidden');
        document.getElementById('regBarcodeUnit').value = _pendingBarcode || '';
        document.getElementById('regCapacity').value = '';
        document.getElementById('regShopProductSearch').value = '';
        document.getElementById('regShopProductDropdown').classList.add('hidden');
        // 이미지 미리보기 초기화
        var rImg = document.getElementById('regImgPreview'), rPh = document.getElementById('regImgPlaceholder');
        if (rImg) { rImg.src = ''; rImg.classList.add('hidden'); }
        if (rPh)  { rPh.classList.remove('hidden'); }
        if (window._regBrandWidget) window._regBrandWidget.reset();
        if (window._regCatWidget)   window._regCatWidget.reset();
        document.getElementById('productRegisterModal').classList.remove('hidden');
        setTimeout(function() { document.getElementById('regNameEn').focus(); }, 50);
    };

    window.closeRegisterModal = function() {
        document.getElementById('productRegisterModal').classList.add('hidden');
    };

    window.submitRegisterModal = function() {
        var form = document.getElementById('regModalForm');
        var errEl = document.getElementById('regModalError');
        var btn = document.getElementById('regSubmitBtn');

        errEl.classList.add('hidden');
        var fd = new FormData(form);
        fd.set('csrf_token', CSRF);

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving…';

        fetch(LC_BASE + '/ajax/add_product.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    closeRegisterModal();
                    window.location.reload();
                } else {
                    errEl.textContent = data.message || 'An error occurred.';
                    errEl.classList.remove('hidden');
                }
            })
            .catch(function() {
                errEl.textContent = 'Server connection error.';
                errEl.classList.remove('hidden');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save mr-2"></i>Register';
            });
    };

    // ─── 모달 내 브랜드/카테고리 빠른 등록 ──────────────────────────
    window.openQuickCreateInModal = function(type) {
        window.openQuickCreate(type, null);
    };

    window.onQuickCreateSuccess = function(type, id, nameEn, nameKo) {
        var label = nameEn + (nameKo ? ' (' + nameKo + ')' : '');
        if (type === 'brand') {
            _regBrands.push({id: id, name_en: nameEn, name_ko: nameKo});
            if (window._regBrandWidget) window._regBrandWidget.select(id, label);
        } else {
            _regCats.push({id: id, name_en: nameEn, name_ko: nameKo});
            if (window._regCatWidget) window._regCatWidget.select(id, label);
        }
    };

    // ─── 한글 → 영문 발음(로마자) 변환 (등록 모달) ──────────────────
    // 음절을 초성/중성/종성으로 분해하여 변환 (국립국어원 로마자 표기법 기반)
    var RR_CHO  = ['g','kk','n','d','tt','r','m','b','pp','s','ss','','j','jj','ch','k','t','p','h'];
    var RR_JUNG = ['a','ae','ya','yae','eo','e','yeo','ye','o','wa','wae','oe','yo','u','wo','we','wi','yu','eu','ui','i'];
    var RR_JONG = ['','k','k','k','n','n','n','t','l','k','m','l','l','l','p','l','m','p','p','t','t','ng','t','t','k','t','p','h'];

    function regRomanizeKorean(text) {
        var out = '';
        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            if (code >= 0xAC00 && code <= 0xD7A3) {
                var s = code - 0xAC00;
                out += RR_CHO[Math.floor(s / 588)] + RR_JUNG[Math.floor((s % 588) / 28)] + RR_JONG[s % 28];
            } else {
                out += text.charAt(i);
            }
        }
        return out;
    }

    window.regRomanizeKoreanName = function() {
        var koInput = document.getElementById('regNameKo');
        var enInput = document.getElementById('regNameEn');
        var text = koInput.value.trim();
        if (!text) { koInput.focus(); return; }
        enInput.value = regRomanizeKorean(text).toUpperCase();
        enInput.focus();
    };

    // ─── 번역 (등록 모달) ────────────────────────────────────────────
    window.regTranslateToKo = function() {
        var en = document.getElementById('regNameEn').value.trim();
        var btn = document.getElementById('regTransToKoBtn');
        if (!en) { document.getElementById('regNameEn').focus(); return; }
        btn.textContent = 'Translating…'; btn.disabled = true;
        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(en) + '&langpair=en|ko')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    document.getElementById('regNameKo').value = data.responseData.translatedText;
                }
            })
            .catch(function() {})
            .finally(function() { btn.textContent = 'Translate ▶ Korean'; btn.disabled = false; });
    };

    window.regTranslateToEn = function() {
        var ko = document.getElementById('regNameKo').value.trim();
        var btn = document.getElementById('regTransToEnBtn');
        if (!ko) { document.getElementById('regNameKo').focus(); return; }
        btn.textContent = 'Translating…'; btn.disabled = true;
        fetch('https://api.mymemory.translated.net/get?q=' + encodeURIComponent(ko) + '&langpair=ko|en')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.responseStatus === 200) {
                    document.getElementById('regNameEn').value = data.responseData.translatedText.toUpperCase();
                }
            })
            .catch(function() {})
            .finally(function() { btn.textContent = 'Translate ▶ English'; btn.disabled = false; });
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeRegisterModal();
            closeConfirmModal();
            closePrintPreview();
        }
    });

    // ─── 프린트 미리보기 ─────────────────────────────────────────────
    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            search: '<?php echo addslashes($search); ?>',
            cat: '<?php echo $cat_id; ?>',
            brand: '<?php echo $brand_id; ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_products.php?' + qs;
        document.getElementById('printPreviewModal').classList.remove('hidden');
    };

    window.closePrintPreview = function() {
        document.getElementById('printPreviewModal').classList.add('hidden');
        document.getElementById('printPreviewFrame').src = 'about:blank';
    };

    window.printPreviewFrame = function() {
        var frame = document.getElementById('printPreviewFrame');
        frame.contentWindow.focus();
        frame.contentWindow.print();
    };

    // ─── 브랜드/카테고리 검색 위젯 팩토리 ─────────────────────────────
    function makeSearchWidget(cfg) {
        var data = cfg.data;
        var searchEl = document.getElementById(cfg.searchId);
        var hiddenEl = document.getElementById(cfg.hiddenId);
        var dropEl   = document.getElementById(cfg.dropdownId);
        var listEl   = document.getElementById(cfg.listId);
        var _selId = '', _focusIdx = -1, _filtered = [];

        function lbl(item) {
            return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : '');
        }

        function highlight(idx) {
            var lis = listEl.querySelectorAll('li[data-s]');
            Array.prototype.forEach.call(lis, function(li, i) {
                li.style.backgroundColor = (i === idx) ? '#ccfbf1' : '';
                li.style.color           = (i === idx) ? '#0f766e' : '';
                li.style.fontWeight      = (i === idx) ? '600'     : '';
            });
            _focusIdx = idx;
            if (lis[idx]) lis[idx].scrollIntoView({ block: 'nearest' });
        }

        function render(filter) {
            listEl.innerHTML = '';
            _focusIdx = -1;
            var q = (filter || '').toLowerCase();
            _filtered = data.filter(function(item) {
                return !q || lbl(item).toLowerCase().indexOf(q) !== -1;
            });
            if (!_filtered.length) {
                listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">No results</li>';
                return;
            }
            _filtered.forEach(function(item) {
                var li = document.createElement('li');
                li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
                li.dataset.s = '1';
                li.addEventListener('mousedown', function(e) {
                    e.preventDefault();
                    select(item.id, lbl(item));
                });
                // Design Ref: §5.3 — 항목별 인라인 수정 (연필) 부착
                LcInlineEdit.attach({
                    li: li, item: item, type: cfg.type, csrf: CSRF,
                    onSaved: function(u) {
                        if (_selId === String(u.id)) { searchEl.value = lbl(u); hiddenEl.value = u.id; } // Plan SC-2
                        render(searchEl.value);
                    }
                });
                listEl.appendChild(li);
            });
        }

        function select(id, label) {
            _selId = String(id);
            hiddenEl.value = id;
            searchEl.value = label;
            dropEl.classList.add('hidden');
        }

        searchEl.addEventListener('focus', function() {
            render(searchEl.value);
            dropEl.classList.remove('hidden');
        });
        searchEl.addEventListener('input', function() {
            _selId = ''; hiddenEl.value = '';
            render(searchEl.value);
            dropEl.classList.remove('hidden');
        });
        searchEl.addEventListener('blur', function() {
            setTimeout(function() {
                if (window.LcInlineEdit && window.LcInlineEdit.editing) return; // 편집중에는 드롭다운 유지
                dropEl.classList.add('hidden');
                if (!_selId) { searchEl.value = ''; hiddenEl.value = ''; }
            }, 150);
        });
        searchEl.addEventListener('keydown', function(e) {
            var open = !dropEl.classList.contains('hidden');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!open) { render(searchEl.value); dropEl.classList.remove('hidden'); }
                var max = _filtered.length - 1;
                if (max >= 0) highlight(Math.min(_focusIdx + 1, max));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (_focusIdx > 0) highlight(_focusIdx - 1);
            } else if (e.key === 'Enter' && open) {
                e.preventDefault();
                if (_focusIdx >= 0 && _filtered[_focusIdx]) {
                    select(_filtered[_focusIdx].id, lbl(_filtered[_focusIdx]));
                } else {
                    dropEl.classList.add('hidden');
                }
            } else if (e.key === 'Escape') {
                dropEl.classList.add('hidden');
            }
        });

        return {
            reset:  function() { _selId = ''; _focusIdx = -1; _filtered = []; hiddenEl.value = ''; searchEl.value = ''; dropEl.classList.add('hidden'); },
            select: select,
            addItem: function(item) { data.push(item); }
        };
    }

    window._regBrandWidget = makeSearchWidget({
        data: _regBrands, type: 'brand', searchId: 'regBrandSearch', hiddenId: 'regBrandId',
        dropdownId: 'regBrandDropdown', listId: 'regBrandList'
    });
    window._regCatWidget = makeSearchWidget({
        data: _regCats, type: 'category', searchId: 'regCatSearch', hiddenId: 'regCatId',
        dropdownId: 'regCatDropdown', listId: 'regCatList'
    });

    // 등록 모달 이미지 미리보기
    (function() {
        var fi = document.getElementById('regImageInput');
        if (!fi) return;
        fi.addEventListener('change', function() {
            var f = this.files && this.files[0];
            var img = document.getElementById('regImgPreview'), ph = document.getElementById('regImgPlaceholder');
            if (!f) { img.src=''; img.classList.add('hidden'); ph.classList.remove('hidden'); return; }
            img.src = URL.createObjectURL(f);
            img.classList.remove('hidden');
            ph.classList.add('hidden');
        });
    })();

    // 검색 결과 없을 때 자동으로 확인 모달 표시
    <?php if ($search && empty($products)): ?>
    openConfirmRegister('<?php echo htmlspecialchars(addslashes($search)); ?>');
    <?php endif; ?>

})();
</script>

<!-- 기존 상품 가져오기 (등록 모달) -->
<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';
    var searchEl = document.getElementById('regShopProductSearch');
    var dropEl   = document.getElementById('regShopProductDropdown');
    var listEl   = document.getElementById('regShopProductList');
    var timer = null;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function lbl(item) {
        return item.name_en + (item.name_ko ? ' (' + item.name_ko + ')' : '');
    }

    function render(products) {
        listEl.innerHTML = '';
        if (!products.length) {
            listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">No results</li>';
            return;
        }
        products.forEach(function(item) {
            var li = document.createElement('li');
            li.className = 'px-3 py-2 text-sm cursor-pointer hover:bg-teal-50 hover:text-teal-700';
            li.innerHTML = '<div class="font-medium">' + escapeHtml(item.name_en) +
                            (item.name_ko ? ' <span class="text-gray-500">(' + escapeHtml(item.name_ko) + ')</span>' : '') + '</div>' +
                            '<div class="text-xs text-gray-400 font-mono">' + escapeHtml(item.sku) +
                            (item.pieces_per_box ? ' &middot; ' + item.pieces_per_box + ' pcs/box' : '') + '</div>';
            li.addEventListener('mousedown', function(e) { e.preventDefault(); applyProduct(item); });
            listEl.appendChild(li);
        });
    }

    function applyProduct(item) {
        var enInput      = document.getElementById('regNameEn');
        var koInput      = document.getElementById('regNameKo');
        var ppbInput     = document.getElementById('regPiecesPerBox');
        var barcodeInput = document.getElementById('regBarcodeUnit');

        if (item.name_en) enInput.value = item.name_en;
        if (item.name_ko) koInput.value = item.name_ko;
        if (item.pieces_per_box) ppbInput.value = item.pieces_per_box;
        if (item.sku && !barcodeInput.value.trim()) {
            barcodeInput.value = item.sku;
        }

        searchEl.value = lbl(item);
        dropEl.classList.add('hidden');
    }

    function search(q) {
        fetch(LC_BASE + '/ajax/search_shop_product.php?q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    render(data.products);
                } else {
                    listEl.innerHTML = '<li class="px-3 py-2 text-sm text-gray-400">' + escapeHtml(data.message) + '</li>';
                }
                dropEl.classList.remove('hidden');
            })
            .catch(function() {
                listEl.innerHTML = '<li class="px-3 py-2 text-sm text-red-400">Search failed.</li>';
                dropEl.classList.remove('hidden');
            });
    }

    searchEl.addEventListener('input', function() {
        var q = searchEl.value.trim();
        if (timer) clearTimeout(timer);
        if (!q) { dropEl.classList.add('hidden'); return; }
        timer = setTimeout(function() { search(q); }, 350);
    });

    searchEl.addEventListener('blur', function() {
        setTimeout(function() { dropEl.classList.add('hidden'); }, 150);
    });
})();
</script>
</div><!-- /.flex.flex-col.h-full -->
<?php require_once __DIR__ . '/partials/modal_brand_cat.php'; ?>
<?php require __DIR__ . '/partials/inline_edit_widget.php'; // Design Ref: §5.4 ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
