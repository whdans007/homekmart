<?php
/**
 * 지점 가격 가져오기 (메인 서버) — 지점의 공개 HTTPS 엔드포인트에서 가격 JSON 을 받아 반영.
 * ─────────────────────────────────────────────────────────────
 * 버튼을 누르면 메인 서버가 지점(예: kimsmall) 서버의 export_prices.php 를 HTTPS 로 호출하여
 * 원가/판매가를 받아, 바코드(products.sku) 기준으로 메인 서버 해당 점포 inventory 에 반영(upsert).
 *
 * 안전장치:
 *  - 지점 연동정보는 config/remote_stores.php 에 분리 (db_config.php 미변경 → 운영 DB 영향 없음)
 *  - HTTPS(443) 아웃바운드만 사용 (공유호스팅에서 허용). MySQL 3306 직접접속 안 함.
 *  - 접속 타임아웃 짧게 (연결 3초 / 전체 25초) → 지점이 안 닿아도 즉시 실패, 사이트 영향 없음.
 *
 * super_admin 전용.
 */
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/lang_helper.php';
require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../config/remote_stores.php';

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    $_SESSION['flash'] = ['type' => 'error', 'message' => t('messages.permission_denied')];
    header('Location: index.php');
    exit;
}

/** stores.name LIKE 패턴으로 점포 ID 탐지 (단일 점포뿐이면 그 점포로 폴백) */
function resolve_store_id_by_pattern(mysqli $conn, string $pattern): ?int {
    $stmt = $conn->prepare("SELECT id FROM stores WHERE name LIKE ? ORDER BY id LIMIT 1");
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) return (int)$row['id'];
    $res = $conn->query("SELECT id FROM stores LIMIT 2");
    if ($res && $res->num_rows === 1) return (int)$res->fetch_assoc()['id'];
    return null;
}

$store_code = preg_replace('/[^a-z0-9_]/', '', strtolower($_REQUEST['store'] ?? 'kimsmall'));
$cfg        = get_remote_store_db($store_code);
$result     = null;

$config_ready = $cfg
    && !empty($cfg['export_url']) && strpos($cfg['export_url'], 'REPLACE') === false
    && !empty($cfg['api_key'])    && strpos($cfg['api_key'], 'REPLACE') === false;

$conn = get_db_connection(); // 메인(로컬) 연결

// 메인(로컬) 대상 점포 확인 (미리보기용 — 로컬 쿼리만)
$local_store_id   = null;
$local_store_name = '';
if ($cfg) {
    $local_store_id = ((int)($cfg['local_store_id'] ?? 0) > 0)
        ? (int)$cfg['local_store_id']
        : resolve_store_id_by_pattern($conn, $cfg['store_pattern']);
    if ($local_store_id) {
        $s = $conn->prepare("SELECT name FROM stores WHERE id = ?");
        $s->bind_param('i', $local_store_id);
        $s->execute();
        if ($row = $s->get_result()->fetch_assoc()) $local_store_name = $row['name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pull') {
    if (!$cfg) {
        $result = ['ok' => false, 'error' => "설정되지 않은 지점 코드입니다: {$store_code}"];
    } elseif (!$config_ready) {
        $result = ['ok' => false, 'error' => 'config/remote_stores.php 의 export_url / api_key 를 실제 값으로 설정하세요.'];
    } elseif (!$local_store_id) {
        $result = ['ok' => false, 'error' => '메인 서버에서 대상 점포를 찾을 수 없습니다. (remote_stores.php 의 local_store_id 설정 필요)'];
    } else {
        try {
            // 1) 지점의 공개 HTTPS 엔드포인트 호출
            $ch = curl_init($cfg['export_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_HTTPHEADER     => ['X-Sync-Key: ' . $cfg['api_key']],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body      = curl_exec($ch);
            $curl_err  = curl_error($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false) {
                throw new Exception('지점 서버 연결 실패: ' . $curl_err);
            }
            $data = json_decode($body, true);
            if ($http_code !== 200 || !is_array($data) || empty($data['success'])) {
                $msg = is_array($data) && isset($data['message']) ? $data['message'] : ('HTTP ' . $http_code);
                throw new Exception('지점 응답 오류: ' . $msg);
            }
            $remote_items = $data['items'] ?? [];

            // 2) 메인 서버에 반영: 매칭 상품은 이름 갱신 + 가격, 없는 상품은 신규 등록
            $find    = $conn->prepare("SELECT id FROM products WHERE sku = ? LIMIT 1");
            // 이름은 킴스몰 값이 비어있지 않을 때만 갱신(빈 값이면 기존 이름 유지)
            $updName = $conn->prepare(
                "UPDATE products
                    SET name_ko = COALESCE(NULLIF(?, ''), name_ko),
                        name_en = COALESCE(NULLIF(?, ''), name_en)
                  WHERE id = ?"
            );
            $insProd = $conn->prepare(
                "INSERT INTO products (sku, name_ko, name_en, is_active, pieces_per_box, is_vat_applicable, last_modified_by_user_id)
                 VALUES (?, ?, ?, 1, ?, 1, ?)"
            );
            $up = $conn->prepare(
                "INSERT INTO inventory (product_id, store_id, cost_price, selling_price, quantity)
                 VALUES (?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE
                    cost_price = VALUES(cost_price),
                    selling_price = VALUES(selling_price)"
            );

            $uid      = (int)($_SESSION['user_id'] ?? 0);
            $updated  = 0; // 기존 상품 이름/가격 갱신
            $inserted = 0; // 신규 등록된 상품
            $skipped  = 0; // 바코드 없는 항목
            $conn->begin_transaction();
            try {
                foreach ($remote_items as $it) {
                    if (!is_array($it)) { $skipped++; continue; }
                    $sku = trim((string)($it['sku'] ?? ''));
                    if ($sku === '') { $skipped++; continue; }

                    $name_ko = isset($it['name_ko']) ? (string)$it['name_ko'] : '';
                    $name_en = isset($it['name_en']) ? (string)$it['name_en'] : '';
                    $ppb     = (isset($it['pieces_per_box']) && (int)$it['pieces_per_box'] > 0) ? (int)$it['pieces_per_box'] : 1;
                    $cost = (isset($it['cost_price'])    && $it['cost_price']    !== null && $it['cost_price']    !== '') ? (float)$it['cost_price']    : null;
                    $sell = (isset($it['selling_price']) && $it['selling_price'] !== null && $it['selling_price'] !== '') ? (float)$it['selling_price'] : null;

                    $find->bind_param('s', $sku);
                    $find->execute();
                    $prod = $find->get_result()->fetch_assoc();

                    if ($prod) {
                        // 기존 상품: 이름 갱신
                        $pid = (int)$prod['id'];
                        $updName->bind_param('ssi', $name_ko, $name_en, $pid);
                        $updName->execute();
                        $updated++;
                    } else {
                        // 없는 상품: 신규 등록 (이름 비면 name_ko는 '', name_en은 null)
                        $ins_ko = $name_ko;
                        $ins_en = $name_en !== '' ? $name_en : null;
                        $insProd->bind_param('sssii', $sku, $ins_ko, $ins_en, $ppb, $uid);
                        $insProd->execute();
                        $pid = (int)$conn->insert_id;
                        $inserted++;
                    }

                    // 가격 반영 (원가/판매가)
                    $up->bind_param('iidd', $pid, $local_store_id, $cost, $sell);
                    $up->execute();
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }

            $result = [
                'ok'           => true,
                'fetched'      => count($remote_items),
                'updated'      => $updated,
                'inserted'     => $inserted,
                'skipped'      => $skipped,
                'remote_store' => $data['store'] ?? '',
            ];
        } catch (Throwable $e) {
            error_log('pull_store_pricing error: ' . $e->getMessage());
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('company.name'); ?> - 지점 가격 가져오기</title>
    <link rel="icon" href="data:,">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
          integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA=="
          crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        body { background: linear-gradient(135deg, #0a0a0a 0%, #1a1a1a 50%, #0a0a0a 100%); min-height: 100vh; color: #f1f5f9; font-family: 'Segoe UI', system-ui, sans-serif; }
        .wrap { max-width: 720px; margin: 0 auto; padding: 2.5rem 1rem 4rem; }
        .page-title { font-size: 1.6rem; font-weight: 800; color: #fff; letter-spacing: 0.03em; }
        .page-subtitle { font-size: 0.8rem; color: rgba(255,255,255,0.5); letter-spacing: 0.1em; text-transform: uppercase; }
        .panel { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); border-radius: 1rem; padding: 1.5rem; backdrop-filter: blur(8px); }
        .info-row { display: flex; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,0.08); gap: 1rem; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: rgba(255,255,255,0.6); white-space: nowrap; }
        .info-val { color: #f1f5f9; font-weight: 600; text-align: right; word-break: break-all; }
        .pull-btn { background: rgba(14,165,233,0.16); border: 1px solid rgba(14,165,233,0.4); color: #7dd3fc; font-weight: 700; padding: 0.75rem 1.4rem; border-radius: 0.6rem; font-size: 1rem; transition: all 0.15s; }
        .pull-btn:hover:not(:disabled) { background: rgba(14,165,233,0.3); color: #fff; }
        .pull-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .main-btn { display: inline-flex; align-items: center; gap: 0.4rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.18); color: rgba(255,255,255,0.7); font-size: 0.85rem; text-decoration: none; padding: 0.4rem 0.85rem; border-radius: 0.5rem; }
        .main-btn:hover { color: #fff; }
        .result-ok { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.35); color: #86efac; }
        .result-err { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; }
        .result-box { border-radius: 0.7rem; padding: 1rem 1.25rem; margin-top: 1.25rem; }
        .note { font-size: 0.8rem; color: rgba(255,255,255,0.45); margin-top: 1rem; line-height: 1.5; }
        .warn { background: rgba(234,179,8,0.12); border: 1px solid rgba(234,179,8,0.35); color: #fde047; border-radius: 0.6rem; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem; }
        code { color: #7dd3fc; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <div class="page-title"><i class="fas fa-cloud-arrow-down me-2"></i>지점 가격 가져오기</div>
            <div class="page-subtitle">Pull Pricing from Store Server</div>
        </div>
        <a href="/" class="main-btn"><i class="fas fa-globe"></i>MAIN</a>
    </div>

    <?php if (!$cfg): ?>
        <div class="result-box result-err">
            설정되지 않은 지점 코드입니다: <code><?php echo htmlspecialchars($store_code); ?></code>
        </div>
    <?php else: ?>
    <?php if (!$config_ready): ?>
        <div class="warn">
            <i class="fas fa-triangle-exclamation me-1"></i>
            <code>config/remote_stores.php</code> 의 <code>export_url</code>(킴스몰 공개 주소) 와 <code>api_key</code> 를 실제 값으로 설정해야 동작합니다.
        </div>
    <?php endif; ?>
    <div class="panel">
        <div class="info-row">
            <span class="info-label">지점</span>
            <span class="info-val"><?php echo htmlspecialchars($cfg['label']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">지점 엔드포인트</span>
            <span class="info-val" style="font-size:0.8rem"><?php echo htmlspecialchars($cfg['export_url']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">메인 반영 대상 점포</span>
            <span class="info-val"><?php echo $local_store_name !== '' ? htmlspecialchars($local_store_name) : '<span style="color:#fca5a5">미확인</span>'; ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">가져올 데이터</span>
            <span class="info-val">상품명 + 원가/판매가 · 없는 상품 신규 등록</span>
        </div>

        <form method="post" class="mt-4 text-end" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').innerHTML='<i class=\'fas fa-spinner fa-spin me-1\'></i>가져오는 중…';">
            <input type="hidden" name="action" value="pull">
            <input type="hidden" name="store" value="<?php echo htmlspecialchars($store_code); ?>">
            <button type="submit" class="pull-btn" <?php echo (!$config_ready || !$local_store_id) ? 'disabled' : ''; ?>>
                <i class="fas fa-cloud-arrow-down me-1"></i>가격 가져와서 업데이트
            </button>
        </form>
    </div>
    <div class="note">
        <i class="fas fa-circle-info me-1"></i>
        지점 서버에 접속되지 않으면 잠시 후 실패 메시지가 표시되며, 메인 사이트에는 영향이 없습니다.
    </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="result-box <?php echo !empty($result['ok']) ? 'result-ok' : 'result-err'; ?>">
            <?php if (!empty($result['ok'])): ?>
                <div style="font-weight:700"><i class="fas fa-circle-check me-1"></i>가져오기 완료<?php echo !empty($result['remote_store']) ? ' (' . htmlspecialchars($result['remote_store']) . ')' : ''; ?></div>
                <div class="mt-2" style="font-size:0.9rem">
                    지점 조회 <?php echo (int)$result['fetched']; ?>건 ·
                    기존 갱신(이름·가격) <strong><?php echo (int)$result['updated']; ?></strong>건 ·
                    신규 등록 <strong><?php echo (int)$result['inserted']; ?></strong>건
                    <?php if ((int)$result['skipped'] > 0): ?> · 건너뜀(바코드 없음) <?php echo (int)$result['skipped']; ?>건<?php endif; ?>
                </div>
            <?php else: ?>
                <div style="font-weight:700"><i class="fas fa-circle-xmark me-1"></i>가져오기 실패</div>
                <div class="mt-2" style="font-size:0.9rem"><?php echo htmlspecialchars($result['error'] ?? '알 수 없는 오류'); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
