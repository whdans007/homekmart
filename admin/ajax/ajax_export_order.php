<?php
// Design Ref: §3 - 출고 처리 AJAX
// Plan SC: SC-1 마이너스 재고 자동 생성

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/StockService.php';
require_once __DIR__ . '/../lib/StockRepository.php';
require_once __DIR__ . '/../lib/AuditLogService.php';
require_once __DIR__ . '/../lib/AuditLogRepository.php';

// 세션 확인
session_start();
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
  exit;
}

// POST 데이터 수집
$productId = (int)($_POST['product_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 0);
$branchId = (int)($_POST['branch_id'] ?? 0);

// 검증
if ($productId <= 0 || $quantity <= 0) {
  echo json_encode(['success' => false, 'message' => '유효하지 않은 입력값입니다.']);
  exit;
}

try {
  $conn = get_lc_db();

  // 제품이 등록되어 있는지 확인
  $checkStmt = $conn->prepare("SELECT id, name_en FROM products WHERE id = ? LIMIT 1");
  $checkStmt->bind_param('i', $productId);
  $checkStmt->execute();
  $product = $checkStmt->get_result()->fetch_assoc();
  $checkStmt->close();

  if (!$product) {
    echo json_encode(['success' => false, 'message' => '상품을 찾을 수 없습니다.']);
    exit;
  }

  // Service 초기화
  $stockRepo = new StockRepository($conn);
  $auditRepo = new AuditLogRepository($conn);
  $auditService = new AuditLogService($auditRepo);
  $stockService = new StockService($stockRepo, $auditService);

  // 출고 처리
  $success = $stockService->createNegativeStock($productId, $quantity, $_SESSION['user_id'], $branchId);

  if ($success) {
    $newStock = $stockService->getStockQuantity($productId);
    echo json_encode([
      'success' => true,
      'message' => '출고가 완료되었습니다.',
      'product_id' => $productId,
      'product_name' => $product['name_en'],
      'new_stock' => $newStock,
      'is_negative' => $newStock < 0
    ]);
  } else {
    echo json_encode(['success' => false, 'message' => '출고 처리 중 오류가 발생했습니다.']);
  }

  $conn->close();

} catch (Exception $e) {
  error_log("ajax_export_order Error: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => '시스템 오류: ' . $e->getMessage()]);
}
?>
