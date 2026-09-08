# 신선상품 매입의 전표(배치) 구조 부재 분석

## 분석 범위와 결론

이 문서는 `admin/add_purchase.php`, `admin/add_fresh_purchase_item.php`, `admin/fresh_purchase_items.php`, `admin/fresh_product_common.php`와 관련 마이그레이션을 정적 분석한 결과다. 실행 중인 DB를 조회하거나 코드를 실행하지 않았으며, 실제 운영 스키마는 저장소의 최신 덤프와 런타임 쿼리에서 추론했다.

결론부터 말하면 사용자 피드백은 단순히 “화면에서 여러 상품을 추가할 수 없다”는 뜻으로 보기 어렵다. 현재 신선상품 화면은 이미 한 거래처를 선택해 여러 상품을 한 POST와 한 DB 트랜잭션으로 저장한다. 그러나 저장 결과에는 그 N개 행이 하나의 업체 매입 전표였다는 식별자가 전혀 없다. 더구나 입력한 `박스 수`, `박스당 무게/개수`, `박스당 원가`를 그대로 보존하지 않고 각각 총중량/총개수/총액으로 합쳐 저장한다. 따라서 저장 직후부터 사용자가 인식한 “한 업체의 한 번 매입”이라는 업무 단위와 DB의 단위가 달라진다.

## 1. 현재 상태 요약

| 비교 항목 | 일반 매입 (`add_purchase.php`) | 신선상품 매입 (`add_fresh_purchase_item.php`) |
|---|---|---|
| 업무 단위 | `purchases` 1건이 매입 전표, `purchase_items` N건이 전표 항목 | `fresh_purchase_items`의 각 행이 독립 매입 기록 |
| 업체/점포/일자 | 부모에 한 번 저장 | 모든 품목 행에 반복 저장 |
| 다건 UI | 거래처 선택 후 여러 상품 추가 | 거래처 선택 후 여러 신선상품 추가 |
| 저장 원자성 | 부모 1건과 자식 N건을 한 트랜잭션에서 저장 | N개 플랫 행을 한 트랜잭션에서 저장 |
| 저장 후 묶음 식별 | `purchase_id` | 없음. 같은 POST였는지 판별 불가 |
| 합계 | 부모의 `total_amount`, `total_items` | 화면에서만 건수/총액 계산. DB에는 전표 합계 없음 |
| 입력값 보존 | 품목별 수량·단가·유형·정렬순서 등을 보존 | 박스 수와 박스당 구성/원가를 보존하지 않고 총중량/총개수/총액으로 환산 |
| 수정/삭제 | `purchase_id` 기준 전표 조회, 품목 추가·수정·정렬·삭제 및 전표 soft delete | 등록과 행 목록만 존재. 전표 단위 수정/취소 불가 |
| 목록 | 전표 1행: 업체, 날짜, 총 품목 수, 총액, 환산 총수량, 확정 상태 | 품목 1행: 날짜, 점포, 신선 마스터, 점포상품, 총 구성량, 총원가, 단위원가 |
| 출력/증빙 | `purchase_id`로 상세 항목과 합계를 인쇄 가능 | 전표 ID가 없어 동일 수준의 출력 기준 없음 |
| 재고 반영 | 등록 파일에 로직은 있으나 현재 블록 주석 처리되어 실행되지 않음 | 재고 수량 갱신 없음. 점포상품 존재 여부만 검증 |

### 일반 매입의 테이블 구조

저장소의 최신 DB 덤프와 실제 INSERT/UPDATE가 가리키는 운영 구조는 다음과 같다. `sql/complete_schema.sql`에는 별도의 오래된/대안 스키마처럼 보이는 `id`, `user_id`, `status` 기반 정의가 있으나, 현재 애플리케이션은 이를 사용하지 않고 `purchase_id`/`item_id` 구조를 사용한다.

`purchases`:

| 컬럼 | 타입/성격 |
|---|---|
| `purchase_id` | `INT UNSIGNED`, PK, AUTO_INCREMENT |
| `store_id` | `INT UNSIGNED NOT NULL DEFAULT 1`, `stores.id` FK |
| `supplier_id` | `INT UNSIGNED NOT NULL`, `suppliers.id` FK |
| `purchase_date` | `DATE NOT NULL` |
| `total_amount` | `DECIMAL(10,2) NOT NULL` |
| `total_items` | `INT NOT NULL` (수량 합계가 아니라 품목 행 수) |
| `created_at` | `TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP` |
| `deleted_at` | nullable timestamp, 전표 soft delete |
| `deleted_by_user_id` | nullable 사용자 ID |
| `is_confirmed` | `TINYINT(1) DEFAULT 0` |
| `confirmed_at` | nullable datetime |
| `confirmed_by_user_id` | nullable 사용자 ID |

`purchase_items`:

| 컬럼 | 타입/성격 |
|---|---|
| `item_id` | `INT UNSIGNED`, PK, AUTO_INCREMENT |
| `sort_order` | `INT NOT NULL DEFAULT 0` |
| `purchase_id` | `INT UNSIGNED NOT NULL`, `purchases.purchase_id` FK, 부모 삭제 시 cascade |
| `product_id` | `INT UNSIGNED NOT NULL`, `products.id` FK |
| `purchase_type` | `ENUM('box','piece') NOT NULL DEFAULT 'box'` |
| `quantity` | `INT NOT NULL` |
| `unit_price` | `DECIMAL(10,2) NOT NULL` |
| `vat_included` | `TINYINT(1) DEFAULT 1` |
| `original_unit_price` | `DECIMAL(10,2) NULL` |
| `vat_amount` | `DECIMAL(10,2) DEFAULT 0` |
| `discount_rate` | `DECIMAL(5,2) DEFAULT 0` |
| `discounted_total` | `DECIMAL(10,2) NULL` |
| `discounted_unit_price` | `DECIMAL(10,2) NULL` |
| `expiration_date` | `DATE NULL`; 최신 덤프 정의에는 없지만 `add_purchase.php` INSERT 대상이며 `edit_purchase.php`가 없으면 추가하는 실사용 컬럼 |

### 일반 매입의 등록 순서

1. 현재 사용자/헤더의 점포를 결정하고 거래처, 매입일, 여러 상품을 받는다. 브라우저는 모든 행을 `items_json`으로 직렬화한다.
2. 서버는 기존 항목 표시용 `existing_item_id`가 없는 유효 행을 추려 `total_items`와 `total_amount = Σ(quantity × 입력 unit_price)`를 계산한다.
3. 신규 등록이면 트랜잭션 안에서 `purchases(store_id, supplier_id, purchase_date, total_amount, total_items)` 1건을 먼저 INSERT하고 새 `purchase_id`를 얻는다.
4. 각 상품에 대해 상품의 VAT 적용 여부를 조회하고, 입력 단가를 VAT 포함 최종 단가로 정규화한 뒤 `purchase_items`를 N건 INSERT한다. 신규 전표는 `sort_order` 1부터, 기존 전표에 추가할 때는 해당 전표의 최대 순번 다음부터 부여한다.
5. 사용자가 상품명을 수정한 신규 행은 `products.name_ko/name_en`도 UPDATE한다.
6. 파일 안에는 박스 수를 `pieces_per_box`로 환산해 `inventory`와 `inventory_transactions`를 갱신하는 코드가 있지만 전체가 주석 처리되어 있다. 따라서 **현재 `add_purchase.php` 등록 경로에서는 재고를 실제로 갱신하지 않는다.** “inventory 갱신 등”은 설계 흔적이지 실행 동작이므로 구분해야 한다.
7. 기존 전표에 품목을 추가했다면 `purchase_items`를 다시 집계해 부모의 `total_amount`, `total_items`를 UPDATE한다. 성공 시 전체 트랜잭션을 commit한다.

주의할 점은 신규 부모의 `total_amount`가 VAT 변환 전 입력 단가로 먼저 계산되는 반면 자식 `unit_price`는 VAT 미포함 입력일 때 12%가 더해질 수 있다는 것이다. 기존 전표에 추가할 때의 재집계는 변환 후 자식 단가를 사용하므로, VAT 미포함 입력이 존재하면 신규 생성 시점과 후속 재집계 사이 합계 기준이 달라질 가능성이 있다. 이는 신선 전표화와 별개의 기존 로직 위험이다.

### 일반 매입의 수정과 전표 기능

- `add_purchase.php?edit_purchase_id=...`는 부모를 `purchase_id`로 읽고 모든 기존 `purchase_items`를 로드한다. 기존 행은 `existing_item_id`로 표시되어 이 화면에서는 읽기 전용이며, 새 행만 같은 `purchase_id`에 추가한다.
- 본격 수정은 `edit_purchase.php`가 담당한다. 요청의 `purchase_id`에 속한 `item_id`인지 검증하고, 품목별 수량·가격·할인·순서 등을 수정하거나 삭제하며 부모 합계를 재계산한다. 정렬 변경도 모든 UPDATE 조건에 `purchase_id`를 포함해 다른 전표의 행을 건드리지 않도록 한다.
- 전표 전체 삭제는 `purchases.deleted_at/deleted_by_user_id`를 갱신하는 soft delete다. 파일에는 품목을 순회해 재고를 되돌리는 처리도 있다. 즉 삭제/취소의 기준이 명확한 단일 `purchase_id`다.
- `purchase_management.php`는 부모 1건을 목록 1행으로 조회하여 업체, 점포, 매입일시, `total_items`, `total_amount`, 확정 상태를 바로 표시한다. 자식으로부터 박스 환산 총 낱개 수만 부가 집계한다.
- `ajax_print_purchase.php`는 `purchase_id` 하나로 부모 및 자식 N건을 조회하여 거래처·일자·총액과 품목 상세를 한 장의 매입 상세 내역으로 출력한다.
- 따라서 전표 단위 조회, 수정, 품목 추가/삭제 및 정렬, soft delete, 확정, 인쇄/증빙이라는 기능이 자연스럽게 성립한다.

## 2. 신선상품 매입의 현재 흐름과 문제 진단

### 현재 UI 및 저장 흐름

1. 헤더에서 선택된 `$current_store_id`가 점포로 고정된다. 화면에서 점포를 별도로 고르는 방식은 아니다.
2. 거래처는 `ajax_search_suppliers.php`로 검색해 한 곳을 선택한다. 거래처를 선택해야 신선상품 입력 영역이 열린다.
3. 활성 `mall_fresh_products` 마스터를 한글명/영문명/코드로 클라이언트 검색하고, 선택할 때마다 표에 새 행을 추가한다.
4. 각 마스터에 대해 `ajax_search_fresh_store_products.php`를 호출해 현재 점포의 `products`/`inventory` 상품을 자동 추천한다. 정확한 이름 일치 항목을 우선하고, 없으면 첫 검색 결과를 사용한다. 사용자는 행 안에서 다른 점포상품을 다시 검색·선택할 수 있다.
5. 각 행에 박스 수량, 중량형이면 박스당 무게(kg), 낱개형이면 박스당 개수, 박스당 원가를 입력한다. 화면은 행 총원가와 100g당/개당 원가, 전체 품목 수와 총액을 즉시 계산한다.
6. 제출 시 모든 행을 하나의 `items_json`에 넣어 POST한다. 서버는 점포·거래처·날짜·마스터·점포상품·수량·단가를 재검증한 뒤 한 트랜잭션을 시작한다.
7. 서버는 행마다 `fresh_purchase_items`를 INSERT하고 전부 성공하면 한 번 commit한다. 중간 오류면 전체 rollback하며, 거래처/날짜/items JSON을 세션 draft에 보존해 리다이렉트 후 복원한다.

즉 UI와 쓰기 원자성만 보면 이미 “한 업체의 여러 상품을 한꺼번에 저장”한다. 코드에서 단일 품목만 허용하거나 행 추가 후 이전 행을 버리는 버그는 확인되지 않았다.

### `fresh_purchase_items`의 현재 구조

최초 생성 마이그레이션과 두 후속 마이그레이션을 합친 유효 구조는 다음과 같다.

| 컬럼 | 타입/성격 |
|---|---|
| `id` | `INT`, PK, AUTO_INCREMENT |
| `store_id` | `INT UNSIGNED NOT NULL`, `stores.id` FK |
| `supplier_id` | `INT UNSIGNED NULL`, 후속 추가, 인덱스 및 `suppliers.id` FK |
| `mall_fresh_product_id` | `INT NULL`, 마스터 FK, 삭제 시 NULL |
| `store_product_id` | `INT UNSIGNED NOT NULL`, 매입 시점의 `products.id` FK |
| `purchase_date` | `DATE NOT NULL` |
| `weight_kg` | `DECIMAL(10,3) NULL`; 중량형의 전체 박스 합산 중량 |
| `pieces_per_box` | `INT NULL`; 이름과 달리 저장값은 `박스당 개수 × 박스 수`, 즉 해당 행의 **총개수** |
| `total_cost` | `DECIMAL(12,2) NOT NULL`; `박스당 원가 × 박스 수` |
| `unit_cost_per_100g` | `DECIMAL(10,2) NULL`; 중량형 계산 단가 |
| `unit_cost_per_piece` | `DECIMAL(10,2) NULL`; 낱개형 계산 단가 |
| `registered_by` | `INT UNSIGNED NULL`, `users.id` 의미이나 생성 SQL에는 FK 없음 |
| `created_at` | `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` |

`quantity`(박스 수), `box_weight_kg`, `box_pieces`, `box_cost`, `batch_id`는 없다. 따라서 예를 들어 “3박스 × 박스당 10kg × 박스당 500”은 `weight_kg=30`, `total_cost=1500`으로만 남아 원래 박스 수와 박스당 조건을 역으로 확정할 수 없다. `pieces_per_box`라는 컬럼명은 특히 실제 저장 의미와 불일치한다.

### 이력 목록의 표시 방식

`fresh_purchase_items.php`는 `fresh_purchase_items` 자체를 COUNT하고 20행씩 페이지 처리한다. 본 조회도 각 품목 행을 `purchase_date DESC, id DESC`로 정렬할 뿐 `GROUP BY`가 없다. 점포, 신선 마스터, 점포상품을 INNER JOIN하여 날짜·점포·상품·총중량 또는 총개수·총원가·단위원가를 한 행씩 표시한다.

중요하게도 `supplier_id`를 SELECT하거나 `suppliers`를 JOIN하지 않는다. 따라서 등록 화면에서 선택해 각 행에 저장한 거래처가 이력 화면에는 표시되지 않으며, 검색 조건에도 거래처가 없다. 수정, 삭제, 상세, 인쇄 링크도 없다. 또한 `mall_fresh_product_id`는 스키마상 NULL 가능하지만 목록은 INNER JOIN하므로 마스터가 삭제되어 NULL이 된 과거 행은 목록에서 사라질 수 있다.

`fresh_product_common.php`의 관련 역할은 다음에 한정된다.

- 성공/오류 flash와 리다이렉트 처리
- 저장 실패 시 다건 draft 저장/한 번 복원
- 플랫 행의 총개수/총중량과 단위원가 표시 문자열 생성
- 현재 점포의 활성 상품 중 inventory에 존재하는 상품 검색 및 선택값 검증

전표를 만들거나 행을 그룹화하는 헬퍼는 없다. 또한 자동매칭은 이름 검색 결과를 선택할 뿐 `mall_fresh_product_store_links`를 생성하거나 검증하지 않는다. 즉 “자동매칭”은 영구 매핑 확정이 아니라 이번 행의 `store_product_id` 추천이다.

### 사용자가 느끼는 문제의 실질적 원인

핵심은 **동시에 INSERT했다는 기술적 사실과 하나의 전표로 저장됐다는 업무적 사실이 같지 않다**는 점이다.

- 같은 POST로 생성된 행들을 나중에 정확히 다시 찾을 수 없다. `(store_id, supplier_id, purchase_date)`로 묶으면 같은 업체가 같은 날 두 번 납품한 경우 서로 다른 전표가 합쳐진다.
- 전표 단위 수정, 전체 취소/soft delete, 확정/마감, 담당자 추적을 할 수 없다. 개별 행 수정 화면조차 현재 없다.
- 전표 총액과 총 품목 수가 저장되지 않는다. 매번 GROUP BY로 계산해도 위와 같이 동일 날짜 복수 납품을 구분할 수 없어 “근사”에 불과하다.
- 거래명세서, 입고표, 영수증 같은 증빙을 어떤 행 집합으로 출력할지 안정적으로 정할 수 없다.
- 거래처와 날짜를 N행에 중복 저장하므로 일부 행만 수정되면 한 번의 매입 안에서 헤더 정보가 불일치할 수 있다.
- 한 전표에 대한 접근 권한, 감사 로그, 외부 문서번호, 비고, 지급/확정 상태 같은 헤더 속성을 붙일 안정적인 위치가 없다.
- 사용자가 입력한 박스 수와 박스당 조건이 사라져, 저장 후 “몇 박스를 어떤 박스 조건으로 샀는가”를 재현하거나 고칠 수 없다. 이는 batch ID 부재와 별도로 반드시 해결해야 하는 데이터 손실이다.
- 목록이 업체를 보여주지 않고 품목 단위로만 나열되므로, 사용자는 실제로 다건 저장했더라도 “한 업체의 한 번 매입”으로 인식할 시각적 단서가 없다.
- 재고는 갱신되지 않는다. 이 화면의 목적이 원가 기록만이라면 의도일 수 있으나, 업무상 “매입 잡기”가 입고 반영까지 뜻한다면 별도 요구 확인과 설계가 필요하다.

## 3. 변경 방안

### 옵션 A — 신선 전용 부모 `fresh_purchase_batches` 신설 (권장)

`fresh_purchase_batches`를 만들고 `fresh_purchase_items.batch_id`가 이를 참조하게 한다. 부모에는 최소 `id`, `store_id`, `supplier_id`, `purchase_date`, `total_amount`, `total_items`, `registered_by`, `created_at`을 둔다. 일반 매입과 동등한 업무가 필요하면 `deleted_at/deleted_by`, `is_confirmed/confirmed_at/confirmed_by`, 문서번호·비고도 고려한다. 자식에는 `quantity_boxes`, `box_weight_kg` 또는 `pieces_per_box`, `box_cost`, `sort_order`를 추가해 원 입력값을 보존하고, 현재 합계/단위원가 컬럼은 계산 스냅샷으로 유지할 수 있다.

장점:

- 신선상품의 중량/개수 이중 모델을 억지로 일반 `purchase_items`에 끼우지 않으면서 전표 기능을 정확히 제공한다.
- 일반 매입과 유사한 목록·상세·수정·취소·확정·인쇄 패턴을 재사용할 수 있다.
- 기존 신선상품 코드의 영향 범위가 명확하고 점진적 이관이 가능하다.
- 원래 박스 입력과 계산 결과를 모두 보존할 수 있다.

단점:

- 부모 테이블, FK, CRUD/출력 화면을 새로 만들어야 한다.
- 과거 플랫 행에는 실제 배치 경계와 박스 수가 없으므로 완전한 복원은 불가능하다.

마이그레이션은 먼저 nullable `batch_id`와 입력 보존 컬럼을 추가하고 신규 데이터부터 부모를 만들도록 배포한 후, 과거 데이터는 정책을 정해 백필하는 순서가 안전하다. 과거 행은 보수적으로 “행당 임시 배치 1개”로 만들면 거짓 그룹화를 피할 수 있다. `(점포, 거래처, 날짜, 등록자, created_at 근접)` 그룹화는 편리하지만 추정값임을 표시해야 하며, `supplier_id IS NULL` 데이터도 “미지정 거래처” 처리 정책이 필요하다. 검증 후 `batch_id NOT NULL` 및 FK/인덱스를 강화한다.

### 옵션 B — 플랫 구조 유지, 화면에서만 배치처럼 그룹화

현재 행들을 `(store_id, supplier_id, purchase_date)` 또는 생성시각 구간으로 GROUP BY해 목록을 업체별 카드/전표처럼 표시하고 합계를 계산한다.

장점:

- DB 변경이 적고 목록 개선을 빠르게 제공할 수 있다.
- 기존 데이터에 즉시 적용 가능하다.

단점:

- 같은 날 같은 업체의 복수 납품을 구분할 수 없다.
- 그룹 키 중 하나가 수정되면 전표가 갈라지며, 동시 등록 원자성을 사후에 증명할 수 없다.
- 전표 단위 수정/취소/확정/증빙 번호를 안전하게 구현할 수 없다.
- 박스 수와 박스당 값 손실은 그대로다.

따라서 임시 UX 개선으로는 가능하지만 사용자 요구를 데이터 모델 수준에서 충족하는 해법은 아니다.

### 옵션 C — 기존 `purchases`/`purchase_items`를 신선상품에도 확장

`purchases`를 공통 전표 헤더로 쓰고, `purchase_items`에 신선 마스터 ID와 중량/박스 구성/신선 단가 컬럼을 추가하거나 공통 헤더 아래 `fresh_purchase_items`가 `purchase_id`를 참조하게 한다.

장점:

- 매입 목록, 전표번호, 업체/점포/일자, 합계, 확정, soft delete, 인쇄 등 성숙한 인프라를 공유할 수 있다.
- 일반·신선 매입 통합 통계와 업체 원장이 쉬워진다.

단점:

- `purchase_items` 단일 테이블 확장은 일반상품과 신선상품에만 필요한 nullable 컬럼이 섞이고, 수량/단가 의미가 유형별로 달라진다.
- 기존 수정·삭제·확정·출력·분석·물류 전환 코드가 일반상품만 가정하고 있어 회귀 위험과 변경 범위가 가장 크다.
- 공통 부모 + 서로 다른 두 자식 테이블 방식은 모델은 깔끔하지만, 기존 FK/화면이 `purchase_items`만 전제로 하므로 결국 서비스 전반의 다형 자식 처리가 필요하다.

장기적으로 모든 매입을 단일 원장으로 통합하려는 명확한 제품 방향이 있을 때 적합하다. 현재 피드백 한 건을 해결하기 위한 첫 변경으로는 위험이 크다.

## 4. 권장안과 이유

**옵션 A: 신선 전용 부모 `fresh_purchase_batches`를 신설하는 방안을 권장한다.**

사용자가 요구하는 것은 입력 위젯의 다건화가 아니라 “한 업체에서 한 번에 들어온 여러 상품”을 하나의 수정·취소·조회 가능한 업무 객체로 만드는 것이다. 전용 부모는 이 경계를 가장 명확히 표현하면서도 신선상품 특유의 중량/개수 원가 모델을 보존한다. 일반 매입의 검증된 UX와 처리 순서를 참고하되, 광범위한 기존 일반 매입 로직을 변경하지 않아 회귀 범위를 제한할 수 있다.

권장 저장 순서는 다음과 같다.

1. 서버가 모든 행을 검증하고 화면 표시용이 아니라 서버에서 `total_amount`와 `total_items`를 계산한다.
2. 트랜잭션에서 `fresh_purchase_batches` 1건을 INSERT한다.
3. 반환된 `batch_id`로 `fresh_purchase_items` N건을 INSERT하며 `quantity_boxes`, 박스당 구성, `box_cost`, 계산된 총 구성/총액/단위원가를 함께 보존한다.
4. 모든 행이 성공한 경우만 commit한다.
5. 목록 기본 단위를 배치로 바꾸고 상세에서 N개 품목을 펼친다. 수정/삭제/확정/인쇄는 항상 `batch_id`와 현재 점포 권한을 함께 검증한다.

초기 릴리스에서는 등록·배치 목록·상세/인쇄·전체 취소를 우선하고, 복잡한 품목 수정은 후속으로 나눌 수 있다. 다만 원 입력 보존 컬럼은 첫 마이그레이션부터 넣어야 새 데이터가 다시 손실되지 않는다. 재고 반영은 기존 일반 매입조차 등록 시 비활성화되어 있으므로 전표화와 결합해 암묵적으로 추가하지 말고, “원가 기록”과 “입고 반영”의 업무 정의를 확인한 별도 결정으로 두는 것이 안전하다.

## 5. 권장안 기준 변경 필요 파일

아래는 구현 시 예상 범위다. 실제 파일명은 프로젝트 관례에 맞춰 확정하되 책임을 분리해야 한다.

### `sql/migrations/`

- 신규 마이그레이션: `fresh_purchase_batches` 생성
- 신규 마이그레이션: `fresh_purchase_items.batch_id` FK/인덱스 및 `quantity_boxes`, `box_weight_kg`, 의미가 명확한 박스당 개수, `box_cost`, `sort_order` 추가
- 과거 데이터 백필 마이그레이션 또는 별도 일회성 이관 도구
- 기존 `pieces_per_box`가 실제로 총개수라는 의미 불일치를 해소할 rename/신규 컬럼 이관 정책
- 필요 시 부모의 soft delete/확정 관련 컬럼과 조회용 `(store_id, purchase_date)`, `supplier_id` 인덱스

기존 `run_create_mall_fresh_products.php`, `run_add_fresh_purchase_box_cost.php`, `run_add_fresh_purchase_supplier.php`는 이미 실행된 이력으로 간주하고 소급 수정하기보다 새 멱등 마이그레이션을 추가하는 편이 안전하다. 신규 설치용 기준 스키마가 따로 유지된다면 `sql/migrations/create_mall_fresh_products.sql`도 최종 구조와 동기화해야 한다.

### `admin/`

- `add_fresh_purchase_item.php`: 부모 1건 생성 후 `batch_id`를 가진 자식 N건 저장, 원 입력값 보존, 서버 합계 계산
- `fresh_purchase_items.php`: 품목 행 목록에서 배치 목록으로 변경; 거래처, 총 품목 수, 총액, 상태 표시 및 업체 검색 추가
- `fresh_product_common.php`: 배치 합계/표시, draft, 권한 검증 등 공통 로직 정리. 기존 행 표시 함수는 상세 품목용으로 의미 수정
- 신규 배치 상세/수정 화면(예: `edit_fresh_purchase.php` 또는 `fresh_purchase_detail.php`)
- 신규 배치 인쇄 화면/AJAX(예: `ajax_print_fresh_purchase.php`)
- 필요 시 배치 취소·확정 endpoint. 상태 변경은 `batch_id`와 `store_id`를 함께 검증
- `ajax_search_fresh_store_products.php`: 직접 변경은 필수는 아니지만, 영구 `mall_fresh_product_store_links`를 자동매칭에 활용할지 검토
- 메뉴/대시보드에서 신선 매입 이력 링크나 카운트를 사용하는 파일이 있다면 배치 기준으로 조정

### `lang/`

- 저장소의 실제 언어 파일들에서 배치 번호, 전표 상세, 거래처, 총 품목 수, 전표 총액, 수정, 취소, 확정, 인쇄, 과거 데이터 추정 그룹 등의 문구 추가
- 현재 `mall_fresh_products.*` 키 중 “품목 저장”으로 표현된 성공 메시지를 “매입 전표 저장” 의미로 조정
- `pieces_per_box`가 화면에서는 박스당 개수이고 DB 표시에서는 총개수였던 용어를 명확히 분리

### 테스트/문서(존재하는 프로젝트 관례에 따라)

- 같은 날짜·같은 업체로 두 전표를 연속 등록해 서로 섞이지 않는지 검증
- 중량형/낱개형 혼합 N행 등록, 한 행 실패 시 부모 포함 전체 rollback 검증
- 합계 재계산, 점포 권한, soft delete/확정, 인쇄 범위 검증
- 과거 플랫 데이터의 백필 규칙과 한계 문서화

