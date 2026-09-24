<?php

require_once __DIR__ . '/auth.php';

function mall_account_deletion_schema_ready($conn): bool {
    $column = $conn->query("SHOW COLUMNS FROM mall_members LIKE 'deleted_at'");
    $table = $conn->query("SHOW TABLES LIKE 'mall_account_deletion_requests'");
    return $column && $column->num_rows > 0 && $table && $table->num_rows > 0;
}

function mall_account_deletion_active_order_count($conn, int $member_id): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM mall_orders
         WHERE member_id = ? AND status NOT IN ('completed','cancelled','delivery_failed')"
    );
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $count;
}

function mall_account_deletion_verify_password($conn, int $member_id, string $password): bool {
    $stmt = $conn->prepare('SELECT password_hash FROM mall_members WHERE id = ? AND is_active = 1');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['password_hash']) && password_verify($password, $row['password_hash']);
}

function mall_account_delete(int $member_id, string $email): array {
    $conn = get_db_connection();
    if (!mall_account_deletion_schema_ready($conn)) {
        return ['success' => false, 'error' => 'SCHEMA_NOT_READY'];
    }
    if (mall_account_deletion_active_order_count($conn, $member_id) > 0) {
        return ['success' => false, 'error' => 'ACTIVE_ORDERS'];
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT id FROM mall_members WHERE id = ? AND is_active = 1 FOR UPDATE');
        $lock->bind_param('i', $member_id);
        $lock->execute();
        $exists = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$exists) {
            throw new RuntimeException('ACCOUNT_NOT_FOUND');
        }

        $delete_tables = [
            'mall_member_login_tokens', 'mall_password_resets', 'mall_addresses',
            'mall_cart_items', 'mall_fresh_cart_items', 'mall_wishlist',
            'mall_device_tokens', 'mall_member_stats', 'mall_reviews'
        ];
        foreach ($delete_tables as $table) {
            $stmt = $conn->prepare("DELETE FROM {$table} WHERE member_id = ?");
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $stmt->close();
        }

        $messages = $conn->prepare("DELETE FROM mall_order_messages WHERE sender_type = 'member' AND sender_member_id = ?");
        $messages->bind_param('i', $member_id);
        $messages->execute();
        $messages->close();

        // Financial/order line records remain, but delivery/contact snapshots are no longer identifiable.
        $orders = $conn->prepare(
            "UPDATE mall_orders SET ship_recipient_name = 'Deleted customer', ship_phone = NULL,
             ship_region = NULL, ship_city = NULL, ship_barangay = NULL,
             ship_detail_address = NULL, ship_landmark = NULL, ship_lat = NULL, ship_lng = NULL,
             memo = NULL WHERE member_id = ?"
        );
        $orders->bind_param('i', $member_id);
        $orders->execute();
        $orders->close();

        $anonymous_email = 'deleted+' . $member_id . '+' . bin2hex(random_bytes(8)) . '@deleted.invalid';
        $random_password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $member = $conn->prepare(
            "UPDATE mall_members SET email = ?, password_hash = ?, google_id = NULL,
             name = 'Deleted customer', english_name = NULL, phone = NULL,
             business_name = NULL, business_reg_no = NULL, wholesale_customer_id = NULL,
             is_active = 0, deleted_at = NOW() WHERE id = ?"
        );
        $member->bind_param('ssi', $anonymous_email, $random_password, $member_id);
        $member->execute();
        $member->close();

        $email_hash = hash('sha256', strtolower(trim($email)));
        $source = 'authenticated';
        $status = 'completed';
        $note = 'Completed automatically after authenticated in-app request.';
        $request = $conn->prepare(
            'INSERT INTO mall_account_deletion_requests
             (member_id, email, email_hash, request_source, status, request_note, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $request->bind_param('isssss', $member_id, $anonymous_email, $email_hash, $source, $status, $note);
        $request->execute();
        $request->close();

        $conn->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('mall_account_delete error: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage() === 'ACCOUNT_NOT_FOUND' ? 'ACCOUNT_NOT_FOUND' : 'SERVER_ERROR'];
    }
}

function mall_account_deletion_submit_web_request(string $email, string $note, string $ip): array {
    $email = strtolower(trim($email));
    $note = trim($note);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'INVALID_EMAIL'];
    }

    $conn = get_db_connection();
    if (!mall_account_deletion_schema_ready($conn)) {
        return ['success' => false, 'error' => 'SCHEMA_NOT_READY'];
    }

    $email_hash = hash('sha256', $email);
    $ip_hash = $ip === '' ? null : hash_hmac('sha256', $ip, hash('sha256', DB_NAME . DB_USER));
    $member_id = null;
    $find = $conn->prepare('SELECT id FROM mall_members WHERE email = ? AND is_active = 1 LIMIT 1');
    $find->bind_param('s', $email);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    if ($row) {
        $member_id = (int)$row['id'];
    }

    // Avoid request flooding without revealing whether the account exists.
    $recent = $conn->prepare(
        "SELECT id FROM mall_account_deletion_requests
         WHERE email_hash = ? AND status = 'pending' AND requested_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1"
    );
    $recent->bind_param('s', $email_hash);
    $recent->execute();
    $already_pending = (bool)$recent->get_result()->fetch_assoc();
    $recent->close();
    if (!$already_pending) {
        $source = 'web';
        $status = 'pending';
        $safe_note = mb_substr($note, 0, 1000, 'UTF-8');
        $request = $conn->prepare(
            'INSERT INTO mall_account_deletion_requests
             (member_id, email, email_hash, request_source, status, request_note, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $request->bind_param('issssss', $member_id, $email, $email_hash, $source, $status, $safe_note, $ip_hash);
        $request->execute();
        $request->close();
    }
    return ['success' => true];
}
