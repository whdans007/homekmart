<?php
// Design Ref: §4 - 마이너스 재고 목록 조회 API
// Plan SC: SC-2 마이너스 재고 상태 UI 표시

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

$limit = (int)($_GET['limit'] ?? 50);
$offset = (int)($_GET['offset'] ?? 0);

try {
  $conn = get_lc_db();

  $stockRepo = new StockRepository($conn);
  $stockService = new StockService($stockRepo, null);

  $negativeStocks = $stockService->getNegativeStockList($limit, $offset);
  $totalCount = $stockService->getNegativeStockCount();

  echo json_encode([
    'success' => true,
    'data' => $negativeStocks,
    'total_count' => $totalCount,
    'limit' => $limit,
    'offset' => $offset
  ]);

  $conn->close();

} catch (Exception $e) {
  error_log("ajax_get_negative_stocks Error: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => '시스템 오류']);
}
?>
