# 최소 주문 수량(배수 단위) Plan

## 1. 목표
- 물류 마스터(`logistics/products.php`)에서 상품별 `min_order_qty`(기본 1)를 기록
- 점포 주문(`store/order.php`)에서 주문 수량이 `min_order_qty`의 **배수**일 때만 주문 가능

## 2. 확정 정책
| 항목 | 결정 |
|------|------|
| 규칙 | 배수 단위 (6이면 6/12/18…) |
| 기준 단위 | 점포가 선택한 주문 단위(BOX/PACK/PCS) 수량에 그대로 적용. 단위 변환 없음 |
| 기본값 | 1 (기존 상품 전부 1 → 동작 변화 없음) |
| 수량 0 | 미선택/삭제 의미 → 검증 제외 |
| 프로모 행 | 동일 규칙 적용 (행 단위 검증) |
| 범위 | 점포 주문만. `logistics/order_new.php`, mall은 이번 범위 제외 (후속 검토) |

## 3. 작업 항목
1. **DB (Claude)** — `lc_products.min_order_qty INT UNSIGNED NOT NULL DEFAULT 1` 추가. 실행 가능한 PHP 마이그레이션 스크립트로 작성(raw .sql만 두지 않음). 로컬 DB에만 적용, 운영 DB는 SELECT 전용 원칙 유지 → 배포 시 별도 확인.
2. **마스터 UI/저장 (Codex)** — `logistics/product_edit.php`(폼 + POST + UPDATE), `logistics/ajax/add_product.php`(INSERT), `logistics/products.php`(등록 모달 입력칸, 목록 컬럼 표시), 필요 시 `logistics/ajax/quick_update.php`. 입력 `max(1,(int))`. ko/en 언어키 추가(`lang/*.json`은 공용 파일이므로 한 에이전트만).
3. **점포 서버 검증 (Claude)** — `store/order.php` POST: 상품별 `min_order_qty`를 DB에서 조회해 `qty % min != 0`이면 오류. 재고 부족 자동조정(약 94~96행)은 "재고 이하 최대 배수"로 내림, 0이 되면 해당 행 제외 + notice.
4. **점포 클라이언트 (Claude)** — 행에 `data-min` 추가. `stepQty`는 min 단위로 증감, `oninput/blur`에서 배수로 보정(올림/내림), 최대치(max)는 재고 이하 최대 배수로. 임시저장 복원·재주문(`from_order`)·편집(`edit`) 초기값이 배수가 아니면 안내 문구 표시(조용히 변경 금지). 상품 행에 "최소 N단위" 배지 표시.
5. **검증 (Claude)** — `/code-review`, 로컬(homekmart.test) 시나리오 테스트.

## 4. 파일 분담 (충돌 방지)
- Codex: `logistics/product_edit.php`, `logistics/ajax/add_product.php`, `logistics/products.php`, `logistics/ajax/quick_update.php`, 언어파일
- Claude: 마이그레이션 스크립트, `store/order.php`
- 선행: Claude가 DB 컬럼 먼저 적용 → Codex 착수

## 5. 엣지케이스 체크리스트
- 재고 < 최소수량 → 주문 불가(수량 0 처리 + 안내)
- 재고 부족 자동조정 후 배수 깨짐 방지
- 기존 주문 편집 시 과거 수량이 현재 규칙에 어긋나는 경우
- 단위 변경(BOX↔PCS) 시 현재 입력 수량 재보정
- 같은 상품이 일반/프로모 여러 행일 때 행 단위 검증
- 서버는 클라이언트 값 불신 — 항상 DB의 min_order_qty로 검증
