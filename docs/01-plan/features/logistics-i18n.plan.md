# Plan: logistics 모듈 다국어(한국어/영어) 지원 추가

## 배경 / 문제

- `logistics/` (물류센터) 모듈은 `t()` 다국어 헬퍼를 **전혀 사용하지 않음** (`grep -r "\bt\("`  결과 0건). 네비게이션/버튼/안내 문구가 대부분 영어로 하드코딩되어 있고, 일부는 한글 하드코딩(`footer.php`의 팝업 알림 등).
- 반면 `admin/`, `office/`, `mall/` 등 다른 모듈은 이미 `lib/lang_helper.php` + `t('namespace.key')` + 헤더의 언어 전환 드롭다운(`ajax_set_language.php`)으로 한국어/영어 전환을 지원함 (`admin/partials/header.php:183-185`, `admin/ajax_set_language.php`).
- 사용자 요청: logistics 모듈 전체 페이지에 동일한 언어 선택 기능 적용.
- `docs/02-design/features/logistics-center.design.md`에는 다국어 관련 언급이 없음 — 의도적 설계가 아니라 단순 누락으로 판단.

## 목표

`logistics/` 하위 전체 사용자 화면(페이지/모달/버튼/안내 문구/이메일성 알림 텍스트)을 `t()` 기반으로 전환하고, 헤더에 언어 선택 드롭다운을 추가해 세션 단위로 한국어 ⇄ English 전환이 가능하게 한다.

## 기술 접근

### 인프라 (공통, 최우선)
- `logistics/lib/auth.php` 최상단에 `require_once __DIR__ . '/../../lib/lang_helper.php';` 추가 (모든 페이지가 `auth.php`를 거치므로 이 한 곳에서 `t()`/`get_language()`를 전역으로 사용 가능해짐 — require 체인 검증 필수, [[feedback_verify_codex_helper_requires]] 참고).
- `logistics/partials/header.php`에 admin과 동일한 패턴의 언어 전환 `<select>` 추가 (사이드바 상단 사용자 정보 카드 부근), JS는 admin 것을 그대로 참고해 `logistics/ajax_set_language.php`를 호출하도록 경로만 변경.
- `logistics/ajax_set_language.php` 신규 생성 — `admin/ajax_set_language.php`와 동일 내용(같은 `../lib/lang_helper.php` 상대경로가 유효함, 세션은 사이트 전역 공유이므로 admin/logistics 어디서 바꿔도 동일하게 적용됨).
- `lang/ko.json`, `lang/en.json`에 신규 최상위 네임스페이스 **`logistics`** 추가. 하위는 파일/영역별로 세분화: `logistics.nav.*`(사이드바 메뉴), `logistics.common.*`(공용 버튼/라벨), `logistics.inbound.*`, `logistics.outbound.*`, `logistics.products.*`, `logistics.suppliers.*`, `logistics.orders.*`, `logistics.requests.*`, `logistics.inventory.*`, `logistics.brand_category.*`, `logistics.export.*`, `logistics.print.*` 등. (기존 파일들 이미 이 네임스페이스 세분화 패턴을 씀 — `mall_admin.products.*` 참고.)
- 로그인/로그아웃 화면(`login.php`)도 포함.

### 범위 포함 (전체 페이지 다국어화 — 사용자 확정)
top-level 36개 페이지 중 실사용 화면 전체 + `logistics/ajax/*.php` 25개(JSON 응답의 사용자 노출 메시지 문자열)까지 포함.

### 범위 제외 (out of scope — 내부 개발/진단 도구, 운영 화면 아님)
- `diag_cancel_restore.php`, `diag_product_search.php`, `diag_qty_out2.php`, `diag_stock.php`, `diag_stock_reconcile.php`
- `check_db_schema.php`
- `test_box_pcs_unit.php`, `test_inbound_helper.php`, `test_pack_unit.php`
- `_popup_preview.html`

### 배치(batch) 전략 — Codex 세션당 파일 수를 좁게 유지해 리뷰 가능한 단위로 분할

| 배치 | 대상 파일 | 비고 |
|------|-----------|------|
| B0 인프라 | `logistics/lib/auth.php`, `logistics/partials/header.php`, `logistics/partials/footer.php`, `logistics/ajax_set_language.php`(신규), `lang/ko.json`, `lang/en.json`(네임스페이스 골격만) | 가장 먼저, 단독 실행 |
| B1 로그인/대시보드 | `login.php`, `index.php` | |
| B2 마스터데이터 | `products.php`, `product_add.php`, `product_edit.php`, `suppliers.php`, `brand_manage.php`, `category_manage.php` | |
| B3 입출고 | `inbound.php`, `inbound_add.php`, `inbound_edit.php`, `inbound_detail.php`, `inbound_items.php`, `inbound_damages.php`, `outbound.php`, `box_break.php`, `fix_inbound_supplier_sync.php` | |
| B4 지점이동/주문/요청 | `branch_outbound.php`, `branch_outbound_list.php`, `orders.php`, `order_detail.php`, `order_new.php`, `requests.php`, `request_detail.php` | |
| B5 조회/재고 | `inventory.php` | |
| B6 인쇄/엑셀 export | `print_branch_outbound.php`, `print_inbound.php`, `print_inbound_items.php`, `print_inventory.php`, `print_orders.php`, `print_outbound.php`, `print_products.php`, `export_expiry.php`, `export_inbound.php`, `export_inbound_items.php`, `export_inventory.php`, `export_products.php` | 인쇄물은 현재 세션 언어를 따르되, 필요 시 별도 고정 언어 옵션은 이번 범위에서 다루지 않음(추가 요청 시 후속 처리) |
| B7 AJAX 메시지 | `logistics/ajax/*.php` (25개) | JSON 메시지 문자열만 `t()`로 전환, 응답 구조/키는 변경 금지 |

각 배치는 별도 Codex 실행(별도 프롬프트, `.codex-logs/logistics-i18n-b{N}-*`)으로 진행하고, 배치마다 Claude Code가 `git status`로 대상 파일 외 변경 없는지 + PHP lint + JSON 유효성 확인 후 다음 배치 진행.

## 제약 조건 (모든 배치 공통)

1. DB 스키마 변경 금지.
2. 기존 AJAX 응답의 `success`/`message`/데이터 키 구조 변경 금지 — **문자열 내용만** `t()`로 치환.
3. 권한 체크(`lc_require_login`, `has_permission` 등) 로직 변경 금지.
4. 반올림/통화 표시 등 기존 CLAUDE.md 규칙(원가·합계 소숫점 둘째자리) 유지.
5. 하드코딩된 문자열을 옮길 때 새 `lang/ko.json`/`lang/en.json` 키는 반드시 두 파일 모두에 동시 추가 (누락 시 `t()`가 키를 그대로 출력하는 fallback 발생).
6. `logistics/lib/auth.php`, `logistics/partials/header.php`, `logistics/partials/footer.php`, `lang/ko.json`, `lang/en.json`은 B0에서만 수정 — 이후 배치에서 재수정 금지(충돌 방지, `docs/00-conventions/agent-orchestration.md` §4 공용파일 원칙과 동일 적용).

## 검증 (배치별)

- `php -l` 문법 검사 (대상 파일 전체)
- `lang/ko.json`, `lang/en.json` JSON 유효성 검사 + 두 파일 키 집합 동일한지 diff 비교
- `git status`로 지정 파일 외 변경 없는지 확인
- `logistics/lib/auth.php`를 거치지 않는 파일(있다면)은 자체적으로 `require_once` 체인이 `t()`에 닿는지 개별 확인 — [[feedback_verify_codex_helper_requires]]
- 브라우저 수동 확인은 Claude Code가 국문/영문 전환 후 대표 페이지(대시보드, 입고, 주문) 스크린샷으로 최종 확인

## Codex 사전 상의 결과 (2026-09-17 반영)

Codex 리뷰(읽기 전용, 코드 미수정)를 거쳐 다음을 확정/반영함:

1. **배치 재분할** — 파일 크기·한글 문자열 양 기준으로 대형 파일은 단독 배치로 분리:
   - B2 → **B2a**(`suppliers.php`, `brand_manage.php`, `category_manage.php`, `product_add.php`), **B2b**(`products.php` 단독, 59KB), **B2c**(`product_edit.php` 단독, 45KB)
   - B3 → **B3a**(`inbound.php`, `inbound_edit.php`, `inbound_detail.php`, `inbound_items.php`, `inbound_damages.php`, `outbound.php`, `box_break.php`, `fix_inbound_supplier_sync.php`), **B3b**(`inbound_add.php` 단독, 134KB·한글 문자열 약 1,200건 — 가장 큼)
   - B4 → **B4a**(`branch_outbound.php`, `branch_outbound_list.php`, `orders.php`, `order_new.php`, `requests.php`, `request_detail.php`), **B4b**(`order_detail.php` 단독, 78KB·한글 문자열 약 963건)
   - B6, B7은 기존 유지(B7은 메시지 많은 파일/단순 파일로 내부 순서만 조정 가능)
2. **네임스페이스** — logistics 화면 문자열은 예외 없이 `logistics.*`로 통일(기존 전역 `common.*`/`product.*`/`supplier.*`와 혼용 금지, 의미 겹침 방지). `logistics.print.*`(인쇄 화면 문구)와 `logistics.export.*`(엑셀 컬럼 헤더)는 구분하고, 단위·상태값 등 공용 표현은 `logistics.format.*`로 별도 관리.
3. **JSON 유효성** — Codex가 PowerShell `ConvertFrom-Json`으로 검증 실패를 보고했으나, Claude Code가 PHP `json_decode`로 재검증한 결과 `lang/ko.json`(43개 키, 168,119 bytes)·`lang/en.json`(43개 키, 150,608 bytes) 모두 **정상**임을 확인. Codex 쪽 도구 환경 문제로 판단, 실제 이슈 아님.
4. **B0 위험 요소 반영**:
   - `logistics/lib/auth.php`를 거치지 않는 진입점이 있는지 각 배치 착수 전 개별 확인(특히 `logistics/ajax/*.php`는 `auth.php`를 require하는지 파일별로 다를 수 있음 — B7 착수 시 재확인).
   - PHP 세션이 admin/office/logistics 간 공유되므로 언어 변경이 전역 적용됨 — **의도된 동작으로 확정**(기존 admin/mall도 동일하게 동작 중, 새로운 정책 아님).
   - `logistics/ajax_set_language.php`는 `admin/ajax_set_language.php`와 동일하게 **CSRF 검증 없이** 구현(기존 컨벤션과 일치시킴 — 세션 언어 전환은 저위험 동작으로 판단, 이번 범위에서 CSRF 추가하지 않음).
   - `logistics/partials/footer.php`의 신규 주문 팝업(`showPopup`, DOM ID, localStorage 키, polling)은 **기존 함수명/DOM ID/전역 변수명을 그대로 유지**하고 화면에 보이는 텍스트 노드만 `t()`로 치환. JS 로직/구조 변경 금지.
5. **print/export 언어 정책 (사용자 확정)** — 인쇄물(B6 print_*)과 엑셀 export(B6 export_*) 모두 **세션 언어를 그대로 따름**(다른 페이지와 동일 정책). 회계/외부 업로드 호환성보다 화면 언어와의 일관성을 우선.

## 최종 배치 순서 (사용자 확정: 연속 자동 진행, 배치별 Claude 검증 후 다음 배치)

B0 → B1 → B2a → B2b → B2c → B3a → B3b → B4a → B4b → B5 → B6 → B7
