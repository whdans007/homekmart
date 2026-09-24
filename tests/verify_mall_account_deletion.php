<?php
/**
 * Integration smoke test for the account-deletion transaction.
 * Creates only a uniquely tagged temporary member, then removes it in finally.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../mall/lib/account_deletion.php';

$conn = get_db_connection();
$tag = bin2hex(random_bytes(6));
$email = "account-delete-test-{$tag}@example.invalid";
$member_id = 0;

function deletion_test_assert($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    deletion_test_assert(mall_account_deletion_schema_ready($conn), 'Account deletion schema is not installed.');

    $password_hash = password_hash('Temporary-test-password-1!', PASSWORD_DEFAULT);
    $member_type = 'retail';
    $name = 'Account deletion test';
    $store_id = MALL_STORE_ID;
    $stmt = $conn->prepare(
        "INSERT INTO mall_members (member_type,email,password_hash,name,store_id,retail_tier,is_active)
         VALUES (?,?,?,?,?,'general',1)"
    );
    $stmt->bind_param('ssssi', $member_type, $email, $password_hash, $name, $store_id);
    $stmt->execute();
    $member_id = (int)$conn->insert_id;
    $stmt->close();

    $token_hash = hash('sha256', 'test-token-' . $tag);
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $token = $conn->prepare('INSERT INTO mall_member_login_tokens (member_id,token_hash,expires_at) VALUES (?,?,?)');
    $token->bind_param('iss', $member_id, $token_hash, $expires);
    $token->execute();
    $token->close();

    $result = mall_account_delete($member_id, $email);
    deletion_test_assert(!empty($result['success']), 'Deletion transaction returned failure: ' . ($result['error'] ?? 'unknown'));

    $check = $conn->prepare('SELECT email,name,phone,is_active,deleted_at FROM mall_members WHERE id = ?');
    $check->bind_param('i', $member_id);
    $check->execute();
    $member = $check->get_result()->fetch_assoc();
    $check->close();
    deletion_test_assert($member !== null, 'Anonymized member row was not retained for order references.');
    deletion_test_assert((int)$member['is_active'] === 0, 'Member is still active.');
    deletion_test_assert($member['deleted_at'] !== null, 'deleted_at was not recorded.');
    deletion_test_assert($member['name'] === 'Deleted customer', 'Member name was not anonymized.');
    deletion_test_assert(str_ends_with($member['email'], '@deleted.invalid'), 'Member email was not anonymized.');

    $token_check = $conn->prepare('SELECT COUNT(*) AS cnt FROM mall_member_login_tokens WHERE member_id = ?');
    $token_check->bind_param('i', $member_id);
    $token_check->execute();
    $token_count = (int)$token_check->get_result()->fetch_assoc()['cnt'];
    $token_check->close();
    deletion_test_assert($token_count === 0, 'Login tokens were not deleted.');

    $audit = $conn->prepare("SELECT status FROM mall_account_deletion_requests WHERE member_id = ? AND request_source = 'authenticated'");
    $audit->bind_param('i', $member_id);
    $audit->execute();
    $audit_row = $audit->get_result()->fetch_assoc();
    $audit->close();
    deletion_test_assert(($audit_row['status'] ?? '') === 'completed', 'Completed deletion audit was not recorded.');

    echo "PASS: account deletion anonymizes the account and revokes login tokens.\n";
} finally {
    if ($member_id > 0) {
        $cleanup_request = $conn->prepare('DELETE FROM mall_account_deletion_requests WHERE member_id = ?');
        $cleanup_request->bind_param('i', $member_id);
        $cleanup_request->execute();
        $cleanup_request->close();

        $cleanup_member = $conn->prepare('DELETE FROM mall_members WHERE id = ? AND email LIKE ?');
        $deleted_pattern = 'deleted+' . $member_id . '+%@deleted.invalid';
        $cleanup_member->bind_param('is', $member_id, $deleted_pattern);
        $cleanup_member->execute();
        $cleanup_member->close();
    }
}
