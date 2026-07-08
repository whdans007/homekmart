# Design: 점포 요청사항 게시판 (store-request-board)

**Feature**: store-request-board
**Phase**: Design
**Architecture**: C — 실용 균형 (공용 helper로 소유권검증/뱃지카운트만 캡슐화, 나머지는 order.php 관례를 따르는 plain form POST)
**Created**: 2026-07-07
**Planning Doc**: [store-request-board.plan.md](../01-plan/features/store-request-board.plan.md)

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 점포의 요청사항이 비공식 채널로만 전달되어 누락/지연됨 |
| **WHO** | 점포 사용자(`store_require_store_user`), 물류센터 스태프(`lc_require_staff`) |
| **RISK** | 다른 점포의 요청 열람(권한 누수), 상태/답변 갱신 시 동시성 문제 |
| **SUCCESS** | 점포가 등록한 요청을 물류센터가 사이드바 뱃지로 인지하고, 답변·상태변경 후 점포가 결과를 확인할 수 있다 |
| **SCOPE** | 요청 등록/목록/상세 + 댓글형 답변 + 상태(대기/처리중/완료) 관리. 카테고리·이미지첨부·실시간 알림은 범위 외 |

---

## 1. 아키텍처 결정 (Option C)

### 선택 이유
- **기존 관례 준수**: `store/order.php`, `logistics/order_detail.php`는 모두 각 페이지에서 쿼리를 직접 작성하고 `action` 기반 plain `<form method="post">` + 리다이렉트로 상태를 바꾼다. 신규 기능도 동일 패턴을 따라 러닝커브·리스크를 낮춘다
- **핵심 중복만 제거**: 점포 소유권 검증(`store_id` 일치 확인)과 사이드바 뱃지 카운트 쿼리는 여러 파일에서 반복되므로 `lib/store_request_helper.php`로 캡슐화
- **AJAX/JS 불필요**: 답변(댓글)·상태변경 모두 폼 제출 후 동일 페이지로 리다이렉트. `logistics/order_detail.php`의 `action` 분기 패턴 재사용

### 구성 요소
```
[Store]                                        [Logistics]
request_new.php  ──INSERT──▶ lc_store_requests(status='pending')
     │
requests.php  ──SELECT (store_id=본인)──▶ lc_store_requests
     │
request_detail.php ──SELECT/INSERT(댓글)──▶ lc_store_requests + lc_store_request_comments
     (store 측: 댓글 작성만, 상태변경 불가)

                                                requests.php ──SELECT (전체+필터)──▶ lc_store_requests
                                                     │
                                                request_detail.php ──SELECT/INSERT/UPDATE──▶
                                                     (logistics 측: 댓글 작성 + 상태변경)

partials/header.php (양쪽) ──COUNT(status='pending')──▶ 사이드바 뱃지
```

### 변경 요약
| 구분 | 파일 | 작업 |
|------|------|------|
| 신규 | `sql/lc_store_requests.sql` | 테이블 2개 생성 마이그레이션 |
| 신규 | `lib/store_request_helper.php` | 소유권 검증 + 뱃지 카운트 공용 함수 (양쪽 앱에서 include) |
| 신규 | `store/request_new.php` | 요청 등록 폼 |
| 신규 | `store/requests.php` | 본인 점포 요청 목록 |
| 신규 | `store/request_detail.php` | 요청 상세 + 댓글 스레드 + 댓글 작성 |
| 신규 | `logistics/requests.php` | 전체 요청 목록(점포/상태 필터) |
| 신규 | `logistics/request_detail.php` | 요청 상세 + 댓글 작성 + 상태 변경 |
| 수정 | `store/partials/header.php` | 내비게이션에 "Requests" 메뉴 추가 |
| 수정 | `logistics/partials/header.php` | 내비게이션에 "Store Requests" 메뉴 + 대기 건수 뱃지 추가 |

---

## 2. 데이터 모델

### 2.1 스키마 (Plan §5 확정)

```sql
CREATE TABLE lc_store_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    store_id    INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     TEXT NOT NULL,
    status      ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id),
    INDEX idx_store_status (store_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 게시판';

CREATE TABLE lc_store_request_comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    author_side ENUM('store','logistics') NOT NULL,
    author_id   INT NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    content     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES lc_store_requests(id) ON DELETE CASCADE,
    INDEX idx_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 댓글(답변) 스레드';
```

- `author_name`은 작성 시점 이름 스냅샷(추후 사용자명 변경에도 과거 댓글 표기 불변)
- 인덱스: 점포 목록(`store_id, status`), 물류 사이드바 뱃지(`status`), 상세 댓글 정렬(`request_id`)

### 2.2 마이그레이션 파일
`sql/lc_store_requests.sql` — 위 `CREATE TABLE` 2개를 담은 실행용 스크립트. (CLAUDE.md 지침에 따라 신규 SQL은 별도 파일로 관리)

---

## 3. 공용 Helper

### 파일: `lib/store_request_helper.php`

```php
<?php
// 점포 소유권 검증: request_id가 해당 store_id 소유인지 확인. 아니면 null 반환
function get_owned_store_request(mysqli $conn, int $request_id, int $store_id): ?array {
    $st = $conn->prepare("SELECT * FROM lc_store_requests WHERE id = ? AND store_id = ?");
    $st->bind_param('ii', $request_id, $store_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

// 물류센터 사이드바 뱃지: 대기(pending) 요청 건수
function count_pending_store_requests(mysqli $conn): int {
    return (int)$conn->query("SELECT COUNT(*) FROM lc_store_requests WHERE status='pending'")->fetch_row()[0];
}

// 상태 라벨/배지 클래스 (store_status_label/class와 동일한 패턴)
function store_request_status_label(string $s): string {
    return ['pending'=>'Pending','in_progress'=>'In Progress','done'=>'Done'][$s] ?? $s;
}
function store_request_status_class(string $s): string {
    return ['pending'=>'bg-yellow-100 text-yellow-800','in_progress'=>'bg-blue-100 text-blue-800',
            'done'=>'bg-green-100 text-green-800'][$s] ?? 'bg-gray-100 text-gray-500';
}
```

- `store/`와 `logistics/` 양쪽에서 `require_once __DIR__ . '/../../lib/store_request_helper.php';`로 include (프로젝트 루트 `lib/`는 이미 `permission_helper.php` 등 양쪽에서 공유 중인 위치)
- 로지스틱스 측 전체 목록 조회는 필터(점포/상태)가 페이지마다 달라 공용 함수화하지 않고 `logistics/requests.php`에 직접 작성 (Option C 원칙: 진짜 중복되는 것만 캡슐화)

---

## 4. 페이지별 설계

### 4.1 `store/request_new.php` — 요청 등록
- **인증**: `store_require_store_user()`
- **GET**: 제목/내용 입력 폼 렌더
- **POST**: `store_verify_csrf()` → `INSERT INTO lc_store_requests (store_id, title, content, status, created_by) VALUES (?,?,?,'pending',?)` → `store_set_flash('success', ...)` → `request_detail.php?id={new_id}`로 리다이렉트
- **검증**: 제목/내용 공백 트림 후 비어있으면 에러 메시지 재표시 (기존 `order.php`의 `$errors[]` 패턴)

### 4.2 `store/requests.php` — 본인 점포 요청 목록
- **인증**: `store_require_store_user()`
- **쿼리**: `SELECT id, title, status, created_at FROM lc_store_requests WHERE store_id = ? ORDER BY created_at DESC` (+ `?status=` 필터, `store/orders.php`의 상태 필터 패턴과 동일)
- **UI**: 목록 테이블(제목/상태뱃지/등록일), "새 요청" 버튼 → `request_new.php`, 각 행 클릭 → `request_detail.php?id={id}`

### 4.3 `store/request_detail.php` — 요청 상세 (점포 측)
- **인증**: `store_require_store_user()`
- **조회**: `get_owned_store_request($conn, $id, $store_id)` — null이면 404 처리 (`store/order_detail.php`의 소유권 체크와 동일 원칙)
- **댓글 목록**: `SELECT * FROM lc_store_request_comments WHERE request_id = ? ORDER BY created_at ASC`
- **POST (`action=comment`)**: `store_verify_csrf()` → 내용 비어있지 않으면 `INSERT INTO lc_store_request_comments (request_id, author_side, author_id, author_name, content) VALUES (?, 'store', ?, ?, ?)` → 동일 페이지로 리다이렉트
- **상태 변경 UI 없음** (FR-6: 점포는 조회만)

### 4.4 `logistics/requests.php` — 전체 요청 목록
- **인증**: `lc_require_staff()`
- **쿼리**: `SELECT r.id, r.title, r.status, r.created_at, s.name AS store_name FROM lc_store_requests r JOIN stores s ON r.store_id = s.id` + `?store=`, `?status=` 필터 (기존 `logistics/orders.php` 필터 패턴과 동일)
- **UI**: 점포명/제목/상태뱃지/등록일 목록, 상태 탭(전체/대기/처리중/완료)

### 4.5 `logistics/request_detail.php` — 요청 상세 (물류센터 측)
- **인증**: `lc_require_staff()`
- **조회**: `SELECT r.*, s.name AS store_name FROM lc_store_requests r JOIN stores s ON r.store_id=s.id WHERE r.id=?`
- **POST (`action=comment`)**: `lc_verify_csrf()` → `INSERT INTO lc_store_request_comments (..., author_side='logistics', ...)`
- **POST (`action=status`)**: `lc_verify_csrf()` → `$_POST['status']`가 `pending|in_progress|done` 중 하나인지 검증 후 `UPDATE lc_store_requests SET status=? WHERE id=?`
- **UI**: 댓글 스레드 + 답변 입력창, 상태 변경 `<select>` + 저장 버튼 (`logistics/order_detail.php`의 action 버튼 그룹과 동일 레이아웃)

---

## 5. 내비게이션 변경

### `store/partials/header.php`
"Orders" 섹션 아래(또는 별도 섹션)에 추가:
```php
<a href="<?php echo STORE_BASE; ?>/requests.php" ...>
    <i class="fas fa-comment-dots mr-2"></i>Requests
</a>
```
활성 판정: `in_array($_sp, ['requests.php','request_new.php','request_detail.php'])`

### `logistics/partials/header.php` ($_lc_is_staff 분기 내부만)
기존 `$_lc_pending_orders` 카운트 로직 옆에 추가:
```php
$_lc_pending_requests = 0;
if ($_lc_is_staff) {
    require_once __DIR__ . '/../../lib/store_request_helper.php';
    $conn = get_lc_db();
    $_lc_pending_requests = count_pending_store_requests($conn);
    $conn->close();
}
```
"Order Management" 섹션과 동일한 amber 톤의 신규 섹션(또는 기존 섹션에 항목 추가)에 뱃지 포함 메뉴 삽입. 점포(비staff) 분기는 이번 스코프에서 변경하지 않음(Plan §7 명시: `@store` 앱이 담당).

---

## 6. 에러 처리 & 보안

| 항목 | 처리 |
|------|------|
| 타 점포 요청 접근 (`store/request_detail.php?id=999`) | `get_owned_store_request()`가 null 반환 → 404 또는 `requests.php`로 리다이렉트 + flash 에러 |
| 존재하지 않는 요청 ID (logistics) | `fetch_assoc()` null → 404 처리 |
| CSRF | 모든 POST에 `store_verify_csrf()` / `lc_verify_csrf()` |
| 상태값 변조 (`status=hacked`) | 화이트리스트 검증(`in_array($status, ['pending','in_progress','done'], true)`) 후 미통과 시 무시 + 에러 flash |
| XSS | 제목/내용/댓글 출력 시 전부 `htmlspecialchars()` (기존 관례) |
| 빈 제목/내용/댓글 | 서버측 트림 후 길이 체크, 클라이언트 `required` 속성 병행 |

---

## 7. Page UI Checklist

#### store/requests.php
- [ ] 목록 테이블: 제목, 상태뱃지(대기=노랑/처리중=파랑/완료=초록), 등록일
- [ ] 상태 필터 탭 (전체/대기/처리중/완료)
- [ ] "새 요청" 버튼 → request_new.php

#### store/request_new.php
- [ ] 입력폼: 제목(text, required), 내용(textarea, required)
- [ ] 등록 버튼 + 취소(목록으로) 링크

#### store/request_detail.php
- [ ] 요청 원글: 제목/내용/상태뱃지/등록일
- [ ] 댓글 스레드: 작성측 라벨(점포/물류센터 구분 색상), 작성자명, 내용, 작성일
- [ ] 댓글 입력폼(textarea + 등록 버튼)

#### logistics/requests.php
- [ ] 목록 테이블: 점포명, 제목, 상태뱃지, 등록일
- [ ] 점포 필터 드롭다운, 상태 필터 탭
- [ ] 사이드바 뱃지: 대기 건수 (빨강 원형, 기존 Order List 뱃지와 동일 스타일)

#### logistics/request_detail.php
- [ ] 요청 원글: 점포명/제목/내용/상태뱃지/등록일
- [ ] 상태 변경 `<select>`(대기/처리중/완료) + 저장 버튼
- [ ] 댓글 스레드 + 댓글 입력폼

---

## 8. Test Plan (경량)

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 요청 등록 | store 로그인 → request_new.php 작성/등록 | requests.php에 상태='Pending'으로 노출 |
| 2 | 타 점포 접근 차단 | store A로 로그인 후 store B 소유 request_id로 상세 접근 | 404/에러 flash, 내용 노출 안 됨 |
| 3 | 물류 답변 + 상태 변경 | logistics staff가 request_detail.php에서 댓글 등록 + 상태를 in_progress로 변경 | store 측 상세에서 댓글/변경된 상태 확인 |
| 4 | 사이드바 뱃지 | pending 요청 1건 존재 상태로 logistics 임의 페이지 진입 | 사이드바에 뱃지 "1" 표시 |
| 5 | 점포 상태변경 시도 차단 | store 상세 화면에 상태변경 UI가 없음을 확인 | 상태변경 폼/버튼 미노출 |

---

## 9. 구현 순서

1. [ ] `sql/lc_store_requests.sql` 마이그레이션 작성 및 실행
2. [ ] `lib/store_request_helper.php` (소유권 검증 + 뱃지 카운트 + 상태 라벨 함수)
3. [ ] `store/request_new.php` → `store/requests.php` → `store/request_detail.php`
4. [ ] `logistics/requests.php` → `logistics/request_detail.php`
5. [ ] `store/partials/header.php`, `logistics/partials/header.php` 내비게이션/뱃지 반영
6. [ ] 전 시나리오(§8) 수동 검증

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-07 | Initial draft (Option C) | whdans007 |
