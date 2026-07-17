<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

ensure_logged_in();

$has_purchase_permission = has_permission('purchase_management') || in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
if (!$has_purchase_permission) {
    http_response_code(403);
    echo '접근 권한이 없습니다.';
    exit;
}

$conn = get_db_connection();

// 현재 사용자 점포 정보 (mobile_purchase_add.php와 동일 패턴)
$current_store_name = '본점';
$current_store_id = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT s.name AS store_name, s.id AS store_id FROM users u LEFT JOIN stores s ON u.store_id = s.id WHERE u.id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $current_store_name = $row['store_name'] ?? '본점';
        $current_store_id = $row['store_id'];
        if ($_SESSION['role'] === 'super_admin' && empty($current_store_id)) {
            $first = $conn->query("SELECT id, name FROM stores ORDER BY id LIMIT 1")->fetch_assoc();
            if ($first) {
                $current_store_id = $first['id'];
                $current_store_name = $first['name'];
            }
        }
    }
    $stmt->close();
}

// 페이지네이션 변수
$current_page = max(1, (int)($_GET['page'] ?? 1));
$items_per_page = 15;
$offset = ($current_page - 1) * $items_per_page;

// 검색/필터 조건 구성 (purchase_management.php와 동일 로직)
$where_conditions = [];
$params = [];
$param_types = '';

$has_deleted_at = $conn->query("SHOW COLUMNS FROM purchases LIKE 'deleted_at'")->num_rows > 0;
$has_status = $conn->query("SHOW COLUMNS FROM purchases LIKE 'status'")->num_rows > 0;

if ($has_deleted_at) {
    $where_conditions[] = "p.deleted_at IS NULL";
} elseif ($has_status) {
    $where_conditions[] = "p.status != 'deleted'";
}

if ($_SESSION['role'] !== 'super_admin') {
    if (!empty($current_store_id)) {
        $where_conditions[] = "p.store_id = ?";
        $params[] = $current_store_id;
        $param_types .= 'i';
    } else {
        $where_conditions[] = "1 = 0";
    }
}

$search_term = trim($_GET['search'] ?? '');
if ($search_term !== '') {
    $where_conditions[] = "s.name LIKE ?";
    $params[] = '%' . $search_term . '%';
    $param_types .= 's';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
}

$count_sql = "SELECT COUNT(*) as total_count FROM purchases p JOIN suppliers s ON p.supplier_id = s.id {$where_clause}";
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
} else {
    $count_result = $conn->query($count_sql);
}
$total_count = $count_result ? (int)($count_result->fetch_assoc()['total_count'] ?? 0) : 0;
$total_pages = max(1, (int)ceil($total_count / $items_per_page));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
    $offset = ($current_page - 1) * $items_per_page;
}

$sql = "SELECT
    p.purchase_id,
    CONCAT(
        DATE_FORMAT(p.purchase_date, '%Y-%m-%d'),
        CASE WHEN p.created_at IS NOT NULL THEN CONCAT(' ', TIME_FORMAT(p.created_at, '%H:%i')) ELSE '' END
    ) AS purchase_datetime,
    st.name AS store_name,
    s.name AS supplier_name,
    p.total_items,
    p.total_amount,
    COALESCE(p.is_confirmed, 0) AS is_confirmed
FROM purchases p
JOIN suppliers s ON p.supplier_id = s.id
LEFT JOIN stores st ON p.store_id = st.id
{$where_clause}
ORDER BY p.purchase_date DESC, p.purchase_id DESC
LIMIT {$items_per_page} OFFSET {$offset}";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
$purchases = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="#047857">
<title>매입 목록 - HOME K MART</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans KR", sans-serif;
    background: #f3f4f6;
    padding-bottom: 90px;
}
.topbar {
    position: sticky; top: 0; z-index: 20;
    background: linear-gradient(135deg, #047857, #065f46);
    color: #fff;
    padding: 0.9rem 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.topbar-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.topbar h1 { font-size: 1.05rem; font-weight: 700; }
.topbar a.back { color: #d1fae5; font-size: 1.1rem; text-decoration: none; padding: 0.25rem; }
.topbar .store-tag { font-size: 0.75rem; color: #d1fae5; margin-top: 0.15rem; }

.section {
    background: #fff;
    border-radius: 14px;
    margin: 0.75rem;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.search-row { display: flex; gap: 0.5rem; }
.search-row input {
    flex: 1;
    padding: 0.7rem 0.8rem;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    font-size: 1rem;
}
.search-row input:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5,150,105,0.15); }
.search-row button {
    flex-shrink: 0;
    padding: 0 1.1rem;
    border-radius: 10px;
    background: #059669;
    color: #fff;
    border: none;
    font-weight: 700;
}
.reset-link {
    display: inline-flex; align-items: center; gap: 0.3rem;
    margin-top: 0.5rem; font-size: 0.8rem; color: #6b7280; text-decoration: none;
}

.summary-bar { margin: 0 0.75rem 0.5rem; font-size: 0.8rem; color: #6b7280; padding: 0 0.25rem; }

.purchase-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    margin: 0 0.75rem 0.75rem;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}
.purchase-card:active { background: #f9fafb; }
.pc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.5rem; }
.pc-supplier { font-weight: 700; font-size: 0.95rem; color: #111827; }
.pc-store { font-size: 0.75rem; color: #9ca3af; margin-top: 0.1rem; }
.pc-amount { font-weight: 700; color: #059669; font-size: 0.95rem; white-space: nowrap; }
.pc-datetime { font-size: 0.75rem; color: #9ca3af; margin-top: 0.1rem; }
.pc-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid #f3f4f6; }
.pc-meta { font-size: 0.8rem; color: #6b7280; }
.status-badge { display: inline-flex; align-items: center; font-size: 0.7rem; font-weight: 700; padding: 0.2rem 0.5rem; border-radius: 999px; }
.status-badge.confirmed { background: #d1fae5; color: #065f46; }
.status-badge.pending { background: #fef3c7; color: #92400e; }

.empty-hint { text-align: center; color: #9ca3af; padding: 2.5rem 0; font-size: 0.9rem; }

.pagination-bar {
    display: flex; align-items: center; justify-content: center; gap: 0.75rem;
    margin: 1rem 0.75rem;
}
.page-btn {
    padding: 0.55rem 1rem; border-radius: 10px; border: 1px solid #d1d5db;
    background: #fff; color: #374151; font-weight: 600; font-size: 0.85rem; text-decoration: none;
}
.page-btn.disabled { opacity: 0.4; pointer-events: none; }
.page-indicator { font-size: 0.85rem; color: #4b5563; }

.fab {
    position: fixed; right: 1.1rem; bottom: 1.4rem; z-index: 30;
    width: 58px; height: 58px; border-radius: 50%;
    background: #059669; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; text-decoration: none;
    box-shadow: 0 4px 12px rgba(5,150,105,0.4);
}
.fab:active { background: #047857; }
</style>
</head>
<body>

<div class="topbar">
    <div class="topbar-row">
        <a href="mobile_main.php" class="back"><i class="fas fa-arrow-left"></i></a>
        <h1><i class="fas fa-clipboard-list mr-1"></i> 매입 목록</h1>
        <span style="width:1.1rem"></span>
    </div>
    <div class="store-tag"><i class="fas fa-store mr-1"></i><?php echo htmlspecialchars($current_store_name); ?></div>
</div>

<div class="section">
    <form method="get" action="mobile_purchase_list.php" class="search-row">
        <input type="text" name="search" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="거래처명 검색" autocomplete="off">
        <button type="submit"><i class="fas fa-search"></i></button>
    </form>
    <?php if ($search_term !== ''): ?>
    <a href="mobile_purchase_list.php" class="reset-link"><i class="fas fa-times"></i> 검색 초기화</a>
    <?php endif; ?>
</div>

<div class="summary-bar">총 <?php echo number_format($total_count); ?>건</div>

<?php if (empty($purchases)): ?>
<div class="empty-hint">
    <i class="fas fa-dolly-flatbed" style="font-size:2.5rem; margin-bottom:0.75rem; display:block;"></i>
    매입 내역이 없습니다.
</div>
<?php else: ?>
<?php foreach ($purchases as $row): ?>
<div class="purchase-card" onclick="window.location.href='edit_purchase.php?id=<?php echo $row['purchase_id']; ?>'">
    <div class="pc-top">
        <div>
            <div class="pc-supplier">#<?php echo str_pad($row['purchase_id'], 4, '0', STR_PAD_LEFT); ?> · <?php echo htmlspecialchars($row['supplier_name']); ?></div>
            <div class="pc-store"><?php echo htmlspecialchars($row['store_name'] ?? '미지정'); ?></div>
        </div>
        <div style="text-align:right;">
            <div class="pc-amount"><?php echo number_format($row['total_amount'], 2); ?></div>
            <div class="pc-datetime"><?php echo htmlspecialchars($row['purchase_datetime']); ?></div>
        </div>
    </div>
    <div class="pc-bottom">
        <span class="pc-meta"><?php echo number_format($row['total_items']); ?>품목</span>
        <?php if ($row['is_confirmed']): ?>
        <span class="status-badge confirmed"><i class="fas fa-check-circle mr-1"></i>매입확정</span>
        <?php else: ?>
        <span class="status-badge pending"><i class="fas fa-clock mr-1"></i>미확정</span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if ($total_pages > 1): ?>
<div class="pagination-bar">
    <a class="page-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>"
       href="?page=<?php echo $current_page - 1; ?><?php echo $search_term !== '' ? '&search=' . urlencode($search_term) : ''; ?>">
        <i class="fas fa-chevron-left"></i> 이전
    </a>
    <span class="page-indicator"><?php echo $current_page; ?> / <?php echo $total_pages; ?></span>
    <a class="page-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>"
       href="?page=<?php echo $current_page + 1; ?><?php echo $search_term !== '' ? '&search=' . urlencode($search_term) : ''; ?>">
        다음 <i class="fas fa-chevron-right"></i>
    </a>
</div>
<?php endif; ?>

<a href="mobile_purchase_add.php" class="fab"><i class="fas fa-plus"></i></a>

</body>
</html>
