<?php
// Design Ref: §3.2 - Business Logic Layer
// Plan SC: SC-5 감사 로그 기록

class AuditLogService {
  private $auditRepo;

  public function __construct(AuditLogRepository $auditRepository) {
    $this->auditRepo = $auditRepository;
  }

  /**
   * 재고 변경 로그 기록
   * @param int $productId
   * @param string $action (NEGATIVE_STOCK, NORMALIZE, MANUAL_ADJUST)
   * @param int $oldQty
   * @param int $newQty
   * @param string $reason
   * @param int $userId
   * @param int $branchId (optional)
   * @param int $stockId (optional)
   * @return bool
   */
  public function logStockChange($productId, $action, $oldQty, $newQty, $reason, $userId, $branchId = null, $stockId = null) {
    $logData = [
      'product_id' => $productId,
      'stock_id' => $stockId,
      'action' => $action,
      'old_quantity' => $oldQty,
      'new_quantity' => $newQty,
      'reason' => $reason,
      'branch_id' => $branchId,
      'user_id' => $userId,
      'created_at' => date('Y-m-d H:i:s')
    ];

    return $this->auditRepo->insertLog($logData);
  }

  /**
   * 제품의 재고 변경 이력 조회
   * @param int $productId
   * @param int $limit
   * @return array
   */
  public function getStockHistory($productId, $limit = 50) {
    return $this->auditRepo->getStockHistory($productId, $limit);
  }

  /**
   * 액션별 통계
   * @return array
   */
  public function getActionStats() {
    return [
      'negative_stock' => $this->auditRepo->getLogCountByAction('NEGATIVE_STOCK'),
      'normalize' => $this->auditRepo->getLogCountByAction('NORMALIZE'),
      'manual_adjust' => $this->auditRepo->getLogCountByAction('MANUAL_ADJUST')
    ];
  }
}
?>
