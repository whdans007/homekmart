# Design: 입고 시 파손 상품 등록 (inbound-damage-registration)

**Feature**: inbound-damage-registration
**Phase**: Design
**Architecture**: C — 실용 균형 (목록 조회는 `lib/inbound_helper.php` 패턴에 합류, 저장은 `inbound_add.php` 인라인 유지)
**Created**: 2026-07-07
**Planning Doc**: [inbound-damage-registration.plan.md](../01-plan/features/inbound-damage-registration.plan.md)

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 입고 시 파손 상품을 기록/제외할 수단이 없어 재고 부정확 + 손실 추적 불가 |
| **WHO** | 물류센터 스태프(`lc_require_staff`) — 입고 등록 담당자 |
| **RISK** | 파손 수량이 실제 입고 수량보다 크게 입력되는 등 검증 실패, 기존 `lc_box_breaks`(박스 개봉 시 파손) 개념과의 혼동 |
| **SUCCESS** | 입고 등록 시 파손 수량을 입력하면 정상재고에서 자동 제외되고, 파손 이력이 저장되어 별도 목록에서 조회 가능 |
| **SCOPE** | 입고 등록 화면 파손 입력 + 이력 테이블 + 목록 화면. 파손품 폐기/공급업체반품 워크플로, 사진 첨부는 범위 외 |

---

## 1. 아키텍처 결정 (Option C)

### 선택 이유
- **목록 조회**: 이 도메인(입고)에는 이미 `lib/inbound_helper.php`의 `getFilteredInboundItems()`가 목록/필터/페이지네이션을 함수로 캡슐화하는 관례가 확립되어 있음 → 신규 `getFilteredInboundDamages()`를 같은 파일에 같은 시그니처 스타일로 추가해 일관성 유지
- **저장 로직**: 파손 데이터 저장은 `inbound_add.php`의 기존 입고 등록 트랜잭션(품목 루프 안, `lc_inventory` INSERT 직후) 한 곳에서만 쓰이므로 별도 helper로 뽑지 않고 인라인 유지 (Option B처럼 저장까지 helper화하면 트랜잭션 커넥션을 넘겨야 해서 오히려 복잡해짐)
- **Excel/인쇄는 범위 외**: Plan에서 요구하지 않은 기능이므로 1차 버전에 포함하지 않음 (`inbound_items.php`와 달리 `export_inbound_damages.php`/`print_inbound_damages.php`는 만들지 않음)

### 구성 요소
```
[inbound_add.php] 품목 행 입력(Damaged/Reason)
        │ POST damaged_qty[] / damage_reason[]
        ▼
foreach 품목 저장 루프 (기존 트랜잭션 내)
        │
        ├─ lc_inventory INSERT: quantity_in = quantity - damaged_qty   (SC-2)
        │
        └─ damaged_qty > 0 이면 lc_inbound_damages INSERT (cost_loss 계산 포함)
                │
                ▼
        [inbound_damages.php] ── getFilteredInboundDamages() (lib/inbound_helper.php) ──▶ 목록+요약
```

### 변경 요약
| 구분 | 파일 | 작업 |
|------|------|------|
| 신규 | `sql/lc_inbound_damages.sql` | 파손 이력 테이블 마이그레이션 (스키마 문서) |
| 신규 | `sql/run_migration_inbound_damages.php` | 브라우저 실행용 마이그레이션 스크립트 |
| 신규 | `logistics/inbound_damages.php` | 파손 이력 목록(필터+요약+페이지네이션) |
| 수정 | `logistics/inbound_add.php` | 품목 행에 Damaged/Reason 입력 + 검증 + 저장 로직 |
| 수정 | `logistics/lib/inbound_helper.php` | `getFilteredInboundDamages()` 추가 |
| 수정 | `logistics/partials/header.php` | "Damaged Goods" 메뉴 추가 (Inbound/Outbound 섹션) |

---

## 2. 데이터 모델

### 2.1 스키마 (Plan §5 확정, FK 대상 테이블명 수정: `lc_suppliers`)

```sql
CREATE TABLE IF NOT EXISTS lc_inbound_damages (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    inbound_id   INT NOT NULL          COMMENT 'lc_inbound.id (파손이 발생한 입고 건)',
    product_id   INT NOT NULL          COMMENT 'lc_products.id',
    supplier_id  INT                   COMMENT 'lc_suppliers.id (조회 편의를 위한 비정규화)',
    quantity     INT NOT NULL          COMMENT '파손 수량 (입고 행과 동일 단위: BOX 또는 PCS)',
    unit         ENUM('BOX','PCS') NOT NULL COMMENT '파손 수량 단위 (해당 입고 행의 단위와 동일)',
    cost_loss    DECIMAL(15,4) NOT NULL DEFAULT 0 COMMENT '손실금액 = PCS환산수량 x cost_price_pcs',
    reason       VARCHAR(255) NOT NULL COMMENT '파손 사유',
    created_by   INT                   COMMENT 'users.id',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_time (product_id, created_at),
    INDEX idx_supplier_time (supplier_id, created_at),
    INDEX idx_inbound (inbound_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='입고 시 파손 상품 이력';

-- FK는 store-request-board 사례(errno 150)처럼 타입 불일치로 실패할 수 있어 별도 단계로 분리
ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_inbound  FOREIGN KEY (inbound_id) REFERENCES lc_inbound(id) ON DELETE CASCADE;
ALTER TABLE lc_inbound_damages
    ADD CONSTRAINT fk_lid_product  FOREIGN KEY (product_id) REFERENCES lc_products(id) ON DELETE RESTRICT;
```

### 2.2 마이그레이션 실행 스크립트

`sql/run_migration_inbound_damages.php` — `sql/run_migration_store_requests.php`와 동일 패턴:
- `mysqli_report(MYSQLI_REPORT_OFF)`로 PHP 8.1+ mysqli 기본 예외모드를 꺼서, FK 실패 등 SQL 오류가 발생해도 페이지가 500으로 죽지 않고 OK/SKIP/ERROR로 보고되게 한다 (직전 store-request-board 배포 사고의 교훈 반영)
- 테이블 생성 → FK 추가(각각 독립 단계, 실패해도 SKIP 처리)
- 실행 후 파일 삭제 안내 문구 포함

---

## 3. `logistics/inbound_add.php` 변경

### 3.1 품목 행 UI (테이블 마지막에 2개 컬럼 추가)

기존 컬럼(# / Product / Capacity / Unit / PKG / Expiry / QTY(PCS) / QTY(BOX) / PRICE(PCS) / PRICE(BOX) / Location / Discount / COST(PCS) / COST(BOX) / Total / 삭제버튼) 뒤에, **Total과 삭제버튼 사이**에 2개 컬럼 추가:

| 신규 컬럼 | 입력 요소 | 기본값 |
|-----------|-----------|--------|
| Damaged | `<input type="number" class="row-damaged-qty" min="0">` | 빈값(0) |
| Reason | `<input type="text" class="row-damage-reason" maxlength="255">` | 빈값, `damaged>0`일 때만 `required` (JS로 토글) |

- 파손 수량이 0보다 크면 해당 행을 옅은 빨강(`bg-red-50`)으로 하이라이트해 시각적으로 구분 (기존 `row-focused`/재고부족 강조 패턴과 동일한 인라인 스타일 토글 방식)
- hidden 배열 필드: `damaged_qty[]`, `damage_reason[]` (product_id[] 등과 같은 인덱스로 병렬 전송)

### 3.2 클라이언트 검증 (JS)

`onRowDamagedChange(inp)` 함수 신규 추가:
- 해당 행의 현재 활성 수량(단위가 BOX면 `.row-qty-box`, PCS면 `.row-qty-pcs`)을 초과하면 입력값을 그 수량으로 clamp
- damaged > 0이면 `.row-damage-reason`에 `required` 속성 부여 + 행 배경 강조, 0이면 해제
- 서버 검증이 최종 소스이므로 클라이언트 검증은 UX 보조 역할만

### 3.3 서버 검증 + 저장 (PHP)

```php
$damaged_qtys    = $_POST['damaged_qty']    ?? [];
$damage_reasons  = $_POST['damage_reason']  ?? [];

// valid_items 생성 루프 내부에 추가
$damaged = max(0, (int)($damaged_qtys[$i] ?? 0));
if ($damaged > $qty) {
    $errors[] = "Item #" . ($i + 1) . ": Damaged quantity cannot exceed the inbound quantity.";
    $error_fields[$i] = 'damaged_qty';
    continue;
}
$damage_reason = trim($damage_reasons[$i] ?? '');
if ($damaged > 0 && $damage_reason === '') {
    $errors[] = "Item #" . ($i + 1) . ": Please enter a damage reason.";
    $error_fields[$i] = 'damage_reason';
    continue;
}
// $valid_items[] 배열에 추가:
//   'damaged_qty' => $damaged, 'damage_reason' => $damage_reason
```

저장 루프(기존 `lc_inventory` INSERT 직후, 같은 트랜잭션 안):

```php
$sellable_qty = $item['quantity'] - $item['damaged_qty'];
// lc_inventory INSERT의 quantity_in 값을 $item['quantity'] 대신 $sellable_qty로 바인딩 (SC-2)

if ($item['damaged_qty'] > 0) {
    // Plan §5 단위 일치 원칙: PCS 환산 후 cost_price_pcs를 곱해 손실금액 계산
    $damaged_pcs_equiv = lc_is_bundle_unit($item['unit'])
        ? $item['damaged_qty'] * $item['pieces_per_box']
        : $item['damaged_qty'];
    $cost_loss = round($damaged_pcs_equiv * $item['cost_price_pcs'], 4);

    $st3 = $conn->prepare(
        "INSERT INTO lc_inbound_damages (inbound_id, product_id, supplier_id, quantity, unit, cost_loss, reason, created_by)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $st3->bind_param('iiiisdsi',
        $inbound_id, $item['product_id'], $form['supplier_id'],
        $item['damaged_qty'], $item['unit'], $cost_loss, $item['damage_reason'], $uid
    );
    $st3->execute();
    $st3->close();
}
```

- `damaged_qty` 0(기본값)인 기존 플로우는 `$sellable_qty === $item['quantity']`가 되어 동작이 완전히 동일 (NFR-1 하위 호환)
- 전부 기존 `autocommit(false)` ~ `commit()`/`rollback()` 트랜잭션 범위 안에서 실행 (NFR-2)

---

## 4. `logistics/inbound_damages.php` 설계

### 4.1 Helper 함수 (`lib/inbound_helper.php`에 추가)

```php
/**
 * Get filtered inbound damage records with pagination + summary totals
 * @param array $filters - ['search' => '', 'date_from' => '', 'date_to' => '']
 * @param int $page
 * @param int $limit
 * @return array - ['items'=>[...], 'total'=>N, 'page'=>N, 'total_pages'=>N,
 *                   'summary'=>['total_qty_pcs'=>N, 'total_cost_loss'=>F], 'error'=>null|string]
 */
function getFilteredInboundDamages($filters = [], $page = 1, $limit = 20) {
    // getFilteredInboundItems()와 동일한 구조: WHERE 절 동적 구성 → COUNT → 요약 SUM → LIMIT/OFFSET 조회
    // WHERE 대상: d.created_at 대신 조인된 i.inbound_date 기준으로 date_from/date_to 필터
    //             search는 p.name_en/p.name_ko/s.name LIKE
}
```

### 4.2 목록 쿼리 (개념)

```sql
SELECT d.id, d.quantity, d.unit, d.cost_loss, d.reason, d.created_at,
       p.name_en, p.name_ko, COALESCE(s.name, '-') AS supplier_name,
       i.inbound_date, i.batch_id
FROM lc_inbound_damages d
JOIN lc_inbound  i ON d.inbound_id = i.id
JOIN lc_products p ON d.product_id = p.id
LEFT JOIN lc_suppliers s ON d.supplier_id = s.id
WHERE 1=1 {search/date_from/date_to 조건}
ORDER BY d.created_at DESC
LIMIT ? OFFSET ?
```

요약(SUM)은 같은 WHERE 조건으로 `SELECT COUNT(*) AS cnt, COALESCE(SUM(cost_loss),0) AS total_loss, COALESCE(SUM(IF(unit='BOX', quantity, quantity)),0)` — PCS 환산 총수량은 표시하지 않고(단위가 섞여 있어 합산이 오해를 줄 수 있음), **총 손실금액(total_cost_loss)**과 **총 건수**만 요약 카드로 표시한다.

### 4.3 화면 구성 (`inbound_items.php`와 동일 톤)
- 상단: 필터 패널 (검색어, 입고일 from/to) + Reset 버튼 (Excel/인쇄 버튼 없음 — Option C 범위)
- 요약 카드: "Total Damage Records: N건" / "Total Loss: ₩X" (2개 스탯 타일)
- 목록 테이블 컬럼: 입고일 / 공급업체 / 상품명 / 파손수량(단위 포함) / 손실금액 / 사유 / 등록자 없음(단순화, created_by는 툴팁 정도만)
- 페이지네이션: `inbound_items.php`와 동일한 하단 페이지 링크 패턴

---

## 5. 내비게이션 변경

### `logistics/partials/header.php` (Inbound/Outbound 섹션 내, Outbound History 다음)
```php
<a href="<?php echo LC_BASE; ?>/inbound_damages.php"
   class="<?php echo $_lc_page === 'inbound_damages.php' ? 'bg-teal-100 text-teal-800' : 'text-gray-600 hover:bg-teal-50 hover:text-teal-700'; ?> flex items-center px-2 py-1.5 text-xs font-medium rounded-md transition-colors">
    <i class="fas fa-triangle-exclamation mr-2 text-xs w-4 text-center"></i>
    Damaged Goods
</a>
```
모바일 드롭다운 메뉴에도 동일 링크 추가.

---

## 6. 에러 처리 & 보안

| 항목 | 처리 |
|------|------|
| 파손 수량 > 입고 수량 | 서버측에서 해당 품목 등록 거부, 에러 메시지 + 입력 포커스 이동(`error_fields`) |
| 파손 수량 > 0인데 사유 미입력 | 서버측 검증 실패 → 에러 메시지 |
| `inbound_damages.php` 미인증 접근 | `lc_require_staff()`로 차단 |
| XSS | 사유/상품명/공급업체명 출력 시 `htmlspecialchars()` |
| FK 실패로 인한 배포 사고 재발 방지 | 마이그레이션 스크립트에 `mysqli_report(MYSQLI_REPORT_OFF)` 적용 (§2.2) |

---

## 7. Page UI Checklist

#### logistics/inbound_add.php (품목 행 추가분)
- [ ] 입력: Damaged 수량 (number, min=0, 기본 빈값)
- [ ] 입력: Damage Reason (text, maxlength 255, damaged>0일 때 required)
- [ ] 파손 수량 입력 시 행 배경 강조(bg-red-50)
- [ ] 서버 에러 시 해당 필드로 포커스 이동 (기존 error_fields 패턴)

#### logistics/inbound_damages.php
- [ ] 필터: 검색어(상품/공급업체), 입고일 from/to, Reset 버튼
- [ ] 요약 카드: Total Damage Records(건수), Total Loss(금액)
- [ ] 목록 테이블: 입고일, 공급업체, 상품명, 파손수량+단위, 손실금액, 사유
- [ ] 페이지네이션

---

## 8. Test Plan (경량)

| # | 시나리오 | 절차 | 기대 결과 |
|---|----------|------|-----------|
| 1 | 파손 없이 기존 입고 등록 | 모든 행 Damaged=0으로 등록 | 기존과 동일하게 전량 `lc_inventory`에 반영, `lc_inbound_damages` 미생성 |
| 2 | 파손 수량 입력 후 등록 | 특정 행 Damaged=3, Reason 입력 후 등록 | `lc_inventory.quantity_in` = 입고수량-3, `lc_inbound_damages`에 1건 생성, cost_loss 계산값 확인 |
| 3 | 파손 수량 > 입고 수량 | Damaged를 입고수량보다 크게 입력 후 제출 | 서버가 등록 거부, 에러 메시지 표시 |
| 4 | 사유 누락 | Damaged>0, Reason 빈값으로 제출 | 서버가 등록 거부, 에러 메시지 표시 |
| 5 | 파손 이력 목록 조회 | `inbound_damages.php`에서 날짜/검색 필터 적용 | 필터에 맞는 결과 + 총 손실금액 요약이 정확히 반영 |

---

## 9. 구현 순서

1. [ ] `sql/lc_inbound_damages.sql` + `sql/run_migration_inbound_damages.php` 작성
2. [ ] `lib/inbound_helper.php`에 `getFilteredInboundDamages()` 추가
3. [ ] `inbound_add.php` 품목 행 UI(Damaged/Reason) + JS 검증 추가
4. [ ] `inbound_add.php` 서버 검증 + `lc_inventory`/`lc_inbound_damages` 저장 로직 반영
5. [ ] `logistics/inbound_damages.php` 신규 작성
6. [ ] `logistics/partials/header.php` 내비게이션 반영
7. [ ] 마이그레이션 실행 (원격 배포 + 브라우저 실행 + 파일 삭제)
8. [ ] §8 시나리오 수동 검증

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-07-07 | Initial draft (Option C) | whdans007 |
