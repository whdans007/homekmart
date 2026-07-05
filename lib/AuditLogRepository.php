<?php
// Design Ref: §3.2 - Data Access Layer
// 감사 로그 데이터 접근 계층

class AuditLogRepository {
  private $conn;

  public function __construct($connection) {
    $this->conn = $connection;
  }

  /**
   * 감사 로그 기록
   * @param array $logData
   * @return bool
   */
  public function insertLog($logData) {
    $sql = "INSERT INTO stock_audit_log (product_id, stock_id, action, old_quantity, new_quantity, reason, branch_id, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $this->conn->prepare($sql);
    if (!$stmt) {
      return false;
    }

    $stmt->bind_param(
      'iisiiiii',
      $logData['product_id'],
      $logData['stock_id'] ?? null,
      $logData['action'],
      $logData['old_quantity'],
      $logData['new_quantity'],
      $logData['reason'],
      $logData['branch_id'] ?? null,
      $logData['user_id'],
      $logData['created_at']
    );

    $result = $stmt->execute();
    $stmt->close();

    return $result;
  }

  /**
   * 제품의 재고 변경 이력 조회
   * @param int $productId
   * @param int $limit
   * @return array
   */
  public function getStockHistory($productId, $limit = 50) {
    $sql = "
      SELECT
        id,
        action,
        old_quantity,
        new_quantity,
        reason,
        user_id,
        u.name as user_name,
        created_at
      FROM stock_audit_log aal
      LEFT JOIN users u ON aal.user_id = u.id
      WHERE product_id = ?
      ORDER BY created_at DESC
      LIMIT ?
    ";

    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param('ii', $productId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $history;
  }

  /**
   * 액션별 로그 개수
   * @param string $action
   * @return int
   */
  public function getLogCountByAction($action) {
    $sql = "SELECT COUNT(*) as count FROM stock_audit_log WHERE action = ?";
    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param('s', $action);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)$row['count'];
  }
}
?>
