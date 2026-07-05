<?php
// Design Ref: §3.1 - Business Logic Layer
// Plan SC: SC-1, SC-3, SC-4 마이너스 재고 생성/정상화

class StockService {
  private $stockRepo;
  private $auditService;

  public function __construct(StockRepository $stockRepository, AuditLogService $auditService) {
    $this->stockRepo = $stockRepository;
    $this->auditService = $auditService;
  }

  /**
   * 마이너스 재고 생성 (미등록 상품 출고 시)
   * @param int $productId
   * @param int $qty (출고 수량)
   * @param int $userId
   * @param int $branchId (optional)
   * @return bool
   */
  public function createNegativeStock($productId, $qty, $userId, $branchId = null) {
    try {
      $stock = $this->stockRepo->getStockByProductId($productId);

      if ($stock) {
        // 기존 재고 차감 (음수로)
        $newQty = $stock['quantity'] - $qty;
        $oldQty = $stock['quantity'];
      } else {
        // 신규 생성 (0 - qty = 음수)
        $newQty = -$qty;
        $oldQty = 0;
      }

      // DB 업데이트
      if (!$this->stockRepo->updateStock($productId, $newQty)) {
        return false;
      }

      // 감사 로그 기록
      $this->auditService->logStockChange(
        $productId,
        'NEGATIVE_STOCK',
        $oldQty,
        $newQty,
        '미등록 상품 출고',
        $userId,
        $branchId
      );

      return true;
    } catch (Exception $e) {
      error_log("StockService::createNegativeStock Error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 자동 정상화 (상품 등록 후)
   * @param int $productId
   * @param int $inboundQty (입고 수량)
   * @param int $userId
   * @return bool
   */
  public function normalizeStock($productId, $inboundQty, $userId) {
    try {
      $stock = $this->stockRepo->getStockByProductId($productId);

      // 음수 상태가 아니면 처리 안 함
      if (!$stock || $stock['quantity'] >= 0) {
        return true;
      }

      // 정상화: 음수 + 입고량 = 최종 재고
      $oldQty = $stock['quantity'];
      $newQty = $oldQty + $inboundQty;

      // DB 업데이트
      if (!$this->stockRepo->updateStock($productId, $newQty)) {
        return false;
      }

      // 감사 로그 기록
      $this->auditService->logStockChange(
        $productId,
        'NORMALIZE',
        $oldQty,
        $newQty,
        '상품 등록 후 자동 정상화',
        $userId
      );

      return true;
    } catch (Exception $e) {
      error_log("StockService::normalizeStock Error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 수동 조정 (관리자만)
   * @param int $productId
   * @param int $adjustQty (조정량: 음수 또는 양수)
   * @param string $reason (필수)
   * @param int $userId
   * @return bool
   */
  public function manualAdjustStock($productId, $adjustQty, $reason, $userId) {
    try {
      // 사유 필수 검증
      if (empty(trim($reason))) {
        return false;
      }

      $stock = $this->stockRepo->getStockByProductId($productId);
      if (!$stock) {
        return false;
      }

      $oldQty = $stock['quantity'];
      $newQty = $oldQty + $adjustQty;

      // DB 업데이트
      if (!$this->stockRepo->updateStock($productId, $newQty)) {
        return false;
      }

      // 감사 로그 기록
      $this->auditService->logStockChange(
        $productId,
        'MANUAL_ADJUST',
        $oldQty,
        $newQty,
        $reason,
        $userId
      );

      return true;
    } catch (Exception $e) {
      error_log("StockService::manualAdjustStock Error: " . $e->getMessage());
      return false;
    }
  }

  /**
   * 마이너스 재고 목록 조회
   * @param int $limit
   * @param int $offset
   * @return array
   */
  public function getNegativeStockList($limit = 50, $offset = 0) {
    return $this->stockRepo->getNegativeStocks($limit, $offset);
  }

  /**
   * 마이너스 재고 개수
   * @return int
   */
  public function getNegativeStockCount() {
    return $this->stockRepo->getNegativeStockCount();
  }

  /**
   * 현재 재고 조회
   * @param int $productId
   * @return int|null
   */
  public function getStockQuantity($productId) {
    $stock = $this->stockRepo->getStockByProductId($productId);
    return $stock ? $stock['quantity'] : null;
  }
}
?>
