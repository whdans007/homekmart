# Receipt Management — Design Document

**Feature**: receipt-management
**Plan**: docs/01-plan/features/receipt-management.plan.md
**Architecture**: Option C — Pragmatic Balance
**Date**: 2026-05-07

---

## Context Anchor

| | |
|--|--|
| **WHY** | 지출 등록 전 영수증 증빙 관리 체계가 없어 이중 입력이 발생하고 증빙이 누락됨 |
| **WHO** | office 시스템 사용자 (점포 관리자) |
| **RISK** | 파일 업로드 보안, 영수증 상태 동기화 오류 (지출 삭제 시 복원 누락) |
| **SUCCESS** | 영수증 등록 → 지출 연결 흐름 완성 + 사용된 영수증이 모달에서 자동 제외 |
| **SCOPE** | office/ 하위 receipts 모듈 + 기존 add/delete.php 4개 파일 수정 |

---

## 1. 개요

### 1.1 아키텍처 선택: Option C (Pragmatic Balance)

영수증을 `office/receipts/` 독립 모듈로 관리. 영수증-지출 링크/언링크 로직은 `office/lib/office_helper.php`에 공유 함수 2개로 추가하여 코드 중복 방지.

```
office/
├── receipts/          ← 신규 독립 모듈
│   ├── list.php
│   ├── add.php
│   ├── edit.php
│   ├── delete.php
│   └── view.php
├── ajax_search_receipts.php    ← 신규 (불러오기 모달용)
├── sql/
│   └── create_receipts.sql    ← 신규 DB 마이그레이션
├── lib/
│   └── office_helper.php      ← 수정 (2 함수 추가)
├── equipment_purchase/
│   ├── add.php                ← 수정 (영수증 모달 + hidden 필드)
│   └── delete.php             ← 수정 (영수증 복원 로직)
└── product_purchase/
    ├── add.php                ← 수정 (영수증 모달 + hidden 필드)
    └── delete.php             ← 수정 (영수증 복원 로직)
```

파일 저장 경로: `uploads/receipts/YYYY/MM/{uniqid()}.{ext}`

---

## 2. 데이터베이스 설계

### 2.1 office_receipts 테이블

```sql
CREATE TABLE office_receipts (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  store_id             INT NOT NULL,
  supplier_name        VARCHAR(255) NOT NULL,
  description          TEXT,
  amount               DECIMAL(12,2) NOT NULL,
  receipt_date         DATE NOT NULL,
  file_path            VARCHAR(500) NULL,          -- uploads/receipts/YYYY/MM/xxx.ext
  file_original_name   VARCHAR(255) NULL,
  file_mime            VARCHAR(100) NULL,           -- image/jpeg | image/png | application/pdf
  notes                TEXT NULL,
  linked_purchase_type ENUM('product','equipment') NULL,
  linked_purchase_id   INT NULL,                   -- NULL = 미사용
  used_at              TIMESTAMP NULL,
  created_by           INT NULL,
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_date  (store_id, receipt_date),
  INDEX idx_unused      (store_id, linked_purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2.2 상태 규칙

| `linked_purchase_id` | 상태 | 모달 표시 | 배지 |
|----------------------|------|-----------|------|
| NULL | 미사용 | ✅ 표시 | — |
| NOT NULL + type=product | 등록됨 (상품구매) | ❌ 제외 | 파란 배지 |
| NOT NULL + type=equipment | 등록됨 (비품구매) | ❌ 제외 | 주황 배지 |

### 2.3 기존 테이블 변경 없음

`office_product_purchases`, `office_equipment_purchases` 테이블에 컬럼 추가 없음.
연결 정보는 `office_receipts` 측에만 저장 (단방향 FK).

---

## 3. 공유 헬퍼 함수 (office_helper.php)

```php
/**
 * 영수증을 지출에 연결 (구매 저장 시 호출)
 * @param int $receipt_id
 * @param string $type 'product' | 'equipment'
 * @param int $purchase_id
 */
function link_receipt(int $receipt_id, string $type, int $purchase_id): void {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "UPDATE office_receipts
         SET linked_purchase_type=?, linked_purchase_id=?, used_at=NOW()
         WHERE id=? AND store_id=? AND linked_purchase_id IS NULL"
    );
    $store_id = get_office_store_id();
    $stmt->bind_param('siii', $type, $purchase_id, $receipt_id, $store_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * 영수증 연결 해제 (지출 삭제 시 호출, 삭제 전에 실행)
 * @param string $type 'product' | 'equipment'
 * @param int $purchase_id
 */
function unlink_receipt_by_purchase(string $type, int $purchase_id): void {
    $conn = get_db_connection();
    $stmt = $conn->prepare(
        "UPDATE office_receipts
         SET linked_purchase_type=NULL, linked_purchase_id=NULL, used_at=NULL
         WHERE linked_purchase_type=? AND linked_purchase_id=?"
    );
    $stmt->bind_param('si', $type, $purchase_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}
```

---

## 4. API / AJAX 명세

### 4.1 ajax_search_receipts.php

**요청**:
```
GET ajax_search_receipts.php
  ?supplier_name=농협    (선택, 부분 일치)
  &date_from=2026-04-01 (선택)
  &date_to=2026-05-07   (선택)
```

**응답**:
```json
{
  "success": true,
  "receipts": [
    {
      "id": 1,
      "supplier_name": "농협마트",
      "description": "야채류 배달",
      "amount": 150000,
      "receipt_date": "2026-05-01",
      "has_file": true
    }
  ]
}
```

**SQL** (미사용 필터 핵심):
```sql
SELECT id, supplier_name, description, amount, receipt_date,
       (file_path IS NOT NULL) AS has_file
FROM office_receipts
WHERE store_id = ?
  AND linked_purchase_id IS NULL          -- 미사용만
  AND (:supplier_name = '' OR supplier_name LIKE ?)
  AND (:date_from = '' OR receipt_date >= ?)
  AND (:date_to   = '' OR receipt_date <= ?)
ORDER BY receipt_date DESC
LIMIT 50
```

### 4.2 영수증 파일 업로드 (add.php POST 내부 처리)

```
POST office/receipts/add.php
Content-Type: multipart/form-data

필드: supplier_name, description, amount, receipt_date, notes, receipt_file
```

파일 처리 로직:
```php
$allowed_mime = ['image/jpeg', 'image/png', 'application/pdf'];
// MIME 검증 → 디렉토리 생성 → uniqid 파일명으로 이동
$dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/receipts/' . date('Y/m') . '/';
$fname = uniqid('r_', true) . '.' . $ext;
move_uploaded_file($tmp, $dir . $fname);
```

---

## 5. 화면 설계

### 5.1 영수증 목록 (office/receipts/list.php)

```
┌────────────────────────────────────────────────────────────┐
│ 📄 영수증 관리                              [+ 영수증 등록] │
├─────────────┬──────────────┬─────────────┬────────────────  ┤
│ 날짜        │ 업체명        │ 금액         │ 상태            │
├─────────────┼──────────────┼─────────────┼──────────────── ─┤
│ 2026-05-01  │ 농협마트      │ 150,000     │ (미사용)        │
│ 2026-04-28  │ OO식품       │ 340,000     │ [상품구매 등록됨] │
│ 2026-04-25  │ 배달의민족    │ 55,000      │ [비품구매 등록됨] │
└─────────────┴──────────────┴─────────────┴──────────────── ─┘
```

- 상태 배지: 미사용 → 기본(회색), 상품구매 → 파란 뱃지, 비품구매 → 주황 뱃지
- 행 클릭 시 수정 페이지로 이동
- 파일 첨부가 있으면 📎 아이콘 표시

### 5.2 영수증 등록 (office/receipts/add.php)

```
┌──────────────────────────────────────────────┐
│ ← 목록  영수증 등록                           │
│                                              │
│ 업체명 *  [농협마트           ▼ 또는 직접입력] │
│ 내용    * [야채류 배달                       ] │
│ 금액    * [150,000                          ] │
│ 날짜    * [2026-05-01                       ] │
│ 메모      [                                 ] │
│ 파일 첨부 [파일 선택]  (JPG/PNG/PDF, 10MB)    │
│                                              │
│           [저장]      [취소]                 │
└──────────────────────────────────────────────┘
```

업체명 입력 UX:
- `<input>` + `<datalist>` 조합으로 suppliers 자동완성
- 미등록 업체는 그냥 텍스트로 입력 (기존 방식과 동일)

### 5.3 영수증에서 불러오기 모달 (add.php에 공통 포함)

```
┌──────────────────────────────────────────────────────────┐
│  영수증에서 불러오기                              [×]    │
├──────────────────────────────────────────────────────────┤
│  업체명 [         ]  기간 [날짜from] ~ [날짜to]  [검색] │
├────────────┬────────────────┬────────────┬───────────────┤
│ 날짜       │ 업체명          │ 금액        │               │
├────────────┼────────────────┼────────────┼───────────────┤
│ 2026-05-01 │ 농협마트        │ 150,000    │ [선택]        │
│ 2026-04-28 │ OO식품         │ 340,000    │ [선택]        │
└────────────┴────────────────┴────────────┴───────────────┘
│  * 미사용 영수증만 표시됩니다                             │
└──────────────────────────────────────────────────────────┘
```

선택 시 동작 (JS):
```javascript
// 선택 시 부모 폼 필드 자동 채움
document.getElementById('supplier_name').value = row.supplier_name;
document.getElementById('amount').value = row.amount;
document.getElementById('receipt_id').value = row.id;  // hidden
modal.hide();
```

---

## 6. 파일별 상세 명세

### 6.1 office/receipts/list.php
- `$css_base = '../../admin/'`
- `$office_nav_base = '../'`
- 쿼리: `SELECT * FROM office_receipts WHERE store_id=? ORDER BY receipt_date DESC, id DESC`
- 상태 배지: `linked_purchase_id IS NULL` → '미사용', 아니면 type에 따라 배지

### 6.2 office/receipts/add.php
- POST 처리 → 파일 업로드 → DB INSERT → `list.php` 리다이렉트
- 공급처 자동완성 데이터: `SELECT name FROM suppliers ORDER BY name` (admin DB, 동일 DB)
- 유효성: `supplier_name`, `amount > 0`, `receipt_date` 필수

### 6.3 office/receipts/edit.php
- 등록됨 상태(`linked_purchase_id IS NOT NULL`) 영수증은 수정 불가 → 경고 표시
- 파일 교체 시 기존 파일 삭제 후 신규 저장

### 6.4 office/receipts/delete.php
- 등록됨 상태 영수증은 삭제 불가 (지출 먼저 삭제 필요)
- 첨부 파일 물리 삭제 후 DB 삭제

### 6.5 office/receipts/view.php
- 이미지: `<img>` 태그로 직접 표시
- PDF: `<embed src="..." type="application/pdf">`
- Content-Type 헤더 설정 후 `readfile()`로 서빙 (직접 접근 차단된 uploads 디렉토리)

### 6.6 office/ajax_search_receipts.php
- GET 요청, JSON 응답
- `linked_purchase_id IS NULL` 필터 필수
- `store_id` 검증 (다른 점포 영수증 접근 차단)

### 6.7 office/equipment_purchase/add.php (수정)
변경 내용:
1. `<input type="hidden" name="receipt_id" id="receipt_id" value="">` 추가
2. 거래처명 필드 상단에 `[영수증에서 불러오기]` 버튼 추가
3. 모달 HTML + JS 추가 (Bootstrap Modal)
4. POST 저장 후 `link_receipt()` 호출:
```php
$purchase_id = $conn->insert_id;
$receipt_id = (int)($_POST['receipt_id'] ?? 0);
if ($receipt_id > 0) {
    link_receipt($receipt_id, 'equipment', $purchase_id);
}
```

### 6.8 office/equipment_purchase/delete.php (수정)
삭제 전 영수증 복원:
```php
// 삭제 전 영수증 복원
unlink_receipt_by_purchase('equipment', $id);
// 기존 DELETE 쿼리 실행
```

### 6.9 office/product_purchase/add.php (수정)
6.7과 동일, `type = 'product'`로 변경

### 6.10 office/product_purchase/delete.php (수정)
6.8과 동일, `type = 'product'`로 변경

---

## 7. 보안 설계

| 항목 | 처리 방법 |
|------|-----------|
| 파일 MIME 검증 | `$_FILES['file']['type']` + `finfo_file()` 이중 검증 |
| 파일명 난수화 | `uniqid('r_', true)` — 원본명은 DB에만 저장 |
| 업로드 디렉토리 | `uploads/receipts/` 에 `.htaccess` `deny from all` |
| 파일 서빙 | `view.php` 통해서만 접근 (직접 URL 접근 차단) |
| 권한 확인 | 모든 파일에 `require_office_permission()` |
| store_id 검증 | 모든 쿼리에 `store_id = get_office_store_id()` 조건 포함 |
| SQL Injection | Prepared Statement (MySQLi) |

---

## 8. 테스트 계획

### L1 — 기능 테스트
- [ ] 영수증 등록 (파일 없음)
- [ ] 영수증 등록 (JPG 첨부)
- [ ] 영수증 등록 (PDF 첨부)
- [ ] 영수증 목록 상태 배지 표시
- [ ] 상품구매 등록 시 모달에서 영수증 선택 → 자동 채움
- [ ] 비품구매 등록 시 동일
- [ ] 사용된 영수증이 모달에 미표시 확인
- [ ] 지출 삭제 후 영수증이 모달에 재표시 확인

### L2 — 엣지 케이스
- [ ] 잘못된 MIME 타입 파일 업로드 거부
- [ ] 10MB 초과 파일 업로드 거부
- [ ] 다른 점포 영수증 접근 차단
- [ ] 등록됨 영수증 수정/삭제 불가 확인
- [ ] 영수증 없이도 구매 등록 가능 확인 (receipt_id 선택 사항)

---

## 9. 구현 순서

1. **DB 마이그레이션** — `office/sql/create_receipts.sql` 생성 및 실행
2. **uploads 디렉토리** — `uploads/receipts/` 생성 + `.htaccess` 작성
3. **office_helper.php** — `link_receipt()`, `unlink_receipt_by_purchase()` 추가
4. **receipts/add.php** — 영수증 등록 폼 + 파일 업로드
5. **receipts/list.php** — 영수증 목록 + 상태 배지
6. **receipts/delete.php** — 등록됨 상태 보호 + 파일 삭제
7. **receipts/edit.php** — 수정 폼
8. **receipts/view.php** — 첨부파일 서빙
9. **ajax_search_receipts.php** — 미사용 필터 검색 AJAX
10. **product_purchase/add.php** — 영수증 모달 추가
11. **equipment_purchase/add.php** — 영수증 모달 추가
12. **product_purchase/delete.php** — unlink_receipt 추가
13. **equipment_purchase/delete.php** — unlink_receipt 추가
14. **네비게이션** — office header에 '영수증 관리' 메뉴 추가

---

## 10. 완료 기준 (Plan 연계)

| # | 기준 | 구현 위치 |
|---|------|-----------|
| 1 | 영수증 등록·수정·삭제·목록 동작 | receipts/ |
| 2 | JPG/PNG/PDF 첨부 및 보기 동작 | add.php, view.php |
| 3 | 업체명 입력 시 suppliers 자동완성 | add.php (datalist) |
| 4 | 상품구매 등록 시 영수증 불러오기 → 자동 채움 | product_purchase/add.php |
| 5 | 비품구매 등록 시 동일 | equipment_purchase/add.php |
| 6 | 파일 보안 (허용 타입만, 난수 파일명) | add.php + .htaccess |
| 7 | 사용된 영수증은 모달에 미표시 | ajax_search_receipts.php |
| 8 | 영수증 목록에 상태 배지 표시 | list.php |
| 9 | 지출 저장 시 영수증 상태 업데이트 | */add.php + link_receipt() |
| 10 | 지출 삭제 시 영수증 복원 | */delete.php + unlink_receipt() |

---

## 11. 구현 가이드

### 11.1 모듈 맵

| 모듈 | 파일 | 우선순위 |
|------|------|----------|
| M1: DB + 인프라 | create_receipts.sql, uploads/.htaccess | 1 |
| M2: 헬퍼 함수 | office_helper.php (+2함수) | 1 |
| M3: 영수증 등록 | receipts/add.php | 2 |
| M4: 영수증 목록 | receipts/list.php | 2 |
| M5: 영수증 삭제/수정/보기 | receipts/delete.php, edit.php, view.php | 3 |
| M6: AJAX 검색 | ajax_search_receipts.php | 3 |
| M7: 구매 연동 | */add.php (모달), */delete.php (복원) | 4 |
| M8: 네비게이션 | partials/header.php | 4 |

### 11.2 세션 플랜

```
Session 1: M1 + M2 + M3 (DB, 헬퍼, 영수증 등록)
Session 2: M4 + M5 + M6 (목록, 수정/삭제, AJAX)
Session 3: M7 + M8 (구매 연동, 네비게이션)
```
