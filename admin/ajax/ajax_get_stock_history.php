<?php
// Design Ref: §4 - 재고 변경 이력 조회 API
// 대시보드의 "History" 버튼에서 호출

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/AuditLogService.php';
require_once __DIR__ . '/../lib/AuditLogRepository.php';

// 세션 확인
session_start();
if (!isset($_SESSION['user_id'])) {
  echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
  exit;
}

$productId = (int)($_GET['product_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 50);

if ($productId <= 0) {
  echo json_encode(['success' => false, 'message' => '유효하지 않은 상품 ID입니다.']);
  exit;
}

try {
  $conn = get_lc_db();

  $auditRepo = new AuditLogRepository($conn);
  $auditService = new AuditLogService($auditRepo);

  // 재고 변경 이력 조회
  $history = $auditService->getStockHistory($productId, $limit);

  echo json_encode([
    'success' => true,
    'history' => $history
  ]);

  $conn->close();

} catch (Exception $e) {
  error_log("ajax_get_stock_history Error: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => '시스템 오류']);
}
?>
