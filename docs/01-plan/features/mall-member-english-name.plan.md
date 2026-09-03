---
template: plan
version: 1.3
feature: mall-member-english-name
date: 2026-08-30
author: whdans007
project: HOME K MART
version_project: '-'
---

# mall-member-english-name Planning Document

> **Summary**: 회원가입 시 영문 이름을 필수 입력으로 받고, 이미 가입된 회원 중 영문 이름이 없는 경우 주문 확정 시점에 한글 이름을 로마자 표기법으로 자동 변환해 채워 넣는다.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-08-30
> **Status**: Draft

---

## Executive Summary

| Perspective | Content |
|-------------|---------|
| **Problem** | 배송기사·캐셔 등 실무 담당자는 영어만 사용하는데, 회원가입(`mall/signup.php`)이 영문 이름을 받지 않아 `mall_members.english_name`이 비어있는 회원이 많고, 피킹슬립·기사앱 등에서 영문 이름을 확인할 방법이 없다. |
| **Solution** | 회원가입(일반 + 구글 가입 모두)에 영문 이름 입력을 필수화하고, 이미 가입된 회원이 처음 주문을 확정할 때 `english_name`이 비어 있으면 한글 이름을 국어의 로마자 표기법(Revised Romanization)으로 서버에서 직접 계산해 `mall_members.english_name`에 영구 저장한다(외부 API 호출 없음). |
| **Function/UX Effect** | 신규 가입자는 가입 즉시 영문 이름을 갖게 되고, 기존 회원은 별도 조치 없이 다음 주문 시 자동으로 영문 이름이 채워진다(프로필에서 언제든 확인/수정 가능). 피킹슬립·기사앱 등 실무 화면은 항상 영문 이름을 신뢰하고 사용할 수 있게 된다. |
| **Core Value** | 필리핀 등 영어만 사용하는 배송/매장 인력이 주문자 이름을 정확히 인지할 수 있어 배송 오류·혼선이 줄어든다. |

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

### 1.1 Purpose

모든 쇼핑몰 회원이 예외 없이 영문 이름을 보유하게 만들어, 영어만 사용하는 배송·매장 인력이 참조하는 모든 화면(피킹슬립, 기사 앱, 관리자 주문 상세)에서 이름을 정확히 인지할 수 있게 한다.

### 1.2 Background

- `mall_members.english_name` 컬럼은 이미 존재하고(`mall/mypage/profile.php`에서 필수 입력으로 편집 가능), 관리자가 "필리핀 배송 시 사용" 목적으로 추가를 요청했었다.
- 그러나 `mall/signup.php`(일반 가입)와 구글 가입 플로우(`mall_google_signup()`) 모두 애초에 영문 이름을 받지 않는다 — 기존/신규 회원 모두 프로필 화면을 스스로 방문해 채워 넣지 않는 한 계속 비어있다.
- 사용자 요청: "회원가입 시 반드시 영문 이름을 기입하도록" + "영문 이름이 없는 경우 주문 시 자동으로 로마자 변환".

### 1.3 Related Documents

- 관련 코드: `mall/signup.php`, `mall/lib/auth.php`(`mall_google_signup`), `mall/lib/order.php`(`mall_create_order`), `mall/mypage/profile.php`(기존 영문 이름 편집)

---

## 2. Scope

### 2.1 In Scope

- [ ] `mall/signup.php` 일반 가입 폼에 영문 이름 필수 입력 추가 (프론트 `required` + 서버 검증)
- [ ] 구글 가입(추가정보 입력) 폼에도 영문 이름 필수 입력 추가, `mall_google_signup()`에 파라미터 추가
- [ ] 한글 이름 → 로마자 자동 변환 함수(`mall/lib/romanize.php`, 국어의 로마자 표기법 기반, 외부 API 미사용)
- [ ] `mall_create_order()`에서 주문 생성 시 `member.english_name`이 비어있으면 자동 변환 결과를 `mall_members.english_name`에 영구 저장
- [ ] 자동 변환된 값도 기존 프로필 화면에서 확인/수정 가능함을 보장(기존 UI 재사용, 신규 UI 불필요)

### 2.2 Out of Scope

- 이미 가입된 회원 전체에 대한 일괄/배치 변환(첫 주문 시점 지연 계산으로 충분 — 필요 시 별도 배치 스크립트를 후속으로 논의)
- 외부 번역/음역 API 연동(정확도보다 오프라인·무비용·개인정보 비유출을 우선)
- 배송지(`mall_addresses.recipient_name`)나 주문 스냅샷(`mall_orders.ship_recipient_name`)의 영문화 — 이번 범위는 회원 계정의 `english_name`만 다룬다
- 로마자 변환 알고리즘의 완전한 정확도 보장(드문 발음규칙 예외는 사용자가 프로필에서 직접 수정하는 것으로 충분)

---

## 3. Requirements

### 3.1 Functional Requirements

| ID | Requirement | Priority | Status |
|----|-------------|----------|--------|
| FR-01 | 일반 회원가입 시 영문 이름을 입력하지 않으면 가입이 거부된다 | High | Pending |
| FR-02 | 구글 가입(추가정보 입력 단계) 시에도 영문 이름을 입력하지 않으면 가입이 거부된다 | High | Pending |
| FR-03 | 한글 이름을 국어의 로마자 표기법 규칙으로 변환하는 서버 함수가 존재한다 | High | Pending |
| FR-04 | 주문 생성(`mall_create_order`) 시점에 회원의 `english_name`이 비어있으면 자동 변환값을 계산해 `mall_members.english_name`에 저장한 뒤 주문을 계속 진행한다 | High | Pending |
| FR-05 | 자동 변환은 주문 처리를 막거나 실패시키지 않는다(변환 실패 시에도 주문은 정상 진행) | High | Pending |

### 3.2 Non-Functional Requirements

| Category | Criteria | Measurement Method |
|----------|----------|-------------------|
| Privacy | 회원 이름이 외부 서비스로 전송되지 않는다 | 코드 리뷰 — 외부 HTTP 호출 없음 확인 |
| Reliability | 로마자 변환 로직 예외가 주문 트랜잭션을 실패시키지 않는다 | 코드 리뷰 — try/catch로 격리 |
| Backward Compatibility | 이미 영문 이름이 있는 회원은 절대 덮어쓰지 않는다 | 코드 리뷰 — `empty($member['english_name'])`일 때만 계산 |

---

## 4. Success Criteria

### 4.1 Definition of Done

- [ ] 일반/구글 가입 양쪽 모두 영문 이름 없이는 가입 완료 불가
- [ ] 영문 이름이 없는 기존 회원이 주문을 완료하면 `mall_members.english_name`이 자동으로 채워짐
- [ ] 자동 채움 이후 프로필 화면에서 값 확인 및 수정 가능
- [ ] `php -l` 전체 통과

### 4.2 Quality Criteria

- [ ] 흔한 성씨/이름 샘플(김/이/박/최/정 등)로 로마자 변환 결과 수동 검증
- [ ] 주문 생성 흐름 회귀 없음(재고/가격/배송지 스냅샷 등 기존 로직 영향 없음)

---

## 5. Risks and Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| 자동 로마자 변환이 여권 표기 등 개인이 실제 쓰는 영문 표기와 다를 수 있음 | Medium | High | 프로필 화면에서 언제든 직접 수정 가능(이미 구현됨), 자동값은 어디까지나 fallback |
| 로마자 변환 로직 버그가 주문 생성 자체를 실패시킬 위험 | High | Low | try/catch로 감싸 실패해도 주문은 계속 진행, 실패 시 english_name은 비워둔 채 로그만 남김 |
| 구글 가입 폼 변경이 기존 가입 완료 흐름을 깨뜨릴 위험 | Medium | Low | 기존 필드(상호명 등)와 동일한 검증 패턴 재사용, 변경 범위를 신규 필드 추가로 최소화 |

---

## 6. Impact Analysis

### 6.1 Changed Resources

| Resource | Type | Change Description |
|----------|------|--------------------|
| `mall/signup.php` | Presentation | 일반 가입 폼 + 구글 가입 폼에 영문 이름 필수 입력 추가 |
| `mall/lib/auth.php` (`mall_google_signup`) | Application | `$english_name` 파라미터 추가, INSERT에 반영 |
| `mall/lib/romanize.php` | Application (신규) | 한글 → 로마자 변환 함수 |
| `mall/lib/order.php` (`mall_create_order`) | Application | 주문 생성 시 `english_name` 자동 채움 로직 추가 |

### 6.2 Current Consumers

| Resource | Operation | Code Path | Impact |
|----------|-----------|-----------|--------|
| `mall_members.english_name` | READ | `mall/mypage/profile.php`, `mall/admin/order_print.php`(display_name_en과 무관, 회원명 아님) | None — 값이 채워지는 방향으로만 바뀜 |
| `mall_members.english_name` | WRITE | `mall/mypage/profile.php`(수동 수정) | None — 자동 채움은 값이 비어있을 때만 발동, 기존 수동 입력값 보존 |
| `mall_google_signup()` | CALL | `mall/signup.php`(google=1 분기) | Breaking(함수 시그니처 변경) — 유일한 호출부이므로 함께 수정 |

### 6.3 Verification

- [ ] `mall_google_signup()`의 유일한 호출부(`mall/signup.php`)가 새 파라미터와 함께 수정되었는지 확인
- [ ] 영문 이름이 이미 있는 기존 회원으로 주문 생성 시 값이 바뀌지 않는지 확인
- [ ] 로마자 변환 실패(예외) 상황에서도 주문이 정상 완료되는지 확인

---

## 7. Architecture Considerations

### 7.1 Project Level Selection

| Level | Characteristics | Recommended For | Selected |
|-------|-----------------|-----------------|:--------:|
| **Starter** | Simple structure | Static sites | ☐ |
| **Dynamic** | Feature-based modules | Web apps with backend | ☑ |
| **Enterprise** | Strict layer separation | High-traffic systems | ☐ |

이 프로젝트는 Procedural PHP + `lib/*.php` 함수형 헬퍼 컨벤션(CLAUDE.md 기준)을 그대로 따른다 — 기존 `shopping-mall`/`mall-delivery-dispatch` 설계와 동일한 Dynamic 수준.

### 7.2 Key Architectural Decisions

| Decision | Options | Selected | Rationale |
|----------|---------|----------|-----------|
| 로마자 변환 방식 | 자체 알고리즘 / 외부 API | 자체 알고리즘 | 사용자 선택 — 무비용, 오프라인, 개인정보 비유출 |
| 자동 변환 결과 저장 위치 | 영구 저장(회원 프로필) / 주문별 임시 계산 | 영구 저장 | 사용자 선택 — 한 번 계산하면 이후 재계산 불필요, 모든 화면에서 일관되게 사용 |
| 구글 가입 필수화 범위 | 구글 가입 포함 / 일반 가입만 | 구글 가입 포함 | 사용자 선택 — 가입 경로 무관하게 규칙 일관 적용 |

### 7.3 Clean Architecture Approach

```
Selected Level: Dynamic (Procedural PHP)

mall/signup.php (Presentation)
    │ calls
    ▼
mall/lib/auth.php: mall_google_signup($google, $member_type, $business_name, $business_reg_no, $english_name)
mall/lib/romanize.php: mall_romanize_korean_name($name) → string
    │ used by
    ▼
mall/lib/order.php: mall_create_order() — english_name 비어있으면 romanize 호출 후 UPDATE mall_members
```

---

## 8. Convention Prerequisites

### 8.1 Existing Project Conventions

- [x] `CLAUDE.md`에 프로젝트 컨벤션 존재(Procedural PHP + lib 헬퍼)
- [ ] 별도 `docs/01-plan/conventions.md` 없음 — CLAUDE.md로 대체
- [ ] ESLint/Prettier/TypeScript 설정 — 해당 없음(PHP 프로젝트)

### 8.2 Conventions to Define/Verify

| Category | Current State | To Define | Priority |
|----------|---------------|-----------|:--------:|
| 함수 네이밍 | 존재 | `mall_` 접두사 + snake_case 유지 | High |
| 에러 처리 | 존재 | 로마자 변환 실패는 `error_log` + fallback(빈 값 유지)로 흡수, 주문 실패로 전파 금지 | High |

---

## 9. Next Steps

1. [ ] Write design document (`mall-member-english-name.design.md`)
2. [ ] 로마자 변환 알고리즘 상세 매핑 테이블 설계
3. [ ] 구현 시작 (`/pdca do mall-member-english-name`)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-30 | Initial draft | whdans007 |
