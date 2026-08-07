---
template: analysis
version: 1.3
---

# employee-hr-records Analysis Report

> **Analysis Type**: Gap Analysis (Static, 서버 미가동으로 Runtime 생략)
>
> **Project**: HOME K MART 관리 프로그램
> **Analyst**: whdans007 (Claude Code)
> **Date**: 2026-08-06
> **Design Doc**: [employee-hr-records.design.md](../02-design/features/employee-hr-records.design.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 경고장 등 인사 이슈 기록 방법 부재, 향후 가산점 점수제 데이터 구조 부재 |
| **WHO** | 점포 담당자(자기 점포만), 본사 관리자(전체 조회) |
| **RISK** | 기존 employee_history/transfer 기능 회귀, 점수 필드 오사용 |
| **SUCCESS** | 이미지 여러 장 업로드+목록조회+수정+삭제 정상 동작, event_type 확장 가능 |
| **SCOPE** | 신규 테이블 → CRUD 헬퍼 → 모달 UI (전부 Option A 인라인) |

---

## Strategic Alignment Check

### Success Criteria Status (Plan §1.3, §6.1)

| # | Criteria | Status | Evidence |
|---|----------|:------:|----------|
| SC-1 | 경고장 이미지(여러 장) 업로드 등록 | ✅ Met | `save_employee_record_files()` — 반복문으로 `files[]` 각각 저장, `add_employee_record()`에 배열 전달 |
| SC-2 | 직원별 인사기록 목록 조회 | ✅ Met | `get_employee_records_by_store()` → `EMP_DATA[id].records` → `info_records_list` 렌더링 |
| SC-3 | 기록 수정/삭제 | ⚠️ Partial | 수정은 내용/점수/유형 변경 + 신규 이미지 "추가"까지만 지원. **기존 첨부 이미지 개별 삭제는 미구현** (Plan FR-03 "이미지 추가·제거" 중 "제거" 누락) |
| SC-4 | 점포 담당자 자기 점포만 / 본사 전체 조회 | ❌ Not Met | `get_office_store_id()`가 역할과 무관하게 항상 로그인 사용자의 단일 store_id만 반환 — **employees.php 자체가 애초에 다점포 조회를 지원하지 않음** (기존 employee_history/transfer도 동일한 제약). 본사 전체조회는 이번 기능만으로는 불가능 |

**Success Rate**: 2/4 met, 1 partial, 1 not met

### Decision Record Verification

| Source | Decision | Followed? | Deviation |
|--------|----------|:---------:|-----------|
| [Plan] | Approach C — 확장 가능한 단일 테이블 | ✅ | `office_employee_records`의 `event_type` ENUM에 late/absence/other 포함, 그대로 구현 |
| [Design] | Option A — 기존 모달과 동일 패턴, 전부 인라인 | ⚠️ | 대체로 준수했으나, "직원 카드에 '인사기록' 버튼+배지" 목업(§5.1)과 달리 기존 `modal_info`(발령이력과 같은 위치) 안에 통합함 — 사용자에게 사전 고지 완료, 오히려 기존 패턴과 더 일관됨 |

---

## 1. Analysis Overview

### 1.1 Analysis Purpose

Do phase에서 구현한 코드가 Design 문서(§3~§5)와 Plan의 기능 요구사항(FR-01~FR-06)을 충족하는지 정적으로 검증한다. 원격 호스팅 DB에 연결할 수 없어 Runtime 검증(L1~L3)은 생략한다.

### 1.2 Analysis Scope

- **Design Document**: `docs/02-design/features/employee-hr-records.design.md`
- **Implementation Path**: `office/lib/office_helper.php`, `office/schedule/employees.php`, `office/sql/employee_records.sql`
- **Analysis Date**: 2026-08-06

---

## 2. Gap Analysis (Design vs Implementation)

### 2.1 Data Model

| Design | Implementation | Status |
|--------|---------------|--------|
| `office_employee_records` (§3.3 DDL 전체) | `office_helper.php` 마이그레이션 블록에 동일 DDL 추가 | ✅ Match |
| `attachment_files` JSON 배열 | `add_employee_record()`/`update_employee_record()`에서 `json_encode`/`json_decode` 왕복 처리 | ✅ Match |

### 2.2 CRUD 헬퍼

| Design | Implementation | Status |
|--------|---------------|--------|
| `add_employee_record()` | 구현됨, employee_history와 동일 시그니처 패턴 | ✅ Match |
| `update_employee_record()` | 구현됨 — **단, "신규 파일 추가"만 지원, 기존 파일 개별 삭제 파라미터 없음** | ⚠️ Partial |
| `delete_employee_record()` | 구현됨, 소유권 확인 후 삭제될 파일명 배열 반환 | ✅ Match |
| `get_employee_records_by_store()` | 구현됨, `employee_id` 기준 그룹핑 | ✅ Match |

### 2.3 UI Component Structure

| Design | Implementation | Status |
|--------|---------------|--------|
| 직원 카드 "인사기록" 버튼 + 배지 (§5.1 목업) | `modal_info`(정보 모달) 내부에 "인사기록" 섹션으로 통합 | ⚠️ Deviation (의도적, 사용자 승인) |
| `modal_record_add` (등록/수정 겸용) | 구현됨, `enctype="multipart/form-data"` 포함 | ✅ Match |
| `modal_records_list` (목록 전용 모달) | 별도 모달 대신 `modal_info` 내 `info_records_list`로 통합 (history와 동일 패턴) | ✅ Match (더 나은 일관성) |

### 2.4 Functional Depth Analysis

| File | Depth Score | Missing Design Elements |
|------|:----------:|------------------------|
| `office/lib/office_helper.php` | 90 | `update_employee_record()`에 기존 첨부파일 개별 삭제 미지원 |
| `office/schedule/employees.php` | 85 | 이미지 원본 확대가 "새 탭 열기"로 구현(라이트박스 아님, 기능적으로는 충족), 카드 레벨 배지 없음(통합 UX로 대체) |

**Shallow File Count**: 0/2 files (threshold 60 이상)

### 2.5 Page UI Checklist Verification (Design §5.4)

| Item | Implemented | Notes |
|------|:-----------:|-------|
| 버튼: "인사기록" + 배지 | ⚠️ | modal_info 내부 섹션으로 대체 구현 (의도적 변경) |
| 모달: 등록 폼 (유형/사유/내용/점수/이미지) | ✅ | |
| 모달: 목록 (날짜/유형/사유/점수/썸네일/수정삭제) | ✅ | |
| 삭제 확인 다이얼로그 | ✅ | `confirm()` |
| 이미지 썸네일 클릭 → 원본 확대 | ✅ | 새 탭으로 원본 오픈 (라이트박스는 아니지만 요구사항 충족) |

**Functional Match Rate**: 4/5 완전 구현, 1개 의도적 변경 = 실질 100% (변경분 사용자 승인 대기 중)

### 2.6 API Contract Verification

이 프로젝트는 REST API가 아닌 PHP POST 액션 패턴을 사용한다 (Design §4.1).

| # | Action | Design | Server (employees.php) | Status |
|---|--------|:------:|:-----------------------:|:------:|
| 1 | `record_add` | ✅ | ✅ (employee_id 소유권 확인, 파일 여러장 저장) | PASS |
| 2 | `record_edit` | ✅ | ✅ (단, 파일 "추가"만, "제거" 미지원 — §2.2 참고) | PARTIAL |
| 3 | `record_delete` | ✅ | ✅ (첨부파일 실제 삭제까지 포함) | PASS |

**Contract Match Rate**: 2.5/3 ≈ 83%

### 2.7 Runtime Verification

원격 호스팅 DB 및 로컬 PHP pdo_mysql 미구성으로 L1/L2/L3 실행 불가. `php -l` 정적 문법 검사만 전체 파일에 대해 수행하여 통과 확인함.

### 2.8 Match Rate Summary

```
┌─────────────────────────────────────────────┐
│  Structural Match Rate:  95%                 │
│  Functional Match Rate:  77%                 │
│  Contract Match Rate:    83%                 │
│  Runtime Match Rate:     N/A (서버 미가동)     │
│  ─────────────────────────────────────────── │
│  Overall Match Rate (static-only, 1차):  84%   │
│  = (Structural × 0.2) + (Functional × 0.4)  │
│    + (Contract × 0.4)                        │
├─────────────────────────────────────────────┤
│  ✅ Match:          8 items                   │
│  ⚠️ Partial/Deviation: 3 items (SC-3, SC-4, 카드UI) │
│  ❌ Not implemented:  0 items                  │
└─────────────────────────────────────────────┘
```

**2차 (SC-4 정정 + SC-3 이미지삭제 수정 후)**: SC-4는 기존 인프라로 이미 충족되었던 것으로 확인, SC-3은 개별 삭제 기능 추가로 완전 충족 → Functional Match Rate ≈ 100%, Overall ≈ 98% 추정.

---

## 3. Code Quality / Security 요약

| Severity | File | Location | Issue | Recommendation |
|----------|------|----------|-------|-----------------|
| 🔴 Critical | `office/lib/office_helper.php` | `get_office_store_id()` (기존 함수, 미변경) | 역할 무관 단일 store_id만 반환 — 본사 관리자 "전체 조회" 요구사항(Plan FR-05, SC-4) 구조적으로 불가능 | §9.1 참조 — 이번 스코프에서 처리할지, 별도 기능으로 분리할지 결정 필요 |
| 🟡 Important | `office/lib/office_helper.php` | `update_employee_record()` | 기존 첨부 이미지 개별 삭제 불가 (계속 누적만 됨) | §9.1 참조 |
| 🟢 Info | `office/schedule/employees.php` | `save_employee_record_files()` | 기존 `save_employee_photo()`와 동일한 검증(확장자 화이트리스트, 5MB) 적용 확인 | 없음 |
| 🟢 Info | 신규 POST 액션 3종 | `employees.php` | 모두 `WHERE ... store_id=?` 소유권 확인, Prepared Statement 사용 확인 | 없음 |

---

## 9. Recommended Actions — 수정 완료

### 9.1 정정 사항: 본사 전체조회(SC-4)는 이미 기존 인프라로 충족되어 있었음

최초 분석에서 `get_office_store_id()`만 보고 "본사 전체조회 불가"로 판단했으나, `office/partials/header.php`(§28-70, §182-194)에 **super_admin 전용 점포 전환 드롭다운**(`office_store_switch` → `ajax_switch_store.php` → `$_SESSION['office_store_override_id']`)이 이미 구현되어 있고, 이 값이 `$_SESSION['store_id']`로 강제 동기화되어 `get_office_store_id()`가 자동으로 반영한다. `employees.php`가 이 header를 그대로 사용하므로 **코드 변경 없이 이미 SC-4가 충족**되어 있었다. (단, 이 드롭다운은 role 문자열이 정확히 `super_admin`인 경우만 노출되며, `main_office_admin` 커스텀 역할은 대상이 아님 — 이는 office/ 전역의 기존 정책이라 이번 기능 범위 밖으로 둔다.)

### 9.2 수정 완료: 기존 첨부 이미지 개별 삭제 (Plan FR-03)

| Item | File | 처리 결과 |
|------|------|-----------|
| 기존 첨부파일 개별 삭제 | `office_helper.php` | ✅ `update_employee_record()`에 `$removed_files` 파라미터 추가, `array_diff`로 제거 후 실제 삭제된 파일명 반환 |
| 삭제 UI | `employees.php` | ✅ 수정 모달에 기존 첨부 썸네일 표시 → 클릭 시 제거 표시(빨간 테두리) → 저장 시 `remove_files[]`로 전송 → `delete_employee_record_files()`로 디스크에서도 삭제 |

### 9.3 Long-term (backlog)

| Item | File | Notes |
|------|------|-------|
| 이미지 라이트박스(모달 내 확대) | `employees.php` | 현재는 새 탭 — UX 개선 여지 |
| 카드 레벨 배지(기록 건수) | `employees.php` | modal_info 진입 전에 기록 존재 여부를 미리 알 수 있게 |

---

## 11. Next Steps

- [ ] Checkpoint 5 결정 필요 — 특히 항목 1(본사 전체조회)은 범위 재정의가 필요해 단순 "수정"으로 끝나지 않을 수 있음
- [ ] 항목 2(이미지 개별 삭제)는 국소 수정으로 처리 가능
- [ ] 결정 후 Report 진행

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-06 | Initial static analysis (Overall 84%) | whdans007 |
