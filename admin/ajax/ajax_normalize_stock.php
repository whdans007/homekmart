<?php
// Design Ref: §4 - 재고 정상화 API
// Plan SC: SC-4 수동 정상화 UI 제공

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/StockService.php';
require_once __DIR__ . '/../lib/StockRepository.php';
require_once __DIR__ . '/../lib/AuditLogService.php';
require_once __DIR__ . '/../lib/AuditLogRepository.php';

// 세션 확인 + 관리자 권한 확인
session_start();
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
  exit;
}

// 관리자 권한 확인 (실제 구현에서는 권한 체크 추가)
// if ($_SESSION['role'] !== 'admin') { ... }

$productId = (int)($_POST['product_id'] ?? 0);
$method = $_POST['method'] ?? 'manual'; // 'manual' or 'auto'
$adjustQty = (int)($_POST['adjust_qty'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

// 검증
if ($productId <= 0) {
  echo json_encode(['success' => false, 'message' => '유효하지 않은 상품 ID입니다.']);
  exit;
}

if ($method === 'manual' && empty($reason)) {
  echo json_encode(['success' => false, 'message' => '조정 사유는 필수입니다.']);
  exit;
}

try {
  $conn = get_lc_db();

  $stockRepo = new StockRepository($conn);
  $auditRepo = new AuditLogRepository($conn);
  $auditService = new AuditLogService($auditRepo);
  $stockService = new StockService($stockRepo, $auditService);

  $success = false;

  if ($method === 'manual') {
    // 수동 조정
    $success = $stockService->manualAdjustStock($productId, $adjustQty, $reason, $_SESSION['user_id']);
  } else if ($method === 'auto') {
    // 자동 정상화 (입고량 필요)
    $success = $stockService->normalizeStock($productId, $adjustQty, $_SESSION['user_id']);
  }

  if ($success) {
    $newStock = $stockService->getStockQuantity($productId);
    echo json_encode([
      'success' => true,
      'message' => '정상화가 완료되었습니다.',
      'product_id' => $productId,
      'new_stock' => $newStock,
      'is_negative' => $newStock < 0
    ]);
  } else {
    echo json_encode(['success' => false, 'message' => '정상화 처리 중 오류가 발생했습니다.']);
  }

  $conn->close();

} catch (Exception $e) {
  error_log("ajax_normalize_stock Error: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => '시스템 오류']);
}
?>
