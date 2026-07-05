---
template: plan-plus
version: 1.0
description: Brainstorming-enhanced PDCA Plan template with User Intent, Alternatives, and YAGNI sections
---

# role-permission-management Planning Document

> **Summary**: 회원 등급(역할)을 9단계로 재정의하고, 슈퍼어드민이 역할을 동적으로 추가하고 역할별 기능 권한을 매트릭스 UI로 부여/회수할 수 있는 시스템 구축
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-06-12
> **Status**: Draft
> **Method**: Plan Plus (Brainstorming-Enhanced PDCA)

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 현재 회원 등급은 5단계(super_admin/admin/staff/office_staff/user)로 고정되어 있고, 권한 추가/변경 시 코드 수정과 배포가 필요해 조직 구조(슈퍼바이져/매니져/점장/사장 등) 변화에 유연하게 대응할 수 없음 |
| **Solution** | `roles`/`role_permissions` DB 테이블을 신설해 역할을 9단계로 확장(추후 추가 가능)하고, 슈퍼어드민이 화면에서 역할 추가 및 역할별 기능 권한을 체크박스 매트릭스로 직접 관리하도록 함. 기존 5역할의 현재 권한은 그대로 DB로 이전되어 동작에 변화 없음 |
| **Function/UX Effect** | 회원관리 화면의 역할 선택 목록이 9개로 확장되고, 슈퍼어드민 메뉴에 "역할 관리"와 "권한 매트릭스" 화면이 추가됨. 기존 사용자의 권한/동작은 마이그레이션 전후 동일 |
| **Core Value** | 코드 배포 없이 조직 직급 구조 변경과 권한 조정이 가능해져, 매장 운영 조직(슈퍼바이져~사장)의 권한 체계를 빠르게 반영할 수 있음 |

---

## 1. User Intent Discovery

### 1.1 Core Problem

회원 등급(role)이 ENUM으로 고정된 5단계(super_admin, admin, staff, office_staff, user)뿐이라, "슈퍼바이져/매니져/점장(센터장)/사장"과 같은 조직 직급을 표현할 수 없고, 새 등급이 필요하거나 등급별 권한을 조정하려면 코드 수정과 배포가 필요함.

### 1.2 Target Users

| User Type | Usage Context | Key Need |
|-----------|---------------|----------|
| 슈퍼어드민 | 회원관리 > 역할 관리 / 권한 매트릭스 화면 | 새 역할 추가, 역할별 기능 권한 ON/OFF |
| 일반 관리자(시스템관리자 이하) | 회원 추가/수정 화면 | 9단계 역할 중 자신보다 낮은 등급을 사용자에게 부여 |
| 일반 직원/매니저 등 | 평소 업무 화면 | 자신의 역할에 부여된 권한에 따라 메뉴/기능 노출 |

### 1.3 Success Criteria

- [ ] 9개 역할(일반사용자/직원/오피스 스텝/슈퍼바이져/매니져/점장(센터장)/사장/시스템 관리자/슈퍼어드민)이 시스템에 등록되어 회원 추가/수정 시 선택 가능
- [ ] 기존 5개 역할(super_admin/admin/staff/office_staff/user)에 속한 기존 사용자의 권한/동작은 마이그레이션 전후 동일하게 유지
- [ ] 슈퍼어드민이 "역할 관리" 화면에서 새 역할을 추가/이름/레벨 수정/삭제(비시스템 역할만)할 수 있음
- [ ] 슈퍼어드민이 "권한 매트릭스" 화면에서 역할별로 각 기능 권한을 체크박스로 켜고 끌 수 있고, 변경 즉시 해당 역할 사용자에게 반영됨

### 1.4 Constraints

| Constraint | Details | Impact |
|------------|---------|--------|
| 기존 권한 유지 | 현재 5개 역할의 기존 권한 구성을 그대로 DB로 이전해야 함 (동작 변화 없어야 함) | High |
| 개인별 권한 override 유지 | `users.permissions` JSON 개인별 커스텀 권한 로직은 변경하지 않음 | Medium |
| ENUM → VARCHAR 변경 | `users.role` 컬럼 타입 변경이 필요하며 기존 값은 그대로 유지되어야 함 | Medium |
| super_admin 전권 보장 | super_admin은 매트릭스에서 항목 변경과 무관하게 항상 모든 권한 보유 (코드 레벨 안전장치 유지) | High |

---

## 2. Alternatives Explored

### 2.1 Approach A: DB 테이블 기반 동적 역할/권한 시스템 — Selected

| Aspect | Details |
|--------|---------|
| **Summary** | `roles` + `role_permissions` 테이블을 신설하고 `users.role`을 VARCHAR로 변경. 역할 기본권한 출처를 하드코딩 배열에서 DB로 전환 |
| **Pros** | 역할 동적 추가/삭제 가능, 권한 매트릭스 UI로 실시간 변경 가능, 기존 이중 권한 구조(역할 기본권한 + 개인별 override) 그대로 유지 |
| **Cons** | 스키마 변경 + 마이그레이션 스크립트 필요, 초기 시드 데이터 구성 작업 필요 |
| **Effort** | Medium |
| **Best For** | 조직 구조가 자주 바뀌고 슈퍼어드민이 직접 권한을 운영해야 하는 경우 |

### 2.2 Approach B: ENUM 확장 + PHP 코드 내 배열 확장

| Aspect | Details |
|--------|---------|
| **Summary** | `users.role` ENUM에 9개 값 추가, `get_default_permissions()` 배열에 4개 신규 역할 항목을 하드코딩으로 추가 |
| **Pros** | 작업량이 적고 스키마 변경이 최소화됨 |
| **Cons** | 역할 추가/권한 조정 시마다 코드 수정 + 배포 필요 → "슈퍼어드민이 역할/권한을 직접 관리" 요구사항을 충족할 수 없음 |
| **Effort** | Low |
| **Best For** | 역할/권한이 거의 변하지 않는 소규모 시스템 |

### 2.3 Decision Rationale

**Selected**: Approach A
**Reason**: 핵심 요구사항이 "슈퍼어드민이 역할을 추가하고 권한을 직접 부여/회수"하는 것이므로, 코드 배포 없이 운영 가능한 DB 기반 동적 구조가 필수적임. 기존 권한 로직(역할 기본권한 + 개인별 JSON override)과 자연스럽게 통합되어 리스크가 낮음.

---

## 3. YAGNI Review

### 3.1 Included (v1 Must-Have)

- [ ] `roles` 테이블 + 9개 기본 역할 시드 데이터 (role_key, label, level, is_system)
- [ ] `role_permissions` 테이블 + 기존 권한(19개 키) 매핑 시드 (기존 5역할은 현재값 그대로, 신규 4역할은 전부 OFF)
- [ ] `users.role` ENUM → VARCHAR(50) 타입 변경 (값은 그대로 유지)
- [ ] `permission_helper.php`: `get_default_permissions()`를 DB(`role_permissions`) 조회로 전환 (DB 오류 시 기존 하드코딩 배열로 폴백), `get_all_roles()`/`get_role_label()`/`update_role_permissions()`/`create_role()`/`delete_role()` 추가
- [ ] 슈퍼어드민용 "역할 관리" 화면 (`admin/role_management.php`) — 목록/추가/수정/삭제(`is_system=1`은 삭제 불가)
- [ ] 슈퍼어드민용 "권한 매트릭스" 화면 (`admin/role_permissions_matrix.php` + `admin/ajax_update_role_permission.php`) — 역할×권한 체크박스 그리드, super_admin 행은 항상 ON 고정 표시
- [ ] `admin/add_user.php`, `admin/edit_user.php`의 역할 선택을 `get_all_roles()` 기반 동적 목록으로 전환, 현재 사용자보다 낮은 level의 역할만 부여 가능
- [ ] `permission_change_log` 테이블 + 매트릭스 변경 시 이력 기록
- [ ] 신규 역할/화면 UI 문자열에 대한 `lang/en.json`, `lang/ko.json` 다국어 항목 추가

### 3.2 Deferred (v2+ Maybe)

| Feature | Reason for Deferral | Revisit When |
|---------|---------------------|--------------|
| 역할별 메뉴 커스터마이징(네비게이션 항목 자체를 역할별로 재배치) | 현재 권한 기반 메뉴 노출로 충분히 대응 가능 | 메뉴 구조가 권한 매트릭스만으로 표현 안 될 때 |
| 역할 계층 기반 자동 위임/승인 워크플로 | level은 부여 가능 역할 제한용으로만 사용, 승인 체계는 별도 기능 | 결재/승인 라인 요구가 생길 때 |

### 3.3 Removed (Won't Do)

| Feature | Reason for Removal |
|---------|-------------------|
| 역할별 매장(store) 범위 자동 제한 로직 변경 | 기존 store-scoped 로직은 role과 독립적으로 동작하므로 본 작업 범위에서 변경하지 않음 |

---

## 4. Scope

### 4.1 In Scope

- [ ] DB 스키마: `roles`, `role_permissions`, `permission_change_log` 테이블 신설 + `users.role` 타입 변경
- [ ] `lib/permission_helper.php` 함수 확장 (DB 기반 역할/권한 조회·수정)
- [ ] 슈퍼어드민용 역할 관리 / 권한 매트릭스 화면 2종
- [ ] `add_user.php`/`edit_user.php` 역할 선택 동적화
- [ ] 마이그레이션 SQL + PHP 실행 스크립트
- [ ] 권한 변경 이력 로그
- [ ] 신규 화면 한/영 다국어 문자열

### 4.2 Out of Scope

- 역할별 메뉴 구조 재배치 — (from YAGNI Review)
- 역할 계층 기반 승인 워크플로 — (from YAGNI Review)
- store-scoped 데이터 접근 로직 변경

---

## 5. Requirements

### 5.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | `roles` 테이블에 9개 역할(일반사용자/직원/오피스 스텝/슈퍼바이져/매니져/점장(센터장)/사장/시스템 관리자/슈퍼어드민)이 시드되어야 한다 | High | Pending |
| FR-02 | 기존 5역할 사용자의 권한 동작은 마이그레이션 전후 동일해야 한다 | High | Pending |
| FR-03 | `users.role`은 VARCHAR(50)으로 변경되며 기존 데이터 값은 보존되어야 한다 | High | Pending |
| FR-04 | 슈퍼어드민은 역할 관리 화면에서 새 역할(role_key, label, level)을 추가할 수 있어야 한다 | High | Pending |
| FR-05 | 슈퍼어드민은 `is_system=1`인 역할을 삭제할 수 없어야 한다 | High | Pending |
| FR-06 | 슈퍼어드민은 권한 매트릭스 화면에서 역할별 권한 항목을 체크박스로 켜고 끌 수 있어야 하며, 변경은 즉시 `has_permission()` 결과에 반영되어야 한다 | High | Pending |
| FR-07 | super_admin 역할의 권한은 매트릭스에서 항상 전체 ON으로 표시되고 변경할 수 없어야 한다 | High | Pending |
| FR-08 | `add_user.php`/`edit_user.php`의 역할 선택 옵션은 `roles` 테이블에서 동적으로 조회되며, 현재 로그인 사용자보다 `level`이 낮은 역할만 부여 가능해야 한다 | Medium | Pending |
| FR-09 | 권한 매트릭스 변경 시 누가/언제/어떤 역할의 어떤 권한을 변경했는지 `permission_change_log`에 기록되어야 한다 | Medium | Pending |

### 5.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Backward Compatibility | 마이그레이션 후 기존 사용자 로그인/메뉴 노출/기능 접근이 이전과 동일 | 마이그레이션 전후 주요 역할별 수동 점검 |
| Data Safety | 마이그레이션은 트랜잭션으로 처리되고 실패 시 롤백 | PHP 마이그레이션 스크립트 내 트랜잭션 적용 |
| Security | 역할 관리/권한 매트릭스 화면은 `settings` 권한(슈퍼어드민) 필요 | `require_permission('settings')` 적용 확인 |

---

## 6. Success Criteria

### 6.1 Definition of Done

- [ ] DB 마이그레이션 SQL + PHP 실행 스크립트 작성 및 실행 완료
- [ ] `permission_helper.php` 신규/수정 함수 구현 및 기존 호출부 정상 동작 확인
- [ ] 역할 관리 / 권한 매트릭스 화면 구현
- [ ] `add_user.php`/`edit_user.php` 역할 선택 동적화 반영
- [ ] 다국어 문자열 추가
- [ ] Gap 분석 90% 이상

### 6.2 Quality Criteria

- [ ] 기존 5역할 사용자 권한 동작 변화 없음 (회귀 없음)
- [ ] 신규 4역할은 권한 매트릭스에서 ON 처리 전까지 모든 관리 기능 접근 불가
- [ ] PHP 문법 오류 없음 (`php -l`)

---

## 7. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| ENUM→VARCHAR 변경 시 기존 데이터 손상 | High | Low | `ALTER TABLE ... MODIFY` 전 백업, 값 그대로 유지되는 타입 변경만 수행 |
| 역할 기본권한 DB 이전 시 누락된 권한 키 발생 | Medium | Medium | 기존 `get_default_permissions()` + `check_legacy_permission()`의 전체 키(19개) 합집합을 시드 대상으로 사용 |
| 권한 매트릭스 오조작으로 일반 사용자 화면 접근 불가 | Medium | Medium | super_admin은 매트릭스 변경 영향을 받지 않도록 코드에서 항상 우회 처리, 변경 이력 로그로 추적/되돌리기 가능 |

---

## 8. Architecture Considerations

### 8.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure (`components/`, `lib/`, `types/`) | Static sites, portfolios, landing pages | |
| **Dynamic** | Feature-based modules, BaaS integration (bkend.ai) | Web apps with backend, SaaS MVPs, fullstack apps | ✅ |
| **Enterprise** | Strict layer separation, DI, microservices | High-traffic systems, complex architectures | |

### 8.2 Key Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 역할 저장 방식 | ENUM 확장 vs DB 테이블 | DB 테이블(`roles`) | 슈퍼어드민의 동적 역할 추가 요구 충족 |
| 권한 기본값 출처 | 하드코딩 배열 vs DB 매트릭스 | DB(`role_permissions`), 폴백은 기존 배열 | 무중단 전환 + 운영 중 직접 조정 가능 |
| 신규 역할 초기 권한 | 기존 유사 역할 추정 vs 전부 OFF | 전부 OFF | 의도치 않은 권한 노출 방지, 슈퍼어드민이 명시적으로 부여 |
| 역할 간 우선순위 표현 | 계층 로직 vs 단순 level 숫자 | 단순 level 숫자 | YAGNI — 정렬/부여 가능 범위 판단에만 사용 |

### 8.3 Component Overview

```
lib/permission_helper.php
  ├─ has_permission()              (기존, 변경 없음 - 개인 override 우선 로직 유지)
  ├─ get_default_permissions($role) → role_permissions 테이블 조회 (DB 오류 시 기존 배열 폴백)
  ├─ get_all_roles()                (신규) → roles 테이블, level ASC
  ├─ get_role_label($role_key)      (신규)
  ├─ update_role_permissions(...)   (신규) → role_permissions upsert + permission_change_log insert
  ├─ create_role(...)               (신규)
  └─ delete_role($role_key)         (신규, is_system/사용중 역할 거부)

admin/
  ├─ role_management.php            (신규) - 역할 목록/추가/수정/삭제
  ├─ role_permissions_matrix.php    (신규) - 역할×권한 매트릭스
  ├─ ajax_update_role_permission.php(신규) - 매트릭스 셀 토글 AJAX
  ├─ add_user.php / edit_user.php   (수정) - 역할 선택 동적화
  └─ partials/header.php            (수정) - 신규 메뉴 노출(슈퍼어드민)

sql/
  └─ xxx_role_permission_system.sql (신규) - 테이블 생성 + ENUM→VARCHAR

migrations/
  └─ migrate_role_permissions.php   (신규) - 기존 권한값 → role_permissions 이전
```

### 8.4 Data Flow

```
[마이그레이션 시점]
get_default_permissions() 하드코딩 배열 (5역할 × 19권한)
        │
        ▼
migrate_role_permissions.php (트랜잭션)
        │
        ├─→ roles 테이블에 9개 역할 INSERT (level/label/is_system)
        └─→ role_permissions 테이블에 5역할×19권한 값 INSERT,
            신규 4역할×19권한은 enabled=0 INSERT
        │
        ▼
users.role ENUM → VARCHAR(50) ALTER (값 보존)

[운영 시점 - 권한 체크]
has_permission($perm)
  → 개인별 users.permissions JSON 존재 시 그 값 우선
  → 없으면 get_default_permissions($role)
       → role_permissions 테이블에서 (role_key, permission_key) 조회
       → DB 오류 시 기존 하드코딩 배열 폴백

[운영 시점 - 권한 매트릭스 변경]
슈퍼어드민이 매트릭스 체크박스 토글
  → ajax_update_role_permission.php
       → update_role_permissions(role_key, permission_key, enabled, admin_user_id)
            → role_permissions UPDATE
            → permission_change_log INSERT
  → 이후 해당 role의 모든 사용자에게 즉시 반영 (has_permission 호출 시 DB 재조회)
```

---

## 9. Convention Prerequisites

### 9.1 Applicable Conventions

- [x] Existing project conventions verified (PDO + 트랜잭션, `require_permission()` 패턴, 다국어 `t()` 함수)
- [x] Naming rules confirmed (role_key는 영문 snake_case, label은 한글)
- [x] Folder structure rules confirmed (`admin/`, `lib/`, `sql/` 기존 구조 사용)

---

## 10. Next Steps

1. [ ] Write design document (`/pdca design role-permission-management`)
2. [ ] Team review and approval
3. [ ] Start implementation (`/pdca do role-permission-management`)

---

## Appendix: Brainstorming Log

| Phase | Question | Answer | Decision |
|-------|----------|--------|----------|
| Intent | 기존 역할 매핑 방식 | 단순 매핑 (super_admin→슈퍼어드민, admin→시스템관리자, office_staff→오피스스텝, staff→직원, user→일반사용자) | 신규 4역할(슈퍼바이져/매니져/점장/사장)은 추가만 |
| Intent | 신규 역할 초기 권한 | 전부 비활성으로 시작 | 슈퍼어드민이 매트릭스에서 켜야 함 |
| Intent | 역할 계층 구조 필요성 | 단순 level 숫자만 부여 | 부여 가능 역할 제한 및 정렬용으로만 사용 |
| Alternatives | DB 테이블 기반 vs ENUM+코드 확장 | DB 테이블 기반 (Approach A) | 슈퍼어드민의 동적 역할/권한 관리 요구 충족 |
| YAGNI | 핵심 구현 항목(roles/role_permissions/마이그레이션/헬퍼/UI 2종/동적 역할선택) | 전부 필수로 포함 | v1 범위 확정 |
| YAGNI | 권한변경 이력로그 / 다국어 라벨 | 둘 다 포함 | 운영 추적성과 일관성을 위해 포함 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-12 | Initial draft (Plan Plus) | whdans007 |
