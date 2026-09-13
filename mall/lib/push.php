<?php
/**
 * 二쇰Ц??FCM(HTTP v1) ?몄떆 諛쒖넚 (Application Layer)
 * Design Ref: mall-order-chat-push.design.md 짠3(?몄쬆), 짠4(?좏겙 紐⑤뜽), 짠8(?ㅽ뙣寃⑸━)
 *
 * ???뚯씪??紐⑤뱺 怨듦컻 吏꾩엯??mall_push_notify_order_message)? ?대뼡 ?덉쇅???곸쐞濡??섏?吏 ?딅뒗???? * ?쒕퉬??怨꾩젙 ??誘몃같移? FCM ?μ븷, ?ㅽ듃?뚰겕 ?ㅻ쪟 ???대뼡 ?댁쑀濡쒕뱺 二쇰Ц??硫붿떆吏 諛쒖넚(INSERT) ?먯껜瑜? * ?ㅽ뙣?쒗궎硫????쒕떎(mall_order_chat_send媛 ???⑥닔瑜??몄텧????洹몃?濡??깃났 ?묐떟??諛섑솚?섍린 ?뚮Ц).
 *
 * ?쇱씠釉뚮윭由??섏〈???놁쓬: ????μ냼?먮뒗 Google API ?대씪?댁뼵??firebase-php-jwt ?깆씠 ?놁쑝誘濡? * OAuth2 ?쒕퉬??怨꾩젙 JWT瑜?PHP ?댁옣 openssl_sign()?쇰줈 吏곸젒 ?쒕챸?쒕떎(짠3.2).
 */
require_once __DIR__ . '/../config/mall_config.php';
require_once __DIR__ . '/../../config/db_config.php';

/** FCM ?≪꽭???좏겙 ?뚯씪 罹먯떆 寃쎈줈(?밸（??諛붽묑 ?꾩떆?붾젆?좊━ ???덈? ?뺤쟻 ?쒕튃?섏? ?딅뒗??. */
function mall_fcm_token_cache_path() {
    return sys_get_temp_dir() . '/homekmart_mall_fcm_token_cache.json';
}

/**
 * 罹먯떆???≪꽭???좏겙??諛섑솚?쒕떎. 留뚮즺 60珥??꾨??곕뒗 ?ъ궗?⑺븯吏 ?딄퀬 null??諛섑솚??誘몃━ 媛깆떊???좊룄?쒕떎
 * (?ㅽ듃?뚰겕 吏???쒕쾭 媛??쒓퀎 ?ㅼ감 ?ъ쑀).
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
 * ?덈줈 諛쒓툒諛쏆? ?≪꽭???좏겙???뚯씪 罹먯떆????ν븳???뚮줉?쇰줈 ?숈떆?붿껌 寃쎌웳 諛⑹?).
 * @param string $access_token
 * @param int $expires_in 珥??⑥쐞(蹂댄넻 3600)
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
 * ?쒕퉬??怨꾩젙 ???뚯씪(JSON)???쎌뼱 諛섑솚?쒕떎. ?뚯씪???녾굅???뺤떇???섎せ?먯쑝硫?null(?붿껌??1?뚮쭔 ?쒕룄 ?? * static 罹먯떆濡?諛섎났 諛쒖넚 ??留ㅻ쾲 ?붿뒪??I/O + ?먮윭濡쒓렇 ??＜瑜?留됰뒗??.
 * @return array{private_key:string, client_email:string, project_id:string}|null
 */
function mall_fcm_load_service_account() {
    static $cached = null; // null=?꾩쭅 ???쎌쓬, false=?ㅽ뙣 ?뺤젙, array=?깃났
    if ($cached !== null) {
        return $cached === false ? null : $cached;
    }

    $path = defined('MALL_FCM_SERVICE_ACCOUNT_FILE') ? MALL_FCM_SERVICE_ACCOUNT_FILE : '';
    if ($path === '' || !is_file($path)) {
        error_log('mall_fcm_load_service_account: ?쒕퉬??怨꾩젙 ???뚯씪???놁뒿?덈떎(' . $path . ') ??二쇰Ц???몄떆瑜?嫄대꼫?곷땲??');
        $cached = false;
        return null;
    }

    $raw = @file_get_contents($path);
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['private_key']) || empty($data['client_email']) || empty($data['project_id'])) {
        error_log('mall_fcm_load_service_account: ?쒕퉬??怨꾩젙 ???뚯씪 ?뺤떇???щ컮瑜댁? ?딆뒿?덈떎(' . $path . ').');
        $cached = false;
        return null;
    }

    $cached = $data;
    return $data;
}

/** RFC 4648 base64url ?몄퐫??JWT ?멸렇癒쇳듃?? ?⑤뵫 ?놁쓬). */
function mall_fcm_base64url($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * ?쒕퉬??怨꾩젙?쇰줈 ?먯껜 ?쒕챸 JWT(RS256)瑜?留뚮뱺??OAuth2 JWT Bearer Flow??assertion 媛?.
 * @param array{private_key:string, client_email:string} $service_account
 * @return string
 * @throws Exception ?쒕챸 ?ㅽ뙣 ?? */
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
        throw new Exception('FCM JWT ?쒕챸 ?ㅽ뙣(openssl_sign)');
    }

    return $unsigned . '.' . mall_fcm_base64url($signature);
}

/**
 * JWT Bearer Flow濡?Google OAuth2 ?≪꽭???좏겙???덈줈 諛쒓툒諛쏆븘 罹먯떆????ν븳??
 * ?곌껐 2珥??꾩껜 3珥???꾩븘????FCM/Google ?묐떟 吏?곗씠 梨꾪똿 諛쒖넚 ?묐떟??怨쇰룄?섍쾶 遺숈옟吏 ?딅룄濡??쒕떎
 * (mall_verify_google_id_token()??curl ??꾩븘???좊?? ?숈씪??諛⑹뼱).
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
        error_log('mall_fcm_fetch_access_token ?ㅽ뙣: http=' . $http_code . ' curl_error=' . $curl_error);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['access_token'])) {
        error_log('mall_fcm_fetch_access_token: ?묐떟 ?뺤떇???щ컮瑜댁? ?딆뒿?덈떎.');
        return null;
    }

    mall_fcm_store_cached_access_token($data['access_token'], $data['expires_in'] ?? 3600);
    return $data['access_token'];
}

/**
 * ?좏슚???≪꽭???좏겙??諛섑솚?쒕떎(罹먯떆 ?곗꽑, ?녾굅??留뚮즺 ?꾨컯?대㈃ ?덈줈 諛쒓툒). ?ㅽ뙣 ??null ?? * ?몄텧痢≪? null?대㈃ 諛쒖넚??議곗슜??嫄대꼫?곗뼱???쒕떎.
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
 * FCM HTTP v1 messages:send ?붾뱶?ъ씤?몃줈 ?⑥씪 湲곌린???뚮┝??諛쒖넚?쒕떎.
 * @param string $access_token
 * @param string $project_id
 * @param string $device_token FCM registration token
 * @param string $title
 * @param string $body
 * @param array<string,string> $data ?λ쭅???깆뿉 ?곕뒗 而ㅼ뒪? data payload(臾몄옄?대쭔)
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
                'notification' => [
                    'sound' => 'default',
                ],
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
        error_log('mall_fcm_send curl ?ㅻ쪟: ' . $curl_error);
        return ['ok' => false, 'invalid_token' => false];
    }
    if ($http_code === 200) {
        return ['ok' => true, 'invalid_token' => false];
    }

    $decoded = json_decode($response, true);
    $status = is_array($decoded) ? ($decoded['error']['status'] ?? '') : '';
    // ?좏겙 ?먯껜媛 ???댁긽 ?좏슚?섏? ?딆? 寃쎌슦留?鍮꾪솢?깊솕 ??곸쑝濡??쒖떆?쒕떎(洹????ㅻ쪟???쇱떆?곸씪 ???덉쓬).
    $invalid_token = $http_code === 404 || in_array($status, ['UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT'], true);

    error_log('mall_fcm_send ?ㅽ뙣: http=' . $http_code . ' status=' . $status . ' body=' . substr((string)$response, 0, 300));
    return ['ok' => false, 'invalid_token' => $invalid_token];
}

/**
 * 二쇰Ц?≪뿉 ??硫붿떆吏媛 ?깅줉?????몄텧?섎뒗 吏꾩엯?? 愿由ъ옄/湲곗궗媛 蹂대궦 硫붿떆吏留?怨좉컼(mall_members)
 * ?깆쑝濡??몄떆?쒕떎 ??怨좉컼??蹂대궦 硫붿떆吏??愿由ъ옄(?곗뒪?ы넲 ??/湲곗궗(紐⑤컮???? 履쎌뿉 Capacitor ?깆씠
 * ?놁쑝誘濡??대쾲 踰붿쐞?먯꽌 ?몄떆 ??곸씠 ?꾨땲??mall-order-chat-push.design.md 짠0, 짠2).
 *
 * ???⑥닔???덈? ?덉쇅瑜??섏?吏 ?딅뒗?????몄텧遺(mall_order_chat_send)媛 ???⑥닔???깃났/?ㅽ뙣? 臾닿??섍쾶
 * ??긽 ?뺤긽?곸쑝濡?硫붿떆吏 INSERT ?깃났 ?묐떟??諛섑솚?????덉뼱???쒕떎.
 *
 * @param int $order_id
 * @param string $sender_type 'member'|'admin'|'driver'
 * @param string $message ?먮Ц 硫붿떆吏(?뚮┝ 蹂몃Ц ?앹꽦???ъ슜, 100?먮줈 ?몃쟻耳?댁뀡)
 * @return void
 */
function mall_push_notify_order_message($order_id, $sender_type, $message) {
    if ($sender_type === 'member') {
        try {
            $conn = mall_get_db_connection();
            $stmt = $conn->prepare('SELECT current_driver_id FROM mall_orders WHERE id = ?');
            $stmt->bind_param('i', $order_id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!empty($row['current_driver_id'])) mall_push_notify_driver((int)$row['current_driver_id'], $order_id, '怨좉컼??硫붿떆吏', $message);
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
            error_log('mall_customer_push order=' . (int)$order_id . ' result=no_active_device_tokens');
            return;
        }

        $access_token = mall_fcm_get_access_token();
        if ($access_token === null) {
            error_log('mall_customer_push order=' . (int)$order_id . ' result=oauth_unavailable');
            return;
        }

        $service_account = mall_fcm_load_service_account();
        if ($service_account === null) {
            error_log('mall_customer_push order=' . (int)$order_id . ' result=credentials_unavailable');
            return;
        }
        $is_delivery_status = $sender_type === 'driver' && in_array(trim($message), ['배달시작', '배달도착', '배달이 도착했습니다.', '배송이 완료되었습니다. 이용해 주셔서 감사합니다.'], true);
        $title = $is_delivery_status ? '배송 알림' : ($sender_type === 'driver' ? '배송기사 메시지' : 'HOME K MART 주문톡');
        $body = mb_substr(preg_replace('/\s+/u', ' ', trim($message)), 0, 100);

        $deactivate_stmt = $conn->prepare('UPDATE mall_device_tokens SET is_active = 0 WHERE id = ?');

        foreach ($tokens as $row) {
            $result = mall_fcm_send(
                $access_token,
                $service_account['project_id'],
                $row['token'],
                $title,
                $body,
                ['type' => $is_delivery_status ? 'delivery_status' : 'order_chat', 'order_id' => (string)$order_id]
            );

            if (!$result['ok'] && $result['invalid_token']) {
                $token_id = (int)$row['id'];
                $deactivate_stmt->bind_param('i', $token_id);
                $deactivate_stmt->execute();
            }
            error_log('mall_customer_push order=' . (int)$order_id . ' device_id=' . (int)$row['id'] . ' result=' . ($result['ok'] ? 'fcm_accepted' : 'send_failed'));
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
