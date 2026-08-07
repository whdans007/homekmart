---
template: plan-plus
version: 1.0
---

# employee-hr-records Planning Document

> **Summary**: 직원 인사기록(경고장 등) 등록·조회·수정·삭제 기능을 신설하여, `office/schedule/employees.php`를 향후 가산점 점수제 기반 인사평가까지 확장 가능한 인사관리 프로그램의 1단계로 전환한다.
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-08-05
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 직원에게 경고장 등 인사 이슈가 발생해도 이를 기록·보관·조회할 방법이 없고, 향후 도입할 가산점 점수제(지각/결근/경고 감점)를 담을 데이터 구조도 없다 |
| **Solution** | 신규 테이블 `office_employee_records`로 경고장(사진 여러 장 첨부, 사유, 점수)을 등록·조회·수정·삭제할 수 있게 하고, 이벤트 타입을 확장 가능하게 설계해 향후 지각/결근 점수제도 같은 구조로 얹을 수 있게 한다 |
| **Function/UX Effect** | 기존 `employee_history`(입사/전입 이력) 모달과 동일한 사용 패턴으로, 직원 카드에서 바로 인사기록을 남기고 사진과 함께 조회할 수 있다 |
| **Core Value** | 인사 이슈의 증빙(사진)과 감점 데이터가 한 곳에 축적되어, 향후 인사평가 자동 집계의 기반이 된다 |

---

## 1. User Intent Discovery

### 1.1 Core Problem

`office/schedule/employees.php`는 현재 직원 기본정보(이름/직급/입사일/사진)와 입사·전입 이력만 관리한다. 문제 발생 시(경고장 등) 이를 기록할 방법이 없어 종이/구두로만 관리되고 있으며, 향후 도입 예정인 가산점 점수제(지각·결근·경고 감점 기반 인사평가)를 위한 데이터 구조도 전혀 없다. 이번 기능은 전체 인사관리 프로그램 전환의 1단계로, 경고장 등 인사기록을 시스템에 남기고 향후 점수제로 자연스럽게 확장 가능한 기반을 만든다.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 점포 담당자 (office_staff 등) | 자기 점포 직원의 인사기록 등록/조회 | 문제 발생 시 즉시 기록, 증빙 사진 보관 |
| 본사 관리자 (super_admin/main_office_admin) | 전 점포 직원 인사기록 조회 | 점포별 인사 이슈 파악, 향후 평가 데이터 취합 |

### 1.3 Success Criteria

- [ ] 경고장 이미지(여러 장)를 업로드하여 직원별로 등록할 수 있다
- [ ] 직원별 인사기록 목록을 조회할 수 있다 (사유, 점수, 첨부 이미지 포함)
- [ ] 등록한 기록을 수정·삭제할 수 있다
- [ ] 점포 담당자는 자기 점포만, 본사 관리자는 전체 조회 가능하다

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 스캔 방식 | 물리적 스캐너 연동 없이 휴대폰/PC 사진 촬영 또는 파일 업로드로 처리 (기존 직원사진 업로드와 동일 패턴) | Low — 신규 하드웨어 연동 불필요 |
| 기존 이력 테이블과의 분리 | `office_employee_history`(입사/전입 로그)는 건드리지 않고 별도 테이블로 분리 | Low — 회귀 위험 최소화 |
| 점수제 UI는 이번에 함께 노출 | 원래는 "추후" 계획이었으나 YAGNI 검토 결과 1차에 점수 입력 UI도 포함하기로 결정 | Medium — 범위가 다소 확대됨, §3 참고 |

---

## 2. Alternatives Explored

### 2.1 Approach A: 기존 이력 테이블 확장

| Aspect | Details |
|--------|---------|
| **Summary** | `office_employee_history`에 `attachment_files`, `points` 컬럼과 `'warning'` event_type 추가 |
| **Pros** | 신규 테이블 없음, 기존 이력 조회 UI 재사용으로 가장 빠름 |
| **Cons** | 인사행정 로그(입사/전입/승진)와 징계·평가 데이터가 한 테이블에 섞여 향후 "직원별 감점 합계" 등 집계 쿼리가 복잡해짐 |
| **Effort** | Low |
| **Best For** | 딱 한 번성 기능이거나 향후 확장 계획이 없을 때 |

### 2.2 Approach C: 실용적 절충안 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `office_employee_history`는 그대로 두고, 신규 테이블 `office_employee_records` 하나를 만들어 경고장뿐 아니라 향후 지각/결근까지 같은 구조(`event_type`, `points`, `attachment_files`)로 담을 수 있게 설계 |
| **Pros** | 나중에 지각/결근 점수제 추가 시 테이블 재설계 불필요, 지금은 경고장 기능만 구현하면 됨(YAGNI 준수), 기존 이력 로그와 목적이 섞이지 않음 |
| **Cons** | 신규 테이블 1개 + 헬퍼 함수 신규 작성 필요 (Approach A보다 약간 더 작업량 있음) |
| **Effort** | Medium |
| **Best For** | 지금 당장은 좁은 기능이지만 명시적으로 확장 계획이 있는 경우 (이번 건 정확히 해당) |

> Approach B(완전 분리 — 경고장 전용 테이블 + 별도 점수제 규칙엔진 테이블)는 점수제 자체가 아직 "추후" 단계라 과잉설계로 판단해 제외.

### 2.3 Decision Rationale

**Selected**: Approach C
**Reason**: 사용자가 향후 지각/결근 감점을 명시적으로 계획하고 있어, 지금 좁게 만들면 나중에 반드시 재설계가 필요하다. `office_employee_records`를 이벤트 타입 확장 가능한 구조로 미리 설계하면 재작업 없이 확장할 수 있다.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] 경고장 이미지 업로드 (여러 장) + 직원별 기록 목록 조회
- [ ] 사유/카테고리 텍스트 입력
- [ ] 기록 수정/삭제 기능
- [ ] 점수(points) 입력 UI (등록 화면에 지금부터 노출)

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 지각/결근 이벤트 타입 실제 입력 UI | 이번엔 "경고장"만 실사용, 데이터 구조만 확장 가능하게 준비 | 점수제(인사평가) 도입 시점 |
| 직원별 감점 합계/인사평가 대시보드 | 평가 로직·기준(가중치, 기간 등)이 아직 미정 | 점수제 도입 후 평가 요구사항 확정 시 |
| 평가 규칙 엔진(카테고리별 기본 점수 자동 매핑 등) | 지금은 점수를 수기 입력하는 것으로 충분 | 규칙이 반복적으로 쌓여 자동화 필요성이 생길 때 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 물리적 문서 스캐너 하드웨어 연동 | 사용자 확인 결과 휴대폰/PC 업로드로 충분, 하드웨어 연동은 범위 밖 |

---

## 4. Scope

### 4.1 In Scope

- [ ] `office_employee_records` 테이블 신설 (event_type, category, content, points, attachment_files, employee_id, store_id, created_by, created_at, updated_at)
- [ ] `office/lib/office_helper.php`에 CRUD 헬퍼 4종 추가 (`add_employee_record`, `update_employee_record`, `delete_employee_record`, `get_employee_records_by_store`)
- [ ] `office/schedule/employees.php`에 인사기록 등록/목록/수정/삭제 모달 + POST 액션(`record_add`/`record_edit`/`record_delete`) 추가
- [ ] 이미지 여러 장 업로드 처리 (`uploads/employees/records/`), 삭제 시 첨부 파일도 함께 정리
- [ ] 점포 스코프 권한 (기존 페이지 권한 구조 재사용)

### 4.2 Out of Scope

- 지각/결근 실제 입력 UI 및 자동 점수 계산 — (YAGNI Review §3.2)
- 인사평가 집계/대시보드 — (YAGNI Review §3.2)
- 평가 규칙 엔진 — (YAGNI Review §3.2)
- 물리적 스캐너 하드웨어 연동 — (YAGNI Review §3.3)

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 직원 카드에서 인사기록(경고장) 등록 모달을 열어 사유/카테고리, 점수, 이미지 여러 장을 입력해 저장할 수 있다 | High | Pending |
| FR-02 | 직원별 인사기록 목록을 조회할 수 있으며, 각 기록의 이미지를 클릭하면 원본을 확대해 볼 수 있다 | High | Pending |
| FR-03 | 등록한 기록을 수정(내용/점수/이미지 추가·제거)할 수 있다 | Medium | Pending |
| FR-04 | 등록한 기록을 삭제할 수 있으며, 삭제 시 첨부된 이미지 파일도 함께 삭제된다 | Medium | Pending |
| FR-05 | 점포 담당자는 자기 점포 직원의 기록만, 본사 관리자는 전체 점포 기록을 조회할 수 있다 | High | Pending |
| FR-06 | `event_type`은 ENUM으로 향후 'late'/'absence' 등을 추가할 수 있도록 확장 가능하게 설계한다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| 보안 | 이미지 업로드 확장자/용량 제한 (기존 `save_employee_photo()`와 동일 기준: jpg/png/gif/webp, 5MB) | 코드 리뷰 |
| 데이터 정합성 | 직원 삭제 시 인사기록도 함께 정리 (FK CASCADE) | DDL 검토 |
| 권한 | 타 점포 직원 기록에 접근 불가 (기존 `WHERE store_id=?` 패턴 준수) | 코드 리뷰 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] FR-01~FR-06 구현 완료
- [ ] `php -l` 문법 검사 통과
- [ ] 기존 employee_history/transfer 기능 회귀 없음 (수동 확인)

### 6.2 Quality Criteria

- [ ] 이미지 업로드 실패 시에도 텍스트 기록은 저장됨 (부분 실패 허용, 기존 `add_employee_history` 패턴처럼 단순 실패 처리)
- [ ] SQL 인젝션/XSS 방지 (Prepared Statement, htmlspecialchars)

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 기존 `employees.php`가 이미 매우 큰 파일이라 신규 모달 추가 시 충돌 가능 | Medium | Medium | Design phase에서 정확한 삽입 위치와 기존 모달 ID 목록(`['modal_add',...]`) 확인 후 추가 |
| 이미지 여러 장 저장을 JSON 배열로 하면 향후 파일별 개별 관리(순서 변경 등)가 어려움 | Low | Low | 지금 요구사항(단순 첨부/조회)에는 충분 — 필요 시 나중에 별도 테이블로 마이그레이션 |
| 점수 필드가 아직 평가 로직 없이 노출되어 담당자가 임의로 입력할 수 있음 | Low | Medium | Design에서 점수 입력에 min/max 가이드(예: -10~0)만 두고, 실제 평가 자동화는 Out of Scope임을 UI에 안내 |

---

## 8. Architecture Considerations

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 데이터 구조 | A) 기존 history 확장 / C) 신규 확장가능 테이블 | C | 향후 점수제 확장을 고려한 실용적 절충 (§2.3) |
| 다중 이미지 저장 | JSON 배열 컬럼 / 별도 자식 테이블 | JSON 배열 (`attachment_files`) | 기존 코드베이스에 이미 JSON 컬럼 패턴 존재(`wholesale_products.wholesale_skus`), 지금 요구사항엔 자식 테이블까지 불필요 |
| UI 패턴 | 신규 페이지 / 기존 페이지 내 모달 | 기존 페이지 내 모달 | `employee_history`와 동일 패턴으로 일관성 유지, 사용자 학습 비용 없음 |

### 8.3 Component Overview

```
office/schedule/employees.php
  ├─ modal_record_add   (등록/수정 겸용)
  ├─ modal_records_list (직원별 목록 + 삭제)
  └─ POST action: record_add / record_edit / record_delete
          │
          ▼
office/lib/office_helper.php
  ├─ add_employee_record()
  ├─ update_employee_record()
  ├─ delete_employee_record()
  └─ get_employee_records_by_store()
          │
          ▼
MySQL: office_employee_records (신규)
uploads/employees/records/ (신규 이미지 저장 경로)
```

### 8.4 Data Flow

```
[직원 카드 "인사기록" 버튼] → modal_record_add 열림 (사유/점수/이미지 여러 장)
  → POST action=record_add (multipart/form-data)
  → 이미지 저장 → 파일명 배열 확보 → add_employee_record() DB 저장
  → employees.php 새로고침 → 직원 카드에 기록 개수 배지 표시

[직원 카드 "기록 목록" 버튼] → modal_records_list 열림
  → get_employee_records_by_store()로 미리 로드된 데이터 표시 (history 패턴과 동일)
  → 이미지 썸네일 클릭 시 원본 확대
  → 수정 → modal_record_add 재사용(값 채움) → record_edit
  → 삭제 → 확인 후 record_delete (첨부 이미지 파일도 함께 삭제)
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] 기존 `office/lib/office_helper.php` 함수 네이밍/구조 패턴 확인 (`add_employee_history` 등과 동일 스타일 사용)
- [x] 기존 `office_employee_history`/`office_employee_transfers` DDL 스타일 확인 (`office/sql/employee_history_transfer.sql`)
- [x] 파일 업로드 컨벤션 확인 (`save_employee_photo()` — 확장자/용량 검증, `uploads/` 하위 저장)

---

## 10. Next Steps

1. [ ] Write design document (`/pdca design employee-hr-records`)
2. [ ] Team review and approval
3. [ ] Start implementation (`/pdca do employee-hr-records`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent Q1 | 핵심 목적 | 전체 인사관리 프로그램으로의 전면 전환 | 확장 가능한 데이터 구조로 설계 (Approach C 선택의 근거) |
| Intent Q2 | 주요 사용자 | 점포는 자기 점포만, 본사는 전체 조회 | 기존 employees.php 권한 구조 재사용 |
| Intent Q3 | 성공 기준 | 이미지 업로드 + 목록 조회면 1차 충분 | Success Criteria §1.3에 반영 (단, YAGNI에서 범위 확대됨) |
| Intent Q4 | 스캔 방식 | 휴대폰/PC 사진 촬영 또는 파일 업로드 | 물리적 스캐너 연동 Out of Scope 확정 |
| Alternatives | 데이터 구조 A vs B vs C | Approach C 선택 | 향후 점수제 확장 대비 + 과잉설계 방지 절충 |
| YAGNI | 사유입력/수정삭제/다중이미지/점수UI | 4개 모두 포함 | 원래 Q3 성공기준보다 범위가 넓어짐 — Constraints §1.4에 명시 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-05 | Initial draft (Plan Plus) | whdans007 |
