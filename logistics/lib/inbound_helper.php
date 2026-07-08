<?php
// File: logistics/lib/inbound_helper.php
// Design Ref: §2 - Data Model, §4 - Database Queries
// Purpose: Reusable helper functions for inbound items filtering and pagination

/**
 * Get filtered inbound items with pagination
 *
 * @param array $filters - ['supplier' => '', 'product_or_barcode' => '', 'date_from' => '', 'date_to' => '']
 * @param int $page - Page number (1-indexed)
 * @param int $limit - Items per page (default 20)
 * @return array - ['items' => [...], 'total' => N, 'page' => N, 'total_pages' => N]
 */
function getFilteredInboundItems($filters = [], $page = 1, $limit = 20) {
    // Design Ref: §4.1 - Main Query Pattern

    $conn = get_lc_db();
    if (!$conn) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0, 'error' => 'DB Connection Failed'];
    }

    // Sanitize inputs
    $search = isset($filters['search']) ? trim($filters['search']) : '';
    $date_from = isset($filters['date_from']) ? trim($filters['date_from']) : '';
    $date_to = isset($filters['date_to']) ? trim($filters['date_to']) : '';
    $page = max(1, intval($page));
    $limit = min(100, max(1, intval($limit))); // Cap at 100 for safety

    // Build WHERE clause dynamically
    $where_parts = [];
    $params = [];
    $types = '';

    // 통합 검색: 거래처명, 상품명(영/한), 바코드를 하나의 검색어로 OR 조회
    if (!empty($search)) {
        $where_parts[] = "(s.name LIKE ? OR p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'ssss';
    }

    if (!empty($date_from)) {
        $where_parts[] = "i.inbound_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }

    if (!empty($date_to)) {
        $where_parts[] = "i.inbound_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }

    $where_clause = !empty($where_parts) ? " AND " . implode(" AND ", $where_parts) : "";

    // 마이너스 재고 조정용 lot(ADJUST, qty=0)은 실제 입고가 아니므로 목록에서 제외
    $where_clause .= " AND NOT (i.lot_number = 'ADJUST' AND i.quantity = 0)";

    // Count total items
    // Design Ref: §4.1 - Adjusted for actual schema
    $count_query = "
        SELECT COUNT(*) AS total
        FROM lc_inbound i
        LEFT JOIN lc_suppliers s ON i.supplier_id = s.id
        LEFT JOIN lc_products p ON i.product_id = p.id
        WHERE 1=1 {$where_clause}
    ";

    try {
        $count_stmt = $conn->prepare($count_query);
        if (!empty($params)) {
            $count_stmt->bind_param($types, ...$params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $total = intval($count_row['total']);
        $count_stmt->close();
    } catch (Exception $e) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0, 'error' => $e->getMessage()];
    }

    // Calculate pagination
    $total_pages = max(1, (int)ceil($total / $limit));
    $offset = ($page - 1) * $limit;

    // Fetch items with LIMIT/OFFSET
    // Design Ref: §4.1 - Query adjusted for actual schema
    $query = "
        SELECT
            i.id,
            i.batch_id,
            i.inbound_date,
            s.name AS supplier_name,
            COALESCE(p.barcode_unit, '') AS barcode,
            COALESCE(p.name_en, p.name_ko, '') AS product_name,
            COALESCE(p.name_ko, '') AS product_name_ko,
            i.cost_price,
            i.quantity,
            COALESCE(i.inbound_unit, 'PCS') AS inbound_unit,
            COALESCE(i.cost_price_pcs, 0)   AS cost_price_pcs,
            COALESCE(p.pieces_per_box, 1)   AS pieces_per_box,
            COALESCE(c.name_en, '') AS category_name,
            COALESCE(b.name_en, '') AS brand_name,
            COALESCE(p.capacity, '') AS capacity,
            COALESCE(p.unit, '') AS product_unit
        FROM lc_inbound i
        LEFT JOIN lc_suppliers s ON i.supplier_id = s.id
        LEFT JOIN lc_products p ON i.product_id = p.id
        LEFT JOIN lc_categories c ON p.category_id = c.id
        LEFT JOIN lc_brands b ON p.brand_id = b.id
        WHERE 1=1 {$where_clause}
        ORDER BY i.inbound_date DESC, i.id DESC
        LIMIT ? OFFSET ?
    ";

    try {
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            // Add LIMIT and OFFSET params
            $params[] = $limit;
            $params[] = $offset;
            $types .= 'ii';
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $stmt->close();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'total_pages' => $total_pages,
            'error' => null
        ];
    } catch (Exception $e) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Get all filtered inbound items without pagination (for export/print)
 *
 * @param array $filters - ['supplier' => '', 'product_or_barcode' => '', 'date_from' => '', 'date_to' => '']
 * @return array - ['items' => [...], 'error' => null|string]
 */
function getAllFilteredInboundItems($filters = []) {
    $conn = get_lc_db();
    if (!$conn) {
        return ['items' => [], 'error' => 'DB Connection Failed'];
    }

    $search    = isset($filters['search']) ? trim($filters['search']) : '';
    $date_from = isset($filters['date_from']) ? trim($filters['date_from']) : '';
    $date_to   = isset($filters['date_to']) ? trim($filters['date_to']) : '';

    $where_parts = []; $params = []; $types = '';

    // 통합 검색: 거래처명, 상품명(영/한), 바코드를 하나의 검색어로 OR 조회
    if (!empty($search)) {
        $where_parts[] = "(s.name LIKE ? OR p.name_en LIKE ? OR p.name_ko LIKE ? OR p.barcode_unit LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'ssss';
    }
    if (!empty($date_from)) {
        $where_parts[] = "i.inbound_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    if (!empty($date_to)) {
        $where_parts[] = "i.inbound_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }

    $where_clause = !empty($where_parts) ? " AND " . implode(" AND ", $where_parts) : "";
    $where_clause .= " AND NOT (i.lot_number = 'ADJUST' AND i.quantity = 0)";

    $query = "
        SELECT
            i.id,
            i.batch_id,
            i.inbound_date,
            s.name AS supplier_name,
            COALESCE(p.barcode_unit, '') AS barcode,
            COALESCE(p.name_en, p.name_ko, '') AS product_name,
            COALESCE(p.name_ko, '') AS product_name_ko,
            i.cost_price,
            i.quantity,
            COALESCE(i.inbound_unit, 'PCS') AS inbound_unit,
            COALESCE(i.cost_price_pcs, 0)   AS cost_price_pcs,
            COALESCE(p.pieces_per_box, 1)   AS pieces_per_box,
            COALESCE(c.name_en, '') AS category_name,
            COALESCE(b.name_en, '') AS brand_name,
            COALESCE(p.capacity, '') AS capacity,
            COALESCE(p.unit, '') AS product_unit
        FROM lc_inbound i
        LEFT JOIN lc_suppliers s ON i.supplier_id = s.id
        LEFT JOIN lc_products p ON i.product_id = p.id
        LEFT JOIN lc_categories c ON p.category_id = c.id
        LEFT JOIN lc_brands b ON p.brand_id = b.id
        WHERE 1=1 {$where_clause}
        ORDER BY i.inbound_date DESC, i.id DESC
    ";

    try {
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
        $conn->close();

        return ['items' => $items, 'error' => null];
    } catch (Exception $e) {
        return ['items' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Design Ref: inbound-damage-registration.design.md §4.1
 * Get filtered inbound damage records with pagination + summary totals
 *
 * @param array $filters - ['search' => '', 'date_from' => '', 'date_to' => '']
 * @param int $page - Page number (1-indexed)
 * @param int $limit - Items per page
 * @return array - ['items'=>[...], 'total'=>N, 'page'=>N, 'total_pages'=>N,
 *                   'summary'=>['count'=>N, 'total_cost_loss'=>F], 'error'=>null|string]
 */
function getFilteredInboundDamages($filters = [], $page = 1, $limit = 20) {
    $conn = get_lc_db();
    if (!$conn) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0,
                 'summary' => ['count' => 0, 'total_cost_loss' => 0], 'error' => 'DB Connection Failed'];
    }

    $search    = isset($filters['search']) ? trim($filters['search']) : '';
    $date_from = isset($filters['date_from']) ? trim($filters['date_from']) : '';
    $date_to   = isset($filters['date_to']) ? trim($filters['date_to']) : '';
    $page  = max(1, intval($page));
    $limit = min(100, max(1, intval($limit)));

    $where_parts = [];
    $params = [];
    $types = '';

    if (!empty($search)) {
        $where_parts[] = "(p.name_en LIKE ? OR p.name_ko LIKE ? OR s.name LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $types .= 'sss';
    }
    if (!empty($date_from)) {
        $where_parts[] = "i.inbound_date >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    if (!empty($date_to)) {
        $where_parts[] = "i.inbound_date <= ?";
        $params[] = $date_to;
        $types .= 's';
    }

    $where_clause = !empty($where_parts) ? " AND " . implode(" AND ", $where_parts) : "";

    $base_from = "
        FROM lc_inbound_damages d
        JOIN lc_inbound  i ON d.inbound_id = i.id
        JOIN lc_products p ON d.product_id = p.id
        LEFT JOIN lc_suppliers s ON d.supplier_id = s.id
        WHERE 1=1 {$where_clause}
    ";

    // Count + summary (동일 WHERE 조건)
    try {
        $sum_query = "SELECT COUNT(*) AS cnt, COALESCE(SUM(d.cost_loss), 0) AS total_loss {$base_from}";
        $sum_stmt = $conn->prepare($sum_query);
        if (!empty($params)) {
            $sum_stmt->bind_param($types, ...$params);
        }
        $sum_stmt->execute();
        $sum_row = $sum_stmt->get_result()->fetch_assoc();
        $sum_stmt->close();
        $total = intval($sum_row['cnt']);
        $summary = ['count' => $total, 'total_cost_loss' => (float)$sum_row['total_loss']];
    } catch (Exception $e) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0,
                 'summary' => ['count' => 0, 'total_cost_loss' => 0], 'error' => $e->getMessage()];
    }

    $total_pages = max(1, (int)ceil($total / $limit));
    $offset = ($page - 1) * $limit;

    $query = "
        SELECT d.id, d.quantity, d.unit, d.cost_loss, d.reason, d.created_at,
               p.name_en, p.name_ko, COALESCE(s.name, '-') AS supplier_name,
               i.inbound_date, i.batch_id
        {$base_from}
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ";

    try {
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $q_params = $params;
            $q_types = $types . 'ii';
            $q_params[] = $limit;
            $q_params[] = $offset;
            $stmt->bind_param($q_types, ...$q_params);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
        $conn->close();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'total_pages' => $total_pages,
            'summary' => $summary,
            'error' => null
        ];
    } catch (Exception $e) {
        return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 0,
                 'summary' => $summary, 'error' => $e->getMessage()];
    }
}

?>
