---
template: design
version: 1.3
feature: mall-member-english-name
date: 2026-08-30
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-member-english-name Design Document

> **Summary**: 회원가입(일반+구글) 시 영문 이름을 필수화하고, 기존 회원은 첫 주문 확정 시점에 한글 이름을 서버 내부 로마자 표기 알고리즘으로 자동 변환해 `mall_members.english_name`에 영구 저장한다.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft
> **Planning Doc**: [mall-member-english-name.plan.md](../../01-plan/features/mall-member-english-name.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 실무자(배송기사/캐셔)가 영어만 사용하는데 상당수 회원의 영문 이름이 비어있음 |
| **WHO** | 신규/기존 쇼핑몰 회원(소매+도매), 이 값을 소비하는 관리자/배송기사 화면 |
| **RISK** | 자동 로마자 변환이 실제 선호 표기(여권 표기 등)와 다를 수 있음 — 프로필에서 언제든 수정 가능하게 해 완화 |
| **SUCCESS** | 신규가입 100% 영문 이름 보유, 기존 회원도 첫 주문 이후 100% 영문 이름 보유 |
| **SCOPE** | (1) 회원가입 필수화 — 일반+구글 가입 (2) 로마자 자동 변환 함수 (3) 주문 생성 시점 자동 채움·영구 저장 |

---

## 1. Overview

### 1.1 Design Goals

- 회원가입 경로(일반/구글) 어느 쪽으로도 `english_name`이 빈 상태로 계정이 생성되지 않게 한다.
- 이미 존재하는(레거시) 회원의 빈 `english_name`을 배치 작업 없이, 회원이 자연스럽게 재방문(주문)하는 시점에 조용히 채운다.
- 로마자 변환 로직은 순수 PHP 함수로 격리해 외부 의존성·비용·개인정보 유출 위험이 없게 한다.
- 변환 로직의 예외/실패가 절대 주문 트랜잭션을 막지 않게 한다(FR-05).

### 1.2 Design Principles

- **Fail-open on non-critical path**: 로마자 변환은 주문의 핵심 로직이 아니므로 실패해도 주문은 계속 진행된다.
- **Write-once, user-correctable**: 자동 채움은 값이 비어있을 때만 발생하고, 이미 값이 있으면 절대 덮어쓰지 않는다. 사용자는 언제든 프로필에서 직접 고칠 수 있다.
- **기존 컨벤션 재사용**: `mall_` 접두사 함수형 헬퍼, `mall/lib/*.php` 구조, 기존 검증 패턴(business_name 등) 그대로 따른다.

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: Minimal | Option B: Clean | Option C: Pragmatic |
|----------|:-:|:-:|:-:|
| **Approach** | `mall_create_order()` 내부에 변환 로직 인라인 작성 | 교체 가능한 `NameRomanizerInterface` 도입 | 신규 `mall/lib/romanize.php` 모듈 + 기존 파일 소폭 수정 |
| **New Files** | 0 | 2~3 (interface + impl + factory) | 1 (`mall/lib/romanize.php`) |
| **Modified Files** | 1 (`order.php`) | 3+ | 3 (`signup.php`, `auth.php`, `order.php`) |
| **Complexity** | Low | High | Medium |
| **Maintainability** | Low (재사용 불가, 테스트 어려움) | High (과설계 — 외부 API 확장은 범위 밖) | High |
| **Effort** | Low | High | Medium |
| **Risk** | Medium (숨은 로직, 재사용 시 복붙 위험) | Low (구현은 안전하나 불필요한 추상화) | Low |
| **Recommendation** | Quick hotfix | 향후 외부 API 병행 시 | **Default choice** |

**Selected**: Option C — **Rationale**: 이 프로젝트는 Plan에서 이미 외부 API 연동을 범위 밖으로 명시했으므로 Option B의 인터페이스 추상화는 YAGNI 위반이다. `mall/lib/*.php` 함수형 헬퍼 컨벤션(다른 모든 기존 Design 문서와 동일)을 그대로 따르는 Option C가 재사용성과 단순함의 균형이 가장 좋다. 사용자가 AskUserQuestion에서 명시적으로 선택함.

### 2.1 Component Diagram

```
┌────────────────────┐      ┌───────────────────────┐      ┌────────────────────┐
│  mall/signup.php    │      │  mall/lib/auth.php     │      │   mall_members      │
│  (일반+구글 가입 폼) │─────▶│  mall_google_signup()  │─────▶│   (english_name)    │
└────────────────────┘      └───────────────────────┘      └─────────▲──────────┘
                                                                       │
┌────────────────────┐      ┌───────────────────────┐                │
│ mall/lib/order.php  │─────▶│ mall/lib/romanize.php  │────────────────┘
│ mall_create_order() │      │ mall_romanize_korean_  │
│ (english_name 비어  │      │ name($name)            │
│  있으면 호출+저장)   │      └───────────────────────┘
└────────────────────┘
```

### 2.2 Data Flow

**가입 경로**
```
회원가입 폼 제출(영문 이름 포함) → 서버 필수값 검증(비어있으면 에러) → INSERT mall_members(..., english_name)
```

**주문 경로 (레거시 회원 자동 채움)**
```
mall_create_order() 진입 → member.english_name 비어있음? 
  → mall_romanize_korean_name(member.name) 호출(try/catch)
  → 성공: UPDATE mall_members SET english_name = ? WHERE id = ?
  → 실패: error_log만 남기고 english_name은 비운 채 진행
→ (성공/실패 무관) 주문 생성 로직 계속 진행
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `mall/signup.php` | `mall/lib/auth.php` | 구글 가입 시 `mall_google_signup()` 호출 |
| `mall/lib/order.php` (`mall_create_order`) | `mall/lib/romanize.php` | 빈 `english_name` 자동 변환 |
| `mall/lib/romanize.php` | 없음 (순수 함수, 외부 호출 없음) | 한글→로마자 매핑 테이블 기반 변환 |

---

## 3. Data Model

### 3.1 Entity Definition

스키마 변경 없음 — `mall_members.english_name`(varchar)은 이전 단계에서 이미 추가·사용 중.

```
mall_members
├── id
├── name              (한글/원본 이름, NOT NULL)
├── english_name       (기존 컬럼, nullable → 이번 기능으로 "사실상 NOT NULL"이 되도록 가입 시 필수화)
├── ... (기존 컬럼 동일)
```

### 3.2 Entity Relationships

변경 없음. `mall_members` 1건이 `mall_orders` N건을 가지는 기존 관계 그대로 사용.

### 3.3 Database Schema

**신규/변경 컬럼 없음.** 이 기능은 순수 애플리케이션 로직(회원가입 검증 + 주문 시점 자동 채움)이며 마이그레이션 스크립트가 필요 없다.

---

## 4. API Specification

REST API가 아닌 서버사이드 폼 처리(PHP) + 내부 함수 호출 방식이므로, 엔드포인트 표 대신 **함수 시그니처**로 명세한다.

### 4.1 함수/엔드포인트 목록

| 대상 | 종류 | 위치 | 설명 |
|------|------|------|------|
| `mall_romanize_korean_name($name)` | 신규 함수 | `mall/lib/romanize.php` | 한글 이름 → 로마자 문자열 반환 |
| `mall_google_signup($google, $member_type, $business_name, $business_reg_no, $english_name)` | 시그니처 변경 | `mall/lib/auth.php` | `$english_name` 파라미터 추가, INSERT에 반영 |
| `mall_create_order($member_id, $member, $requested_channel, $memo)` | 내부 로직 추가 | `mall/lib/order.php` | 함수 시그니처는 불변, 내부에 자동 채움 로직 삽입 |
| `mall/signup.php` POST 처리 | 검증 로직 추가 | `mall/signup.php` | 일반/구글 두 분기 모두 `english_name` 필수 검증 |

### 4.2 상세 명세

#### `mall_romanize_korean_name(string $name): string`

**입력**: 한글 이름 문자열 (예: `"홍길동"`)
**출력**: 로마자 변환 문자열, 성+이름 사이 공백 (예: `"Hong Gildong"`)
**동작 규칙**:
- 국어의 로마자 표기법(문화체육관광부 고시 제2000-8호) 초성/중성/종성 매핑 테이블을 내부 배열로 보유.
- 입력을 UTF-8 코드포인트 단위로 순회하며 한글 음절(U+AC00~U+D7A3)마다 초성/중성/종성을 분해해 로마자로 치환.
- 한글이 아닌 문자(영문/숫자/공백 등)는 그대로 통과.
- 빈 문자열 또는 완전히 매핑 불가한 입력이면 예외를 던지지 않고 빈 문자열 `''`을 반환(호출부에서 빈 값이면 저장하지 않음).
- 첫 글자(성 부분)만 대문자화, 나머지는 소문자 처리 후 이름 부분 앞글자만 대문자화(예: "Hong Gildong").

**예외**: 이 함수 자체는 예외를 던지지 않는다(내부에서 모두 처리). `mall_create_order()`가 호출부를 try/catch로 감싸는 것은 향후 로직 변경에 대비한 방어적 조치.

#### `mall_google_signup()` 변경

**Before**: `mall_google_signup($google, $member_type, $business_name = '', $business_reg_no = '')`
**After**: `mall_google_signup($google, $member_type, $business_name = '', $business_reg_no = '', $english_name = '')`

- INSERT 컬럼 목록에 `english_name` 추가, bind 타입 문자열 `'ssssssis'` → `'sssssssis'`로 1개 `s` 추가(위치는 구현 시 실제 컬럼 순서에 맞춰 재확인).
- 유일한 호출부인 `mall/signup.php`의 google 분기도 함께 수정.

#### `mall_create_order()` 내부 추가 로직

```php
// Design Ref: §4.2 — 레거시 회원 영문 이름 자동 채움 (FR-04, FR-05)
if (empty($member['english_name']) && !empty($member['name'])) {
    try {
        $auto_english_name = mall_romanize_korean_name($member['name']);
        if ($auto_english_name !== '') {
            $upd = $conn->prepare('UPDATE mall_members SET english_name = ? WHERE id = ?');
            $upd->bind_param('si', $auto_english_name, $member_id);
            $upd->execute();
            $upd->close();
        }
    } catch (Exception $e) {
        error_log('mall_romanize_korean_name auto-fill error: ' . $e->getMessage());
        // 의도적으로 무시 — 주문 흐름을 막지 않는다 (FR-05)
    }
}
```

삽입 위치: 기존 `$conn`(트랜잭션 커넥션)이 이미 열려 있고 `$member_id`가 확정된 시점 이후, 트랜잭션 커밋 전 어디든 가능하나, 명확성을 위해 **`begin_transaction()` 직후, 주문번호 INSERT 이전**에 배치한다(주문 자체와 무관한 부수 효과이므로 실패해도 롤백 대상이 아님 — try/catch로 자체 격리).

### 4.3 Error Responses

이 기능은 신규 HTTP 에러 코드를 추가하지 않는다. `mall/signup.php`의 기존 에러 표시 방식(폼 재표시 + 인라인 메시지)을 그대로 재사용해 "영문 이름을 입력해주세요" 메시지만 추가한다.

---

## 5. UI/UX Design

### 5.1 Screen Layout — 회원가입 폼 (일반)

```
┌────────────────────────────────────┐
│  이메일                             │
│  비밀번호                           │
│  이름 (한글)                        │
│  영문 이름  ← 신규 필수 입력          │
│  전화번호                           │
│  (사업자 회원인 경우) 상호명 등        │
│  [가입하기]                         │
└────────────────────────────────────┘
```

### 5.2 Screen Layout — 구글 가입(추가정보) 폼

```
┌────────────────────────────────────┐
│  (구글 계정 이름/이메일 표시, 읽기전용) │
│  영문 이름  ← 신규 필수 입력          │
│  회원 유형 선택                      │
│  (사업자인 경우) 상호명 등             │
│  [가입 완료]                         │
└────────────────────────────────────┘
```

### 5.3 User Flow

```
[일반] 가입폼 작성(영문 이름 미입력) → 제출 → 서버 검증 실패 → "영문 이름을 입력해주세요" 표시 + 폼 유지
[구글] 구글 로그인 → 추가정보 입력(영문 이름 미입력) → 제출 → 동일 에러 → 폼 유지

[레거시 회원] 로그인(영문 이름 없음) → 주문 확정 → (화면 변화 없음, 백그라운드 자동 채움) → 주문 완료
             → (선택) 마이페이지 > 프로필에서 자동 채워진 영문 이름 확인/수정
```

### 5.4 Page UI Checklist

#### `mall/signup.php` — 일반 가입 폼

- [ ] Input: 영문 이름 (`name="english_name"`, `required`, placeholder 예: "Hong Gildong")
- [ ] Validation: 서버 측 `trim($english_name) === ''`이면 에러 메시지 "영문 이름을 입력해주세요" 표시 후 폼 재표시(기존 입력값 유지)

#### `mall/signup.php` — 구글 가입(추가정보) 폼

- [ ] Input: 영문 이름 (`name="english_name"`, `required`)
- [ ] Validation: 동일하게 서버 측 필수 검증

#### `mall/mypage/profile.php` (변경 없음, 확인만)

- [ ] 기존 영문 이름 입력란이 자동 채움된 값도 정상 표시/수정 가능한지 확인 (이미 구현됨 — 회귀 확인 목적)

---

## 6. Error Handling

### 6.1 Error Code Definition

| Code | Message | Cause | Handling |
|------|---------|-------|----------|
| (폼 인라인) | "영문 이름을 입력해주세요" | 일반/구글 가입 폼에서 `english_name` 공백 제출 | 폼 재표시, 기존 입력값 유지, 신규 필드만 비움 |
| (없음, 무음 처리) | - | `mall_create_order()` 내 로마자 변환 실패 | `error_log()` 기록, 주문은 정상 진행 (사용자에게 노출 안 함) |

### 6.2 Error Response Format

기존 `mall/signup.php`의 폼 에러 표시 패턴(세션/GET 파라미터 기반 인라인 메시지)을 그대로 재사용 — 신규 포맷 도입 없음.

---

## 7. Security Considerations

- [x] 입력 검증: `english_name`은 `htmlspecialchars()` 처리 후 저장/출력(기존 `name`, `business_name` 처리와 동일 패턴).
- [x] 외부 API 미사용 — 회원 이름이 외부로 전송되지 않음(Plan NFR "Privacy" 충족).
- [x] `mall_romanize_korean_name()`은 순수 문자열 연산만 수행 — SQL Injection/XSS 벡터 없음(DB 저장 시 prepared statement 사용).
- [x] 인증/인가 변경 없음 — 기존 `mall_google_signup()` 호출부(`mall/signup.php`)의 세션 검증 로직 그대로 유지.

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 함수 단위 테스트 | `mall_romanize_korean_name()` 샘플 입력/출력 | 수동 PHP 스크립트(CLI) | Do |
| L2: 폼 검증 테스트 | `mall/signup.php` 필수 입력 검증 | 브라우저 수동 테스트 | Do |
| L3: E2E 시나리오 | 레거시 회원 주문 시 자동 채움 | 브라우저 수동 테스트 (실 DB) | Do |

### 8.2 L1: 로마자 변환 함수 테스트 시나리오

| # | 입력 | 기대 출력(예시) | 검증 포인트 |
|---|------|----------------|-------------|
| 1 | "김민준" | "Kim Minjun" | 흔한 성 "김" 처리 |
| 2 | "이서연" | "Lee Seoyeon" | 흔한 성 "이"(로마자 표기법상 "I"가 아닌 관용 표기 "Lee" 허용 여부는 구현 시 결정) |
| 3 | "박" (외자/성만) | 빈 문자열 아닌 로마자 1글자 이상 | 짧은 입력 처리 |
| 4 | "" (빈 문자열) | "" | 빈 입력 시 예외 없이 빈 문자열 반환 |
| 5 | "John" (이미 영문) | "John" (통과) | 비한글 입력 그대로 통과 |
| 6 | "김Bob" (혼합) | "Kim Bob" 유사 | 혼합 문자열 처리 |

### 8.3 L2: 회원가입 폼 검증 시나리오

| # | Page | Action | Expected Result |
|---|------|--------|-----------------|
| 1 | `mall/signup.php` (일반) | 영문 이름 비우고 제출 | "영문 이름을 입력해주세요" 표시, 가입 안 됨 |
| 2 | `mall/signup.php` (일반) | 영문 이름 포함 제출 | 가입 성공, `mall_members.english_name`에 값 저장 확인 |
| 3 | `mall/signup.php` (구글) | 영문 이름 비우고 제출 | 동일 에러, 가입 안 됨 |
| 4 | `mall/signup.php` (구글) | 영문 이름 포함 제출 | 가입 성공, `mall_google_signup()` 통해 값 저장 확인 |

### 8.4 L3: 레거시 회원 자동 채움 시나리오

| # | Scenario | Steps | Success Criteria |
|---|----------|-------|-------------------|
| 1 | 빈 english_name 회원 주문 | DB에서 특정 회원의 `english_name`을 임시로 NULL/빈값으로 설정 → 로그인 → 주문 확정 | 주문 정상 완료 + `mall_members.english_name`이 자동으로 채워짐(DB 확인) |
| 2 | 이미 값 있는 회원 주문 | 정상 회원으로 주문 확정 | `english_name` 값이 변경되지 않음(덮어쓰기 없음) |
| 3 | 변환 실패 강제 시뮬레이션 | (Do 단계에서 임시로 예외 발생 코드 삽입 후 테스트, 이후 제거) | 주문이 실패하지 않고 정상 완료됨 |

### 8.5 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| `mall_members` | 2 | 1건은 `english_name` NULL/빈값, 1건은 값 있음 (자동 채움 미발동 확인용) |

---

## 9. Clean Architecture

### 9.1 Layer Structure (Procedural PHP 버전)

| Layer | Responsibility | Location |
|-------|---------------|----------|
| **Presentation** | 폼 렌더링, 클라이언트 검증(`required`) | `mall/signup.php` (HTML 부분) |
| **Application** | 가입/주문 처리 흐름, 검증 오케스트레이션 | `mall/signup.php` (POST 처리부), `mall/lib/order.php` |
| **Domain** | 로마자 변환 규칙(순수 로직, DB/세션 의존 없음) | `mall/lib/romanize.php` |
| **Infrastructure** | DB INSERT/UPDATE (mysqli) | `mall/lib/auth.php`, `mall/lib/order.php` |

### 9.2 Dependency Rules

```
mall/signup.php (Presentation+Application)
        │
        ├──▶ mall/lib/auth.php (Infrastructure) — mall_google_signup()
        │
mall/lib/order.php (Application+Infrastructure)
        │
        └──▶ mall/lib/romanize.php (Domain, 의존성 없음) — mall_romanize_korean_name()
```

`mall/lib/romanize.php`는 DB 커넥션이나 세션에 의존하지 않는 순수 함수 모듈로 작성해, 향후 CLI 스크립트나 다른 컨텍스트에서도 재사용 가능하게 한다.

### 9.3 File Import Rules

| From | Can Import | Cannot Import |
|------|-----------|---------------|
| `mall/signup.php` | `mall/lib/auth.php` | `mall/lib/romanize.php` 직접 호출(가입 시점엔 변환 불필요 — 사용자가 직접 입력) |
| `mall/lib/order.php` | `mall/lib/romanize.php` | 없음 |
| `mall/lib/romanize.php` | 없음(순수 함수) | DB/세션/다른 lib 파일 |

### 9.4 This Feature's Layer Assignment

| Component | Layer | Location |
|-----------|-------|----------|
| 영문 이름 입력 폼 (일반/구글) | Presentation | `mall/signup.php` |
| 가입 검증 로직 | Application | `mall/signup.php` (POST 처리부) |
| `mall_romanize_korean_name()` | Domain | `mall/lib/romanize.php` |
| `mall_google_signup()` INSERT, `mall_create_order()` UPDATE | Infrastructure | `mall/lib/auth.php`, `mall/lib/order.php` |

---

## 10. Coding Convention Reference

### 10.1 Naming Conventions

| Target | Rule | Example |
|--------|------|---------|
| 함수 | `mall_` 접두사 + snake_case | `mall_romanize_korean_name()` |
| 신규 파일 | snake_case + `.php`, `lib/` 하위 | `mall/lib/romanize.php` |
| 변수 | snake_case | `$english_name`, `$auto_english_name` |

### 10.2 Error Handling Convention

- 사용자에게 영향을 주는 검증 실패(가입 폼) → 기존 인라인 에러 메시지 패턴 재사용.
- 사용자에게 영향을 주지 않아야 하는 부수 로직(주문 시 자동 채움) → try/catch + `error_log()`, 절대 예외를 상위로 전파하지 않음.

### 10.3 This Feature's Conventions

| Item | Convention Applied |
|------|-------------------|
| 함수 네이밍 | `mall_` 접두사 + snake_case (기존과 동일) |
| 파일 구조 | `mall/lib/*.php` 함수형 헬퍼 (기존과 동일) |
| 에러 처리 | 사용자-대면 검증은 명시적 에러, 백그라운드 보조 로직은 fail-open |
| DB 접근 | mysqli prepared statement, `get_db_connection()`(메인 DB) 사용 — `get_store_db()`와 절대 혼동 금지 |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/
├── lib/
│   ├── romanize.php        (신규) — mall_romanize_korean_name()
│   ├── auth.php             (수정) — mall_google_signup() 시그니처+INSERT 변경
│   └── order.php            (수정) — mall_create_order() 내 자동 채움 로직 추가
└── signup.php                (수정) — 일반/구글 폼 모두 영문 이름 필수 입력+검증
```

### 11.2 Implementation Order

1. [ ] `mall/lib/romanize.php` 작성 — 초성/중성/종성 매핑 테이블 + `mall_romanize_korean_name()` 구현, CLI로 8.2 샘플 케이스 수동 검증
2. [ ] `mall/lib/auth.php`의 `mall_google_signup()`에 `$english_name` 파라미터 및 INSERT 컬럼 추가
3. [ ] `mall/signup.php` 일반 가입 폼: 입력란 추가 + 서버 검증 + INSERT bind 반영
4. [ ] `mall/signup.php` 구글 가입 폼: 입력란 추가 + 서버 검증 + `mall_google_signup()` 호출부에 인자 추가
5. [ ] `mall/lib/order.php`의 `mall_create_order()`에 자동 채움 로직 삽입(try/catch로 격리)
6. [ ] 8.3/8.4 시나리오 수동 테스트 (신규 가입 2건, 레거시 회원 주문 1건, 값 있는 회원 주문 1건)

### 11.3 Session Guide

이 기능은 단일 세션으로 완료 가능한 소규모 범위 (신규 파일 1개, 수정 파일 3개) — 모듈 분할 불필요.

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| 전체 구현 | `module-1` | romanize.php + auth.php + signup.php + order.php 전체 | 15-20 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료) | - |
| Session 2 | Do | `--scope module-1` (전체) | 15-20 |
| Session 3 | Check + Report | 전체 | 10-15 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft (Option C 선택) | whdans007 |
