---
template: design
version: 1.3
---

# employee-hr-records Design Document

> **Summary**: 직원 인사기록(경고장 등) 등록/조회/수정/삭제 기능. 신규 테이블 `office_employee_records`를 기존 `employee_history` 모달 패턴과 동일하게 `office/schedule/employees.php`에 인라인으로 구현한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-06
> **Status**: Draft
> **Planning Doc**: [employee-hr-records.plan.md](../01-plan/features/employee-hr-records.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 경고장 등 인사 이슈를 기록할 방법이 없고, 향후 가산점 점수제를 담을 데이터 구조도 없음 |
| **WHO** | 점포 담당자(자기 점포만), 본사 관리자(전체 조회) |
| **RISK** | 기존 `employees.php`/`employee_history` 기능 회귀, 점수 필드가 평가 로직 없이 노출되어 오사용 가능 |
| **SUCCESS** | 이미지 여러 장 업로드+목록조회+수정+삭제 정상 동작, 향후 event_type 확장(지각/결근) 시 테이블 재설계 불필요 |
| **SCOPE** | 신규 테이블 → CRUD 헬퍼 → 모달 UI (등록/목록/수정/삭제) — 전부 Option A 인라인 |

---

## 1. Overview

### 1.1 Design Goals

- 기존 `office_employee_history` 인프라와 동일한 사용 패턴(모달, 직원 카드 임베드 데이터)을 그대로 재사용해 학습 비용 없이 도입
- `event_type`을 확장 가능한 ENUM으로 두어, 향후 지각/결근 점수제 도입 시 새 테이블 없이 이벤트만 추가하면 되도록 설계
- 사진 업로드는 기존 `save_employee_photo()`와 동일한 검증 규칙(확장자/용량)을 재사용해 일관성 유지

### 1.2 Design Principles

- **기존 패턴 재사용 우선**: 새로운 UI 패턴/컴포넌트를 만들지 않고 `modal_history_add`와 동일한 구조를 복제
- **점포 스코프 고정**: 모든 쿼리는 기존 `$store_id` 변수 기반 필터를 그대로 사용
- **부분 실패 허용**: 이미지 저장 실패가 텍스트 기록 저장을 막지 않음 (기존 `add_employee_history` 패턴과 동일한 관용도)

---

## 2. Architecture Options (v1.7.0)

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 기존 history 모달과 동일하게 전부 인라인 | 모달 UI를 별도 partial 파일로 분리 | POST/CRUD는 인라인, 모달 HTML만 분리 |
| **New Files** | 0 (SQL 참고파일 1개) | 1 (partial) + SQL | 1 (partial) + SQL |
| **Modified Files** | 2 (`employees.php`, `office_helper.php`) | 3 | 3 |
| **Complexity** | Low | Medium | Medium |
| **Maintainability** | Medium (파일이 커지지만 기존 패턴과 일관) | High | High |
| **Effort** | Low | Medium | Medium |
| **Risk** | Low (기존 패턴 복제라 회귀 위험 최소) | Low | Low |
| **Recommendation** | 현재 파일 크기(1176줄)에서는 충분 | 파일이 더 커질 미래 대비 | 절충 |

**Selected**: Option A — **Rationale**: `employees.php`(1176줄), `office_helper.php`(817줄) 모두 아직 분리가 필요할 만큼 크지 않고, 기존 `employee_history` 모달과 완전히 동일한 패턴을 복제하는 것이 일관성과 유지보수성 면에서 가장 안전하다 (사용자 선택, Checkpoint 3).

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────┐
│ office/schedule/employees.php                │
│  ├─ modal_record_add   (등록/수정 겸용, 신규)  │
│  ├─ modal_records_list (목록+삭제, 신규)       │
│  └─ POST action: record_add/edit/delete (신규) │
└───────────────────┬───────────────────────────┘
                     │ calls
                     ▼
┌─────────────────────────────────────────────┐
│ office/lib/office_helper.php                  │
│  ├─ add_employee_record()                     │
│  ├─ update_employee_record()                  │
│  ├─ delete_employee_record()                  │
│  └─ get_employee_records_by_store()           │
└───────────────────┬───────────────────────────┘
                     ▼
┌─────────────────────────────────────────────┐
│ MySQL: office_employee_records (신규)         │
│ uploads/employees/records/ (신규 이미지 경로)  │
└─────────────────────────────────────────────┘
```

### 2.2 Data Flow

```
등록: 모달 입력 → POST(multipart) → 이미지 저장 → add_employee_record() → 새로고침
조회: 페이지 로드 시 get_employee_records_by_store() → 직원별 배열에 임베드 → 모달에서 렌더
수정: 목록에서 수정 클릭 → modal_record_add에 값 채워 재사용 → POST record_edit
삭제: 목록에서 삭제 클릭 → 확인 → POST record_delete → 첨부파일도 함께 삭제
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `employees.php` 신규 모달 | `office_helper.php` 신규 함수 4종 | 데이터 CRUD |
| `add_employee_record()` | `office_employee_records` 테이블 | 저장 |
| 이미지 저장 로직 | 기존 `save_employee_photo()` 검증 규칙 (jpg/png/gif/webp, 5MB) | 파일 검증 재사용 |

---

## 3. Data Model

### 3.1 Entity Definition

```
office_employee_records
├── id                 INT UNSIGNED AUTO_INCREMENT PK
├── employee_id        INT UNSIGNED NOT NULL (FK → office_employees.id, ON DELETE CASCADE)
├── store_id           INT UNSIGNED NOT NULL      -- 기록 시점 소속 점포
├── event_type         ENUM('warning','late','absence','other') NOT NULL DEFAULT 'warning'
├── category           VARCHAR(100) NULL          -- 사유 (자유 텍스트: 지각, 근태불량, 고객컴플레인 등)
├── content             VARCHAR(500) NULL          -- 상세 내용
├── points              INT NOT NULL DEFAULT 0     -- 감점 (음수) — 1차엔 warning만 실사용, late/absence는 향후
├── attachment_files    JSON NULL                  -- 첨부 이미지 파일명 배열, 예: ["emp_1_5_170...jpg", ...]
├── created_by          INT UNSIGNED NULL
├── created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
└── updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### 3.2 Entity Relationships

```
[office_employees] 1 ──── N [office_employee_records]
```

### 3.3 Database Schema

```sql
-- Design Ref: employee-hr-records.design.md §3.1
-- 참고용 DDL. 실제 생성은 office/lib/office_helper.php 상단의
-- CREATE TABLE IF NOT EXISTS 자동 마이그레이션 블록에서 처리 (employee_history와 동일 패턴)

CREATE TABLE IF NOT EXISTS office_employee_records (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id       INT UNSIGNED NOT NULL,
  store_id          INT UNSIGNED NOT NULL COMMENT '기록 시점 소속 점포',
  event_type        ENUM('warning','late','absence','other') NOT NULL DEFAULT 'warning',
  category          VARCHAR(100) NULL COMMENT '사유 분류',
  content           VARCHAR(500) NULL COMMENT '상세 내용',
  points            INT NOT NULL DEFAULT 0 COMMENT '감점 (향후 인사평가용, 음수 권장)',
  attachment_files  JSON NULL COMMENT '첨부 이미지 파일명 배열',
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_employee_created (employee_id, created_at),
  INDEX idx_store_type (store_id, event_type),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. API Specification

> 이 프로젝트는 REST API가 아닌 PHP POST 액션 패턴을 사용한다 (기존 `history_add`/`transfer_request`와 동일).

### 4.1 액션 목록

| Action (POST `action=`) | Description | Auth |
|--------|------|------|
| `record_add` | 인사기록 신규 등록 (이미지 여러 장 포함) | office 로그인 + 점포 소유 확인 |
| `record_edit` | 기존 기록 수정 | 동일 |
| `record_delete` | 기록 삭제 (첨부파일도 함께 삭제) | 동일 |

### 4.2 상세 명세

#### `POST employees.php` (`action=record_add`, multipart/form-data)

**Request:**
```
employee_id: int (required)
event_type: string (기본 'warning', ENUM 값만 허용)
category: string (optional, max 100)
content: string (optional, max 500)
points: int (optional, default 0)
files[]: image[] (optional, 여러 장, 각 5MB 이하, jpg/png/gif/webp)
```

**동작:**
1. `employee_id`가 현재 `$store_id` 소속인지 확인 (기존 `history_add`와 동일 소유권 체크)
2. 업로드된 각 파일을 `save_employee_photo()`와 동일한 검증으로 `uploads/employees/records/`에 저장 → 파일명 배열 생성 (검증 실패한 파일은 건너뜀, 텍스트 기록 저장은 계속 진행)
3. `add_employee_record()` 호출로 DB 저장
4. `employees.php`로 리다이렉트

#### `POST employees.php` (`action=record_edit`)

기존 레코드의 `category`/`content`/`points`/`event_type` 갱신. 신규 첨부 파일이 있으면 기존 배열에 추가(교체 아님, 삭제는 개별 파일 삭제 UI에서 처리).

#### `POST employees.php` (`action=record_delete`)

레코드 삭제 전 `attachment_files`를 조회해 실제 파일도 `unlink()`로 정리.

**Error Responses:** 이 프로젝트 패턴상 JSON 에러 응답이 아닌 flash 메시지 + 리다이렉트를 사용 (기존 `history_add` 패턴 준수). 소유권 불일치/필수값 누락 시 조용히 무시(No-op) — 기존 `history_add`와 동일한 관용도.

---

## 5. UI/UX Design

### 5.1 화면 레이아웃

```
[직원 카드]
┌────────────────────────────────┐
│ 사진  이름 / 직급 / 입사일         │
│ [이력] [전입요청] [인사기록 3건] ← 신규 버튼(배지: 기록 수) │
└────────────────────────────────┘

[modal_record_add] (등록/수정 겸용)
┌────────────────────────────────┐
│ 유형: [경고장 ▾] (1차는 경고장 고정 노출) │
│ 사유/카테고리: [_______________]  │
│ 상세 내용: [___________________]  │
│ 점수(감점): [ -2 ] (숫자, 기본 0)  │
│ 첨부 이미지: [파일 선택 (여러 장)]  │
│ [취소] [저장]                     │
└────────────────────────────────┘

[modal_records_list]
┌────────────────────────────────┐
│ 2026-08-01 | 경고장 | 지각 | -2점 │
│  [썸네일][썸네일]        [수정][삭제] │
│ 2026-07-15 | 경고장 | 근태불량 | -3점│
│  [썸네일]                [수정][삭제] │
└────────────────────────────────┘
```

### 5.2 User Flow

```
직원 카드 "인사기록" 클릭 → modal_records_list 열림 (기존 기록 목록)
  → "새 기록 추가" 버튼 → modal_record_add 열림 → 저장 → 목록 갱신
  → 개별 기록의 "수정" → modal_record_add 값 채워 재오픈 → 저장
  → 개별 기록의 "삭제" → confirm() → record_delete
  → 썸네일 클릭 → 이미지 원본 새 탭/라이트박스로 확대
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|-----------------|
| `modal_record_add` | `employees.php` (기존 `modal_history_add` 옆에 추가) | 등록/수정 폼 |
| `modal_records_list` | `employees.php` | 직원별 기록 목록 + 삭제 트리거 |
| "인사기록" 버튼 + 배지 | `employees.php` 직원 카드 템플릿 (기존 "이력" 버튼 옆) | 진입점, 기록 수 표시 |

### 5.4 Page UI Checklist

#### 직원 카드 (employees.php)

- [ ] 버튼: "인사기록" (기존 "이력"/"전입요청" 버튼과 같은 줄), 배지에 기록 건수 표시
- [ ] 모달: 등록 폼 — 유형(select, 1차는 'warning' 고정 노출), 사유(text), 상세내용(textarea), 점수(number, 기본 0), 첨부이미지(file, multiple)
- [ ] 모달: 목록 — 날짜, 유형, 사유, 점수, 썸네일(여러 장), 수정/삭제 버튼
- [ ] 삭제 확인 다이얼로그
- [ ] 이미지 썸네일 클릭 시 원본 확대 보기

---

## 6. Error Handling

### 6.1 처리 방식

| 상황 | 처리 |
|------|------|
| `employee_id`가 현재 점포 소속이 아님 | 저장 무시 (기존 `history_add` 패턴) |
| 이미지 파일 검증 실패 (확장자/용량) | 해당 파일만 건너뛰고 나머지 저장 진행, 텍스트 기록은 정상 저장 |
| `points`가 숫자가 아님 | 0으로 강제 |
| `event_type`이 ENUM 값이 아님 | 'other'로 강제 (기존 `history_add`의 `event_type` 검증 패턴과 동일) |
| 삭제 시 첨부파일이 이미 디스크에 없음 | `file_exists()` 체크 후 조용히 skip (기존 `delete_employee_photo()` 패턴) |

---

## 7. Security Considerations

- [x] 기존 페이지 로그인/세션 체크 재사용 (`office/partials/header.php`)
- [x] Prepared Statement (mysqli `bind_param`) — 기존 `employees.php` 패턴 그대로 준수
- [x] 점포 소유권 확인 (`WHERE id=? AND store_id=?`) — 타 점포 기록 접근/수정/삭제 차단
- [x] 파일 업로드 검증: 화이트리스트 확장자, 5MB 제한, `move_uploaded_file()` 사용 (기존 `save_employee_photo()` 재사용)
- [x] XSS: 출력 시 `htmlspecialchars()` (기존 페이지 컨벤션)

---

## 8. Test Plan

자동화 테스트 인프라가 없는 프로젝트이므로 `php -l` 문법 검사 + 수동 시나리오 테스트로 검증한다.

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| 문법 검사 | 수정 PHP 파일 전체 | `php -l` | Do |
| 수동 기능 테스트 | 등록/조회/수정/삭제 전 시나리오 | 브라우저 수동 QA | Check |
| 회귀 테스트 | 기존 employee_history/transfer 기능 | 브라우저 수동 QA | Check |

### 8.2 수동 테스트 시나리오

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 인사기록 등록(이미지 1장) | 직원 카드 → 인사기록 → 새 기록 → 이미지 1장 + 사유 + 점수 입력 → 저장 | 목록에 반영, 배지 카운트 증가 |
| 2 | 인사기록 등록(이미지 여러 장) | 위와 동일하되 이미지 3장 선택 | 3장 모두 저장, 목록에서 3개 썸네일 표시 |
| 3 | 이미지 없이 텍스트만 등록 | 이미지 선택 안 하고 저장 | 정상 저장 (이미지 필수 아님) |
| 4 | 잘못된 파일 형식 업로드 | .txt 파일 첨부 시도 | 해당 파일 무시, 나머지 텍스트 기록은 저장 |
| 5 | 기록 수정 | 목록에서 수정 → 점수 변경 → 저장 | 변경된 점수 반영 |
| 6 | 기록 삭제 | 목록에서 삭제 → 확인 | 목록에서 사라짐, 첨부 이미지 파일도 서버에서 삭제 |
| 7 | 점포 스코프 확인 | 다른 점포 계정으로 로그인 | 해당 직원/기록이 보이지 않음 |
| 8 | 회귀: 기존 이력/전입 기능 | 이력 추가, 전입 요청 정상 동작 확인 | 기존과 동일하게 동작 |

### 8.3 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| `office_employees` | 1건 이상 | 기존 테스트 직원 |
| `office_employee_records` | 등록 후 자동 생성 | event_type, points, attachment_files |

---

## 9. Clean Architecture

이 프로젝트는 PHP 절차적 구조(office/ + lib/)를 사용한다.

### 9.4 This Feature's Layer Assignment

| Component | Layer(유사 개념) | Location |
|-----------|-------|----------|
| 신규 모달 HTML/POST 처리 | 컨트롤러/프레젠테이션 | `office/schedule/employees.php` |
| CRUD 헬퍼 4종 | 비즈니스 로직 | `office/lib/office_helper.php` |
| `office_employee_records` | 데이터 | MySQL |

---

## 10. Coding Convention Reference

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| PHP 함수 네이밍 | `add_employee_history()` 등과 동일한 스네이크케이스 패턴 |
| DB 접근 | mysqli + Prepared Statement (기존 `employees.php` 패턴, PDO 아님) |
| 파일 업로드 | 기존 `save_employee_photo()`/`delete_employee_photo()` 패턴 재사용 확장 |
| 주석 | `// Design Ref: employee-hr-records.design.md §{절}` 형식 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
office/sql/employee_records.sql   (신규, 참고용 DDL)
office/lib/office_helper.php      (수정 — CRUD 헬퍼 4종 추가)
office/schedule/employees.php     (수정 — 모달 2개 + POST 액션 3개 + 카드 버튼)
uploads/employees/records/        (신규 디렉토리, 런타임 생성)
```

### 11.2 Implementation Order

1. [ ] `office/sql/employee_records.sql` 작성 (참고용, 실제 생성은 코드의 자동 마이그레이션 블록)
2. [ ] `office_helper.php`에 자동 마이그레이션 블록(`CREATE TABLE IF NOT EXISTS`) + CRUD 헬퍼 4종 추가
3. [ ] `employees.php`: POST 액션 `record_add`/`record_edit`/`record_delete` 추가 (기존 `history_add` 옆)
4. [ ] `employees.php`: 이미지 저장 함수 (`save_employee_record_files`) — `save_employee_photo` 확장
5. [ ] `employees.php`: 직원 데이터 배열에 `'records' => get_employee_records_by_store($store_id)[$e['id']] ?? []` 임베드
6. [ ] `employees.php`: 직원 카드에 "인사기록" 버튼 + 배지 추가
7. [ ] `employees.php`: `modal_record_add`, `modal_records_list` HTML + JS (기존 모달 목록 배열에 ID 추가)
8. [ ] 수동 테스트 시나리오 1~8 실행

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB + 헬퍼 | `module-1` | 테이블 마이그레이션 + CRUD 헬퍼 4종 | 15-20 |
| POST 액션 + 파일업로드 | `module-2` | 등록/수정/삭제 액션, 이미지 저장 로직 | 15-20 |
| UI 모달 + 카드 버튼 | `module-3` | 모달 2개 HTML/JS, 카드 진입점 | 20-25 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan Plus + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1,module-2` | 30-40 |
| Session 3 | Do | `--scope module-3` | 20-25 |
| Session 4 | Check + Report | 전체 | 20-30 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-06 | Initial draft (Option A selected) | whdans007 |
