---
template: design
version: 1.0
feature: fresh-curation-price-modal
date: 2026-09-12
author: whdans007
project: HOME K MART
version_project: '-'
---

# fresh-curation-price-modal Design Document

> **Summary**: `mall/admin/products.php` 신선상품 큐레이션 행에 "가격 설정" 모달을 추가해, 낱개는 개수(N) ⇄ N개 총액, 무게는 100g당 ⇄ 선택 무게(g) 총액을 양방향으로 즉시 환산해 보여주고, "표에 적용" 시 기존 원가/기준도매가/기준판매가 입력칸에 1개당(또는 100g당) 값을 채워 넣는다. 신규 컬럼/신규 엔드포인트 없음.
>
> **Project**: HOME K MART
> **Version**: -
> **Author**: whdans007
> **Date**: 2026-09-12
> **Status**: Draft
> **Planning Doc**: [fresh-curation-price-modal.plan.md](../01-plan/features/fresh-curation-price-modal.plan.md)

### Pipeline References (if applicable)

| Phase | Document | Status |
|-------|----------|--------|
| Phase 1 | Schema Definition | N/A (미사용, 스키마 변경 없음) |
| Phase 2 | Coding Conventions | N/A (CLAUDE.md 컨벤션으로 대체) |
| Phase 3 | Mockup | N/A |
| Phase 4 | API Spec | N/A — 기존 `save_fresh_curation.php` action=update를 무변경 재사용 |

---

## Context Anchor

> Plan 문서에서 복사

| Key | Value |
|-----|-------|
| **WHY** | 낱개/무게 신선상품의 판매 단위별 가격을 암산 없이 빠르게 산정하고 싶음 |
| **WHO** | `mall/admin/products.php`에서 신선상품 큐레이션 가격을 관리하는 관리자(`mall_management` 권한 보유자) |
| **RISK** | 계산기 결과와 실제 저장되는 값의 "단위"가 다르면(예: 총액을 개당가격 자리에 저장) 고객 결제 금액이 왜곡됨 → 모달은 항상 기존 필드와 동일한 단위(1개당/100g당)로만 값을 채워 넣는다 |
| **SUCCESS** | "가격 설정" 버튼 → 개수/무게 또는 총액 입력 시 1개당/100g당 원가·기준도매가·기준판매가가 계산됨 → "표에 적용" 시 기존 입력칸에 반영되고 기존 저장 버튼으로 정상 저장됨 |
| **SCOPE** | v1: 모달 UI + 양방향(단가⇄총액) 계산기 + 기존 표 입력칸·저장 흐름 재사용. 스키마/엔드포인트 변경 없음 |

> ⚠️ **Plan 문서와의 차이**: Plan 초안(v0.1)에서는 낱개 상품에 `piece_pack_size` 신규 컬럼을 추가해 "N개입 패키지를 새 판매 단위로 저장"하는 방향을 검토했으나, Codex 2차 검토에서 신선상품 매입등록 화면(`admin/add_fresh_purchase_item.php` 등)의 자동 가격재계산 로직과 충돌함이 발견되어 **폐기**되었다(Plan v1.1 최종). 이 Design 문서는 **Plan 최종본(스키마 변경 없음)을 기준**으로 작성한다.

---

## 1. Overview

### 1.1 Design Goals

- 신선상품 큐레이션 행에서 "개수/무게를 알면 얼마인지", "얼마를 받고 싶으면 단가를 얼마로 해야 하는지"를 암산 없이 즉시 확인
- 기존 스키마(`mall_fresh_products`), 기존 저장 엔드포인트(`ajax/save_fresh_curation.php` action=update), 기존 고객 결제 로직(`mall/lib/fresh_cart.php`)을 **전혀 변경하지 않는다**
- 기존 `mall/admin/products.php`의 코드 스타일(순수 JS, TailwindCSS, 인라인 `<script>`, 기존 모달 패턴)을 그대로 재사용한다

### 1.2 Design Principles

- **핵심 로직은 단일 파일**: 모달 마크업 + JS 계산 로직은 `mall/admin/products.php` 한 파일 안에서 완결된다(§2.0 Option A 채택). 다국어 텍스트 키(`lang/ko.json`/`lang/en.json`)는 이 프로젝트의 기존 관례(§CLAUDE.md "언어 다중화 지원")상 별도로 추가하며, 이는 코드/로직 파일이 아닌 번역 사전이라 "핵심 로직 단일 파일" 원칙과 배치되지 않는다
- **양방향 바인딩으로 정방향/역방향을 하나의 UI로 통합**: Plan에서 "정방향 미리보기"와 "역방향 단가 역산"을 별도 기능처럼 서술했으나, 실제로는 "1개당(또는 100g당) 값"과 "N개(또는 선택 무게) 총액" 두 입력을 서로 연동시키면 하나의 입력쌍으로 양쪽 요구를 동시에 만족한다 — 이번 Design의 핵심 단순화
- **저장 시점 분리 유지**: 모달은 "표에 적용"까지만 하고, 실제 DB 저장은 기존 표의 저장 버튼이 담당 — 두 책임을 분리해 기존 검증/에러 처리 로직을 100% 재사용
- **기존 모달 패턴 재사용(단, 배경 클릭 닫기는 조건부)**: `category-manage-modal`(`mall/admin/products.php:943`)과 동일한 `fixed inset-0 bg-gray-900 bg-opacity-50 hidden z-50 flex` 토글 방식과 Esc 닫기(`image-preview-modal` 패턴, `products.php:1022-1034`)를 재사용한다. 단, `image-preview-modal`은 모달 내부 어디를 클릭해도 닫히는 구조(이미지 하나만 있어 무방)라 그대로 복사하면 안 되고, `e.target === modal`(오버레이 자체를 클릭했을 때만)로 제한해야 한다(Codex 검토 반영, 2026-09-12) — 내부 input 클릭 시 모달이 닫히면 안 되기 때문
- **새 모달은 독립 마크업으로 추가**: `#price-calc-modal`은 `category-manage-modal`의 권한 조건문(`products.php:942-1001`) 안에 넣지 않고 별도 블록으로 추가한다(Codex 검토 반영)

---

## 2. Architecture Options

### 2.0 Architecture Comparison

| Criteria | Option A: 기존 파일 전부 인라인 | Option B: 별도 JS 파일 분리 | Option C: 별도 PHP include 컴포넌트 |
|----------|:-:|:-:|:-:|
| **Approach** | 모달 마크업+JS를 `products.php` 기존 `<script>` 블록에 추가 | `mall/admin/assets/fresh-price-modal.js`로 분리해 `<script src>`로 로드 | 모달 마크업을 별도 `.php` partial로 분리해 include |
| **New Files** | 0 | 1 | 1 |
| **Modified Files** | 1 (`products.php`) | 2 | 2 |
| **Complexity** | Low | Medium | Medium |
| **기존 관례 부합** | 100% — 이 파일은 이미 모든 JS가 인라인(1700줄+), 다른 모달(`category-manage-modal`, `image-preview-modal`)도 전부 같은 파일에 인라인 | 낮음 — 이 프로젝트/이 파일에 JS 분리 관례 없음 | 낮음 — 이 파일에 partial include 관례 없음 |
| **Risk** | Low | Low | Low |
| **Recommendation** | **Default choice** | 비권장(이종 관례 도입) | 비권장(이종 관례 도입) |

**Selected**: Option A — **Rationale**: 사용자 확인 완료(2026-09-12). `mall/admin/products.php`는 이미 모든 모달(`category-manage-modal`, `image-preview-modal`)과 JS 로직이 한 파일에 인라인되어 있는 일관된 구조다. 이번 기능도 같은 파일 하나로 끝나는 범위(§Plan 6.1)라 굳이 새 관례를 도입할 이유가 없다.

### 2.1 Component Diagram

```
┌──────────────────────────────────────────────────────────────────────┐
│ mall/admin/products.php (이번 기능의 로직이 추가되는 유일한 코드 파일) │
│                                                                        │
│  [신선상품 행] "가격 설정" 버튼 (row_type='fresh'에만 노출)             │
│        │ onclick                                                     │
│        ▼                                                              │
│  openPriceCalcModal(row)  ─── row.querySelector로 현재 표시값 읽음     │
│        │                                                              │
│        ▼                                                              │
│  #price-calc-modal (신규 마크업, category-manage-modal과 동일 패턴)    │
│    ├─ 낱개: 개수(N) input ⇄ N개 총액 input (양방향, 원가/도매가/판매가 │
│    │        3세트 각각)                                               │
│    └─ 무게: 무게(g) input ⇄ 선택무게 총액 input (양방향, 3세트)        │
│        │ "표에 적용" 클릭                                             │
│        ▼                                                              │
│  row.querySelector('.edit-cost-price').value = ...  (기존 input 갱신) │
│        │                                                              │
│        ▼                                                              │
│  기존 "저장" 버튼(.save-curated-btn) 클릭 (관리자가 직접, 자동 아님)   │
│        │                                                              │
│        ▼                                                              │
│  fetch('ajax/save_fresh_curation.php', action=update)  ── 무변경 ──▶  │
│        │                                                              │
│        ▼                                                              │
│  mall_fresh_products.cost_price_override /                           │
│  wholesale_reference_price_override / price_per_100g  (무변경 의미)  │
└──────────────────────────────────────────────────────────────────────┘
```

### 2.2 Data Flow

```
[열기] "가격 설정" 클릭 → openPriceCalcModal(row)이 현재 행의
       edit-cost-price / edit-wholesale-reference-price / edit-selling-price
       input.value와 data-original-cost-price(신규 data attribute, §3 참고),
       data-sale-type을 읽어 모달 초기값으로 채움

[낱개 계산] N(개수) 입력 → 1개당원가 × N = N개 총원가 (자동 갱신)
            N개 총원가를 직접 수정 → 1개당원가 = 총원가 ÷ N (역산, 자동 갱신)
            기준도매가/기준판매가는 원가와 완전히 독립된 각자의 1개당⇄총액 양방향 쌍으로 관리한다
            (원가를 바꿔도 도매가/판매가 쌍은 자동으로 덮어쓰지 않음 — 아래 참고)

[무게 계산] 무게(g, 100 단위) 입력 → 100g당원가 × (무게/100) = 선택무게 총원가
            선택무게 총원가를 직접 수정 → 100g당원가 = 총원가 ÷ (무게/100)
            원가/도매가/판매가 3세트 모두 낱개와 동일한 양방향 로직, 단위만 100g당

> **Codex 검토 반영 — 도매가 자동 재계산 제거**: 최초 설계는 "원가가 바뀌면 도매가도 `ceil(원가×(1+markup))`로 자동 재계산"했으나, 기존 표 로직은 `wholesale_reference_price_override`가 있으면 그 override 값이 항상 우선이다(`products.php:610`). 모달을 열 때마다 혹은 원가를 바꿀 때마다 도매가를 자동으로 덮어쓰면 관리자가 의도적으로 넣어둔 도매가 override를 조용히 지워버릴 수 있다. 따라서:
> - 원가/도매가/판매가 3개의 1개당⇄총액 쌍은 **서로 독립적으로** 양방향 계산된다(원가를 바꿔도 도매가·판매가 쌍은 그대로 유지)
> - 도매가를 "원가 기준으로 다시 계산하고 싶을 때"만 쓰는 별도 버튼 **"도매가 자동계산"**(원가 1개당 값 × (1+markup) → ceil)을 도매가 쌍 옆에 추가해, 명시적으로 눌렀을 때만 덮어쓴다

[적용] "표에 적용" 클릭 → 모달의 "1개당/100g당" 값 3개(원가/도매가/판매가)를
       row의 기존 input 3개에 그대로 대입 → 모달 닫힘. DB 저장은 아직 안 됨

[저장] 관리자가 기존 "저장" 버튼 클릭(기존 흐름, 변경 없음) → 기존 검증/AJAX
```

### 2.3 Dependencies

| Component | Depends On | Purpose |
|-----------|-----------|---------|
| `#price-calc-modal` 마크업 | TailwindCSS (기존 로드됨), FontAwesome (기존 로드됨) | 기존 모달과 동일한 시각 스타일 |
| `openPriceCalcModal()` JS | 행의 기존 input 값, `window.MALL_WHOLESALE_MARKUP_RATE`(신규 전역 변수, §3) | 계산 기준값 확보 |
| "표에 적용" 핸들러 | 기존 `.edit-cost-price` 등 input 요소 | 값 대입만 하고 별도 저장 API 호출 없음 |

---

## 3. Data Model

> 스키마 변경 없음. 이 절은 "모달 계산에 필요한데 현재 DOM에 없는 값"을 어떻게 노출할지만 다룬다.

### 3.1 신규로 필요한 데이터 노출 (DB 컬럼 아님 — 렌더링 시 data attribute로만 추가)

현재 `mall/admin/products.php`는 "최근 매입원가"(`original_cost_price`)를 override가 있을 때만 힌트(`text-gray-400`)로 보여준다(`products.php:655-657`). 모달은 override 여부와 무관하게 항상 이 값이 필요하므로, 행 렌더링 시 data attribute로 항상 노출한다.

> **Codex 검토 반영**: `original_cost_price`는 매입 조회(287-292행) → sale_type별 선택(411행) → 배정(428행) → 행 변수(599행)로 정확히 이어지는 값이 맞다. 다만 이 값이 NULL인 경우는 "이 상품에 매입 이력이 전혀 없다"가 아니라 "가장 최근 매입 행의 해당 sale_type 원가 컬럼이 NULL이다"를 의미할 수 있다(예: 과거 데이터 이관 등으로 최신 행에만 값이 비어있는 경우). 따라서 UI 문구는 "매입 이력 없음"이 아니라 "최근 매입원가를 확인할 수 없음"으로 표현하고, 원가 계산 영역을 비활성화하되 **기존에 저장된 override 값은 절대 지우지 않는다**(§6.1 갱신).

```php
// mall/admin/products.php, 신선상품 <tr> 렌더링부(약 632-637행 근처)에 추가
data-original-cost-price="<?php echo $original_cost_price !== null ? $original_cost_price : ''; ?>"
data-sale-type="<?php echo htmlspecialchars($c['sale_type'] ?? ''); ?>"
```

`sale_type`은 일반상품 행에는 없는 값이므로 신선상품 행(`row_type='fresh'`)에만 추가한다.

### 3.2 마크업률 전역 노출

> **Codex 검토 반영**: `window.MALL_CSRF_TOKEN`은 이 파일이 아니라 `mall/admin/partials/sidebar.php:81`에서 선언된다(이 파일은 `include`로 그 partial을 불러 쓴다, `products.php:477`). 이번 기능은 `products.php` 범위를 유지하기 위해 CSRF 선언부 근처가 아니라, `products.php` 자체의 기존 `<script>` 블록 시작 부분(약 1003행 근처)에 새 전역 변수를 선언한다.

```php
// mall/admin/products.php의 기존 <script> 블록 시작부(약 1003행 근처)에 추가
window.MALL_WHOLESALE_MARKUP_RATE = <?php echo json_encode($wholesale_reference_markup_rate); ?>;
```

### 3.3 Database Schema

변경 없음. `mall_fresh_products`, `fresh_purchase_items` 등 기존 테이블 그대로.

---

## 4. API Specification

변경 없음. 기존 `mall/admin/ajax/save_fresh_curation.php` action=update를 파라미터 변경 없이 그대로 사용한다(§1 Plan 6.1/6.2 참고). 이번 기능은 신규 엔드포인트를 만들지 않는다.

---

## 5. UI/UX Design

### 5.1 Screen Layout (모달)

```
┌──────────────────────────────────────────────┐
│  가격 설정 — {상품명}                    [x]   │
├──────────────────────────────────────────────┤
│  [낱개 상품인 경우]                            │
│   개수:      [ -  N  + ] 개                    │
│                                                │
│              1개당        │  N개 총액          │
│   원가        [_______]   │  [_______]         │
│   기준도매가   [_______]   │  [_______] [도매가 자동계산] │
│   기준판매가   [_______]   │  [_______]         │
│                                                │
│  [무게 상품인 경우]                            │
│   무게:      [ -  300 + ] g                    │
│                                                │
│              100g당       │  선택 무게 총액     │
│   원가        [_______]   │  [_______]         │
│   기준도매가   [_______]   │  [_______] [도매가 자동계산] │
│   기준판매가   [_______]   │  [_______]         │
│                                                │
│  ※ 최근 매입원가를 확인할 수 없으면 원가       │
│     계산은 비활성화되고 기존 저장값은 그대로   │
│     유지됩니다(직접 입력은 계속 가능)          │
│  ※ 반올림으로 실제 계산 결과와 최대 N×0.01원   │
│     오차가 있을 수 있습니다                    │
│                                                │
│              [ 취소 ]      [ 표에 적용 ]        │
└──────────────────────────────────────────────┘
```

### 5.2 User Flow

```
신선상품 행 "가격 설정" 클릭 → 모달 열림(현재값으로 초기화)
  → (선택) 개수/무게 조정 또는 총액 입력 → 실시간 재계산
  → "표에 적용" 클릭 → 모달 닫힘, 표의 3개 입력칸 갱신(미저장 상태)
  → 관리자가 표의 기존 "저장" 버튼 클릭 → 실제 DB 저장(기존 흐름)
```

### 5.3 Component List

| Component | Location | Responsibility |
|-----------|----------|----------------|
| "가격 설정" 버튼 | `mall/admin/products.php`, 신선상품 행 판매가 셀 근처 | 모달 오픈 트리거, `row_type==='fresh'`일 때만 렌더링 |
| `#price-calc-modal` | `mall/admin/products.php`, 기존 모달들과 같은 위치(예: `category-manage-modal` 근처) | 계산기 UI 컨테이너 |
| `openPriceCalcModal(row)` | 인라인 `<script>` | 모달 초기화(현재값 로드), sale_type별 UI 분기 |
| `recalcFromUnit()` / `recalcFromTotal()` | 인라인 `<script>` | 1개당⇄총액 양방향 계산(원가/도매가/판매가 각각 독립) |
| `recalcWholesaleFromCost()` | 인라인 `<script>` | "도매가 자동계산" 버튼 클릭 시에만 원가 기준으로 도매가 쌍을 덮어씀 |
| `applyToRow()` | 인라인 `<script>` | 계산 결과를 표의 기존 input에 대입 후 모달 닫기 |

### 5.4 Page UI Checklist

#### 신선상품 큐레이션 표 (`mall/admin/products.php` "큐레이션된 상품" 섹션)

- [ ] Button: "가격 설정" (신선상품 행에만, 판매가 셀 근처 아이콘 또는 텍스트 버튼)
- [ ] Modal: `#price-calc-modal` (기존 모달과 동일한 `fixed inset-0` 오버레이 스타일)
- [ ] Modal Header: 상품명 표시 + 닫기(×) 버튼
- [ ] Modal Input (piece): 개수(N) 스테퍼(-/+, 최소 1, 정수만)
- [ ] Modal Input (weight): 무게(g) 스테퍼(-/+, 100 단위, 최소 100)
- [ ] Modal Input Pair ×3(원가/기준도매가/기준판매가): 1개당(또는 100g당) 입력 + N개(또는 선택무게) 총액 입력, 각 쌍은 서로 독립적으로 실시간 연동
- [ ] Button: "도매가 자동계산" (도매가 쌍 옆, 클릭 시에만 원가 기준으로 재계산해 덮어씀 — 원가 변경 시 자동 실행 아님)
- [ ] Modal 안내 문구: 최근 매입원가를 확인할 수 없을 때 원가 계산 비활성 + 기존 저장값 유지 안내
- [ ] Modal 안내 문구: 반올림 오차 가능성 안내
- [ ] Button: "취소"(모달 닫기, 표 변경 없음)
- [ ] Button: "표에 적용"(계산값을 표 input에 반영 후 모달 닫기)

---

## 6. Error Handling

### 6.1 Error Code Definition

> 모달 자체는 서버 호출이 없어(순수 클라이언트 계산) 신규 에러 코드가 없다. 저장 단계는 기존 `save_fresh_curation.php`의 기존 에러 처리(`VALIDATION_ERROR` 등)를 그대로 사용한다.

| Case | Handling |
|------|----------|
| 최근 매입원가를 확인할 수 없음(`data-original-cost-price=""`) | 원가 쌍의 "N개/무게 총액" 자동계산 보조만 비활성화. **1개당 입력칸은 계속 활성 상태로 두고, 모달을 열 때 표에 이미 저장돼 있는 원가 값(override 또는 오리지널)을 그대로 초기값으로 채운다** — 절대 빈 값/0으로 지우지 않는다 |
| N 또는 무게에 0 이하 값 입력 | 클라이언트에서 최소값(1 또는 100)으로 보정, 계산은 항상 양수 기준으로만 수행 |
| 총액 입력값이 비정상(음수, 텍스트 등) | 역산 계산을 건너뛰고 이전 유효값 유지(입력 필드는 사용자가 고칠 때까지 그대로 둠) |
| 모달을 연 채로 다른 행의 "가격 설정"을 다시 클릭 | 발생하지 않음 — 버튼 클릭 시 모달이 이미 열려 있으면 먼저 닫고 새 대상으로 재오픈(§ 상태 관리 참고) |

### 6.2 저장 단계 에러

기존 `save_fresh_curation.php`가 이미 처리(`VALIDATION_ERROR`: "가격은 0 이상이어야 합니다" 등) — 변경 없음.

### 6.3 모달 상태 관리 (Codex 검토 반영)

- 페이지에는 신선상품 행마다 모달을 따로 만들지 않고, **`#price-calc-modal` 하나를 페이지에 한 번만 두고 재사용**한다. "가격 설정" 버튼 클릭 시 대상 `row`(DOM 참조)를 모달의 상태 변수(예: `let currentTargetRow = null;`)에 저장한다
- 모달을 열 때마다 이전 상태를 남기지 않도록 **입력값·단위 라벨(1개당/100g당)·비활성 상태를 항상 새로 초기화**한다
- 이벤트 리스너(버튼 클릭, 입력 변경, 배경 클릭, Esc)는 페이지 로드 시 **한 번만 바인딩**하고, 행마다 반복 바인딩하지 않는다(신선상품 행이 여러 개여도 리스너가 중복 등록되지 않도록)
- "취소"로 닫은 뒤 다른 행에서 다시 열어도 이전 행의 값이 새 모달에 남아있지 않아야 한다(위 초기화로 보장)

---

## 7. Security Considerations

- [x] 신규 서버 엔드포인트 없음 — 모달은 순수 클라이언트 계산이라 XSS/인젝션 표면 추가 없음(값은 항상 숫자 input, 저장 시점에 기존 서버 검증 통과)
- [x] 권한: 기존과 동일하게 `mall_management` 권한 필요(페이지 접근 자체가 이미 보호됨, 모달은 페이지 내부 UI일 뿐)
- [x] CSRF: 실제 저장은 기존 저장 버튼의 기존 `csrf_token` 흐름을 그대로 사용, 모달에서 별도 요청 없음
- [ ] N/A: Rate Limiting, HTTPS 등 이번 기능과 무관

---

## 8. Test Plan

### 8.1 Test Scope

| Type | Target | Tool | Phase |
|------|--------|------|-------|
| L1: 계산 로직 단위 확인 | JS 계산 함수(1개당⇄총액, ceil 마크업) | 수동 브라우저 콘솔 확인 (이 프로젝트에 JS 테스트 러너 없음) | Do |
| L2: UI 동작 확인 | 모달 열기/닫기, 입력 연동, "표에 적용" | 수동 브라우저 테스트 | Do |
| L3: 회귀 확인 | 기존 표 인라인 입력/저장이 여전히 동작하는지 | 수동 브라우저 테스트 | Check |

> 이 프로젝트는 PHP+바닐라 JS이며 Playwright/Jest 등 자동화 테스트 러너가 구성되어 있지 않다(package.json에 CSS 빌드 스크립트만 존재). L1/L2/L3는 모두 수동 테스트로 진행한다.

### 8.2 수동 테스트 시나리오

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 낱개 상품 — 개수로 계산 | 가격 설정 클릭 → N=5 입력 | 1개당 원가는 그대로, N개 총원가가 5배로 표시 |
| 2 | 낱개 상품 — 총액으로 역산 | N=5 상태에서 "N개 총액(판매가)"에 10000 입력 | 1개당 판매가가 2000으로 역산되어 표시 |
| 3 | 무게 상품 — 무게로 계산 | 가격 설정 클릭 → 무게=300 입력 | 100g당 원가는 그대로, 선택무게 총원가가 3배로 표시 |
| 4 | 무게 상품 — 총액으로 역산 | 무게=300 상태에서 "선택무게 총액(판매가)"에 9000 입력 | 100g당 판매가가 3000으로 역산되어 표시 |
| 5 | 표에 적용 후 저장 | 계산 후 "표에 적용" → 표 input 값 확인 → 기존 "저장" 클릭 | AJAX 성공, 새로고침 후에도 값 유지 |
| 6 | 매입 이력 없는 상품 | 매입 이력 없는 신선상품에서 가격 설정 클릭 | 원가 입력쌍 비활성화 + 안내 문구, 판매가 입력쌍은 정상 동작 |
| 7 | 회귀 — 기존 인라인 입력 | 모달을 열지 않고 표에서 직접 숫자 수정 후 저장 | 기존과 동일하게 정상 저장됨 |
| 8 | 회귀 — 일반상품 행 | 일반상품(row_type='general') 행에 "가격 설정" 버튼이 없는지 확인 | 버튼 미노출 |

### 8.3 Seed Data Requirements

| Entity | Minimum Count | Key Fields Required |
|--------|:------------:|---------------------|
| `mall_fresh_products` (piece) | 1 | `sale_type='piece'`, 매입 이력 있음(정상 계산 확인용) |
| `mall_fresh_products` (piece, 매입 이력 없음) | 1 | `sale_type='piece'`, `fresh_purchase_items`에 매칭 행 없음(비활성 케이스 확인용) |
| `mall_fresh_products` (weight) | 1 | `sale_type='weight'`, 매입 이력 있음 |

---

## 9. Clean Architecture

> 이 프로젝트는 Next.js 계층 구조를 쓰지 않는 PHP 서버 렌더링 프로젝트다. CLAUDE.md 컨벤션(파일 조직: `admin/`, `lib/`, `mall/admin/`)을 그대로 따른다.

### 9.1 이 기능의 파일 배치

| Component | 역할 | Location |
|-----------|------|----------|
| 모달 마크업 + 계산 JS | Presentation(관리자 UI) | `mall/admin/products.php` (기존 파일 내부, §2.0 Option A) |
| `data-original-cost-price`/`data-sale-type` 추가 | 기존 렌더링 로직 확장 | `mall/admin/products.php` (신선상품 `<tr>` 렌더링부) |
| 저장 로직 | 기존 재사용, 무변경 | `mall/admin/ajax/save_fresh_curation.php` |

> 신규 계층/신규 파일이 없어 §9.2~9.4(레이어 의존성 규칙, 파일 임포트 규칙)는 이번 기능에는 해당 없음(N/A) — 기존 파일 내부에서 완결되는 순수 UI 추가 작업.

---

## 10. Coding Convention Reference

### 10.1 이 기능에서 따르는 기존 컨벤션

| Item | Convention Applied |
|------|-------------------|
| 함수 네이밍 | 기존 파일의 camelCase 함수명 패턴(`toggleHomeSlotProduct`, `openPriceCalcModal` 등) |
| 모달 마크업 | 기존 `category-manage-modal`/`image-preview-modal`과 동일한 Tailwind 클래스 조합(`fixed inset-0 bg-gray-900 bg-opacity-50 hidden z-50 flex`) |
| 다국어 | 새 UI 텍스트("가격 설정", "표에 적용" 등)는 `t()` 헬퍼로 `lang/ko.json`/`lang/en.json`에 키 추가 |
| 금액 표시 | CLAUDE.md 규칙대로 소숫점 둘째자리까지(`toFixed(2)` 또는 서버와 동일한 반올림 정책) |
| 주석 | 이 파일의 기존 관례대로 "왜"를 설명하는 한 줄 주석만(예: 936-948행대 패턴 참고) |

---

## 11. Implementation Guide

### 11.1 File Structure

```
mall/admin/products.php   (이번 기능의 로직이 들어가는 유일한 코드 파일)
  ├─ (신선상품 <tr> 렌더링부) data-original-cost-price, data-sale-type 속성 추가
  ├─ (판매가 셀 근처) "가격 설정" 버튼 추가 (row_type='fresh'만)
  ├─ (category-manage-modal과는 별개 블록으로) #price-calc-modal 마크업 신규 추가
  ├─ (기존 <script> 블록 시작부, 약 1003행 근처) window.MALL_WHOLESALE_MARKUP_RATE 전역 변수 추가
  └─ (기존 <script> 블록 하단) openPriceCalcModal / recalc 함수 / applyToRow 추가(리스너는 페이지 로드 시 1회만 바인딩, §6.3)

lang/ko.json, lang/en.json  (신규 UI 텍스트 키 추가만, 기존 구조는 변경 없음)
```

### 11.2 Implementation Order

1. [ ] `lang/ko.json`/`lang/en.json`에 신규 텍스트 키 추가("가격 설정", "표에 적용", "개수", "무게(g)", "1개당", "N개 총액", "100g당", "선택 무게 총액", 매입이력없음 안내, 반올림안내 등)
2. [ ] PHP 렌더링부: `data-original-cost-price`/`data-sale-type` 속성 추가 + "가격 설정" 버튼 추가
3. [ ] `window.MALL_WHOLESALE_MARKUP_RATE` 전역 변수 추가
4. [ ] `#price-calc-modal` 마크업 추가(기존 모달 패턴 복사)
5. [ ] `openPriceCalcModal(row)` 구현 — sale_type 분기, 초기값 로드, 매입이력 없음 처리
6. [ ] 양방향 계산 함수 구현(원가/도매가/판매가 3세트 × 1개당⇄총액)
7. [ ] `applyToRow()` 구현 — 계산 결과를 표 input에 대입, 모달 닫기
8. [ ] 모달 열기/닫기 트리거 배선(버튼 클릭, 배경 클릭, Esc 키 — 기존 `image-preview-modal` 패턴 재사용)
9. [ ] 수동 테스트(§8.2 8개 시나리오) 실행
10. [ ] `php -l mall/admin/products.php` 린트 확인

### 11.3 Session Guide

이번 기능은 단일 파일 내 UI 추가 작업으로 규모가 작아(신규 컬럼/엔드포인트 없음) 세션 분할 없이 한 번에 구현 가능하다.

#### Module Map

| Module | Scope Key | Description | Estimated Turns |
|--------|-----------|-------------|:---------------:|
| 전체 구현 | `module-1` | lang 키 + 렌더링 속성/버튼 + 모달 마크업 + JS 계산/배선 | 15-25 |

#### Recommended Session Plan

| Session | Phase | Scope | Turns |
|---------|-------|-------|:-----:|
| Session 1 | Plan + Design | 전체 (완료) | - |
| Session 2 | Do | `--scope module-1` (전체) | 15-25 |
| Session 3 | Check + Report | 전체 | 10-15 |

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-12 | Initial draft — Option A(단일 파일 인라인) 채택, 1개당⇄총액 양방향 계산 방식으로 Plan의 정방향/역방향 요구사항 통합 | whdans007 |
| 0.2 | 2026-09-12 | Codex 검토 반영 — 배경클릭 닫기 조건 수정, CSRF/전역변수 선언 위치 정정, 도매가 자동 덮어쓰기를 명시적 버튼으로 변경, 원가 없음 시 기존 override 보존, 모달 상태관리(단일 인스턴스 재사용) 절 추가 | whdans007 |
