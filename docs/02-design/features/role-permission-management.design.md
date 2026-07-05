---
template: design
version: 1.3
description: PDCA Design phase document template with Context Anchor, Session Guide, and Clean Architecture support
---

# role-permission-management Design Document

> **Summary**: 회원 등급(역할)을 DB 테이블 기반 9단계 체계로 재정의하고, 슈퍼어드민이 역할을 추가하고 역할별 기능 권한을 매트릭스 UI로 부여/회수할 수 있도록 구현
>
> **Project**: HOME K MART 관리 프로그램
> **Author**: whdans007
> **Date**: 2026-06-12
> **Status**: Draft
> **Planning Doc**: [role-permission-management.plan.md](../../01-plan/features/role-permission-management.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | ENUM 고정 5단계 역할로는 "슈퍼바이져/매니져/점장/사장" 등 조직 직급을 표현할 수 없고, 등급/권한 변경마다 코드 배포가 필요함 |
| **WHO** | 슈퍼어드민(역할/권한 관리), 일반 관리자(자신보다 낮은 등급 부여), 전 직원(역할별 권한에 따른 메뉴/기능 접근) |
| **RISK** | ENUM→VARCHAR 타입 변경 시 기존 데이터 손상 가능성, 권한 매트릭스 오조작으로 접근 불가 발생 가능 |
| **SUCCESS** | 9개 역할 등록 및 선택 가능, 기존 5역할 사용자 동작 무변화, 슈퍼어드민의 역할 추가/권한 매트릭스 즉시 반영 |
| **SCOPE** | DB 스키마(roles/role_permissions/permission_change_log), permission_helper.php 확장, 역할관리/권한매트릭스 화면, add_user/edit_user 동적화, 마이그레이션 스크립트, 다국어 |

---

## 1. Overview

### 1.1 Design Goals

- `users.role`을 ENUM에서 VARCHAR로 전환하면서 기존 5개 역할 사용자의 동작(권한, 메뉴 노출)을 100% 보존
- 역할 정의(`roles`)와 역할별 기능 권한(`role_permissions`)을 DB 테이블로 분리하여, 슈퍼어드민이 코드 배포 없이 역할 추가/권한 조정 가능
- 기존 이중 권한 구조(역할 기본권한 + 사용자별 JSON override)를 유지한 채, "역할 기본권한"의 출처만 하드코딩 → DB로 전환

### 1.2 Design Principles

- **하위 호환 우선**: 기존 `has_permission()`, `get_user_permissions()` 호출부는 시그니처 변경 없이 동작
- **안전한 폴백**: DB 조회 실패 시 기존 하드코딩 배열로 자동 폴백 (기존 `permission_helper.php`의 try/catch 패턴 재사용)
- **시스템 역할 보호**: `is_system=1`(기존 5역할 + super_admin)은 삭제 불가, super_admin은 매트릭스에서 항상 전체 ON 고정

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | 통합 화면 1개 + 헬퍼 함수만 추가 | Repository/Service 계층 신설 | 화면 2개 분리 + permission_helper.php 함수 확장 |
| **New Files** | 3 | 8+ | 5 |
| **Modified Files** | 4 | 6+ | 4 |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Medium | High | High |
| **Effort** | Low | High | Medium |
| **Risk** | Low (화면 혼잡) | Low (기존 컨벤션과 이질적) | Low |
| **Recommendation** | 빠른 적용 | 장기 대규모 시스템 | **Default choice** |

**Selected**: Option C — **Rationale**: 현재 코드베이스는 절차형 PHP + `lib/*_helper.php` 함수형 구조이며 클래스/DI 계층이 없음. Option B는 이질적인 계층을 추가해 유지보수 부담을 키우고, Option A는 화면 책임이 섞여 향후 권한 항목 증가 시 확장이 어려움. Option C는 Plan에서 설계한 구조를 그대로 따르며 기존 `admin/*_management.php` + `lib/*_helper.php` 패턴과 일치.

> 아래 상세 설계는 Option C를 따른다.

### 2.1 Component Diagram

```
┌──────────────────┐     ┌────────────────────────┐     ┌──────────────────┐
│  admin/*.php      │────▶│ lib/permission_helper.php│────▶│  MySQL (PDO)      │
│  (역할관리/매트릭스/  │     │  has_permission()        │     │  roles            │
│   회원관리)         │     │  get_default_permissions()│     │  role_permissions │
│                   │◀────│  get_all_roles()          │◀────│  permission_change_log│
│  ajax_update_     │     │  update_role_permissions()│     │  users            │
│  role_permission  │     │  create_role/delete_role  │     │                   │
└──────────────────┘     └────────────────────────┘     └──────────────────┘
```

### 2.2 Data Flow

```
[마이그레이션 1회 실행]
get_default_permissions() 하드코딩 배열(5역할×19권한)
   → migrate_role_permissions.php (PDO 트랜잭션)
       → roles 테이블에 9역할 INSERT
       → role_permissions 테이블에 5역할×19권한 값 INSERT + 신규4역할×19권한 enabled=0 INSERT
   → users.role ALTER TYPE (ENUM→VARCHAR, 값 보존)

[권한 체크 - 매 요청]
has_permission($perm)
  → users.permissions JSON에 키 존재? → 그 값 사용
  → 없으면 get_default_permissions($role)
       → role_permissions WHERE role_key=? AND permission_key=? 조회
       → super_admin role_key면 무조건 true
       → DB 오류 시 기존 하드코딩 배열 폴백

[권한 매트릭스 변경]
슈퍼어드민이 체크박스 토글
  → ajax_update_role_permission.php (POST role_key, permission_key, enabled)
       → update_role_permissions() → role_permissions UPSERT + permission_change_log INSERT
  → 다음 요청부터 해당 role 사용자에게 즉시 반영
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `role_management.php` | `permission_helper.php` (get_all_roles, create_role, delete_role) | 역할 CRUD |
| `role_permissions_matrix.php` | `permission_helper.php` (get_all_roles, get_default_permissions, get_permission_label) | 매트릭스 렌더링 |
| `ajax_update_role_permission.php` | `permission_helper.php` (update_role_permissions) | 매트릭스 셀 저장 |
| `add_user.php` / `edit_user.php` | `permission_helper.php` (get_all_roles, get_role_label) | 역할 선택 동적화 |
| `migrate_role_permissions.php` | `config/db_config.php`, 기존 `get_default_permissions()` 소스(이전) | 1회 데이터 이전 |

---

## 3. Data Model

### 3.1 Entity Definition (PHP 연관배열 기준)

```php
// roles 테이블 1 row
[
  'id' => 1,
  'role_key' => 'manager',      // 영문 snake_case, UNIQUE
  'label' => '매니져',           // 한글 표시명
  'level' => 40,                // 정렬/부여가능 범위 판단용 숫자
  'is_system' => 0,             // 1=삭제불가(기존 5역할 + super_admin)
  'created_at' => '...',
  'updated_at' => '...'
]

// role_permissions 테이블 1 row
[
  'id' => 1,
  'role_key' => 'manager',
  'permission_key' => 'product_management',
  'enabled' => 0
]

// permission_change_log 테이블 1 row
[
  'id' => 1,
  'admin_user_id' => 3,
  'role_key' => 'manager',
  'permission_key' => 'product_management',
  'old_value' => 0,
  'new_value' => 1,
  'created_at' => '...'
]
```

### 3.2 Entity Relationships

```
[roles] 1 ──── N [role_permissions]   (role_key 기준)
[roles] 1 ──── N [users]              (role_key 기준, FK 제약 없음 - 기존 패턴 유지)
[roles] 1 ──── N [permission_change_log] (role_key 기준)
```

### 3.3 Database Schema

```sql
-- 1) 역할 정의 테이블
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(50) NOT NULL UNIQUE,
  label VARCHAR(50) NOT NULL,
  level INT NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_roles_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) 역할별 권한 매트릭스
CREATE TABLE IF NOT EXISTS role_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(50) NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_role_permission (role_key, permission_key),
  INDEX idx_role_permissions_role (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) 권한 변경 이력
CREATE TABLE IF NOT EXISTS permission_change_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT NOT NULL,
  role_key VARCHAR(50) NOT NULL,
  permission_key VARCHAR(50) NOT NULL,
  old_value TINYINT(1) NOT NULL,
  new_value TINYINT(1) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_change_log_role (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4) users.role 타입 변경 (값 보존)
ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL DEFAULT 'user';

-- 5) 9개 역할 시드
INSERT INTO roles (role_key, label, level, is_system) VALUES
  ('user', '일반사용자', 10, 1),
  ('staff', '직원', 20, 1),
  ('office_staff', '오피스 스텝', 25, 1),
  ('supervisor', '슈퍼바이져', 30, 0),
  ('manager', '매니져', 40, 0),
  ('branch_manager', '점장(센터장)', 50, 0),
  ('ceo', '사장', 60, 0),
  ('admin', '시스템 관리자', 90, 1),
  ('super_admin', '슈퍼어드민', 100, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);
```

> `role_permissions` 시드(기존 5역할의 19개 권한값 이전 + 신규 4역할 전체 enabled=0)는 `migrations/migrate_role_permissions.php`에서 PHP 배열을 그대로 INSERT (사용자 메모리 규칙: DB 변경 시 PHP 실행 스크립트 동반).

---

## 4. API Specification (관리자 화면 + AJAX 엔드포인트)

> 이 프로젝트는 REST API가 아닌 PHP 페이지/AJAX 엔드포인트 구조이므로, 표준 REST 형식 대신 실제 엔드포인트로 기술합니다.

### 4.1 Endpoint List

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET/POST | `/admin/role_management.php` | 역할 목록 조회 + 추가/수정/삭제 폼 처리 | `settings` 권한(super_admin) |
| GET | `/admin/role_permissions_matrix.php` | 역할×권한 매트릭스 화면 | `settings` 권한(super_admin) |
| POST | `/admin/ajax_update_role_permission.php` | 매트릭스 셀 1개 토글 저장 | `settings` 권한(super_admin) |
| GET/POST | `/admin/add_user.php` (수정) | 회원 추가, 역할 선택 동적화 | `user_management` 권한 |
| GET/POST | `/admin/edit_user.php` (수정) | 회원 수정, 역할 선택 동적화 | `user_management` 권한 |

### 4.2 Detailed Specification

#### `POST /admin/role_management.php` (역할 추가)

**Request (form-urlencoded):**
```
action=create
role_key=branch_supervisor
label=부점장
level=35
```

**처리 결과:**
- 성공: `$_SESSION['flash']`에 성공 메시지 설정 후 리다이렉트, `roles` 1행 INSERT + `role_permissions`에 19개 권한 enabled=0으로 INSERT
- `role_key` 중복 시: 에러 메시지 표시 (UNIQUE 제약)

#### `POST /admin/role_management.php` (역할 삭제)

**Request:**
```
action=delete
role_key=branch_supervisor
```

**검증 규칙:**
- `is_system=1`인 역할 → 삭제 거부, 에러 메시지
- `users` 테이블에 해당 `role_key`를 가진 사용자가 1명 이상 존재 → 삭제 거부, 에러 메시지
- 통과 시: `roles`, `role_permissions` 에서 해당 role_key 행 삭제 (트랜잭션)

#### `POST /admin/ajax_update_role_permission.php`

**Request (JSON 또는 form):**
```json
{
  "role_key": "manager",
  "permission_key": "product_management",
  "enabled": 1
}
```

**Response (200):**
```json
{ "success": true }
```

**Response (403 - super_admin 변경 시도):**
```json
{ "success": false, "error": "super_admin 권한은 변경할 수 없습니다." }
```

**Response (400 - 잘못된 permission_key):**
```json
{ "success": false, "error": "INVALID_PERMISSION_KEY" }
```

---

## 5. UI/UX Design

### 5.1 Screen Layout — 역할 관리 (`role_management.php`)

```
┌────────────────────────────────────────────────┐
│  헤더 (admin/partials/header.php)                │
├────────────────────────────────────────────────┤
│  [+ 새 역할 추가]  (role_key / 한글명 / level 입력)  │
├────────────────────────────────────────────────┤
│  역할 목록 (level 오름차순)                        │
│  ┌──────┬──────────┬───────┬─────────┬────────┐ │
│  │순서  │ 역할명     │ level │ 시스템   │ 작업    │ │
│  ├──────┼──────────┼───────┼─────────┼────────┤ │
│  │ 1    │ 일반사용자  │ 10    │ 🔒시스템 │ -      │ │
│  │ ...  │ ...      │ ...   │ ...     │ [수정][삭제]│
│  │ 9    │ 슈퍼어드민  │ 100   │ 🔒시스템 │ -      │ │
│  └──────┴──────────┴───────┴─────────┴────────┘ │
└────────────────────────────────────────────────┘
```

### 5.2 Screen Layout — 권한 매트릭스 (`role_permissions_matrix.php`)

```
┌──────────────────────────────────────────────────────────────────┐
│  역할 × 권한 매트릭스 (가로 스크롤)                                    │
│  ┌───────────┬─────────┬─────────┬─────────┬ ... ┬─────────────┐  │
│  │ 역할(label)│회원관리  │상품관리  │매입관리  │ ... │설정(환경설정) │  │
│  ├───────────┼─────────┼─────────┼─────────┼ ... ┼─────────────┤  │
│  │ 일반사용자  │  [ ]    │  [ ]    │  [ ]    │ ... │  [ ]        │  │
│  │ 직원       │  [ ]    │  [ ]    │  [ ]    │ ... │  [ ]        │  │
│  │ 슈퍼바이져  │  [✓]    │  [ ]    │  [ ]    │ ... │  [ ]        │  │
│  │ ...        │  ...    │  ...    │  ...    │ ... │  ...        │  │
│  │ 슈퍼어드민  │  [✓](고정)│ [✓](고정)│ [✓](고정)│ ... │ [✓](고정)   │  │
│  └───────────┴─────────┴─────────┴─────────┴ ... ┴─────────────┘  │
│  (체크박스 클릭 시 ajax_update_role_permission.php로 즉시 저장,        │
│   저장 성공 시 체크박스 옆에 토스트 "저장됨" 표시)                       │
└──────────────────────────────────────────────────────────────────┘
```

### 5.3 User Flow

```
슈퍼어드민 로그인 → 회원관리 메뉴 → [역할 관리] 클릭 → 역할 추가(예: "부점장", level 35)
   → [권한 매트릭스] 클릭 → "부점장" 행에서 product_management, purchase_management 체크
   → 회원관리 > 회원수정 → 특정 사용자의 역할을 "부점장"으로 변경
   → 해당 사용자 재로그인 시 product_management/purchase_management 메뉴 노출 확인
```

### 5.4 Page UI Checklist

#### 역할 관리 (`role_management.php`)

- [ ] 폼: 역할 추가 (입력 필드: role_key 영문, label 한글, level 숫자)
- [ ] 테이블: 역할 목록 (컬럼: 순서/level, label, role_key, is_system 배지, 작업버튼)
- [ ] 배지: 🔒 시스템 역할 표시 (is_system=1인 9개 중 5개 기존 + super_admin = 실제로 user/staff/office_staff/admin/super_admin 5개)
- [ ] 버튼: 수정 (label, level만 수정 가능, role_key 변경 불가)
- [ ] 버튼: 삭제 (is_system=1 또는 사용중인 역할은 비활성화 + 툴팁 "삭제 불가")
- [ ] 에러 메시지: role_key 중복 시 표시

#### 권한 매트릭스 (`role_permissions_matrix.php`)

- [ ] 테이블: 역할(행, level 오름차순) × 권한항목(열, 19개 + `get_permission_label()` 한글 라벨)
- [ ] 체크박스: 각 셀, AJAX로 즉시 저장
- [ ] super_admin 행: 모든 체크박스 checked + disabled
- [ ] 토스트/알림: 저장 성공/실패 표시
- [ ] 안내문구: "변경 사항은 즉시 해당 역할 사용자에게 적용됩니다"

#### 회원 추가/수정 (`add_user.php` / `edit_user.php`)

- [ ] 드롭다운: 역할 선택 — `get_all_roles()`에서 현재 사용자 `level`보다 낮은 역할만 옵션으로 표시 (super_admin은 전체)
- [ ] 라벨: 각 역할 옵션은 `roles.label`(한글명) 표시

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| VALIDATION_ERROR | role_key는 영문 소문자/숫자/언더스코어만 가능합니다 | 잘못된 role_key 형식 | 폼 재입력 요청 |
| DUPLICATE_ROLE_KEY | 이미 존재하는 역할 키입니다 | UNIQUE 제약 위반 | 폼 재입력 요청 |
| SYSTEM_ROLE_PROTECTED | 시스템 역할은 삭제할 수 없습니다 | is_system=1 역할 삭제 시도 | 삭제 버튼 비활성화 + 서버측 재검증 |
| ROLE_IN_USE | 해당 역할을 사용 중인 사용자가 있어 삭제할 수 없습니다 | users.role 참조 존재 | 사용자 목록 안내 |
| SUPER_ADMIN_LOCKED | super_admin 권한은 변경할 수 없습니다 | 매트릭스에서 super_admin 행 토글 시도 | AJAX 403 응답, 체크박스 원복 |
| INVALID_PERMISSION_KEY | 알 수 없는 권한 항목입니다 | permission_key가 정의된 19개 목록 외 값 | AJAX 400 응답 |

### 6.2 Error Response Format (AJAX)

```json
{
  "success": false,
  "error": {
    "code": "SUPER_ADMIN_LOCKED",
    "message": "super_admin 권한은 변경할 수 없습니다."
  }
}
```

---

## 7. Security Considerations

- [ ] `role_management.php`, `role_permissions_matrix.php`, `ajax_update_role_permission.php`는 `require_permission('settings')`로 보호 (현재 `settings`는 super_admin만 보유)
- [ ] `ajax_update_role_permission.php`에서 `role_key === 'super_admin'`이면 항상 거부 (서버측 하드코딩 검증, UI 비활성화와 별개로 이중 방어)
- [ ] `permission_key`는 코드에 정의된 19개 화이트리스트와 대조 후에만 처리 (임의 키 INSERT 방지)
- [ ] 모든 DB 접근은 PDO Prepared Statement 사용 (기존 컨벤션)
- [ ] `add_user.php`/`edit_user.php`에서 역할 부여 시 `level` 비교로, 자신보다 높거나 같은 level의 역할 부여 시도 시 서버측에서 거부 (기존 `edit_user.php`의 "자기 자신 역할 변경 금지" 로직과 병행)

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 기능 테스트 | `permission_helper.php` 신규/수정 함수 동작 | PHP 스크립트 수동 실행 / `php -l` | Do |
| L2: UI 액션 테스트 | 역할관리, 권한매트릭스, 회원추가/수정 화면 | 수동 브라우저 테스트 | Do |
| L3: 회귀 테스트 | 기존 5역할 사용자 권한/메뉴 노출 | 마이그레이션 전후 비교 | Check |

### 8.2 L1: 기능 테스트 시나리오

| # | 대상 | 테스트 내용 | 기대 결과 |
|---|------|-----------|----------|
| 1 | `get_default_permissions('manager')` | 마이그레이션 후 호출 | 19개 권한 모두 false (신규 역할 초기값) |
| 2 | `get_default_permissions('admin')` | 마이그레이션 후 호출 | 기존 admin 하드코딩 배열과 동일한 값 반환 |
| 3 | `get_all_roles()` | 호출 | level ASC로 정렬된 9개 역할 배열 반환 |
| 4 | `update_role_permissions('manager','product_management',1,$admin_id)` | 호출 | role_permissions UPDATE + permission_change_log 1건 추가 |
| 5 | `delete_role('admin')` | 호출 (is_system=1) | false 반환, "SYSTEM_ROLE_PROTECTED" |
| 6 | `delete_role('manager')` (사용자 존재) | 호출 | false 반환, "ROLE_IN_USE" |
| 7 | DB 연결 실패 시 `get_default_permissions('staff')` | DB 강제 차단 후 호출 | 기존 하드코딩 배열 폴백 값 반환 |

### 8.3 L2: UI 액션 테스트 시나리오

| # | Page | Action | Expected Result |
|---|------|--------|------------------|
| 1 | role_management.php | 새 역할 추가("부점장", level 35) | 목록에 추가됨, role_permissions에 19행 enabled=0 생성 |
| 2 | role_management.php | 동일 role_key로 재추가 | "DUPLICATE_ROLE_KEY" 에러 표시 |
| 3 | role_management.php | 시스템 역할(예: admin) 삭제 클릭 | 버튼 비활성화 상태, 클릭 불가 |
| 4 | role_permissions_matrix.php | "부점장" 행에서 product_management 체크 | AJAX 200, 체크 유지, 새로고침 후에도 유지 |
| 5 | role_permissions_matrix.php | super_admin 행 체크박스 클릭 | 변경 불가 (disabled), 클릭 무반응 |
| 6 | add_user.php | 역할 드롭다운 확인 | 9개 역할 표시, 현재 사용자 level보다 높은 역할은 미표시 |
| 7 | edit_user.php | "부점장" 권한 적용된 사용자로 역할 변경 후 재로그인 | product_management 메뉴 노출 확인 |

### 8.4 L3: 회귀 시나리오 (마이그레이션 전후 비교)

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-----------------|
| 1 | 기존 admin 사용자 | 마이그레이션 전/후 동일 계정으로 로그인 | 메뉴/접근 권한 동일 |
| 2 | 기존 staff 사용자 | 마이그레이션 전/후 동일 계정으로 로그인 | shop_access, barcode_management만 접근 가능 (동일) |
| 3 | 개인별 permissions override 사용자 | 마이그레이션 전/후 | `users.permissions` JSON override가 role 기본권한보다 우선 적용됨 (동일) |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| roles | 9 | role_key, label, level, is_system (마이그레이션으로 자동 생성) |
| role_permissions | 9 × 19 = 171 | role_key, permission_key, enabled (마이그레이션으로 자동 생성) |
| users (기존) | 마이그레이션 대상 전체 | role 값 보존 확인용 |

---

## 9. Clean Architecture

> 본 프로젝트는 절차형 PHP + 헬퍼 함수 구조이며 Clean Architecture 계층 분리를 적용하지 않음 (Option C 선택 사유 참조). 아래는 기존 구조 내에서의 책임 배치만 기술.

### 9.4 This Feature's Layer Assignment (기존 구조 기준)

| Component | Role | Location |
|-----------|------|----------|
| 역할/권한 헬퍼 함수 | 데이터 접근 + 비즈니스 로직 | `lib/permission_helper.php` |
| 역할 관리 화면 | 프레젠테이션 + 폼 처리 | `admin/role_management.php` |
| 권한 매트릭스 화면 | 프레젠테이션 | `admin/role_permissions_matrix.php` |
| 매트릭스 저장 엔드포인트 | AJAX 핸들러 | `admin/ajax_update_role_permission.php` |
| 마이그레이션 | 1회 데이터 이전 스크립트 | `migrations/migrate_role_permissions.php` |
| 스키마 | DDL | `sql/xxx_role_permission_system.sql` |

---

## 10. Coding Convention Reference

### 10.4 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| DB 접근 | PDO + Prepared Statement, `config/db_config.php`의 `get_db_connection()` / PDO 직접 생성 패턴 재사용 |
| 트랜잭션 | `autocommit(false)` + `commit()`/`rollback()` (마이그레이션, 역할 추가/삭제 시 다중 INSERT) |
| 권한 체크 | `require_permission('settings')` 패턴 재사용 |
| 다국어 | `t('roles.xxx')`, `t('role_mgmt.xxx')` 형태로 `lang/en.json`, `lang/ko.json`에 추가 |
| 네이밍 | 함수 camelCase 아닌 snake_case (`get_all_roles`, `update_role_permissions`) — 기존 `permission_helper.php` 컨벤션 따름 |
| 파일명 | `admin/role_management.php`, `admin/role_permissions_matrix.php`, `admin/ajax_update_role_permission.php` — 기존 `*_management.php`, `ajax_*.php` 패턴 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
sql/
└── 002_role_permission_system.sql        (신규) - roles/role_permissions/permission_change_log 생성 + ENUM→VARCHAR

migrations/
└── migrate_role_permissions.php           (신규) - 기존 권한값 → role_permissions 이전 (PDO 트랜잭션)

lib/
└── permission_helper.php                  (수정)
    - get_default_permissions() DB 조회로 전환 (폴백 유지)
    - get_all_roles() 신규
    - get_role_label() 신규
    - update_role_permissions() 신규
    - create_role() / delete_role() 신규

admin/
├── role_management.php                    (신규)
├── role_permissions_matrix.php            (신규)
├── ajax_update_role_permission.php        (신규)
├── add_user.php                           (수정) - 역할 선택 동적화
├── edit_user.php                          (수정) - 역할 선택 동적화
└── partials/header.php                    (수정) - 슈퍼어드민 메뉴 추가

lang/
├── en.json                                (수정) - role_mgmt.* 문자열 추가
└── ko.json                                (수정) - role_mgmt.* 문자열 추가
```

### 11.2 Implementation Order

1. [ ] `sql/002_role_permission_system.sql` 작성 (테이블 생성 + ENUM→VARCHAR + 9역할 시드)
2. [ ] `migrations/migrate_role_permissions.php` 작성 (기존 5역할×19권한 값 이전, 신규 4역할 enabled=0 시드, 트랜잭션)
3. [ ] `lib/permission_helper.php`: `get_default_permissions()` DB 조회 전환 + 신규 함수 5종 추가
4. [ ] `admin/role_management.php` 구현
5. [ ] `admin/role_permissions_matrix.php` + `admin/ajax_update_role_permission.php` 구현
6. [ ] `admin/add_user.php`, `admin/edit_user.php` 역할 선택 동적화
7. [ ] `admin/partials/header.php` 메뉴 추가
8. [ ] `lang/en.json`, `lang/ko.json` 문자열 추가
9. [ ] 마이그레이션 실행 + 회귀 테스트 (8.4)

### 11.3 Session Guide

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| DB & 마이그레이션 | `module-1` | SQL 스키마 + 마이그레이션 스크립트 + 실행 | 10-15 |
| 권한 헬퍼 확장 | `module-2` | `permission_helper.php` 함수 5종 추가/수정 | 10-15 |
| 역할 관리 화면 | `module-3` | `role_management.php` | 10-15 |
| 권한 매트릭스 화면 | `module-4` | `role_permissions_matrix.php` + AJAX | 10-15 |
| 회원관리 동적화 | `module-5` | `add_user.php`/`edit_user.php`/header 메뉴/다국어 | 10-15 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 | 완료 |
| Session 2 | Do | `--scope module-1,module-2` | 20-30 |
| Session 3 | Do | `--scope module-3,module-4` | 25-35 |
| Session 4 | Do | `--scope module-5` | 15-20 |
| Session 5 | Check + Report | 전체 | 25-30 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-06-12 | Initial draft | whdans007 |
