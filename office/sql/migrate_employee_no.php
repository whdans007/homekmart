<?php
/**
 * 기존 직원들에게 사번(employee_no) 소급 발급
 * 형식: 입사년도 2자리 + 회사코드 2자리(01, 고정) + 순번 3자리 (예: "2601059")
 * 순번은 전체 회사(모든 점포 합산) 기준, 입사연도별로 매년 001부터 다시 시작.
 * 입사일(hire_date)이 없는 직원은 현재년도 기준으로 발급되며(정렬상 맨 뒤로 밀림),
 * 필요 시 입사일을 먼저 입력한 뒤 이 스크립트를 다시 실행해 재발급하는 것을 권장.
 *
 * Design Ref: employee-no-numbering (2026-08-06 요청)
 * office_helper.php의 자동 컬럼 마이그레이션이 employee_no 컬럼을 먼저 생성한다.
 */

require_once __DIR__ . '/../../config/db_config.php';
require_once __DIR__ . '/../../lib/session_helper.php';
require_once __DIR__ . '/../lib/office_helper.php'; // employee_no 컬럼 자동 생성 트리거

ensure_logged_in();
if (($_SESSION['role'] ?? '') !== 'super_admin') {
    die("이 스크립트는 super_admin만 실행할 수 있습니다.");
}

$conn = get_db_connection();

echo "<h2>직원 사번(employee_no) 소급 발급</h2><pre>";

try {
    // employee_no가 아직 없는 직원을 입사일 순(오래된 순), 입사일 없으면 맨 뒤로 밀어서 조회
    $res = $conn->query(
        "SELECT id, hire_date FROM office_employees
         WHERE employee_no IS NULL
         ORDER BY (hire_date IS NULL) ASC, hire_date ASC, id ASC"
    );

    if ($res->num_rows === 0) {
        echo "✓ 사번이 없는 직원이 없습니다. 발급할 대상이 없습니다.\n";
    } else {
        echo "대상 직원 수: {$res->num_rows}명\n\n";

        // 연도별 다음 순번 캐시 (이미 발급된 사번이 있으면 그 뒤부터 이어서 채번)
        $seq_by_year = [];
        $update = $conn->prepare("UPDATE office_employees SET employee_no = ? WHERE id = ?");

        while ($row = $res->fetch_assoc()) {
            $year = $row['hire_date'] ? date('y', strtotime($row['hire_date'])) : date('y');
            $prefix = $year . OFFICE_COMPANY_CODE;

            if (!isset($seq_by_year[$prefix])) {
                $cnt_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM office_employees WHERE employee_no LIKE ?");
                $like = $prefix . '%';
                $cnt_stmt->bind_param('s', $like);
                $cnt_stmt->execute();
                $seq_by_year[$prefix] = (int)($cnt_stmt->get_result()->fetch_assoc()['c'] ?? 0);
                $cnt_stmt->close();
            }

            $seq_by_year[$prefix]++;
            $employee_no = $prefix . sprintf('%03d', $seq_by_year[$prefix]);

            $update->bind_param('si', $employee_no, $row['id']);
            $update->execute();

            echo "  - 직원 #{$row['id']} (입사일: " . ($row['hire_date'] ?: '미입력') . ") → 사번 {$employee_no}\n";
        }
        $update->close();

        echo "\n✅ 사번 소급 발급이 완료되었습니다!\n";
    }
} catch (Exception $e) {
    echo "\n❌ 오류: " . $e->getMessage() . "\n";
}

$conn->close();
echo "</pre>";
echo "<br><a href='../schedule/employees.php'>직원 관리로 이동</a>";
?>
