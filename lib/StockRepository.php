<?php
// Design Ref: §3.1 - Data Access Layer
// 재고 데이터 접근 계층

class StockRepository {
  private $conn;

  public function __construct($connection) {
    $this->conn = $connection;
  }

  /**
   * 제품별 재고 조회
   * @param int $productId
   * @return array|null
   */
  public function getStockByProductId($productId) {
    $sql = "SELECT * FROM stock WHERE product_id = ? LIMIT 1";
    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stock = $result->fetch_assoc();
    $stmt->close();
    return $stock;
  }

  /**
   * 재고 생성 또는 업데이트
   * @param int $productId
   * @param int $quantity
   * @return bool
   */
  public function updateStock($productId, $quantity) {
    // 기존 재고 확인
    $existing = $this->getStockByProductId($productId);

    if ($existing) {
      // 업데이트
      $sql = "UPDATE stock SET quantity = ? WHERE product_id = ?";
      $stmt = $this->conn->prepare($sql);
      $stmt->bind_param('ii', $quantity, $productId);
    } else {
      // 신규 생성
      $sql = "INSERT INTO stock (product_id, quantity) VALUES (?, ?)";
      $stmt = $this->conn->prepare($sql);
      $stmt->bind_param('ii', $productId, $quantity);
    }

    $result = $stmt->execute();
    $stmt->close();
    return $result;
  }

  /**
   * 마이너스 재고 목록 조회
   * @param int $limit
   * @param int $offset
   * @return array
   */
  public function getNegativeStocks($limit = 50, $offset = 0) {
    $sql = "
      SELECT
        s.id,
        s.product_id,
        p.name_en as product_name,
        s.quantity,
        aal.created_at,
        u.name as user_name
      FROM stock s
      JOIN products p ON s.product_id = p.id
      LEFT JOIN stock_audit_log aal ON s.product_id = aal.product_id
        AND aal.action = 'NEGATIVE_STOCK'
        AND aal.id = (
          SELECT MAX(id) FROM stock_audit_log
          WHERE product_id = s.product_id AND action = 'NEGATIVE_STOCK'
        )
      LEFT JOIN users u ON aal.user_id = u.id
      WHERE s.quantity < 0
      ORDER BY aal.created_at DESC
      LIMIT ? OFFSET ?
    ";

    $stmt = $this->conn->prepare($sql);
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $stocks = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $stocks;
  }

  /**
   * 마이너스 재고 개수
   * @return int
   */
  public function getNegativeStockCount() {
    $sql = "SELECT COUNT(*) as count FROM stock WHERE quantity < 0";
    $stmt = $this->conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return (int)$row['count'];
  }
}
?>
