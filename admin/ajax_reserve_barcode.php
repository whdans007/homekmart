<?php
require_once __DIR__ . '/../lib/session_helper.php';
require_once __DIR__ . '/../lib/permission_helper.php';
require_once __DIR__ . '/../config/db_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensure_logged_in();
    if (!has_permission('product_management') && !in_array($_SESSION['role'] ?? '', ['admin','super_admin'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '권한이 없습니다.']);
        exit;
    }

    $prefix = isset($_POST['prefix']) ? preg_replace('/\D/', '', $_POST['prefix']) : '2011223';
    if (strlen($prefix) !== 7) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'prefix는 7자리 숫자여야 합니다.']);
        exit;
    }

    $conn = get_db_connection();
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();

    $createSql = "CREATE TABLE IF NOT EXISTS barcode_sequences (
        prefix VARCHAR(7) PRIMARY KEY,
        last_serial INT NOT NULL DEFAULT 0,
        step INT NOT NULL DEFAULT 1,
        max_serial INT NOT NULL DEFAULT 99999,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createSql);

    // 행 잠금
    $stmt = $conn->prepare("SELECT prefix, last_serial, step, max_serial, status FROM barcode_sequences WHERE prefix = ? FOR UPDATE");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $ins = $conn->prepare("INSERT INTO barcode_sequences(prefix,last_serial,step,max_serial,status) VALUES(?,0,1,99999,'active')");
        $ins->bind_param('s', $prefix);
        $ins->execute();
        $ins->close();
        $row = ['prefix'=>$prefix,'last_serial'=>0,'step'=>1,'max_serial'=>99999,'status'=>'active'];
    }

    if (($row['status'] ?? 'active') !== 'active') {
        throw new Exception('시퀀스가 비활성화되어 있습니다.');
    }

    // 중복 방지: products.sku와 충돌 없는 다음 바코드 선택
    $next_serial = (int)$row['last_serial'];
    $step = (int)$row['step'];
    $max_serial = (int)$row['max_serial'];

    $checkProduct = $conn->prepare("SELECT 1 FROM products WHERE sku = ? LIMIT 1");
    // 도매 제품 JSON SKU 중복 방지(테이블이 있을 때만)
    $wholesaleExists = false;
    if ($resCheck = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'wholesale_products'")) {
        $wholesaleExists = (bool)$resCheck->fetch_row();
        $resCheck->close();
    }
    $checkWholesale = null;
    if ($wholesaleExists) {
        $checkWholesale = $conn->prepare("SELECT 1 FROM wholesale_products WHERE wholesale_skus IS NOT NULL AND wholesale_skus LIKE ? LIMIT 1");
    }

    $attempts = 0;
    $foundSerial = null;
    $barcode = null;
    while (true) {
        $attempts++;
        if ($attempts > 1000) {
            throw new Exception('가용 바코드를 찾지 못했습니다(시도 초과).');
        }
        $candidate = $next_serial + $step; // 다음 후보
        if ($candidate > $max_serial) {
            throw new Exception('시퀀스 범위를 초과했습니다.');
        }
        $serial5 = str_pad((string)$candidate, 5, '0', STR_PAD_LEFT);
        $base12 = $prefix . $serial5;

        // 체크디지트 계산
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $n = ord($base12[$i]) - 48;
            $sum += ($i % 2 === 0) ? $n : $n * 3;
        }
        $check = (10 - ($sum % 10)) % 10;
        $candidateBarcode = $base12 . $check;

        // 기존 제품과 중복 여부 확인
        $checkProduct->bind_param('s', $candidateBarcode);
        $checkProduct->execute();
        $dupRes = $checkProduct->get_result();
        $exists = ($dupRes && $dupRes->fetch_row());
        // 도매 SKU 배열에도 포함되면 중복으로 간주
        if (!$exists && $checkWholesale) {
            $pattern = '%"' . $conn->real_escape_string($candidateBarcode) . '"%';
            $checkWholesale->bind_param('s', $pattern);
            $checkWholesale->execute();
            $dupRes2 = $checkWholesale->get_result();
            if ($dupRes2 && $dupRes2->fetch_row()) {
                $exists = true;
            }
        }

        if (!$exists) {
            $foundSerial = $candidate;
            $barcode = $candidateBarcode;
            break;
        }
        // 중복이면 다음 번호로 진행
        $next_serial = $candidate;
    }
    $checkProduct->close();
    if ($checkWholesale) { $checkWholesale->close(); }

    // last_serial을 선택한 일련번호로 갱신
    $upd = $conn->prepare("UPDATE barcode_sequences SET last_serial = ? WHERE prefix = ?");
    $upd->bind_param('is', $foundSerial, $prefix);
    $upd->execute();
    $upd->close();

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'barcode' => $barcode,
        'prefix' => $prefix,
        'serial' => $foundSerial,
        'checkDigit' => (int)substr($barcode, -1),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
