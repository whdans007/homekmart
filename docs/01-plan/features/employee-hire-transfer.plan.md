# 직원 입사일/소속점포 전입/발령 이력 — Plan Document

**Feature**: employee-hire-transfer
**Date**: 2026-08-04
**Method**: PDCA Plan (Checkpoint 1-2 완료 후 작성)

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | office/schedule/employees.php 직원 카드에 입사일, 발령(전입/승진 등) 이력이 없어 근속/이동 이력을 추적할 수 없음. 점포 이동은 필드 하나만 바꾸면 이력 없이 사라짐 |
| **Solution** | (1) 입사일자 필드 추가 (2) 발령 이력 로그 테이블로 승진/직책변경/메모를 날짜별 누적 기록 (3) 점포 전입은 목적지 점포 승인이 필요한 요청 워크플로로 처리, 승인 시 이력에 자동 기록 |
| **UX Effect** | 직원 카드 사진 클릭 시 뜨는 정보 모달(이미 구현됨)에 입사일 + 발령 이력 타임라인 표시. "발령 추가" 버튼으로 수동 기록, "전입 요청" 버튼으로 타 점포 이동 신청 |
| **Core Value** | 직원의 근속·이동·승진 히스토리를 한 곳에서 확인 가능. 점포 간 인력 이동이 임의로 즉시 반영되지 않고 목적지 점포 확인을 거쳐 데이터 정합성 보장 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 입사일/발령 이력 부재로 직원 근속 및 이동 히스토리 추적 불가 (사용자 요청) |
| **WHO** | 오피스 스태프 (office 모듈 권한 보유자, 전 점포) |
| **RISK** | 점포 전입이 즉시 반영되면 출발 점포 스케줄/지문 데이터가 갑자기 사라져 혼란 → 승인 워크플로로 완화 |
| **SUCCESS** | 직원 카드에서 입사일 확인 가능, 발령 이력 타임라인 확인 가능, 전입 요청 → 목적지 점포 승인 → store_id 반영 + 이력 자동 기록 |
| **SCOPE** | office_employees 컬럼 추가 1개, 신규 테이블 2개, employees.php 정보모달 확장 + 신규 액션 3개 (발령추가/전입요청/전입승인) |

---

## 1. 화면 구성

기존 직원 카드(사진 클릭 → 정보 모달, 이미 구현됨)를 확장:

```
┌─────────────────────────────┐
│  [사진]  홍길동 #12          │
│          [재직 중]           │
├─────────────────────────────┤
│ Role        Cashier          │
│ 소속 에이전시  미지정         │
│ 지문 등록    슬롯 1           │
│ 입사일자    2024-03-15  ← 신규│
├─────────────────────────────┤
│ 발령 이력              [+추가]│ ← 신규
│ 2026-08-01 전입: A점포→B점포  │
│ 2025-11-01 승진: 캐셔→슈퍼바이저│
│ 2024-03-15 입사               │
├─────────────────────────────┤
│ [전입 요청]  [닫기]           │ ← 신규 버튼
└─────────────────────────────┘
```

전입 승인 대기 건수는 직원등록 페이지 상단에 배지로 표시 (목적지 = 현재 로그인 점포인 pending 건):
```
직원등록                              [전입 승인 대기 2건] [Add Employee]
```
클릭 시 대기 목록 모달 → 승인/반려.

---

## 2. Requirements

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| FR-01 | 직원 등록/수정 모달에 입사일자(date) 필드 추가 | Must |
| FR-02 | 정보 모달에 입사일자 표시 | Must |
| FR-03 | 발령 이력 로그: 날짜/유형(승진·직책변경·메모)/내용 수동 기록, 정보 모달 내 타임라인으로 표시 | Must |
| FR-04 | 점포 전입 요청: 현재 점포 → 목적지 점포 + 사유 입력, status=pending 생성 | Must |
| FR-05 | 목적지 점포 로그인 시 자신에게 온 pending 전입 요청 배지/목록 확인 | Must |
| FR-06 | 전입 승인 시 office_employees.store_id 갱신 + 발령 이력에 자동 기록 (반려 시 상태만 변경, 이력 미기록) | Must |
| FR-07 | 전입 승인/반려는 목적지 점포의 office 권한 보유자 누구나 처리 가능 (관리자 등급 구분 없음, 기존 office 모듈 권한 모델과 동일) | Must |
| FR-08 | 진행 중(pending) 전입 요청이 있는 직원은 카드에 "전입 대기중" 표시, 중복 요청 방지 | Should |

---

## 3. DB 테이블

```sql
-- 1) 입사일자 컬럼 (office_helper.php의 SHOW COLUMNS 자동 추가 패턴 재사용)
ALTER TABLE office_employees ADD COLUMN hire_date DATE NULL DEFAULT NULL AFTER job_role;

-- 2) 발령/이력 로그 (append-only)
CREATE TABLE IF NOT EXISTS office_employee_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  event_type  ENUM('hire','transfer','role_change','promotion','note') NOT NULL DEFAULT 'note',
  content     VARCHAR(500) NOT NULL,
  event_date  DATE NOT NULL,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_employee_date (employee_id, event_date),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 점포 전입 승인 요청 (store_change_requests 패턴 재사용, 대상만 employee)
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

**참고**: `stores` 테이블은 이미 존재 (`sql/complete_schema.sql`). 점포명 조회는 `lib/store_change_request_helper.php`의 `scr_get_all_stores()` 패턴 재사용 가능.

---

## 4. 파일 구조 (변경 대상)

```
office/schedule/employees.php          # 입사일 필드, 정보모달 확장, 액션 6개 추가
office/lib/office_helper.php           # hire_date 컬럼 자동추가, get_office_employees()에 hire_date SELECT 추가
office/sql/
└── employee_history_transfer.sql      # 신규 (위 테이블 2개 DDL, 참고용 — 실제 생성은 PHP 자동 마이그레이션)
```

**신규 POST action** (employees.php 내 기존 POST 스위치에 추가):
- `history_add` — 발령 이력 수동 추가 (employee_id, event_type, content, event_date)
- `transfer_request` — 전입 요청 생성 (employee_id, to_store_id, reason)
- `transfer_decide` — 전입 승인/반려 (request_id, approve, decision_note)

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 전입 승인 시 출발 점포의 스케줄(office_schedule_items)·지문(office_fingerprints)에 남은 참조 | Medium | Medium | FK는 `ON DELETE SET NULL/CASCADE`로 이미 안전. 승인 로직에서 직원 삭제는 하지 않고 store_id만 변경 — 과거 스케줄 기록은 그대로 유지(정상 동작, 별도 처리 불필요) |
| 목적지 점포 승인 전 다른 스태프가 같은 직원에 대해 중복 전입 요청 | Low | Medium | FR-08: pending 요청 존재 시 신규 요청 버튼 비활성화 |
| hire_date 미입력 기존 직원 다수 | Low | High | NULL 허용, 화면에는 "미입력"으로 표시 |

---

## 6. Impact Analysis

| Resource | Type | Change |
|----------|------|--------|
| `office_employees` | DB Table | `hire_date` 컬럼 추가 (nullable, 기존 데이터 영향 없음) |
| `get_office_employees()` (office_helper.php:83) | Function | SELECT 목록에 `hire_date` 추가 — 반환 배열에 키 추가뿐이라 기존 호출부(employees.php 외 다른 곳에서 이 함수 사용 여부 확인 필요) 영향 없음 |
| `employees.php` POST 스위치 | Code | 기존 action(`add`,`edit`,`toggle_status`,`delete`,`fp_*`) 로직 변경 없음, 신규 action 3개만 추가 |

- [ ] `get_office_employees()` 호출부 전수 확인 (grep) — SELECT 컬럼 추가가 다른 페이지에 영향 없는지
- [ ] 기존 전입 관련 참고 코드(`lib/store_change_request_helper.php`, admin/my_store.php)는 `users` 테이블 대상이라 별개 — 재사용은 패턴만, 코드 공유 없음

---

## 7. YAGNI Review

### In Scope (v1)
- [x] 입사일자 필드 (등록/수정/조회)
- [x] 발령 이력 수동 기록 + 타임라인 표시
- [x] 점포 전입 승인 요청 워크플로 (요청 → 목적지 승인/반려 → store_id 반영 + 이력 자동기록)
- [x] pending 전입 요청 배지 표시

### Out of Scope (v1 이후)
- 직원이 동시에 여러 점포에 소속(공유)되는 기능 (Checkpoint에서 "단일 점포 소속"으로 확정)
- 전입 요청에 대한 알림(이메일/푸시) — 배지 확인만 지원
- 발령 이력 수정/삭제 (append-only 로그 원칙, v1은 기록만)
- 입사일 기준 근속연수 자동 계산·리포트

---

## 8. Next Steps

1. [ ] `/pdca design employee-hire-transfer` — 위 3개 테이블/6개 신규 API의 구체 구현 설계
2. [ ] 구현 (employees.php 확장)
3. [ ] Gap 검증

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-04 | Initial draft (Checkpoint 1-2 확정 반영) | Claude |
