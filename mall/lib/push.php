<?php
/**
 * 주문톡 FCM(HTTP v1) 푸시 발송 (Application Layer)
 * Design Ref: mall-order-chat-push.design.md §3(인증), §4(토큰 모델), §8(실패격리)
 *
 * 이 파일의 모든 공개 진입점(mall_push_notify_order_message)은 어떤 예외도 상위로 던지지 않는다 —
 * 서비스 계정 키 미배치, FCM 장애, 네트워크 오류 등 어떤 이유로든 주문톡 메시지 발송(INSERT) 자체를
 * 실패시키면 안 된다(mall_order_chat_send가 이 함수를 호출한 뒤 그대로 성공 응답을 반환하기 때문).
 *
 * 라이브러리 의존성 없음: 이 저장소에는 Google API 클라이언트/firebase-php-jwt 등이 없으므로
 * OAuth2 서비스 계정 JWT를 PHP 내장 openssl_sign()으로 직접 서명한다(§3.2).
 */
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

/** FCM 액세스 토큰 파일 캐시 경로(웹루트 바깥 임시디렉토리 — 절대 정적 서빙되지 않는다). */
function mall_fcm_token_cache_path() {
    return sys_get_temp_dir() . '/homekmart_mall_fcm_token_cache.json';
}

/**
 * 캐시된 액세스 토큰을 반환한다. 만료 60초 전부터는 재사용하지 않고 null을 반환해 미리 갱신을 유도한다
 * (네트워크 지연/서버 간 시계 오차 여유).
 * @return string|null
 */
function mall_fcm_get_cached_access_token() {
    $path = mall_fcm_token_cache_path();
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) {
        return null;
    }
    if ((int)$data['expires_at'] <= time() + 60) {
        return null;
    }
    return $data['access_token'];
}

/**
 * 새로 발급받은 액세스 토큰을 파일 캐시에 저장한다(플록으로 동시요청 경쟁 방지).
 * @param string $access_token
 * @param int $expires_in 초 단위(보통 3600)
 * @return void
 */
function mall_fcm_store_cached_access_token($access_token, $expires_in) {
    $path = mall_fcm_token_cache_path();
    $payload = json_encode(['access_token' => $access_token, 'expires_at' => time() + (int)$expires_in]);

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    @chmod($path, 0600);
}

/**
 * 서비스 계정 키 파일(JSON)을 읽어 반환한다. 파일이 없거나 형식이 잘못됐으면 null(요청당 1회만 시도 —
 * static 캐시로 반복 발송 시 매번 디스크 I/O + 에러로그 폭주를 막는다).
 * @return array{private_key:string, client_email:string, project_id:string}|null
 */
function mall_fcm_load_service_account() {
    static $cached = null; // null=아직 안 읽음, false=실패 확정, array=성공
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }

    $path = defined('MALL_FCM_SERVICE_ACCOUNT_FILE') ? MALL_FCM_SERVICE_ACCOUNT_FILE : '';
    if ($path === '' || !is_file($path)) {
        error_log('mall_fcm_load_service_account: 서비스 계정 키 파일이 없습니다(' . $path . ') — 주문톡 푸시를 건너뜁니다.');
        $cached = false;
        return null;
    }

    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['private_key']) || empty($data['client_email']) || empty($data['project_id'])) {
        error_log('mall_fcm_load_service_account: 서비스 계정 키 파일 형식이 올바르지 않습니다(' . $path . ').');
        $cached = false;
        return null;
    }

    $cached = $data;
    return $data;
}

/** RFC 4648 base64url 인코딩(JWT 세그먼트용, 패딩 없음). */
function mall_fcm_base64url($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * 서비스 계정으로 자체 서명 JWT(RS256)를 만든다(OAuth2 JWT Bearer Flow의 assertion 값).
 * @param array{private_key:string, client_email:string} $service_account
 * @return string
 * @throws Exception 서명 실패 시
 */
function mall_fcm_build_jwt($service_account) {
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $service_account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $unsigned = mall_fcm_base64url(json_encode($header)) . '.' . mall_fcm_base64url(json_encode($claims));

    $signature = '';
    $signed = openssl_sign($unsigned, $signature, $service_account['private_key'], 'SHA256');
    if (!$signed) {
        throw new Exception('FCM JWT 서명 실패(openssl_sign)');
    }

    return $unsigned . '.' . mall_fcm_base64url($signature);
}

/**
 * JWT Bearer Flow로 Google OAuth2 액세스 토큰을 새로 발급받아 캐시에 저장한다.
 * 연결 2초/전체 3초 타임아웃 — FCM/Google 응답 지연이 채팅 발송 응답을 과도하게 붙잡지 않도록 한다
 * (mall_verify_google_id_token()의 curl 타임아웃 선례와 동일한 방어).
 * @param array $service_account
 * @return string|null
 */
function mall_fcm_fetch_access_token($service_account) {
    $jwt = mall_fcm_build_jwt($service_account);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        error_log('mall_fcm_fetch_access_token 실패: http=' . $http_code . ' curl_error=' . $curl_error);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('mall_fcm_fetch_access_token: 응답 형식이 올바르지 않습니다.');
        return null;
    }

    mall_fcm_store_cached_access_token($data['access_token'], $data['expires_in'] ?? 3600);
    return $data['access_token'];
}

/**
 * 유효한 액세스 토큰을 반환한다(캐시 우선, 없거나 만료 임박이면 새로 발급). 실패 시 null —
 * 호출측은 null이면 발송을 조용히 건너뛰어야 한다.
 * @return string|null
 */
function mall_fcm_get_access_token() {
    $cached = mall_fcm_get_cached_access_token();
    if ($cached !== null) {
        return $cached;
    }

    $service_account = mall_fcm_load_service_account();
    if ($service_account === null) {
        return null;
    }

    try {
        return mall_fcm_fetch_access_token($service_account);
    } catch (Throwable $e) {
        error_log('mall_fcm_get_access_token error: ' . $e->getMessage());
        return null;
    }
}

/**
 * FCM HTTP v1 messages:send 엔드포인트로 단일 기기에 알림을 발송한다.
 * @param string $access_token
 * @param string $project_id
 * @param string $device_token FCM registration token
 * @param string $title
 * @param string $body
 * @param array<string,string> $data 딥링크 등에 쓰는 커스텀 data payload(문자열만)
 * @return array{ok:bool, invalid_token:bool}
 */
function mall_fcm_send($access_token, $project_id, $device_token, $title, $body, array $data = []) {
    $message = [
        'message' => [
            'token' => $device_token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => array_map('strval', $data),
            'android' => [
                'priority' => 'high',
            ],
        ],
    ];

    $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($project_id) . '/messages:send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('mall_fcm_send curl 오류: ' . $curl_error);
        return ['ok' => false, 'invalid_token' => false];
    }
    if ($http_code === 200) {
        return ['ok' => true, 'invalid_token' => false];
    }

    $decoded = json_decode($response, true);
    $status = is_array($decoded) ? ($decoded['error']['status'] ?? '') : '';
    // 토큰 자체가 더 이상 유효하지 않은 경우만 비활성화 대상으로 표시한다(그 외 오류는 일시적일 수 있음).
    $invalid_token = $http_code === 404 || in_array($status, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true);

    error_log('mall_fcm_send 실패: http=' . $http_code . ' status=' . $status . ' body=' . substr((string)$response, 0, 300));
    return ['ok' => false, 'invalid_token' => $invalid_token];
}

/**
 * 주문톡에 새 메시지가 등록된 뒤 호출하는 진입점. 관리자/기사가 보낸 메시지만 고객(mall_members)
 * 앱으로 푸시한다 — 고객이 보낸 메시지는 관리자(데스크톱 웹)/기사(모바일 웹) 쪽에 Capacitor 앱이
 * 없으므로 이번 범위에서 푸시 대상이 아니다(mall-order-chat-push.design.md §0, §2).
 *
 * 이 함수는 절대 예외를 던지지 않는다 — 호출부(mall_order_chat_send)가 이 함수의 성공/실패와 무관하게
 * 항상 정상적으로 메시지 INSERT 성공 응답을 반환할 수 있어야 한다.
 *
 * @param int $order_id
 * @param string $sender_type 'member'|'admin'|'driver'
 * @param string $message 원문 메시지(알림 본문 생성에 사용, 100자로 트렁케이션)
 * @return void
 */
function mall_push_notify_order_message($order_id, $sender_type, $message) {
    if ($sender_type === 'member') {
        try {
            $conn = mall_get_db_connection();
            $stmt = $conn->prepare('SELECT current_driver_id FROM mall_orders WHERE id = ?');
            $stmt->bind_param('i', $order_id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!empty($row['current_driver_id'])) mall_push_notify_driver((int)$row['current_driver_id'], $order_id, '고객님 메시지', $message);
        } catch (Throwable $e) { error_log('driver chat push: '.$e->getMessage()); }
        return;
    }

    try {
        $conn = mall_get_db_connection();

        $order_stmt = $conn->prepare('SELECT member_id FROM mall_orders WHERE id = ?');
        $order_stmt->bind_param('i', $order_id);
        $order_stmt->execute();
        $order = $order_stmt->get_result()->fetch_assoc();
        $order_stmt->close();
        if (!$order) {
            return;
        }
        $member_id = (int)$order['member_id'];

        $token_stmt = $conn->prepare('SELECT id, token FROM mall_device_tokens WHERE member_id = ? AND is_active = 1');
        $token_stmt->bind_param('i', $member_id);
        $token_stmt->execute();
        $tokens = $token_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $token_stmt->close();
        if (empty($tokens)) {
            return;
        }

        $access_token = mall_fcm_get_access_token();
        if ($access_token === null) {
            return;
        }

        $service_account = mall_fcm_load_service_account();
        if ($service_account === null) {
            return;
        }

        $title = $sender_type === 'driver' ? '배송기사 메시지' : 'HOME K MART 주문톡';
        $body = mb_substr(preg_replace('/\s+/u', ' ', trim($message)), 0, 100);

        $deactivate_stmt = $conn->prepare('UPDATE mall_device_tokens SET is_active = 0 WHERE id = ?');

        foreach ($tokens as $row) {
            $result = mall_fcm_send(
                $access_token,
                $service_account['project_id'],
                $row['token'],
                $title,
                $body,
                ['type' => 'order_chat', 'order_id' => (string)$order_id]
            );

            if (!$result['ok'] && $result['invalid_token']) {
                $token_id = (int)$row['id'];
                $deactivate_stmt->bind_param('i', $token_id);
                $deactivate_stmt->execute();
            }
        }
        $deactivate_stmt->close();
    } catch (Throwable $e) {
        error_log('mall_push_notify_order_message error: ' . $e->getMessage());
    }
}

function mall_push_notify_driver($driver_id, $order_id, $title, $body) {
    try {
        $conn = mall_get_db_connection();
        $stmt = $conn->prepare('SELECT id, token FROM mall_driver_device_tokens WHERE driver_id = ? AND is_active = 1');
        $stmt->bind_param('i', $driver_id); $stmt->execute();
        $tokens = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        if (!$tokens) return;
        $access = mall_fcm_get_access_token();
        $account = mall_fcm_load_service_account();
        if ($access === null || $account === null) return;
        $disable = $conn->prepare('UPDATE mall_driver_device_tokens SET is_active = 0 WHERE id = ?');
        foreach ($tokens as $row) {
            $result = mall_fcm_send($access, $account['project_id'], $row['token'], $title, mb_substr(trim($body),0,100), ['type'=>'driver_order','order_id'=>(string)$order_id]);
            if (!$result['ok'] && $result['invalid_token']) { $id=(int)$row['id']; $disable->bind_param('i',$id); $disable->execute(); }
        }
        $disable->close();
    } catch (Throwable $e) { error_log('mall_push_notify_driver: '.$e->getMessage()); }
}
