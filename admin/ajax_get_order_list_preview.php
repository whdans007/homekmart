<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';

// 권한 확인
if (!has_permission('product_management') && !has_permission('shop_access')) {
    http_response_code(403);
    exit('권한이 없습니다.');
}

$list_id = (int)($_GET['id'] ?? 0);

if ($list_id <= 0) {
    http_response_code(400);
    exit('유효하지 않은 리스트 ID입니다.');
}

try {
    $conn = get_db_connection();
    if (!$conn) {
        throw new Exception("데이터베이스 연결 실패");
    }

    // 현재 사용자의 store_id 확인
    $current_user_id = $_SESSION['user_id'] ?? 0;
    $current_store_id = $_SESSION['store_id'] ?? 0;

    if (empty($current_store_id) && !empty($current_user_id)) {
        $user_sql = "SELECT store_id FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        if ($user_stmt) {
            $user_stmt->bind_param("i", $current_user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            if ($user_row = $user_result->fetch_assoc()) {
                $current_store_id = $user_row['store_id'];
            }
            $user_stmt->close();
        }
    }

    // 리스트 조회
    if (!empty($current_store_id)) {
        $list_sql = "SELECT * FROM store_order_lists WHERE id = ? AND store_id = ?";
        $list_stmt = $conn->prepare($list_sql);
        $list_stmt->bind_param("ii", $list_id, $current_store_id);
    } else {
        $list_sql = "SELECT * FROM store_order_lists WHERE id = ?";
        $list_stmt = $conn->prepare($list_sql);
        $list_stmt->bind_param("i", $list_id);
    }

    $list_stmt->execute();
    $list_result = $list_stmt->get_result();

    if ($list_result->num_rows === 0) {
        http_response_code(404);
        exit('리스트를 찾을 수 없습니다.');
    }

    $order_list = $list_result->fetch_assoc();
    $list_stmt->close();

    // 리스트 아이템 조회
    $items_sql = "SELECT
                      soli.id as item_id,
                      soli.product_id,
                      soli.quantity,
                      soli.order_status,
                      p.sku,
                      p.name_en,
                      p.name_ko,
                      COALESCE(p.pieces_per_box, 1) as pieces_per_box
                  FROM store_order_list_items soli
                  JOIN products p ON soli.product_id = p.id
                  WHERE soli.order_list_id = ?
                  ORDER BY soli.id ASC";

    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("i", $list_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();

    $items = [];
    while ($item_row = $items_result->fetch_assoc()) {
        $items[] = $item_row;
    }

    $items_stmt->close();
    $conn->close();

    // HTML 생성
    $title = htmlspecialchars($order_list['title']);
    $total_items = count($items);
    $total_quantity = 0;

    foreach ($items as $item) {
        $total_quantity += $item['quantity'];
    }

    $html = '
    <style>
        @media print {
            body { margin: 3mm 5mm; padding: 0; }
            .print-preview { margin: 0; padding: 0; }
            .preview-table tbody tr:nth-child(4n+3),
            .preview-table tbody tr:nth-child(4n+4) {
                background-color: #e8e8e8 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .preview-header {
                page-break-after: avoid;
                break-after: avoid;
                margin-bottom: 8px;
            }
            .preview-table {
                page-break-before: avoid;
                break-before: avoid;
                page-break-inside: auto;
            }
            .preview-table thead {
                display: table-header-group;
            }
            .preview-table tr {
                page-break-inside: avoid;
            }
            .preview-footer {
                page-break-before: avoid;
                break-before: avoid;
            }
            button { display: none; }
        }

        .print-preview {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: white;
        }

        .preview-header {
            margin-bottom: 6px;
        }

        .preview-header h1 {
            margin: 0 0 3px 0;
            font-size: 16px;
            color: #1f2937;
        }

        .preview-info {
            font-size: 11px;
            color: #6b7280;
            margin-top: 2px;
        }

        .preview-info p {
            margin: 0;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .preview-table thead {
            background-color: #f3f4f6;
            border-bottom: 2px solid #d1d5db;
        }

        .preview-table th {
            padding: 4px 8px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #374151;
        }

        .preview-table td {
            padding: 3px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 12px;
            line-height: 1.3;
        }

        .preview-table tbody tr:nth-child(4n+3),
        .preview-table tbody tr:nth-child(4n+4) {
            background-color: #f3f4f6;
        }

        .preview-table .text-center {
            text-align: center;
        }

        .preview-table .text-right {
            text-align: right;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-order {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-non-order {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .preview-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }

        .preview-footer .stats {
            display: flex;
            gap: 30px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
        }

        .stat-label {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }
    </style>

    <div class="print-preview">
        <div class="preview-header">
            <h1>' . $title . '</h1>
            <div class="preview-info">
                <p><strong>생성일:</strong> ' . date('Y-m-d H:i', strtotime($order_list['created_at'])) . '</p>
                <p><strong>수정일:</strong> ' . date('Y-m-d H:i', strtotime($order_list['updated_at'])) . '</p>
            </div>
        </div>

        <table class="preview-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>상품명</th>
                    <th class="text-center">수량</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($items as $item) {
        $product_name = $item['name_ko'] ?: $item['name_en'];
        $status_label = $item['order_status'] === '주문' ? '주문' : '비주문';
        $status_class = $item['order_status'] === '주문' ? 'status-order' : 'status-non-order';

        $html .= '
                <tr>
                    <td>' . htmlspecialchars($item['sku']) . '</td>
                    <td>' . htmlspecialchars($product_name) . ($item['name_en'] ? ' <span style="color:#9ca3af; font-size:11px;">(' . htmlspecialchars($item['name_en']) . ')</span>' : '') . '</td>
                    <td class="text-center"><strong>' . (int)$item['quantity'] . '</strong></td>
                </tr>';
    }

    $html .= '
            </tbody>
        </table>

        <div class="preview-footer">
            <div class="stats">
                <div class="stat-item">
                    <div class="stat-value">' . $total_items . '</div>
                    <div class="stat-label">총 상품 수</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">' . $total_quantity . '</div>
                    <div class="stat-label">총 수량</div>
                </div>
            </div>
            <div>' . date('Y년 m월 d일 H:i 생성') . '</div>
        </div>
    </div>';

    echo $html;

} catch (Exception $e) {
    error_log("Preview error: " . $e->getMessage());
    http_response_code(500);
    exit('오류가 발생했습니다: ' . $e->getMessage());
}
