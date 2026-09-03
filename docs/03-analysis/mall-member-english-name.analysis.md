---
template: analysis
version: 1.3
feature: mall-member-english-name
date: 2026-08-30
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-member-english-name Analysis Report

> **Analysis Type**: Gap Analysis (Static-only — 로컬/원격 PHP 실행 환경 없음, 서버는 수동 업로드 배포)
>
> **Project**: HOME K MART
> **Version**: -
> **Analyst**: whdans007
> **Date**: 2026-08-30
> **Design Doc**: [mall-member-english-name.design.md](../02-design/features/mall-member-english-name.design.md)

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

## Strategic Alignment Check

> PRD 없음(표준 `/pdca plan`, PM 단계 생략) — Plan→Design→Do 3단계 체인만 검증.

### Success Criteria Status (Plan §4.1 FR-01~FR-05)

| # | Criteria | Status | Evidence |
|---|----------|:------:|----------|
| FR-01 | 일반 가입 시 영문 이름 미입력이면 가입 거부 | ✅ | `mall/signup.php:61-62` — `elseif ($english_name === '') { ... }`, INSERT 이전에 걸러짐 |
| FR-02 | 구글 가입 시에도 영문 이름 미입력이면 가입 거부 | ✅ | `mall/signup.php:28-30`(폼단 검증) + `mall/lib/auth.php:240-242`(`mall_google_signup()` 자체 방어, `ENGLISH_NAME_REQUIRED`) — 2중 방어 |
| FR-03 | 한글→로마자 변환 서버 함수 존재 | ✅ | `mall/lib/romanize.php:85` `mall_romanize_korean_name()`, 초성/중성/종성 매핑 테이블 기반, 외부 호출 없음 |
| FR-04 | 주문 생성 시 `english_name` 비어있으면 자동 계산 후 영구 저장 | ✅ | `mall/lib/order.php:66-79` — 트랜잭션 시작 직후 `empty($member['english_name'])` 체크 → `UPDATE mall_members` |
| FR-05 | 자동 변환 실패가 주문을 막지 않음 | ✅ | `mall/lib/order.php:67-78` — 내부 try/catch로 격리, 상위 주문 로직과 무관하게 계속 진행 |

**Success Rate**: 5/5 criteria met

### Decision Record Verification

| Source | Decision | Followed? | Deviation |
|--------|----------|:---------:|-----------|
| [Plan] | 자체 로마자화 알고리즘(외부 API 미사용) | ✅ | 없음 — `romanize.php`는 순수 함수, 외부 HTTP 호출 없음 |
| [Plan] | 자동 변환 결과 회원에 영구 저장 | ✅ | 없음 — `UPDATE mall_members SET english_name = ...` |
| [Plan] | 구글 가입도 필수화 범위 포함 | ✅ | 없음 — `mall_google_signup()` 시그니처 변경 + 폼 검증 모두 반영 |
| [Design] | 아키텍처 Option C(신규 `romanize.php` + 3개 파일 수정) | ✅ | 없음 — 정확히 4개 파일(신규 1 + 수정 3)로 구현됨, 추가 파일 없음 |

---

## 1. Analysis Overview

### 1.1 Analysis Purpose

Do 단계에서 구현한 4개 파일(`mall/lib/romanize.php`, `mall/lib/auth.php`, `mall/signup.php`, `mall/lib/order.php`)이 Design 문서(§4, §5.4, §9)와 일치하는지, Plan의 FR-01~05를 실제로 충족하는지 정적으로 검증한다.

### 1.2 Analysis Scope

- **Design Document**: `docs/02-design/features/mall-member-english-name.design.md`
- **Implementation Path**: `mall/lib/romanize.php`, `mall/lib/auth.php`, `mall/signup.php`, `mall/lib/order.php`
- **Analysis Date**: 2026-08-30
- **제약**: 이 프로젝트는 로컬 PHP 인터프리터/서버가 없고(수동 파일 업로드 배포), Playwright 등 런타임 테스트 도구도 없음 → **정적 코드 검증만 수행**(Design §2.3 Match Rate Formula의 "static only" 산식 적용)

---

## 2. Gap Analysis (Design vs Implementation)

### 2.1 함수/엔드포인트 계약 (Design §4.1)

| Design | Implementation | Status | Notes |
|--------|---------------|--------|-------|
| `mall_romanize_korean_name($name)` (신규, `mall/lib/romanize.php`) | 동일 시그니처로 구현됨 | ✅ Match | 예외 없이 빈 문자열 반환하는 fail-safe 동작도 설계대로 구현 |
| `mall_google_signup(..., $english_name)` (시그니처 변경, `mall/lib/auth.php`) | `function mall_google_signup($google, $member_type, $business_name = '', $business_reg_no = '', $english_name = '')` | ✅ Match | 유일한 호출부 `mall/signup.php:36`도 5-인자로 함께 수정 확인(grep으로 다른 호출부 없음 확인) |
| `mall_create_order()` 내부 로직 추가(시그니처 불변) | 함수 시그니처 그대로, 트랜잭션 내부에 자동 채움 블록만 추가 | ✅ Match | 기존 호출부(`mall/ajax/submit_order.php`)는 수정 불필요 — 실제로 변경 안 됨 |
| `mall/signup.php` 양쪽 폼 필수 검증 | 일반 폼(§signup.php:61-62), 구글 폼(§signup.php:28-30) 모두 구현 | ✅ Match | |

### 2.2 Data Model

| Field | Design | Impl | Status |
|-------|--------|------|--------|
| `mall_members.english_name` | 변경 없음(기존 컬럼 재사용) | INSERT 문에 컬럼만 추가, ALTER 없음 | ✅ |

스키마 변경 없음 — Design §3.3과 일치, 마이그레이션 스크립트 불필요 확인됨.

### 2.3 Component/File Structure

| Design File | Implementation | Status |
|-------------|-----------------|--------|
| `mall/lib/romanize.php` (신규) | 생성됨 | ✅ Match |
| `mall/lib/auth.php` (수정) | 수정됨 | ✅ Match |
| `mall/signup.php` (수정) | 수정됨 | ✅ Match |
| `mall/lib/order.php` (수정) | 수정됨 | ✅ Match |

**Structural Match Rate**: 4/4 = 100%

### 2.4 Functional Depth Analysis

| File | Depth Score | Placeholder Indicators | Missing Design Elements |
|------|:----------:|----------------------|------------------------|
| `mall/lib/romanize.php` | 100 | 없음 | 없음 — 초성/중성/종성 테이블, UTF-8 디코더, 단어 대문자화까지 완전 구현 |
| `mall/lib/auth.php` | 100 | 없음 | 없음 — 파라미터 추가, 필수 검증, INSERT bind 모두 반영 |
| `mall/signup.php` | 100 | 없음 | 없음 — 양쪽 폼 input + 서버 검증 + INSERT/함수호출 반영 |
| `mall/lib/order.php` | 100 | 없음 | 없음 — try/catch 격리, `empty()` 가드, UPDATE 반영 |

**Shallow File Count**: 0 / 4 files (0%)

### 2.5 Page UI Checklist Verification (Design §5.4)

| Page | Design Elements | Implemented | Missing | Rate |
|------|:--------------:|:-----------:|:-------:|:----:|
| `mall/signup.php` 일반 가입 폼 | 2 (input+required, 서버 검증) | 2 | 0 | 100% |
| `mall/signup.php` 구글 가입 폼 | 2 (input+required, 서버 검증) | 2 | 0 | 100% |
| `mall/mypage/profile.php` (회귀 확인) | 1 (자동채움 값 표시/수정 가능) | 1 | 0 | 100% |

**Functional Match Rate**: 100%

### 2.6 API Contract Verification

REST API가 아닌 내부 함수 호출 계약이므로 "Design 명세 ↔ 정의부 ↔ 호출부" 3-way로 검증:

| # | 계약 | Design | 정의부 | 호출부 | Contract |
|---|------|:------:|:------:|:------:|:--------:|
| 1 | `mall_google_signup(...,$english_name)` | ✅ | ✅ (`auth.php:236`) | ✅ (`signup.php:36`, 유일한 호출부) | PASS |
| 2 | `mall_romanize_korean_name($name)` | ✅ | ✅ (`romanize.php:85`) | ✅ (`order.php:71`, 유일한 호출부) | PASS |

**Contract Match Rate**: 2/2 = 100%

### 2.7 Runtime Verification Results

이 프로젝트에 로컬 PHP/DB/브라우저 자동화 실행 환경이 없어(원격 프로덕션 서버에 수동 업로드로만 배포) L1/L2/L3 런타임 테스트를 이 세션에서 자동 실행할 수 없음. Design §8.3/§8.4에 정의된 시나리오는 **배포 후 수동 브라우저 테스트**로 검증 필요(아래 §9 참조).

**Runtime Match Rate**: N/A (static-only formula 적용)

### 2.8 Match Rate Summary

```
┌─────────────────────────────────────────────┐
│  Structural Match Rate:  100%                │
│  Functional Match Rate:  100%                │
│  Contract Match Rate:    100%                │
│  Runtime Match Rate:     N/A (미실행 — 수동 배포 환경) │
│  ─────────────────────────────────────────── │
│  Overall Match Rate:     100%                │
│  = (Structural × 0.2) + (Functional × 0.4)  │
│    + (Contract × 0.4)   [static-only formula]│
├─────────────────────────────────────────────┤
│  ✅ Match:          10 items (100%)          │
│  ⚠️ Shallow:        0 items (0%)             │
│  ❌ Not implemented: 0 items (0%)            │
└─────────────────────────────────────────────┘
```

---

## 3. Code Quality Analysis

### 3.1 Complexity

| File | Function | Complexity | Status |
|------|----------|:----------:|--------|
| `romanize.php` | `mall_romanize_korean_name` | 낮음(단순 순회+분기) | ✅ Good |
| `romanize.php` | `mall_romanize_utf8_to_codepoints` | 중간(바이트 디코딩 분기 4갈래) | ✅ Good — UTF-8 디코더 표준 패턴 |
| `order.php` | `mall_create_order` | 기존과 동일 + 소규모 블록 추가 | ✅ Good — 기존 함수 복잡도를 크게 늘리지 않음 |

### 3.2 Code Smells

발견된 항목 없음. 기존 파일 컨벤션(try/catch, prepared statement, `mall_` 접두사)을 그대로 재사용.

### 3.3 Security Issues

| Severity | File | Location | Issue | Recommendation |
|----------|------|----------|-------|----------------|
| 🟢 Info | `mall/lib/order.php` | 자동 채움 UPDATE | prepared statement 사용, SQLi 위험 없음 | 조치 불필요 |
| 🟢 Info | `mall/lib/romanize.php` | 전체 | 외부 네트워크 호출 없음 — 개인정보(이름) 비유출 확인(Plan NFR "Privacy" 충족) | 조치 불필요 |
| 🟢 Info | `mall/signup.php` | 입력 필드 출력 | 기존과 동일하게 `htmlspecialchars()` 처리 확인 | 조치 불필요 |

Critical/Warning 없음.

---

## 4. Clean Architecture Compliance

> Reference: Design §9

### 4.1 Layer Dependency Verification

| Layer | Expected Dependencies | Actual Dependencies | Status |
|-------|----------------------|---------------------|--------|
| Presentation (`signup.php`) | Application(`auth.php`) | `require_once __DIR__ . '/lib/auth.php'` 그대로, `romanize.php` 직접 호출 없음 | ✅ |
| Application/Infra (`order.php`) | Domain(`romanize.php`) | `require_once __DIR__ . '/romanize.php'` 신규 추가 | ✅ |
| Domain (`romanize.php`) | 없음(순수 함수) | require/include 없음, DB/세션 미접근 확인 | ✅ |

### 4.2 Dependency Violations

없음 — Design §9.3 File Import Rules를 정확히 준수(특히 `romanize.php`가 DB/세션에 의존하지 않는 순수 함수로 유지됨).

### 4.3 Architecture Score

```
┌─────────────────────────────────────────────┐
│  Architecture Compliance: 100%               │
├─────────────────────────────────────────────┤
│  ✅ Correct layer placement: 4/4 files       │
│  ⚠️ Dependency violations:   0 files         │
│  ❌ Wrong layer:              0 files         │
└─────────────────────────────────────────────┘
```

---

## 5. Convention Compliance

| Category | Convention | Files Checked | Compliance |
|----------|-----------|:-------------:|:----------:|
| 함수 네이밍 | `mall_` 접두사 + snake_case | 4 | 100% |
| 파일 위치 | `mall/lib/*.php` | 1 (신규) | 100% |
| 에러 처리 | 사용자-대면 검증은 명시적, 부수 로직은 fail-open | 4 | 100% |
| DB 접근 | `get_db_connection()`(메인 DB), prepared statement | 2 | 100% — `get_store_db()`와 혼동 없음 확인 |

---

## 6. Overall Score

```
┌─────────────────────────────────────────────┐
│  Overall Score: 96/100                       │
├─────────────────────────────────────────────┤
│  Design Match:        100 points             │
│  Code Quality:        95 points              │
│  Security:            100 points             │
│  Testing:             70 points (런타임 미검증)│
│  Architecture:        100 points             │
│  Convention:          100 points             │
└─────────────────────────────────────────────┘
```

Match Rate(정적) 100%이나, 실제 브라우저/DB 환경에서의 런타임 검증이 아직 이루어지지 않아 "Testing" 항목만 낮게 반영 — 종합적으로 **90% 게이트를 충족**하므로 QA/Report 단계 진행 가능. 단, 배포 직후 §9 체크리스트 수동 확인을 권장.

---

## 7. Recommended Actions

### 7.1 Immediate (배포 직후, 24시간 내)

| Priority | Item | 확인 방법 |
|----------|------|----------|
| 🟡 1 | 일반 가입 폼: 영문 이름 비우고 제출 → 에러 메시지 노출 확인 | 브라우저 수동 테스트 |
| 🟡 2 | 구글 가입 폼: 영문 이름 비우고 제출 → 에러 확인 | 브라우저 수동 테스트 |
| 🟡 3 | 기존 회원(영문 이름 없음)으로 로그인 후 주문 완료 → `mall_members.english_name` DB값 확인 | phpMyAdmin/DB 조회 |

### 7.2 Short-term (선택)

| Priority | Item | Notes |
|----------|------|-------|
| 🟢 1 | 흔한 성씨(김/이/박/최/정 등) 로마자 변환 결과 실제 확인 | Design §8.2 샘플 케이스 참고, 부정확하면 프로필에서 수정 가능하므로 Blocking 아님 |

### 7.3 Long-term (backlog)

없음 — Plan Out of Scope 항목(일괄 배치 변환, 외부 API)은 의도적으로 범위 밖.

---

## 8. Design Document Updates Needed

없음 — 구현이 Design 문서와 100% 일치.

---

## 9. Next Steps

- [x] Critical/Important 이슈 없음 — 수정 불필요
- [ ] 배포 후 §7.1 체크리스트 수동 확인
- [ ] 완료 보고서 작성(`mall-member-english-name.report.md`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial analysis (static-only, Match Rate 100%) | whdans007 |
