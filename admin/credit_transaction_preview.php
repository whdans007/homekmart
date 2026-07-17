<?php
require_once __DIR__ . '/../lib/lang_helper.php';
$page_title = '외상거래 명세 - ' . t('company.name');
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../config/db_config.php';

if (!has_permission('wholesale_management')) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: shop.php');
    exit;
}

// 인쇄 상단 로고
// iframe 인쇄에서는 상대경로/루트경로가 깨지고, https↔http 혼합 콘텐츠 차단 문제도
// 발생할 수 있으므로 로고 파일을 data URI(base64)로 인라인하여 어떤 환경에서도 표시되게 함.
$logo_url  = '';
$logo_file = __DIR__ . '/../logo/homekmart_logo.png';
if (is_readable($logo_file)) {
    $logo_url = 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file));
} else {
    // 파일을 읽지 못하는 경우 절대 URL로 대체
    $_scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_base    = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''))), '/');
    $logo_url = $_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . $_base . '/logo/homekmart_logo.png';
}

$tx_id = (int)($_GET['id'] ?? 0);
$tx = null;
$items = [];
$errors = [];
$balance = 0;
$total_sales = 0;
$total_paid = 0;

// 거래 삭제(취소) 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $del_id = (int)($_POST['tx_id'] ?? 0);
    if ($del_id > 0) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $check_sql = "SELECT id, customer_id, transaction_date, final_amount FROM credit_transactions WHERE id = ?";
            if ($_SESSION['role'] !== 'super_admin') {
                $check_sql .= " AND store_id = " . (int)$current_store_id;
            }
            $check = $pdo->prepare($check_sql);
            $check->execute([$del_id]);
            $del_tx = $check->fetch(PDO::FETCH_ASSOC);

            if (!$del_tx) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제 권한이 없습니다.'];
            } else {
                // 이 거래에 수금이 충당되어 있는지 확인 (FIFO 기준, credit_transaction_preview.php 메인 로직과 동일한 계산)
                $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_payments WHERE customer_id = ?");
                $paid_stmt->execute([$del_tx['customer_id']]);
                $total_paid_check = (float)$paid_stmt->fetchColumn();

                $older_stmt = $pdo->prepare("
                    SELECT COALESCE(SUM(final_amount),0) FROM credit_transactions
                    WHERE customer_id = ? AND status = 'confirmed'
                      AND (transaction_date < ? OR (transaction_date = ? AND id < ?))
                ");
                $older_stmt->execute([$del_tx['customer_id'], $del_tx['transaction_date'], $del_tx['transaction_date'], $del_id]);
                $older_sum_check = (float)$older_stmt->fetchColumn();
                $tx_amount_check = (float)$del_tx['final_amount'];
                $tx_applied_check = max(0, min($tx_amount_check, $total_paid_check - $older_sum_check));

                if ($tx_applied_check > 0.005) {
                    $_SESSION['flash'] = [
                        'type' => 'error',
                        'message' => '이 거래는 이미 ' . number_format($tx_applied_check, 2) . '의 수금이 충당되어 있어 삭제할 수 없습니다. 먼저 거래처의 수금 기록을 확인하고 정리한 후 다시 시도해주세요.'
                    ];
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM credit_transaction_items WHERE transaction_id = ?")->execute([$del_id]);
                    $pdo->prepare("DELETE FROM credit_transactions WHERE id = ?")->execute([$del_id]);
                    $pdo->commit();
                    $_SESSION['flash'] = ['type' => 'success', 'message' => '외상거래가 삭제되었습니다.'];
                    header('Location: credit_transactions.php');
                    exit;
                }
            }
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollback();
            $_SESSION['flash'] = ['type' => 'error', 'message' => '삭제 중 오류: ' . $e->getMessage()];
        }
        header("Location: credit_transaction_preview.php?id=$del_id");
        exit;
    }
}

if ($tx_id > 0) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $sql = "
            SELECT ct.*, cc.name AS customer_name, cc.phone AS customer_phone, cc.address AS customer_address,
                   s.name AS store_name, s.phone AS store_phone, s.address AS store_address, s.bank_account AS store_bank_account, u.full_name AS user_name
            FROM credit_transactions ct
            LEFT JOIN credit_customers cc ON ct.customer_id = cc.id
            LEFT JOIN stores s ON ct.store_id = s.id
            LEFT JOIN users u ON ct.user_id = u.id
            WHERE ct.id = ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tx_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            $errors[] = '외상거래를 찾을 수 없습니다.';
        } else {
            $items_sql = "
                SELECT
                    cti.id, cti.product_id, cti.quantity, cti.unit_price, cti.total_price, cti.sale_unit, cti.remarks, cti.custom_product_name,
                    COALESCE(p.sku, '수기') AS sku,
                    COALESCE(wp.wholesale_name_ko, p.name_ko, cti.custom_product_name) AS name_ko,
                    COALESCE(wp.wholesale_name_en, p.name_en, cti.custom_product_name) AS name_en,
                    COALESCE(p.pieces_per_box, wp.min_quantity, 1) AS pieces_per_box
                FROM credit_transaction_items cti
                LEFT JOIN products p ON cti.product_id = p.id
                LEFT JOIN wholesale_products wp ON wp.product_id = p.id AND wp.store_id = ?
                WHERE cti.transaction_id = ?
                ORDER BY cti.sort_order ASC, cti.id ASC
            ";
            $items_stmt = $pdo->prepare($items_sql);
            $items_stmt->execute([$tx['store_id'], $tx_id]);
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

            // 거래처 잔액 집계 (확정 거래 합계 - 수금 합계)
            $sales_stmt = $pdo->prepare("SELECT COALESCE(SUM(final_amount),0) FROM credit_transactions WHERE customer_id = ? AND status = 'confirmed'");
            $sales_stmt->execute([$tx['customer_id']]);
            $total_sales = (float)$sales_stmt->fetchColumn();

            $paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM credit_payments WHERE customer_id = ?");
            $paid_stmt->execute([$tx['customer_id']]);
            $total_paid = (float)$paid_stmt->fetchColumn();

            $balance = $total_sales - $total_paid;

            // 이 거래의 FIFO 충당액 계산 (오래된 거래부터 수금 충당)
            $older_stmt = $pdo->prepare("
                SELECT COALESCE(SUM(final_amount),0) FROM credit_transactions
                WHERE customer_id = ? AND status = 'confirmed'
                  AND (transaction_date < ? OR (transaction_date = ? AND id < ?))
            ");
            $older_stmt->execute([$tx['customer_id'], $tx['transaction_date'], $tx['transaction_date'], $tx_id]);
            $older_sum = (float)$older_stmt->fetchColumn();
            $tx_amount = (float)$tx['final_amount'];
            $tx_applied = max(0, min($tx_amount, $total_paid - $older_sum));
            $tx_remaining = $tx_amount - $tx_applied;
            $tx_pay_status = ($tx_applied >= $tx_amount - 0.005 && $tx_amount > 0) ? 'paid' : ($tx_applied > 0 ? 'partial' : 'unpaid');
        }
    } catch (PDOException $e) {
        $errors[] = '데이터베이스 오류: ' . $e->getMessage();
    }
} else {
    $errors[] = '잘못된 거래 ID입니다.';
}

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>

<div class="px-2 sm:px-3 md:px-4 py-4">
    <div class="w-full max-w-none">
        <?php if (isset($flash)): ?>
            <div class="mb-6 p-4 rounded-md <?php echo $flash['type'] === 'error' ? 'bg-red-50 border border-red-200' : 'bg-green-50 border border-green-200'; ?>">
                <p class="text-sm <?php echo $flash['type'] === 'error' ? 'text-red-700' : 'text-green-700'; ?>"><?php echo htmlspecialchars($flash['message']); ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-md">
                <ul class="text-sm text-red-700 list-disc list-inside">
                    <?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
            <div class="text-center">
                <a href="credit_transactions.php" class="inline-flex items-center px-4 py-2 text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700">
                    <i class="fas fa-arrow-left mr-2"></i>목록으로
                </a>
            </div>
        <?php else: ?>
            <div id="invoice-content" class="bg-white shadow-sm rounded-lg border p-4 print:shadow-none print:border-none">
                <!-- 상단 가운데 로고 -->
                <div class="invoice-logo text-center mb-4">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Home K Mart" style="height:60px;display:inline-block;">
                </div>
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <h1 class="text-xl font-bold text-gray-900 mb-1">
                            <span id="invoice-title-text">외상 거래명세서</span>
                            <?php if ($tx_pay_status === 'paid'): ?>
                                <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800 align-middle"><i class="fas fa-check-circle mr-1"></i>완납</span>
                            <?php elseif ($tx_pay_status === 'partial'): ?>
                                <span class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-amber-100 text-amber-800 align-middle"><i class="fas fa-adjust mr-1"></i>부분수금</span>
                            <?php else: ?>
                                <span id="unpaid-status-badge" class="ml-2 inline-flex items-center px-2.5 py-1 rounded-full text-sm font-semibold bg-red-100 text-red-800 align-middle"><i class="fas fa-exclamation-circle mr-1"></i>미수</span>
                            <?php endif; ?>
                        </h1>
                    </div>
                    <div id="preview-action-buttons" class="flex gap-2 flex-shrink-0 print:hidden">
                        <a href="add_credit_transaction.php?edit=<?php echo $tx_id; ?>" class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700"><i class="fas fa-edit mr-1.5"></i>수정</a>
                        <button id="print-btn" class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700"><i class="fas fa-print mr-1.5"></i>인쇄</button>
                        <button id="delete-btn" class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700"><i class="fas fa-trash mr-1.5"></i>삭제</button>
                        <a href="credit_transactions.php" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"><i class="fas fa-arrow-left mr-1.5"></i>목록</a>
                    </div>
                </div>

                <!-- 거래처 정보 -->
                <div class="mb-6">
                    <table class="info-table w-full border border-gray-200 mb-4">
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:45%;">
                                    <span class="text-gray-500">거래처</span>
                                    <span class="cust-name font-bold text-base ml-2"><?php echo htmlspecialchars($tx['customer_name']); ?></span>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:27.5%;">
                                    <span class="text-gray-500">전화번호</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($tx['customer_phone'] ?: '-'); ?></span>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-gray-200" style="width:27.5%;">
                                    <span class="text-gray-500">거래일자</span>
                                    <span class="ml-2"><?php echo date('Y-m-d', strtotime($tx['transaction_date'])); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900" colspan="3">
                                    <span class="text-gray-500">주소</span>
                                    <span class="ml-2"><?php echo htmlspecialchars($tx['customer_address'] ?: '-'); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 상품 목록 -->
                <div class="mb-6">
                    <table class="product-table min-w-full border border-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b border-gray-200">SKU</th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase border-b border-gray-200">상품명</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b border-gray-200">박스입수</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b border-gray-200">수량</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b border-gray-200">단가</th>
                                <th class="px-2 py-2 text-right text-xs font-medium text-gray-500 uppercase border-b border-gray-200">합계</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200"><?php echo htmlspecialchars($item['sku']); ?></td>
                                    <td class="px-2 py-2 text-sm text-gray-900 border-b border-gray-200">
                                        <?php if ($item['name_en']): ?><div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name_en']); ?></div><?php endif; ?>
                                        <?php if ($item['name_ko'] && $item['name_ko'] !== $item['name_en']): ?><div class="text-sm text-gray-600"><?php echo htmlspecialchars($item['name_ko']); ?></div><?php endif; ?>
                                        <?php if (!$item['name_en'] && !$item['name_ko']): ?><div class="text-sm text-gray-500">-</div><?php endif; ?>
                                    </td>
                                    <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['pieces_per_box']); ?></td>
                                    <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['quantity']); ?></td>
                                    <td class="px-2 py-2 text-sm text-gray-900 text-right border-b border-gray-200"><?php echo number_format($item['unit_price']); ?></td>
                                    <td class="px-2 py-2 text-sm text-gray-900 text-right font-medium border-b border-gray-200"><?php echo number_format($item['total_price']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50 grand-total-row">
                            <tr>
                                <td colspan="5" class="px-2 py-2 text-right text-sm font-medium text-gray-900 border-t border-gray-200 grand-total-label">이번 거래 합계:</td>
                                <td class="px-2 py-2 text-right text-lg font-bold text-gray-900 border-t border-gray-200 grand-total-value"><?php echo number_format($tx['final_amount']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- 거래처 외상 잔액 요약 (인쇄 제외) -->
                <div id="balance-summary" class="mb-6 flex justify-end">
                    <table class="balance-table w-full max-w-md border border-gray-300">
                        <tbody>
                            <tr>
                                <th class="bg-blue-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-300">이 거래 금액</th>
                                <td class="px-3 py-2 text-sm text-right text-gray-900 border-b border-gray-300"><?php echo number_format($tx_amount); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-blue-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-300">이 거래 충당(수금)</th>
                                <td class="px-3 py-2 text-sm text-right text-blue-700 border-b border-gray-300"><?php echo number_format($tx_applied); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-blue-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-300">이 거래 미수 잔여</th>
                                <td class="px-3 py-2 text-sm text-right font-semibold <?php echo $tx_remaining > 0.005 ? 'text-red-600' : 'text-gray-900'; ?> border-b border-gray-300"><?php echo number_format($tx_remaining); ?></td>
                            </tr>
                            <tr>
                                <th colspan="2" class="bg-gray-100 px-3 py-1.5 text-left text-xs font-semibold text-gray-500 border-b border-gray-300">거래처 전체 누계</th>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-300">외상매출 누계</th>
                                <td class="px-3 py-2 text-sm text-right text-gray-900 border-b border-gray-300"><?php echo number_format($total_sales); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-3 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-300">수금 누계</th>
                                <td class="px-3 py-2 text-sm text-right text-blue-700 border-b border-gray-300"><?php echo number_format($total_paid); ?></td>
                            </tr>
                            <tr>
                                <th class="bg-red-50 px-3 py-2 text-left text-sm font-bold text-red-800 border-r border-gray-300">미수금 잔액</th>
                                <td class="px-3 py-2 text-base text-right font-bold <?php echo $balance > 0 ? 'text-red-600' : 'text-gray-900'; ?>"><?php echo number_format($balance); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($tx['notes'])): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">비고</h3>
                        <div class="bg-gray-50 p-4 rounded-lg text-gray-700"><?php echo nl2br(htmlspecialchars($tx['notes'])); ?></div>
                    </div>
                <?php endif; ?>

                <!-- 작성자 / 인수자 서명란 -->
                <div id="signOffBox" class="mb-6">
                    <table class="payment-table w-full border border-gray-300" style="table-layout: fixed;">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300" style="width: 33.33%;">작성자 (Prepared by)</th>
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-r border-gray-300" style="width: 33.33%;">인수자 (Received by)</th>
                                <th class="px-3 py-2 text-center text-sm font-medium text-gray-700 border-b border-gray-300" style="width: 33.34%;">Cashier</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-sm text-gray-900 border-r border-gray-300" style="vertical-align: top;">
                                    <div>성명: <strong><?php echo htmlspecialchars($tx['user_name']); ?></strong></div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;">서명: _____________________</div>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900 border-r border-gray-300" style="vertical-align: top;">
                                    <div>성명: <?php echo htmlspecialchars($tx['customer_name']); ?></div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;">서명: _____________________</div>
                                </td>
                                <td class="px-3 py-2 text-sm text-gray-900" style="vertical-align: top;">
                                    <div>성명: _____________________</div>
                                    <div class="sign-space" style="height: 50px;"></div>
                                    <div style="text-align: right;">서명: _____________________</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 점포 정보 -->
                <div class="mb-6">
                    <table class="info-table w-full border border-gray-200">
                        <tbody>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200" style="width:12%;">점포명</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:23%;"><?php echo htmlspecialchars($tx['store_name'] ?: '-'); ?></td>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200" style="width:12%;">전화</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" style="width:18%;"><?php echo htmlspecialchars($tx['store_phone'] ?: '-'); ?></td>
                                <td class="px-3 py-2 text-xs text-gray-500 border-b border-gray-200" style="width:35%; vertical-align: middle;">판매가격은 구매 시점에 따라 변경 될 수 있습니다.</td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-b border-r border-gray-200">주소</th>
                                <td class="px-3 py-2 text-sm text-gray-900 border-b border-r border-gray-200" colspan="3"><?php echo htmlspecialchars($tx['store_address'] ?: '-'); ?></td>
                                <td class="px-3 py-2 text-xs text-gray-500 border-b border-gray-200" style="vertical-align: middle;">계산대에서 개별 구매시 가격은 일치 하지 않습니다.</td>
                            </tr>
                            <tr>
                                <th class="bg-gray-50 px-2 py-2 text-left text-sm font-medium text-gray-700 border-r border-gray-200">계좌번호</th>
                                <td class="px-3 py-2 text-sm text-gray-900" colspan="4"><?php echo htmlspecialchars($tx['store_bank_account'] ?: '-'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 삭제 확인 모달 -->
<div id="delete-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100"><i class="fas fa-exclamation-triangle text-red-600"></i></div>
                </div>
                <div class="text-center">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">외상거래 삭제</h3>
                    <div class="text-sm text-gray-500 mb-4">
                        <?php if (($tx_pay_status ?? 'unpaid') !== 'unpaid'): ?>
                            <p class="font-semibold text-red-600">이 거래는 이미 <?php echo number_format($tx_applied ?? 0, 2); ?>의 수금이 충당되어 있어 삭제할 수 없습니다.</p>
                            <p class="mt-2">먼저 거래처의 수금 기록을 확인하고 정리한 후 다시 시도해주세요.</p>
                        <?php else: ?>
                            <p>이 외상거래를 삭제하시겠습니까?</p>
                            <p class="font-semibold text-red-600 mt-2">삭제된 데이터는 복구할 수 없습니다.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex space-x-3 justify-center">
                    <?php if (($tx_pay_status ?? 'unpaid') !== 'unpaid'): ?>
                        <button id="cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">확인</button>
                    <?php else: ?>
                        <button id="cancel-delete" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">취소</button>
                        <button id="confirm-delete" type="button" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">삭제</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<form id="delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="tx_id" value="<?php echo $tx_id; ?>">
</form>

<!-- 인쇄 미리보기 모달 -->
<div id="print-preview-modal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-50 overflow-y-auto">
    <div class="min-h-screen px-4 py-6">
        <!-- 모달 헤더 (고정) -->
        <div class="sticky top-0 z-10 bg-white rounded-t-lg mx-auto px-4 py-3 flex items-center justify-between border-b" style="max-width: 941px;">
            <h3 class="text-lg font-semibold text-gray-900">인쇄 미리보기</h3>
            <div class="flex items-center gap-3">
                <button id="modal-print-btn" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                    <i class="fas fa-print mr-2"></i>인쇄
                </button>
                <button id="modal-close-btn" class="inline-flex items-center px-4 py-2 bg-gray-500 text-white text-sm font-medium rounded-md hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i>닫기
                </button>
            </div>
        </div>
        <!-- 모달 콘텐츠 -->
        <div class="bg-white mx-auto rounded-b-lg shadow-xl" style="max-width: 941px;">
            <div id="print-preview-content" class="print-preview-wrapper p-4">
                <!-- 여기에 invoice-content가 복사됨 -->
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const printBtn = document.getElementById('print-btn');
    const deleteBtn = document.getElementById('delete-btn');
    const deleteModal = document.getElementById('delete-modal');
    const cancelDelete = document.getElementById('cancel-delete');
    const confirmDelete = document.getElementById('confirm-delete');
    const deleteForm = document.getElementById('delete-form');
    const printModal = document.getElementById('print-preview-modal');
    const printPreviewContent = document.getElementById('print-preview-content');
    const modalCloseBtn = document.getElementById('modal-close-btn');
    const modalPrintBtn = document.getElementById('modal-print-btn');

    // 인쇄 버튼 - 모달 팝업으로 미리보기 표시
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            const invoiceContent = document.getElementById('invoice-content');
            if (!invoiceContent || !printModal || !printPreviewContent) return;
            // 복제본에서 액션 버튼 제거 후 미리보기에 표시
            const clone = invoiceContent.cloneNode(true);
            const actionBtns = clone.querySelector('#preview-action-buttons');
            if (actionBtns) actionBtns.remove();
            // 거래처 외상 잔액 요약은 인쇄/미리보기에서 제외
            const balanceBlock = clone.querySelector('#balance-summary');
            if (balanceBlock) balanceBlock.remove();
            // '미수' 상태 배지는 인쇄/미리보기에서 제외 (화면에서는 계속 표시)
            const unpaidBadge = clone.querySelector('#unpaid-status-badge');
            if (unpaidBadge) unpaidBadge.remove();
            // 명세서 제목("외상 거래명세서")은 인쇄/미리보기에서 제외 (화면에서는 계속 표시)
            const titleText = clone.querySelector('#invoice-title-text');
            if (titleText) titleText.remove();
            printPreviewContent.innerHTML = clone.outerHTML;
            printModal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        });
    }

    // 미리보기 모달 닫기
    function closePrintModal() {
        if (printModal) printModal.classList.add('hidden');
        document.body.style.overflow = '';
    }
    if (modalCloseBtn) modalCloseBtn.addEventListener('click', closePrintModal);
    if (printModal) printModal.addEventListener('click', function(e) { if (e.target === printModal) closePrintModal(); });

    // 미리보기 모달 - 인쇄 버튼 (iframe 출력)
    if (modalPrintBtn) {
        modalPrintBtn.addEventListener('click', function() {
            const singleContent = printPreviewContent.innerHTML;
            // 출력 부수 (동일 명세서 1부, 1페이지)
            const COPIES = 1;
            let printContent = '';
            for (let i = 0; i < COPIES; i++) {
                printContent += '<div class="print-copy">' + singleContent + '</div>';
            }

            // 인쇄용 iframe 생성 (앱 레이아웃 셸과 분리하여 인쇄)
            const printFrame = document.createElement('iframe');
            printFrame.style.position = 'absolute';
            printFrame.style.top = '-10000px';
            printFrame.style.left = '-10000px';
            document.body.appendChild(printFrame);

            const printDoc = printFrame.contentDocument || printFrame.contentWindow.document;
            printDoc.open();
            printDoc.write(`
                <!DOCTYPE html>
                <html lang="ko">
                <head>
                    <meta charset="UTF-8">
                    <title>외상 거래명세서</title>
                    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    <style>
                        body { font-family: 'Malgun Gothic', sans-serif; font-size: 10px; line-height: 1.3; padding: 5mm; }
                        body, body * { color: #000 !important; }
                        /* 출력 표 테두리 검정 통일 (회색 → 검정) */
                        table { border-collapse: collapse !important; }
                        .info-table, .info-table th, .info-table td,
                        .product-table, .product-table th, .product-table td,
                        .balance-table, .balance-table th, .balance-table td,
                        .payment-table, .payment-table th, .payment-table td {
                            border: 1px solid #000 !important;
                        }
                        .payment-table { width: 100% !important; table-layout: fixed !important; }
                        .payment-table th, .payment-table td { font-size: 10px !important; padding: 4px 6px !important; }
                        .payment-table .sign-space { height: 40px !important; }
                        #invoice-content { box-shadow: none; border: none; }
                        .invoice-logo { text-align: center !important; margin-bottom: 8px !important; }
                        .invoice-logo img { height: 55px !important; display: inline-block !important; }
                        h1 { font-size: 16px !important; margin-bottom: 8px !important; }
                        h3 { font-size: 12px !important; }
                        table { font-size: 10px !important; }
                        table th { font-size: 9px !important; padding: 3px 5px !important; }
                        table td { font-size: 10px !important; padding: 3px 5px !important; }
                        .info-table th, .info-table td { font-size: 10px !important; padding: 3px 5px !important; }
                        .balance-table { width: 100% !important; max-width: 360px !important; margin-left: auto; }
                        .balance-table th, .balance-table td { font-size: 10px !important; padding: 3px 5px !important; }
                        .product-table { table-layout: fixed; width: 100%; }
                        .product-table th:nth-child(1), .product-table td:nth-child(1) { width: 12%; }
                        .product-table th:nth-child(2), .product-table td:nth-child(2) { width: 38%; }
                        .product-table td:nth-child(2) div { font-size: 10px !important; line-height: 1.25 !important; }
                        .product-table th:nth-child(3), .product-table td:nth-child(3) { width: 11%; }
                        .product-table th:nth-child(4), .product-table td:nth-child(4) { width: 9%; }
                        .product-table th:nth-child(5), .product-table td:nth-child(5) { width: 14%; }
                        .product-table th:nth-child(6), .product-table td:nth-child(6) { width: 16%; }
                        .product-table tfoot .grand-total-value { font-size: 16px !important; }
                        .product-table tfoot .grand-total-label { font-size: 13px !important; }
                        @page { size: A4; margin: 10mm; }
                        .print-copy { page-break-after: always; }
                        .print-copy:last-child { page-break-after: auto; }
                        .product-table { page-break-inside: auto !important; }
                        .product-table thead { display: table-header-group; }
                        .product-table tbody tr { page-break-inside: avoid; }
                    </style>
                </head>
                <body>${printContent}</body>
                </html>
            `);
            printDoc.close();

            // CSS 로딩 대기 후 인쇄
            printFrame.onload = function() {
                setTimeout(function() {
                    printFrame.contentWindow.focus();
                    printFrame.contentWindow.print();
                    setTimeout(function() { document.body.removeChild(printFrame); }, 1000);
                }, 500);
            };
        });
    }

    // 삭제 처리
    if (deleteBtn) deleteBtn.addEventListener('click', function() { deleteModal.classList.remove('hidden'); });
    if (cancelDelete) cancelDelete.addEventListener('click', function() { deleteModal.classList.add('hidden'); });
    if (confirmDelete) confirmDelete.addEventListener('click', function() { deleteForm.submit(); });
    if (deleteModal) deleteModal.addEventListener('click', function(e) { if (e.target === deleteModal) deleteModal.classList.add('hidden'); });

    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Escape') return;
        if (deleteModal && !deleteModal.classList.contains('hidden')) deleteModal.classList.add('hidden');
        if (printModal && !printModal.classList.contains('hidden')) closePrintModal();
    });
});
</script>

<style>
/* 인쇄 미리보기 모달 스타일 (실제 출력은 iframe에서 별도 처리) */
.print-preview-wrapper { font-size: 10px !important; line-height: 1.3; }
.print-preview-wrapper #invoice-content { box-shadow: none; border: none; padding: 10px; }
/* 미리보기 표 테두리 검정 통일 (회색 → 검정) */
.print-preview-wrapper table { border-collapse: collapse !important; }
.print-preview-wrapper .info-table,
.print-preview-wrapper .info-table th,
.print-preview-wrapper .info-table td,
.print-preview-wrapper .product-table,
.print-preview-wrapper .product-table th,
.print-preview-wrapper .product-table td,
.print-preview-wrapper .balance-table,
.print-preview-wrapper .balance-table th,
.print-preview-wrapper .balance-table td,
.print-preview-wrapper .payment-table,
.print-preview-wrapper .payment-table th,
.print-preview-wrapper .payment-table td {
    border: 1px solid #000 !important;
}
.print-preview-wrapper .payment-table { width: 100% !important; table-layout: fixed !important; }
.print-preview-wrapper .payment-table th,
.print-preview-wrapper .payment-table td { font-size: 10px !important; padding: 4px 6px !important; }
.print-preview-wrapper .payment-table .sign-space { height: 36px !important; }
.print-preview-wrapper .invoice-logo { text-align: center !important; margin-bottom: 8px !important; }
.print-preview-wrapper .invoice-logo img { height: 50px !important; display: inline-block !important; }
.print-preview-wrapper h1 { font-size: 16px !important; margin-bottom: 8px !important; }
.print-preview-wrapper h3 { font-size: 12px !important; }
.print-preview-wrapper table { font-size: 10px !important; }
.print-preview-wrapper table th { font-size: 9px !important; padding: 3px 5px !important; }
.print-preview-wrapper table td { font-size: 10px !important; padding: 3px 5px !important; }
.print-preview-wrapper .info-table th,
.print-preview-wrapper .info-table td { font-size: 10px !important; padding: 3px 5px !important; }
.print-preview-wrapper .balance-table { width: 100% !important; max-width: 360px !important; margin-left: auto; }
.print-preview-wrapper .balance-table th,
.print-preview-wrapper .balance-table td { font-size: 10px !important; padding: 3px 5px !important; }
.print-preview-wrapper .product-table { table-layout: fixed; width: 100%; }
.print-preview-wrapper .product-table th:nth-child(1),
.print-preview-wrapper .product-table td:nth-child(1) { width: 12%; }
.print-preview-wrapper .product-table th:nth-child(2),
.print-preview-wrapper .product-table td:nth-child(2) { width: 38%; }
.print-preview-wrapper .product-table td:nth-child(2) div { font-size: 10px !important; line-height: 1.25 !important; }
.print-preview-wrapper .product-table th:nth-child(3),
.print-preview-wrapper .product-table td:nth-child(3) { width: 11%; }
.print-preview-wrapper .product-table th:nth-child(4),
.print-preview-wrapper .product-table td:nth-child(4) { width: 9%; }
.print-preview-wrapper .product-table th:nth-child(5),
.print-preview-wrapper .product-table td:nth-child(5) { width: 14%; }
.print-preview-wrapper .product-table th:nth-child(6),
.print-preview-wrapper .product-table td:nth-child(6) { width: 16%; }
.print-preview-wrapper .product-table tfoot .grand-total-value { font-size: 16px !important; }
.print-preview-wrapper .product-table tfoot .grand-total-label { font-size: 13px !important; }
</style>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
