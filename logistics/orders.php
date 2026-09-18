<?php
require_once __DIR__ . '/lib/auth.php';
$page_title = t('logistics.orders.page_title');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/inventory_helper.php';

lc_require_login();

// 관리자(super_admin/admin) 삭제된 주문 복구 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    lc_verify_csrf();
    if (!lc_is_admin()) {
        lc_set_flash('error', t('logistics.orders.access_denied'));
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }
    $restore_id = (int)($_POST['order_id'] ?? 0);
    if ($restore_id) {
        $conn = get_lc_db();
        $st = $conn->prepare("UPDATE lc_orders SET deleted_at=NULL, deleted_by=NULL WHERE id=? AND deleted_at IS NOT NULL");
        $st->bind_param('i', $restore_id);
        $st->execute();
        if ($st->affected_rows > 0) {
            lc_set_flash('success', t('logistics.orders.restored', ['id' => str_pad($restore_id, 4, '0', STR_PAD_LEFT)]));
        } else {
            lc_set_flash('error', t('logistics.orders.not_deleted'));
        }
        $st->close();
        $conn->close();
    }
    $sf = (int)($_POST['store_filter'] ?? 0);
    header('Location: ' . LC_BASE . '/orders.php?status=' . ($_POST['status_filter'] ?? 'deleted') . ($sf > 0 ? '&store=' . $sf : '')); exit;
}

// 관리자(super_admin/admin) 삭제 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    lc_verify_csrf();
    if (!lc_is_admin()) {
        lc_set_flash('error', t('logistics.orders.access_denied'));
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }
    $del_id = (int)($_POST['order_id'] ?? 0);
    if ($del_id) {
        try {
            $conn = get_lc_db();
            $conn->autocommit(false);

            // 출고된 lot이 있으면 재고 quantity_out 복구
            $lots = $conn->query(
                "SELECT oll.inventory_id, oll.quantity
                 FROM lc_order_item_lots oll
                 JOIN lc_order_items oi ON oll.order_item_id = oi.id
                 WHERE oi.order_id = $del_id"
            )->fetch_all(MYSQLI_ASSOC);

            if ($lots) {
                $restore = $conn->prepare("UPDATE lc_inventory SET quantity_out = GREATEST(0, quantity_out - ?) WHERE id = ?");
                foreach ($lots as $lot) {
                    $restore->bind_param('ii', $lot['quantity'], $lot['inventory_id']);
                    $restore->execute();
                }
                $restore->close();
            }

            $conn->query("DELETE oll FROM lc_order_item_lots oll JOIN lc_order_items oi ON oll.order_item_id = oi.id WHERE oi.order_id = $del_id");
            $conn->query("DELETE FROM lc_order_items WHERE order_id = $del_id");
            $st = $conn->prepare("DELETE FROM lc_orders WHERE id = ?");
            $st->bind_param('i', $del_id); $st->execute(); $st->close();
            $conn->commit(); $conn->close();
        lc_set_flash('success', t('logistics.orders.deleted_restored', ['id' => str_pad($del_id, 4, '0', STR_PAD_LEFT)]));
        } catch (Exception $e) {
            if (isset($conn)) { $conn->rollback(); $conn->close(); }
        lc_set_flash('error', t('logistics.orders.db_error', ['message' => $e->getMessage()]));
        }
    }
    $sf = (int)($_POST['store_filter'] ?? 0);
    header('Location: ' . LC_BASE . '/orders.php?status=' . ($_POST['status_filter'] ?? 'all') . ($sf > 0 ? '&store=' . $sf : '')); exit;
}

// 즉시 배달 완료 처리 (점장(센터장) 이상: super_admin/admin/branch_manager)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deliver') {
    lc_verify_csrf();
    if (!lc_is_admin()) {
        lc_set_flash('error', t('logistics.orders.access_denied'));
        header('Location: ' . LC_BASE . '/orders.php'); exit;
    }
    $dlv_id = (int)($_POST['order_id'] ?? 0);
    if ($dlv_id) {
        try {
            $conn = get_lc_db();
            $now  = date('Y-m-d H:i:s');
            // 승인/출고 상태는 이미 재고가 차감되어 있으므로 상태/시각만 확정한다.
            $st = $conn->prepare(
                "UPDATE lc_orders
                 SET status='delivered', shipped_at=COALESCE(shipped_at, ?), delivered_at=?
                 WHERE id=? AND status IN ('approved','shipped')"
            );
            $st->bind_param('ssi', $now, $now, $dlv_id);
            $st->execute();
            if ($st->affected_rows > 0) {
        lc_set_flash('success', t('logistics.orders.delivered_success', ['id' => str_pad($dlv_id, 4, '0', STR_PAD_LEFT)]));
            } else {
        lc_set_flash('error', t('logistics.orders.deliver_invalid'));
            }
            $st->close();
            $conn->close();
        } catch (Exception $e) {
            if (isset($conn)) { $conn->close(); }
        lc_set_flash('error', t('logistics.orders.db_error', ['message' => $e->getMessage()]));
        }
    }
    $sf = (int)($_POST['store_filter'] ?? 0);
    header('Location: ' . LC_BASE . '/orders.php?status=' . ($_POST['status_filter'] ?? 'all') . ($sf > 0 ? '&store=' . $sf : '')); exit;
}

$status_filter = $_GET['status'] ?? 'all';
if ($status_filter === 'deleted' && !lc_is_admin()) { $status_filter = 'all'; }
$store_filter = (int)($_GET['store'] ?? 0); // 0 = 전체 점포 (물류직원/관리자 전용 필터)
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

$stores = [];

try {
    $conn = get_lc_db();

    $conds  = [];
    $params = [];
    $types  = '';

    // 점포 담당자는 본인 점포 주문만 조회
    if (lc_is_store_user()) {
        $sid = lc_current_store_id();
        $conds[] = "o.store_id = ?";
        $params[] = $sid;
        $types   .= 'i';
    } elseif ($store_filter > 0) {
        // 물류직원/관리자: 선택한 점포로 필터
        $conds[] = "o.store_id = ?";
        $params[] = $store_filter;
        $types   .= 'i';
    }

    if ($status_filter === 'deleted') {
        // 휴지통: 소프트 삭제된 주문만 (admin 전용)
        $conds[] = "o.deleted_at IS NOT NULL";
    } else {
        $conds[] = "o.deleted_at IS NULL";
        if ($status_filter !== 'all') {
            $conds[] = "o.status = ?";
            $params[] = $status_filter;
            $types   .= 's';
        } else {
            // Plan SC-7: 출고대기(draft) 건은 주문내역에 노출하지 않음 (branch_outbound_list.php에서 관리)
            $conds[] = "o.status <> 'draft'";
        }
    }

    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

    $cnt = $conn->prepare("SELECT COUNT(*) FROM lc_orders o $where");
    if ($params) { $cnt->bind_param($types, ...$params); }
    $cnt->execute();
    $total = (int)$cnt->get_result()->fetch_row()[0];
    $cnt->close();
    $total_pages = max(1, (int)ceil($total / $limit));

    $order_by = $status_filter === 'deleted' ? 'o.deleted_at DESC' : 'o.created_at DESC';
    $sql = "SELECT o.id, o.order_date, o.status, o.total_amount, o.created_at,
                   o.approved_at, o.shipped_at, o.delivered_at, o.deleted_at,
                   s.name AS store_name,
                   u.full_name AS created_by_name,
                   COUNT(oi.id) AS item_count
            FROM lc_orders o
            LEFT JOIN stores s ON o.store_id = s.id
            LEFT JOIN users u ON o.created_by = u.id
            LEFT JOIN lc_order_items oi ON o.id = oi.order_id
            $where
            GROUP BY o.id
            ORDER BY $order_by
            LIMIT $limit OFFSET $offset";
    $st = $conn->prepare($sql);
    if ($params) { $st->bind_param($types, ...$params); }
    $st->execute();
    $orders = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();

    // 점포 필터 버튼용 목록 (물류직원/관리자 전용) — CENTER(물류센터)는 제외
    if (lc_is_staff()) {
        $stores = $conn->query("SELECT id, name FROM stores WHERE name <> 'CENTER (물류센터)' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    }

    $conn->close();
} catch (Exception $e) {
    $db_error = $e->getMessage(); $orders = []; $total = 0; $total_pages = 1;
}

$statuses = ['all' => t('logistics.orders.all'), 'pending' => t('logistics.orders.pending'), 'approved' => t('logistics.orders.approved'), 'cancel_requested' => t('logistics.orders.cancel_requested'), 'shipped' => t('logistics.orders.shipped'), 'delivered' => t('logistics.orders.delivered'), 'cancelled' => t('logistics.orders.cancelled')];
if (lc_is_admin()) {
    $statuses['deleted'] = t('logistics.orders.deleted');
}
?>

<style>
main { overflow: hidden !important; }
</style>

<div class="flex flex-col h-full gap-3 overflow-hidden">

<!-- 페이지 헤더 -->
<div class="flex items-center justify-between shrink-0">
    <h2 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars(t('logistics.orders.title')); ?></h2>
    <div class="flex items-center gap-2">
        <button type="button" onclick="openPrintPreview()"
           class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700"><i class="fas fa-print mr-1"></i><?php echo htmlspecialchars(t('logistics.orders.print')); ?></button>
        <?php if (lc_is_store_user()): ?>
        <a href="<?php echo LC_BASE; ?>/order_new.php" class="inline-flex items-center px-4 py-2 bg-teal-600 text-white text-sm font-medium rounded-lg hover:bg-teal-700 transition-colors">
            <i class="fas fa-plus mr-2"></i><?php echo htmlspecialchars(t('logistics.orders.place_order')); ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Status Filter -->
<div class="flex gap-2 flex-wrap shrink-0">
    <?php foreach ($statuses as $key => $label): ?>
    <a href="?status=<?php echo $key; ?><?php echo $store_filter > 0 ? '&store=' . $store_filter : ''; ?>"
       class="px-3 py-1.5 text-sm rounded-full border transition-colors
              <?php
                if ($status_filter === $key) {
                    echo $key === 'deleted' ? 'bg-red-600 text-white border-red-600' : 'bg-teal-600 text-white border-teal-600';
                } else {
                    echo $key === 'deleted' ? 'bg-white text-red-500 border-red-200 hover:bg-red-50' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50';
                }
              ?>">
        <?php if ($key === 'deleted'): ?><i class="fas fa-trash mr-1"></i><?php endif; ?><?php echo $label; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($db_error)): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700 text-sm shrink-0"><?php echo htmlspecialchars($db_error); ?></div>
<?php endif; ?>

<!-- 테이블 카드 -->
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden flex flex-col flex-1 min-h-0">
    <div class="px-4 py-3 border-b border-gray-100 shrink-0 flex items-center gap-3 flex-wrap">
        <span class="text-sm text-gray-500 whitespace-nowrap"><?php echo htmlspecialchars(t('logistics.orders.total', ['count' => number_format($total)])); ?></span>
        <?php if (lc_is_staff() && !empty($stores)): ?>
        <div class="flex gap-1.5 flex-wrap items-center">
            <a href="?status=<?php echo urlencode($status_filter); ?>"
               class="px-2.5 py-1 text-xs rounded-full border transition-colors <?php echo $store_filter === 0 ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'; ?>">
                <i class="fas fa-store mr-1"></i><?php echo htmlspecialchars(t('logistics.orders.all_stores')); ?>
            </a>
            <?php foreach ($stores as $s): ?>
            <a href="?status=<?php echo urlencode($status_filter); ?>&store=<?php echo (int)$s['id']; ?>"
               class="px-2.5 py-1 text-xs rounded-full border transition-colors <?php echo $store_filter === (int)$s['id'] ? 'bg-teal-600 text-white border-teal-600' : 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50'; ?>">
                <?php echo htmlspecialchars($s['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="overflow-auto flex-1 min-h-0">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 sticky top-0 z-10"><tr>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.order_number')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.order_date')); ?></th>
                <?php if (lc_is_staff()): ?>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.store')); ?></th>
                <?php endif; ?>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.status')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.item_count')); ?></th>
                <th class="px-4 py-3 text-right text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.total_amount')); ?></th>
                <th class="px-4 py-3 text-left text-xs text-gray-500 font-medium"><?php echo htmlspecialchars(t('logistics.orders.ordered_by')); ?></th>
                <?php if (lc_is_admin()): ?>
                <th class="px-4 py-3 text-center text-xs text-gray-500 font-medium w-32"><?php echo htmlspecialchars(t('logistics.orders.actions')); ?></th>
                <?php endif; ?>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
            <?php if (empty($orders)): ?>
            <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                <i class="fas fa-clipboard-list text-3xl mb-2 block text-gray-300"></i>
                <?php echo htmlspecialchars(t('logistics.orders.empty')); ?>
            </td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
            <tr class="hover:bg-gray-50 cursor-pointer" onclick="location.href='<?php echo LC_BASE; ?>/order_detail.php?id=<?php echo $o['id']; ?>'">
                <td class="px-4 py-3 font-mono text-gray-700">#<?php echo str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?></td>
                <td class="px-4 py-3 text-gray-600">
                    <?php echo htmlspecialchars($o['order_date']); ?>
                    <?php if (!empty($o['created_at'])): ?>
                    <span class="text-xs text-gray-400 ml-1"><?php echo date('H:i', strtotime($o['created_at'])); ?></span>
                    <?php endif; ?>
                </td>
                <?php if (lc_is_staff()): ?>
                <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars($o['store_name'] ?? '-'); ?></td>
                <?php endif; ?>
                <td class="px-4 py-3 text-center">
                    <span class="text-xs px-2 py-1 rounded-full font-medium <?php echo lc_status_class($o['status']); ?>">
                        <?php echo htmlspecialchars($statuses[$o['status']] ?? $o['status']); ?>
                    </span>
                </td>
                <td class="px-4 py-3 text-right text-gray-600"><?php echo htmlspecialchars(t('logistics.orders.items_count', ['count' => $o['item_count']])); ?></td>
                <td class="px-4 py-3 text-right font-semibold"><?php echo number_format($o['total_amount'], 2); ?></td>
                <td class="px-4 py-3 text-gray-500 text-xs"><?php echo htmlspecialchars($o['created_by_name'] ?? '-'); ?></td>
                <?php if (lc_is_admin()): ?>
                <?php $order_num = str_pad($o['id'], 4, '0', STR_PAD_LEFT); ?>
                <td class="px-4 py-3 text-center" onclick="event.stopPropagation()">
                    <?php if ($status_filter === 'deleted'): ?>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="restore">
                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <input type="hidden" name="store_filter" value="<?php echo (int)$store_filter; ?>">
                        <button type="submit"
                                 onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.orders.restore_confirm', ['id' => $order_num])), ENT_QUOTES); ?>)"
                                class="text-green-600 hover:text-green-700 text-xs px-2 py-1 rounded hover:bg-green-50 transition-colors">
                            <i class="fas fa-undo mr-1"></i><?php echo htmlspecialchars(t('logistics.orders.restore')); ?>
                        </button>
                    </form>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <input type="hidden" name="store_filter" value="<?php echo (int)$store_filter; ?>">
                        <button type="submit"
                                 onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.orders.delete_confirm', ['id' => $order_num])), ENT_QUOTES); ?>)"
                                class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded hover:bg-red-50 transition-colors">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php else: ?>
                    <?php if (in_array($o['status'], ['approved', 'shipped'], true)): ?>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="deliver">
                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <input type="hidden" name="store_filter" value="<?php echo (int)$store_filter; ?>">
                        <button type="submit"
                                 onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.orders.deliver_confirm', ['id' => $order_num])), ENT_QUOTES); ?>)"
                                class="text-green-600 hover:text-green-700 text-xs px-2 py-1 rounded hover:bg-green-50 transition-colors whitespace-nowrap"
                                 title="<?php echo htmlspecialchars(t('logistics.orders.deliver_title')); ?>">
                            <i class="fas fa-check-double mr-1"></i><?php echo htmlspecialchars(t('logistics.orders.deliver')); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(lc_csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                        <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter); ?>">
                        <input type="hidden" name="store_filter" value="<?php echo (int)$store_filter; ?>">
                        <button type="submit"
                                 onclick="return confirm(<?php echo htmlspecialchars(json_encode(t('logistics.orders.delete_confirm', ['id' => $order_num])), ENT_QUOTES); ?>)"
                                class="text-red-400 hover:text-red-600 text-xs px-2 py-1 rounded hover:bg-red-50 transition-colors">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
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
        $qs = ['status' => $status_filter];
        if ($store_filter > 0) { $qs['store'] = $store_filter; }
    ?>
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-center gap-1 shrink-0">
        <?php if ($block_start > 1): ?>
        <a href="?page=<?php echo $block_start - $window; ?>&<?php echo http_build_query($qs); ?>"
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="<?php echo htmlspecialchars(t('logistics.orders.previous_pages')); ?>">
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
           class="flex items-center justify-center rounded font-medium transition-colors <?php echo $i === $page ? 'w-9 h-9 bg-teal-600 text-white text-base shadow-md ring-2 ring-teal-300' : 'w-8 h-8 text-sm text-gray-500 hover:bg-gray-100'; ?>">
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
           class="w-8 h-8 flex items-center justify-center rounded border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 text-sm transition-colors" title="<?php echo htmlspecialchars(t('logistics.orders.next_pages')); ?>">
            <i class="fas fa-angle-double-right text-xs"></i>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.flex.flex-col.h-full -->

<!-- 프린트 미리보기 모달 -->
<div id="printPreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center">
    <div class="absolute inset-0 bg-black bg-opacity-50"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-5xl mx-4 flex flex-col" style="height:90vh">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 shrink-0">
            <h3 class="text-base font-semibold text-gray-900"><i class="fas fa-print text-blue-600 mr-2"></i><?php echo htmlspecialchars(t('logistics.orders.print_preview')); ?></h3>
            <div class="flex items-center gap-2">
                <button type="button" onclick="printPreviewFrame()"
                        class="px-4 py-1.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                    <i class="fas fa-print mr-1"></i><?php echo htmlspecialchars(t('logistics.orders.print')); ?>
                </button>
                <button type="button" onclick="closePrintPreview()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="flex-1 min-h-0">
            <iframe id="printPreviewFrame" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>

<script>
(function() {
    var LC_BASE = '<?php echo LC_BASE; ?>';

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closePrintPreview();
        }
    });

    window.openPrintPreview = function() {
        var qs = new URLSearchParams({
            status: '<?php echo addslashes($status_filter); ?>',
            store: '<?php echo (int)$store_filter; ?>'
        }).toString();
        document.getElementById('printPreviewFrame').src = LC_BASE + '/print_orders.php?' + qs;
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
})();
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
