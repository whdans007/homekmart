<?php
// Design Ref: §4 - 마이너스 재고 관리 대시보드
// Plan SC: SC-4 수동 정상화 UI 제공

$page_title = 'Negative Stock Management - Admin';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/StockService.php';
require_once __DIR__ . '/lib/StockRepository.php';
require_once __DIR__ . '/lib/AuditLogService.php';
require_once __DIR__ . '/lib/AuditLogRepository.php';

// 관리자 권한 확인
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
  header('Location: /admin/login.php');
  exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
  $conn = get_lc_db();

  $stockRepo = new StockRepository($conn);
  $stockService = new StockService($stockRepo, null);

  // 마이너스 재고 목록 조회
  $negativeStocks = $stockService->getNegativeStockList($limit, $offset);
  $totalCount = $stockService->getNegativeStockCount();
  $totalPages = max(1, ceil($totalCount / $limit));

} catch (Exception $e) {
  $error_msg = $e->getMessage();
  $negativeStocks = [];
  $totalCount = 0;
  $totalPages = 1;
}
?>

<style>
  .dashboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 8px;
    margin-bottom: 30px;
  }
  .stat-card {
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
    margin-bottom: 20px;
  }
  .stat-card .number {
    font-size: 32px;
    font-weight: bold;
    color: #667eea;
  }
  .stat-card .label {
    color: #666;
    margin-top: 10px;
    font-size: 14px;
  }
  .negative-row {
    background-color: #fff3cd;
    border-left: 4px solid #ffc107;
  }
  .quantity-badge {
    display: inline-block;
    background-color: #f8d7da;
    color: #721c24;
    padding: 8px 12px;
    border-radius: 4px;
    font-weight: bold;
  }
  .modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
  }
  .modal.active {
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .modal-content {
    background-color: white;
    padding: 30px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  }
</style>

<div class="container-fluid py-4">

  <!-- 헤더 -->
  <div class="dashboard-header">
    <h1>⚠️ Negative Stock Management</h1>
    <p class="mb-0">마이너스 재고를 모니터링하고 정상화합니다.</p>
  </div>

  <!-- 통계 카드 -->
  <div class="row mb-4">
    <div class="col-md-4">
      <div class="stat-card">
        <div class="number"><?php echo $totalCount; ?></div>
        <div class="label">Total Negative Stocks</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="number"><?php echo $page; ?> / <?php echo $totalPages; ?></div>
        <div class="label">Pages</div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="stat-card">
        <div class="number"><?php echo count($negativeStocks); ?></div>
        <div class="label">This Page</div>
      </div>
    </div>
  </div>

  <!-- 에러 메시지 -->
  <?php if (isset($error_msg)): ?>
  <div class="alert alert-danger alert-dismissible fade show">
    <strong>Error:</strong> <?php echo htmlspecialchars($error_msg); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- 테이블 -->
  <div class="card">
    <div class="card-header bg-light">
      <h5 class="mb-0">Negative Stock List</h5>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Product Name</th>
            <th>Current Stock</th>
            <th>Created Date</th>
            <th>Created By</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($negativeStocks)): ?>
          <tr>
            <td colspan="6" class="text-center py-4 text-muted">
              <i class="fas fa-check-circle" style="font-size: 32px; opacity: 0.5;"></i>
              <p class="mt-2">모든 재고가 정상 상태입니다!</p>
            </td>
          </tr>
          <?php else: ?>
            <?php $row_num = $offset + 1; ?>
            <?php foreach ($negativeStocks as $stock): ?>
            <tr class="negative-row">
              <td><?php echo $row_num++; ?></td>
              <td>
                <strong><?php echo htmlspecialchars($stock['product_name']); ?></strong>
                <small class="text-muted d-block">ID: <?php echo $stock['product_id']; ?></small>
              </td>
              <td>
                <span class="quantity-badge">
                  <i class="fas fa-arrow-down"></i> <?php echo $stock['quantity']; ?>
                </span>
              </td>
              <td>
                <small><?php echo $stock['created_at']; ?></small>
              </td>
              <td>
                <small><?php echo htmlspecialchars($stock['user_name'] ?? '—'); ?></small>
              </td>
              <td>
                <button class="btn btn-sm btn-primary" onclick="openNormalizeModal(<?php echo $stock['product_id']; ?>, '<?php echo htmlspecialchars($stock['product_name']); ?>')">
                  <i class="fas fa-arrow-up"></i> Normalize
                </button>
                <button class="btn btn-sm btn-info" onclick="viewHistory(<?php echo $stock['product_id']; ?>)">
                  <i class="fas fa-history"></i> History
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 페이지네이션 -->
  <?php if ($totalPages > 1): ?>
  <nav class="mt-4">
    <ul class="pagination justify-content-center">
      <?php if ($page > 1): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?php echo $page - 1; ?>">← Previous</a>
      </li>
      <?php endif; ?>

      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
      <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
      </li>
      <?php endfor; ?>

      <?php if ($page < $totalPages): ?>
      <li class="page-item">
        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next →</a>
      </li>
      <?php endif; ?>
    </ul>
  </nav>
  <?php endif; ?>

</div>

<!-- 정상화 모달 -->
<div id="normalizeModal" class="modal">
  <div class="modal-content">
    <h4>정상화</h4>
    <form id="normalizeForm" onsubmit="submitNormalize(event)">
      <input type="hidden" name="product_id" id="normalizeProductId">

      <div class="mb-3">
        <label class="form-label">Product</label>
        <input type="text" class="form-control" id="normalizeProductName" disabled>
      </div>

      <div class="mb-3">
        <label class="form-label">Method</label>
        <select name="method" id="normalizeMethod" class="form-select" onchange="toggleMethodFields()">
          <option value="manual">Manual Adjustment (수동 조정)</option>
          <option value="auto">Auto Normalize (자동 정상화)</option>
        </select>
      </div>

      <div class="mb-3" id="qtyField">
        <label class="form-label">Adjustment Quantity</label>
        <input type="number" name="adjust_qty" id="adjustQty" class="form-control" required>
      </div>

      <div class="mb-3" id="reasonField">
        <label class="form-label">Reason</label>
        <textarea name="reason" id="normalizeReason" class="form-control" rows="3" placeholder="조정 사유를 입력하세요 (필수)"></textarea>
      </div>

      <div class="alert alert-info" id="methodInfo">
        <small>수동 조정: 재고를 직접 조정합니다.</small>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-1">
          <i class="fas fa-check"></i> Normalize
        </button>
        <button type="button" class="btn btn-secondary" onclick="closeNormalizeModal()">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<!-- 이력 모달 -->
<div id="historyModal" class="modal">
  <div class="modal-content">
    <h4>재고 변경 이력</h4>
    <div id="historyContent" class="py-3">
      <!-- 이력이 여기 로드됨 -->
    </div>
    <button type="button" class="btn btn-secondary" onclick="closeHistoryModal()">
      <i class="fas fa-times"></i> Close
    </button>
  </div>
</div>

<script>
function openNormalizeModal(productId, productName) {
  document.getElementById('normalizeProductId').value = productId;
  document.getElementById('normalizeProductName').value = productName;
  document.getElementById('adjustQty').value = '';
  document.getElementById('normalizeReason').value = '';
  document.getElementById('normalizeMethod').value = 'manual';
  toggleMethodFields();
  document.getElementById('normalizeModal').classList.add('active');
}

function closeNormalizeModal() {
  document.getElementById('normalizeModal').classList.remove('active');
}

function toggleMethodFields() {
  const method = document.getElementById('normalizeMethod').value;
  const reasonField = document.getElementById('reasonField');
  const methodInfo = document.getElementById('methodInfo');

  if (method === 'manual') {
    reasonField.style.display = 'block';
    methodInfo.innerHTML = '<small>수동 조정: 재고를 직접 조정합니다. 사유는 필수입니다.</small>';
    document.getElementById('normalizeReason').required = true;
  } else {
    reasonField.style.display = 'none';
    methodInfo.innerHTML = '<small>자동 정상화: 상품 등록 시 자동으로 진행됩니다. 입고량을 입력하세요.</small>';
    document.getElementById('normalizeReason').required = false;
  }
}

function submitNormalize(event) {
  event.preventDefault();

  const productId = document.getElementById('normalizeProductId').value;
  const method = document.getElementById('normalizeMethod').value;
  const adjustQty = document.getElementById('adjustQty').value;
  const reason = document.getElementById('normalizeReason').value;

  if (method === 'manual' && !reason.trim()) {
    alert('조정 사유를 입력하세요.');
    return;
  }

  const formData = new FormData();
  formData.append('product_id', productId);
  formData.append('method', method);
  formData.append('adjust_qty', adjustQty);
  formData.append('reason', reason);

  fetch('/admin/ajax/ajax_normalize_stock.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert('정상화가 완료되었습니다.');
      closeNormalizeModal();
      location.reload();
    } else {
      alert('오류: ' + data.message);
    }
  })
  .catch(err => alert('요청 실패: ' + err.message));
}

function viewHistory(productId) {
  fetch('/admin/ajax/ajax_get_stock_history.php?product_id=' + productId)
    .then(r => r.json())
    .then(data => {
      if (data.success && data.history.length > 0) {
        let html = '<table class="table table-sm"><tbody>';
        data.history.forEach(h => {
          html += `<tr>
            <td><strong>${h.action}</strong></td>
            <td>${h.old_quantity} → ${h.new_quantity}</td>
            <td><small>${h.created_at}</small></td>
          </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('historyContent').innerHTML = html;
      } else {
        document.getElementById('historyContent').innerHTML = '<p class="text-muted">이력이 없습니다.</p>';
      }
      document.getElementById('historyModal').classList.add('active');
    })
    .catch(err => alert('로드 실패: ' + err.message));
}

function closeHistoryModal() {
  document.getElementById('historyModal').classList.remove('active');
}

// 모달 바깥 클릭 시 닫기
document.querySelectorAll('.modal').forEach(modal => {
  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      modal.classList.remove('active');
    }
  });
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
