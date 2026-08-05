# Design: employee-hire-transfer (직원 입사일/발령이력/점포전입)

**Feature**: employee-hire-transfer
**Plan**: `docs/01-plan/features/employee-hire-transfer.plan.md`
**Architecture**: Option C — Pragmatic (신규 파일 0개, office_helper.php에 로직 분리)
**Date**: 2026-08-04

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 입사일/발령 이력 부재로 직원 근속 및 이동 히스토리 추적 불가 |
| **WHO** | 오피스 스태프 (office 모듈 권한 보유자, 전 점포) |
| **RISK** | 점포 전입이 즉시 반영되면 출발 점포 데이터가 혼란 → 승인 워크플로로 완화. 승인 권한 검증 누락 시 타 점포 승인 도용 위험 |
| **SUCCESS** | 직원 카드에서 입사일 확인, 발령 이력 타임라인 확인, 전입 요청 → 목적지 점포 승인 → store_id 반영 + 이력 자동 기록 |
| **SCOPE** | office_employees 컬럼 1개, 신규 테이블 2개, employees.php 확장, office_helper.php 함수 7개 |

---

## 1. Overview

### 1.0 Architecture Comparison

| 기준 | Option A: Minimal | Option B: Clean | **Option C: Pragmatic (선택)** |
|------|:-:|:-:|:-:|
| 신규 파일 | 0 | 3 (ajax_*.php) | 0 |
| 수정 파일 | 1 (employees.php) | 2 | 2 (employees.php, office_helper.php) |
| 복잡도 | 낮음 | 중간 | 낮음 |
| 유지보수성 | 낮음 (SQL이 컨트롤러에 뒤섞임) | 높음 | 높음 (로직은 helper 함수로 분리) |
| 기존 패턴 일관성 | 보통 | 낮음 (이 페이지는 POST-redirect 방식, AJAX 미사용) | 높음 (fp_* 액션과 동일 패턴 + office_helper.php 함수 분리 관행 유지) |

**선택**: Option C — **Rationale**: employees.php는 이미 전체 페이지 POST-redirect 패턴(add/edit/toggle_status/delete/fp_*)만 사용 중이라 AJAX 엔드포인트 분리(Option B)는 패턴 불일치. 반면 SQL을 컨트롤러에 직접 넣는 Option A는 향후 재사용(예: 점포별 전입 현황 리포트)이 어려움. office_helper.php가 이미 `get_office_employees()`, `get_fingerprints_by_employee()` 등 조회 함수를 모아두는 관행이 있으므로 그대로 확장.

### 1.1 Design Goals

- 기존 employees.php의 POST 스위치/모달 패턴을 그대로 확장 (새 진입점 없음)
- 전입 승인은 목적지 점포 세션에서만 처리 가능하도록 서버측 재검증 필수
- 발령 이력은 append-only (수정/삭제 없음) — 감사 추적 목적

### 1.2 Design Principles

- 기존 파일/함수 재사용 우선 (신규 파일 최소화)
- 승인이 필요한 상태변경(store_id)과 단순 기록(history)을 명확히 분리
- 모든 신규 DB 오브젝트는 기존 `SHOW COLUMNS`/`SHOW TABLES` 가드 방식으로 자동 생성 (office_helper.php IIFE 패턴)

---

## 2. Data Model

### 2.1 스키마 변경

```sql
-- 1) 입사일자 (office_employees 확장)
ALTER TABLE office_employees ADD COLUMN hire_date DATE NULL DEFAULT NULL AFTER job_role;

-- 2) 발령/이력 로그 (append-only)
CREATE TABLE IF NOT EXISTS office_employee_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  store_id    INT UNSIGNED NOT NULL COMMENT '기록 시점 소속 점포',
  event_type  ENUM('hire','transfer','role_change','promotion','note') NOT NULL DEFAULT 'note',
  content     VARCHAR(500) NOT NULL,
  event_date  DATE NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_employee_date (employee_id, event_date),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 점포 전입 승인 요청
CREATE TABLE IF NOT EXISTS office_employee_transfers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id    INT UNSIGNED NOT NULL,
  from_store_id  INT UNSIGNED NOT NULL,
  to_store_id    INT UNSIGNED NOT NULL,
  reason         VARCHAR(500) NULL,
  status         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  requested_by   INT UNSIGNED NULL,
  approved_by    INT UNSIGNED NULL,
  decision_note  VARCHAR(500) NULL,
  decided_at     TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status_to_store (status, to_store_id),
  INDEX idx_employee (employee_id),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**자동 생성 위치**: `office/lib/office_helper.php` 상단의 기존 컬럼 자동추가 IIFE(파일 상단, photo/inactive_reason 등을 추가하던 블록)에 `hire_date` 항목 추가, 그 아래 두 `CREATE TABLE IF NOT EXISTS`를 같은 IIFE 안에서 실행. `office/sql/employee_history_transfer.sql`은 참고용 DDL로만 남김 (실제 실행 경로는 PHP 자동 마이그레이션).

### 2.2 office_helper.php 신규 함수

| 함수 | 역할 |
|------|------|
| `get_office_employees()` (기존 확장) | SELECT 목록에 `hire_date` 추가 |
| `get_employee_history_by_store(int $store_id): array` | 현재 점포 소속 직원 전원의 이력을 employee_id 기준 배열로 일괄 조회 (N+1 방지, `get_fingerprints_by_employee()`와 동일 패턴) |
| `add_employee_history(int $employee_id, int $store_id, string $event_type, string $content, string $event_date, ?int $created_by): bool` | 이력 1건 추가 |
| `get_all_stores(): array` | 전체 점포 목록 (id, name) — 전입 대상 select용. `stores` 테이블 직접 조회 (store_change_request_helper.php의 `scr_get_all_stores()`와 동일 쿼리, office 모듈 자체 함수로 중복 정의하여 lib 간 의존성 없앰) |
| `get_employee_pending_transfer(int $employee_id): ?array` | 해당 직원의 처리 대기중 전입 요청 1건 반환 (중복 요청 방지용) |
| `create_employee_transfer_request(int $employee_id, int $from_store_id, int $to_store_id, ?string $reason, ?int $requested_by): true|string` | 전입 요청 생성. 이미 pending 있으면 에러 문자열 반환 |
| `get_pending_transfers_to_store(int $store_id): array` | 목적지가 해당 점포인 pending 요청 목록 (직원명/사진/현재 job_role, 출발점포명 JOIN 포함) |
| `decide_employee_transfer(int $request_id, bool $approve, int $to_store_id, int $approver_id, string $note): true|string` | 승인/반려 처리. **`$to_store_id`(현재 세션 store_id)와 요청의 `to_store_id`가 일치하는지 내부에서 재검증** — 불일치 시 에러 반환. 승인 시 트랜잭션으로 `office_employees.store_id` 갱신 + `office_employee_history`에 `transfer` 이벤트 자동 삽입 |

---

## 3. UI/UX Design

### 3.1 정보 모달 확장 (기존 `modal_info`, employees.php)

```
┌─────────────────────────────┐
│  [사진]  홍길동 #12          │
│          [재직 중]           │
├─────────────────────────────┤
│ Role        Cashier          │
│ 소속 에이전시  미지정         │
│ 지문 등록    슬롯 1           │
│ 입사일자    2024-03-15        │  ← 신규 행
├─────────────────────────────┤
│ 발령 이력              [+추가]│  ← 신규 섹션
│ 2026-08-01 [전입] A점포→B점포 │
│ 2025-11-01 [승진] 캐셔→슈퍼바이저│
│ 2024-03-15 [입사] 입사        │
├─────────────────────────────┤
│ [전입 요청]         [닫기]    │  ← 신규 버튼 (pending 있으면 "전입 대기중" 비활성)
└─────────────────────────────┘
```

### 3.2 발령 추가 모달 (신규 `modal_history_add`)

- 날짜 (date, 기본값 오늘)
- 유형 (select: 승진 / 직책변경 / 메모) — `transfer`/`hire`는 시스템이 자동 기록하므로 수동 옵션에서 제외
- 내용 (textarea, 필수)

### 3.3 전입 요청 모달 (신규 `modal_transfer_request`)

- 목적지 점포 (select, 현재 점포 제외 전체 `stores`)
- 사유 (textarea, 선택)
- 제출 → "목적지 점포 승인 대기중" 안내 후 정보모달 갱신 (버튼이 "전입 대기중"으로 비활성화)

### 3.4 페이지 헤더 배지 + 승인 목록 모달 (신규)

```
직원등록                    [전입 승인 대기 2건] [Add Employee]
```

배지는 `get_pending_transfers_to_store($store_id)` 결과가 있을 때만 표시. 클릭 시 `modal_transfers_incoming`:

```
┌───────────────────────────────────────────┐
│ 전입 승인 대기                              │
├───────────────────────────────────────────┤
│ [사진] 홍길동 (Cashier) — A점포 → 현재점포   │
│ 사유: 인력 재배치                            │
│ 반려 메모: [___________]  [승인] [반려]      │
├───────────────────────────────────────────┤
│ ...                                        │
└───────────────────────────────────────────┘
```

### 3.5 Page UI Checklist

#### employees.php (직원등록)

- [ ] 배지: "전입 승인 대기 N건" (N>0일 때만, Add Employee 버튼 왼쪽)
- [ ] Add/Edit 모달: 입사일자 date input (선택 입력)
- [ ] 정보 모달: 입사일자 표시행 ("미입력" 처리 포함)
- [ ] 정보 모달: 발령 이력 타임라인 (event_date desc 정렬, 유형 배지 색상 구분)
- [ ] 정보 모달: "발령 추가" 버튼 → modal_history_add
- [ ] 정보 모달: "전입 요청" 버튼 (pending 존재 시 "전입 대기중" 비활성 표시)
- [ ] modal_history_add: 날짜/유형(select)/내용(textarea, required)
- [ ] modal_transfer_request: 목적지 점포 select(현재 점포 제외)/사유 textarea
- [ ] modal_transfers_incoming: 요청별 직원 사진/이름/역할/출발점포/사유/요청일 + 반려메모 입력 + [승인]/[반려] 버튼

---

## 4. POST Action 명세 (employees.php 기존 스위치에 추가)

| action | 파라미터 | 처리 | 권한/검증 |
|--------|---------|------|-----------|
| `history_add` | employee_id, event_type, content, event_date | `add_employee_history()` 호출 | employee_id가 현재 store_id 소속인지 확인 |
| `transfer_request` | employee_id, to_store_id, reason | `get_employee_pending_transfer()`로 중복 체크 → `create_employee_transfer_request()` | employee_id가 현재 store_id 소속인지 확인, to_store_id != 현재 store_id |
| `transfer_decide` | request_id, approve(1/0), decision_note | `decide_employee_transfer($id, $approve, get_office_store_id(), ...)` | **함수 내부에서 요청의 to_store_id === 현재 store_id 재검증 (Critical)** |
| `add` (기존 확장) | 기존 + hire_date | 직원 생성 후 `add_employee_history($id, $store_id, 'hire', '입사', $hire_date ?: today, ...)` 자동 삽입 | 기존과 동일 |

기존 코드와 동일하게 처리 후 `header('Location: employees.php'); exit;` 패턴 유지.

---

## 5. Error Handling

| 상황 | 처리 |
|------|------|
| 이미 pending 전입 요청이 있는 직원에 재요청 | `create_employee_transfer_request()`가 에러 문자열 반환 → `?err=duplicate_transfer` 리다이렉트, 기존 `$fp_err_messages` 패턴에 메시지 추가 |
| 다른 점포 세션에서 자신에게 오지 않은 요청을 승인 시도 | `decide_employee_transfer()` 내부 검증 실패 → 무시하고 리다이렉트 (에러 메시지 노출하지 않음, 정보 노출 최소화) |
| 발령 추가 시 내용 미입력 | 서버측 `if ($content === '') return;` 무시 (기존 `add`/`edit` action의 필수값 체크 패턴과 동일) |

---

## 6. Security Considerations

- [ ] **전입 승인 권한 재검증**: `decide_employee_transfer()`가 요청의 `to_store_id`와 호출측이 넘긴 현재 세션 `store_id`를 반드시 비교 — 이 검증이 없으면 다른 점포 스태프가 URL 조작만으로 임의 점포의 전입 요청을 승인할 수 있음 (가장 중요한 보안 포인트)
- [ ] `history_add`/`transfer_request`의 `employee_id`가 현재 세션 `store_id` 소속인지 확인 (다른 점포 직원 데이터 조작 방지)
- [ ] 모든 신규 쿼리는 기존 코드와 동일하게 prepared statement 사용
- [ ] `content`/`reason`/`decision_note` 출력 시 `htmlspecialchars()` 적용 (기존 컨벤션)

---

## 7. Test Plan (수동 QA — 이 프로젝트는 자동화 테스트 도구 미사용)

| # | 시나리오 | 절차 | 기대 결과 |
|---|---------|------|-----------|
| 1 | 신규 직원 등록 + 입사일 자동 이력 | 입사일자 입력하여 직원 추가 | 정보모달 이력에 "입사" 이벤트가 입력한 날짜로 표시 |
| 2 | 발령 수동 추가 | 정보모달 → 발령추가 → 승진 유형 입력 | 이력 리스트 최상단(날짜 desc)에 반영 |
| 3 | 전입 요청 → 중복 요청 차단 | A점포에서 직원 전입요청 2회 연속 시도 | 2번째 요청 시 에러 안내, pending 1건만 유지 |
| 4 | 전입 요청 → 목적지 점포 승인 | B점포 세션 로그인 → 배지 확인 → 승인 | 직원이 A점포 목록에서 사라지고 B점포 목록에 나타남, 이력에 transfer 이벤트 자동 추가 |
| 5 | 전입 요청 → 목적지 점포 반려 | B점포 세션에서 반려 처리 | 직원 store_id 변경 없음, 요청 status=rejected, 이력 미추가 |
| 6 | 승인 권한 우회 시도 | C점포(목적지 아님) 세션에서 동일 request_id로 승인 POST 직접 전송 | 서버측 검증으로 무시됨, store_id 변경 없음 |

---

## 8. Implementation Guide

### 8.1 구현 순서

1. [ ] `office_helper.php`: 자동 마이그레이션 IIFE에 `hire_date` 컬럼 + 신규 테이블 2개 추가
2. [ ] `office_helper.php`: 7개 함수 추가, `get_office_employees()` SELECT 확장
3. [ ] `employees.php`: Add/Edit 모달에 입사일자 필드, `add` action에 hire 이력 자동 삽입
4. [ ] `employees.php`: 정보모달에 입사일자 + 발령이력 타임라인 + "발령추가"/"전입요청" 버튼, 관련 모달 2개 추가
5. [ ] `employees.php`: 페이지 헤더 배지 + 승인목록 모달 + `transfer_decide` action (보안 재검증 포함)
6. [ ] 수동 QA (§7 시나리오 1~6, 특히 6번 보안 시나리오 필수 확인)

### 8.2 Session Guide

| Module | Scope Key | 설명 | 예상 분량 |
|--------|-----------|------|:---------:|
| DB + Helper 함수 | `module-1` | 스키마 자동생성 + office_helper.php 7개 함수 | 소 |
| 입사일/이력 UI | `module-2` | Add/Edit 필드, 정보모달 확장, 발령추가 모달 | 중 |
| 전입 워크플로 UI | `module-3` | 전입요청 모달, 배지, 승인목록 모달, 보안 검증 | 중 |

단일 세션으로도 충분한 규모이나, 필요 시 `/pdca do employee-hire-transfer --scope module-1` 형태로 분할 가능.

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-04 | Initial draft (Checkpoint 3: Option C 선택 반영) | Claude |
