# BKIT PDCA Plan: KIM'S MALL 창고 재고조사

> **Feature**: `kimsmall-stock-count`
> **Date**: 2026-09-22
> **Status**: Plan
> **Owners**: Claude Code (재고 도메인·DB·확정·병합), Codex (입력·조회 UI)
> **Target**: `kimsmall_wherehouse/`와 해당 `kw_*` 테이블

## 1. 목표와 운영 기준

입고 마감 후 입출고가 없는 기간에 창고 실물을 여러 날짜에 걸쳐 조사한다. 바코드 스캔 후 박스 또는 낱개 수량을 저장하고, 날짜별 기록 및 상품별 합계를 검토한 뒤 한 번의 **재고 확정**으로 실제 재고에 반영한다.

- 조사 건은 여러 조사 날짜를 포함한다. 날짜는 입력 기록을 조회하는 기준이며, 확정은 조사 건 단위로 한 번만 한다.
- 조사 건이 열려 있는 동안 창고 재고를 바꾸는 입고·출고·취소·수정·박스 개봉을 서버에서 차단한다. 입고 마감 확인도 조사 시작 조건이다.
- 현재 `kw_inventory.unit`은 `BOX`, `PACK`, `PCS`를 구분한다. 조사 화면에서는 `BOX + PACK`을 **박스**로 표시하되, 원본 LOT 단위는 유지한다.
- 화면의 낱개 환산 총량은 `BOX × BOX 입수 + PACK × PACK 입수 + 별도 PCS`다. 두 묶음 단위의 입수가 같음이 확인되면 `(BOX + PACK) × 공통 입수 + PCS`로 표시할 수 있다. 이 값은 비교·표시용이며 재고 저장에서는 원래 단위를 유지한다.
- 기존 박스 재고만 있는 상품은 낱개 재고를 0으로 비교한다. 조사하지 않은 상품은 확정 시 변경하지 않는다.

## 2. 현행 코드 근거

| 근거 | 확인 내용 |
|---|---|
| `kimsmall_wherehouse/lib/unit_helper.php` | `kw_get_stock_by_unit()`가 `BOX/PACK/PCS`를 각각 집계한다. 출고 차감도 단위별 LOT에서 수행한다. |
| `kimsmall_wherehouse/ajax/box_break.php` | 박스 개봉은 묶음 LOT을 줄이고 `PCS` LOT을 만든다. |
| `kimsmall_wherehouse/ajax/search_product_by_barcode.php` | 상품 검색에 세 종류 바코드, `pieces_per_box`, 단위별 현재고가 있다. 조사 저장용 API에서는 바코드 정확 일치를 우선해야 한다. |
| `kimsmall_wherehouse/partials/header.php` | 창고 직원용 사이드 메뉴와 모바일 메뉴가 있다. |
| `kimsmall_wherehouse/inventory.php` | 기존 재고 화면은 `BOX/PACK/PCS` 수량을 분리 표시한다. `total_stock`의 단순 합은 낱개 환산 총량으로 사용하지 않는다. |

## 3. 범위와 요구사항

| ID | 요구사항 | 담당 |
|---|---|---|
| FR-01 | 사이드 메뉴에 **재고조사** 섹션과 **재고조사**, **재고조사 리스트** 항목 추가. 모바일 메뉴도 연결한다. | (Codex) |
| FR-02 | 조사 시작/진행/확정 상태를 가진 조사 건을 만들고 여러 날짜의 입력을 같은 건에 연결한다. | (Claude Code) |
| FR-03 | 박스/낱개 선택 → 정확한 바코드 스캔 → 상품 정보·박스당 입수·기준 재고 표시 → 수량 입력·저장. 중복 또는 미등록 바코드는 명확히 알린다. | (Codex) |
| FR-04 | 저장은 원본 스캔/입력 기록을 남기고, 같은 조사 건·상품·단위의 여러 입력은 합산한다. 날짜별 내역과 상품별 총합을 모두 제공한다. | (Claude Code) API·집계 / (Codex) 화면 |
| FR-05 | 목록에 기준 박스·낱개, 조사 박스·낱개, 각각의 `+/-` 차이, 낱개 환산 총량을 보여준다. 0개 실물도 명시적으로 입력할 수 있어야 한다. | (Claude Code) 계산 / (Codex) 화면 |
| FR-06 | Design의 변경 경로 전수 조사로 확인된 모든 `kw_inventory` 수량 변경 경로를 서버에서 막고, 이미 열린 조사 건이 중복 생성되지 않도록 한다. | (Claude Code) |
| FR-07 | 확정 시 권한·상태·입력값을 재검증하고 단일 트랜잭션과 행 잠금으로 LOT 재고 및 감사 이력을 기록한다. 재요청은 재적용하지 않는다. | (Claude Code) |
| FR-08 | 확정 후 날짜별 기록, 상품별 확정 전후 수량, 적용 차이, 확정자·시각을 읽기 전용으로 보존한다. | (Claude Code) 데이터 / (Codex) 화면 |
| FR-09 | 확정 전 검토 화면에 날짜별 입력, 상품별 합계, 기준 재고, 단위별 `+/-`, 미조사 상품 수, 최종 적용 예정 수량을 표시하고 명시적 확정 동작을 제공한다. | (Claude Code) 검증 API / (Codex) 화면 |

## 4. 데이터·계산 계약

**(Claude Code)** BKIT Design 단계에서 조사 건(`session`), 입력 원본(`entry`), 상품별 합계(`line`), 확정 조정(`adjustment`)의 저장 구조/인덱스와 마이그레이션을 확정한다. 합계의 논리 키는 최소 `(session_id, product_id, inventory_unit)`이다. 저장 이벤트는 날짜·입력자·스캔 바코드·선택 단위·수량·적용 입수를 보존한다. 반복 스캔은 누적하고, 잘못 입력한 기록은 삭제 대신 정정 이력을 남기는 방식을 우선한다. **Design 결정**: 별도 `line` 테이블 대신 유효한 `entry`를 조회 시 집계하고, 취소된 입력은 감사 이력으로 유지한다.

**(Claude Code)** 조사 시작 시 기준 재고를 단위별로 고정한다. 확정 직전에 같은 단위의 현재고가 기준과 달라졌으면 입출고 차단 누락 가능성으로 보고 확정을 중지한다. LOT별 감소·증가 배분은 기존 FEFO/원가/유통기한 정책을 확인해 Design에 명시한다. 생성된 조정 LOT은 출고 순서와 원가 보고서에 미치는 영향까지 검증한다.

**(Claude Code)** `PACK`은 화면의 박스 집계에 포함하지만 DB에서는 `PACK`으로 남긴다. 상품에 `BOX`와 `PACK`이 모두 있거나 두 단위의 실제 입수가 다르면 단순 합산·환산·배분을 금지하고 설계 전 확인 필요 항목을 해결한다. 기존 `PACK` LOT만 있는 상품은 확정 시 `PACK` 단위에 반영한다.

**(Codex)** UI는 API가 제공한 기준/조사/차이/환산 값을 그대로 표시한다. 브라우저에서 계산한 결과만으로 확정 요청을 만들지 않는다.

## 5. 영향 파일과 분담

| 파일 또는 영역 | 작업 | 담당 |
|---|---|---|
| `kimsmall_wherehouse/sql/` 신규 migration + 실행 스크립트 | 조사 테이블·유일 키·인덱스 | (Claude Code) |
| `kimsmall_wherehouse/lib/stock_count_service.php` 신규 | 조사 시작·저장·집계·확정·차단 판정 | (Claude Code) |
| `kimsmall_wherehouse/ajax/stock_count.php` 신규 | 조회/저장/확정 API, 권한·CSRF·검증 | (Claude Code) |
| `kimsmall_wherehouse/inbound_add.php`, `inbound_edit.php`, `orders.php`, `order_detail.php`, `ajax/box_break.php`, `ajax/branch_outbound.php`, `ajax/update_inbound_item.php`, 관련 `ajax/`·`lib/` | 모든 수량 변경 경로 감사와 서버 차단. 목록은 Design의 전수 조사로 확정 | (Claude Code) |
| `kimsmall_wherehouse/stock_count.php` 신규 | 스캔·입력·현재 조사 합계 화면 | (Codex) |
| `kimsmall_wherehouse/stock_count_list.php` 신규 | 날짜별 기록·상품별 차이·확정 상태 화면 | (Codex) |
| `kimsmall_wherehouse/partials/header.php` | 데스크톱·모바일 메뉴 연결 | (Codex) |
| `tests/` 또는 `kimsmall_wherehouse/test_stock_count.php` 신규 | 환산·합산·차단·확정·중복 요청 통합 검증 | (Claude Code) |

## 6. BKIT PDCA 실행 순서와 역할

### Plan → Design

1. **(Claude Code)** 이 Plan을 기준으로 실제 DB 스키마, 재고 변경 경로, LOT 원가·유통기한 규칙을 감사하고 `docs/02-design/features/kimsmall-stock-count.design.md`를 작성한다.
2. **(Claude Code)** API 요청/응답, 권한, 오류 코드, `PACK` 매핑, 입력 정정 방식, 동시성·잠금 순서를 확정한다. Codex가 사용할 계약을 먼저 공유한다.
3. **(Codex)** Design을 리뷰해 스캔 UX, 날짜별 조회, 차이 표시와 확정 전 검토 화면의 누락을 피드백한다.

### Do

4. **(Claude Code)** `claude/kimsmall-stock-count` 독립 워크트리에서 migration, 서비스/API, 재고 변경 차단, 확정 트랜잭션과 검증을 구현한다.
5. **(Codex)** `codex/kimsmall-stock-count` 독립 워크트리에서 신규 두 화면과 메뉴를 구현한다. 공용 파일·API는 수정하지 않고 확정된 API 계약에 맞춘다.

### Check → Act

6. **(Claude Code)** 양쪽 결과를 통합해 `/code-review`, PHP lint, DB migration 검증, 단위별·LOT별 통합 시나리오를 수행한다. 스타일과 권한·CSRF·prepared statement를 확인한다.
7. **(Codex)** 통합 화면에서 실제 바코드 스캔, 동일 상품 반복 입력, 날짜별 조회, 모바일 화면, 확정 후 읽기 전용 상태를 확인한다.
8. **(Claude Code)** BKIT Check 결과와 수정 사항을 기록하고 최종 병합 게이트를 담당한다. 운영 DB migration/배포는 검토 가능한 변경과 실행 절차를 먼저 제시한다.

작업은 `docs/00-conventions/agent-orchestration.md`에 따라 독립 브랜치·워크트리로 진행한다. 같은 파일의 동시 편집은 피한다.

## 7. 완료 기준

- **(Claude Code)** `BOX 3 + PCS 5`, 입수 12인 상품은 41개로 표시하되 재고는 박스 3·낱개 5로 분리 저장된다.
- **(Claude Code)** `BOX`와 `PACK`이 공존하는 상품은 각 단위의 실제 입수로 환산되고, 확정 후에도 원래 LOT 단위가 보존된다. 입수를 판정할 수 없으면 확정을 거부한다.
- **(Claude Code)** 같은 상품을 같은 날/다른 날 여러 번 저장해도 원본 기록은 남고 조사 건 합계는 정확하다.
- **(Claude Code)** 조사 중 입고·출고·취소·수정·박스 개봉이 서버에서 거부된다. 차단되지 않은 수량 변화가 발견되면 확정이 중지된다.
- **(Claude Code)** 미조사 상품은 유지되고, 0개로 조사한 상품은 명시적으로 0으로 확정된다.
- **(Claude Code)** 확정 중 오류 또는 이중 클릭에도 재고가 한 번만 바뀌고, 부분 반영이 남지 않는다.
- **(Codex)** 사용자는 날짜별 입력과 상품별 합계, `+/-` 차이를 구분해 확인할 수 있다.
- **(Codex)** 확정 전 검토 화면에서 최종 적용 예정 수량과 미조사 상품 수를 확인할 수 있다.

## 8. 설계 전 확인 필요

| 항목 | 막는 결정 | 확인 담당 |
|---|---|---|
| `PACK` 상품의 `pieces_per_box`가 실제 포장당 낱개 수인지, `BOX`와 `PACK`이 같은 상품에 함께 존재하는지 | 박스 합계의 낱개 환산과 확정 LOT 배분 | (Claude Code) |
| 조사 시작 시 판단할 **입고 마감** 상태의 실제 테이블·상태값, 차단해야 할 모든 수량 변경 경로 | 조사 시작 조건과 서버 차단 범위 | (Claude Code) |
| 실물 차이의 플러스 조정 LOT 원가·유통기한·보관 위치와 마이너스 차감 LOT 우선순위 | 확정 서비스와 회계·FEFO 정합성 | (Claude Code) |
| 여러 날짜에 걸친 조사 중 긴급 입출고가 필요해진 경우 조사 건을 폐기·재시작할지에 대한 운영 절차. 진행 중 입출고 허용은 현재 요구사항에 포함되지 않음 | 조사 중단 상태와 차단 해제 조건 | (Claude Code) |

위 항목은 코드와 운영 데이터를 확인해 Design에서 결론을 내린다. 확인 전 재고 확정 구현을 시작하지 않는다.
