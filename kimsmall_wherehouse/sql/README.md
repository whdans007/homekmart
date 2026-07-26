# KIM'S MALL 창고 — DB 마이그레이션 실행 순서

`logistics/`(M TOWN 물류센터)를 복제해 만든 KIM'S MALL 창고용 `kw_*` 테이블을
생성하기 위한 마이그레이션 안내입니다. 로컬 환경에서는 DB에 접속할 수 없어
(운영 서버 전용 DB) 아래 절차는 **서버 배포 후 브라우저로 직접 실행**해야 합니다.

## 실행 전 확인사항

- 대상 서버에 `kimsmall_wherehouse/` 폴더가 배포되어 있어야 합니다.
- 모든 스크립트는 **재실행 안전(idempotent)** 합니다 — 이미 적용된 항목은
  `IF NOT EXISTS` / 컬럼 존재 확인 후 건너뜁니다. 순서만 지키면 여러 번 실행해도
  안전합니다.
- v12, v14는 원본에 브라우저 실행 스크립트가 없었으나, 다른 마이그레이션과 동일한
  패턴(컬럼 존재 확인 후 ALTER)으로 `run_migration_v12.php` / `run_migration_v14.php`를
  새로 작성했습니다. 다른 번호와 동일하게 브라우저로 실행하면 됩니다.
- v13은 원본 `.sql` 대신 `run_add_barcode_column.php`가 동일한 역할(브라우저 실행판)을
  하므로 그것으로 대체합니다.

## 실행 순서

| 순서 | 파일 | 실행 방법 | 내용 |
|----|------|----------|------|
| 1 | `run_migration.php` | 브라우저 | 기본 테이블 5종 생성 (`kw_products`, `kw_inbound`, `kw_inventory`, `kw_orders`, `kw_order_items`) |
| 2 | `run_migration_v2.php` | 브라우저 | 브랜드/카테고리 테이블, 바코드 컬럼 3종(`barcode_unit`/`barcode_box`/`barcode_logistics`) 추가, 기존 `barcode` 컬럼 제거 |
| 3 | `run_migration_v3.php` | 브라우저 | — |
| 4 | `run_migration_v4.php` | 브라우저 | 컬럼 추가 |
| 5 | `run_migration_v5.php` | 브라우저 | `kw_inbound_batches` 테이블, `kw_inbound.batch_id` 컬럼 추가 |
| 6 | `run_migration_v6.php` | 브라우저 | — |
| 7 | `run_migration_v7.php` | 브라우저 | — |
| 8 | `run_migration_v8.php` | 브라우저 | — |
| 9 | `run_migration_v9.php` | 브라우저 | ENUM 값 추가 |
| 10 | `run_migration_v10.php` | 브라우저 | — |
| 11 | `run_migration_v11.php` | 브라우저 | — |
| 12 | `run_migration_v12.php` | 브라우저 | `kw_inventory.storage_location` 컬럼 추가 |
| 13 | `run_add_barcode_column.php` | 브라우저 | `kw_products.barcode` 컬럼 추가 (v13.sql 대체) |
| 14 | `run_migration_v14.php` | 브라우저 | `kw_inbound_batches.confirmed_at`/`confirmed_by` 컬럼 추가 (v6의 `is_confirmed` 이후에 실행해야 함) |
| 15 | `run_migration_v15.php` | 브라우저 | — |
| 16 | `run_migration_v16.php` | 브라우저 | — |
| 17 | `run_migration_v17.php` | 브라우저 | `kw_orders.status` ENUM에 `draft` 추가 (지점출고 임시저장 워크플로) |
| 18 | `run_migration_v18.php` | 브라우저 | 재실행 가드 + lot 수량 스냅샷 검증 포함 |
| 19 | `run_migration_v19.php` | 브라우저 | — |
| 20 | `run_migration_v20.php` | 브라우저 | — |
| 21 | `run_migration_v21.php` | 브라우저 | — |
| 22 | `run_migration_v22.php` | 브라우저 | PACK 단위 추가 (재실행 가드 + 적용 전/후 검증 포함) |

## 알려진 버그 수정 이력

원본 `logistics/`를 복제하는 과정에서 발견된 문제들을 수정했습니다.

- **v3 (500 에러)**: `kw_brands`/`kw_categories`는 v2에서 이미 `name_en`/`name_ko`
  컬럼으로 생성되므로, v3의 `CHANGE COLUMN name name_en` 단계는 대상 컬럼이 없어
  항상 실패합니다. PHP 8.1+ 환경은 mysqli 쿼리 실패 시 기본적으로 예외를 던지는데
  이 예외를 처리하지 않아 HTTP 500이 발생했습니다(원본 `logistics/`에도 동일한
  잠재 버그가 있었으나 우연히 드러나지 않았던 것). try/catch로 감싸 이미 처리된
  것으로 보고 건너뛰도록 수정했습니다. **v3를 다시 실행하면 정상 동작합니다.**
- **v16/v20/v21 (파일명 불일치)**: 각 러너가 `kw_migration_v16/20/21.sql`을
  읽도록 되어 있었지만, 실제 파일명은 `lc_migration_v16/20/21.sql`로 남아있어
  `file_get_contents`가 실패했습니다. 파일명을 `kw_migration_v16/20/21.sql`로
  변경해 해결했습니다.
- **v2/v5 (FK 제약조건 이름 충돌)**: InnoDB는 FK 제약조건(CONSTRAINT) 이름이
  **테이블이 아닌 스키마(DB) 전체에서 유일**해야 합니다. v2의 `fk_lc_products_brand`
  / `fk_lc_products_category`, v5의 `fk_inbound_batch`는 테이블 접두사 변환(`lc_`→`kw_`)
  대상이 아니어서(단어 경계상 매칭 안 됨 / 애초에 접두사 없음) 원본 M TOWN
  물류센터가 이미 사용 중인 이름과 그대로 충돌했습니다(errno 121 Duplicate key).
  `fk_kw_products_brand` / `fk_kw_products_category` / `fk_kw_inbound_batch`로
  이름을 바꿔 해결했습니다. **v2는 이 충돌이 v2 자체의 "Duplicate" 스킵 처리 로직에
  가려져 에러 없이 조용히 건너뛰어졌을 가능성이 높으므로, kw_products에 FK가
  실제로 걸렸는지 확인 차 v2를 다시 실행하는 것을 권장합니다. v5도 다시 실행하세요.**
- **v4~v11, v15, v17~v21 (잠재적 500 위험)**: 위 파일들도 v3와 같은 이유로
  예외 처리가 없어 쿼리 실패 시 500이 날 수 있었습니다. 연결 직후
  `mysqli_report(MYSQLI_REPORT_OFF)`를 추가해 PHP 8.1 이전과 동일하게
  쿼리 실패 시 `false`를 반환하도록 복원했습니다(각 스크립트의 기존 if/else
  오류 처리 로직이 원래 의도대로 동작).
- **v12/v14 (러너 스크립트 없음)**: 원본 `logistics/`에는 이 두 번호만 CLI용
  `.sql`만 있고 브라우저 실행 스크립트가 없었습니다. 다른 번호와 동일한
  패턴(컬럼 존재 확인 → ALTER, 재실행 안전)으로 `run_migration_v12.php` /
  `run_migration_v14.php`를 새로 작성했습니다.

## 실행 방법 (브라우저)

`http://main.homekmart.net/kimsmall_wherehouse/sql/run_migration.php` 부터 위 표 순서대로
URL을 방문합니다. 각 페이지에서 결과(✅ 생성 완료 / ➖ 이미 존재)를 확인한 뒤 다음 순서로 넘어가세요.

## 완료 후 정리

- 위 마이그레이션 스크립트들(`run_*.php`, `*.sql`)은 **보안을 위해 실행 후 삭제하거나
  파일명을 바꾸는 것을 권장**합니다 (`run_migration.php` 자체 안내 문구 참고).
- 마이그레이션 완료 후 관리자 페이지에서 점포명 `KIMS MALL WHEREHOUSE (킴스몰 창고)`를 등록하고
  KIM'S MALL 창고 직원 계정을 이 점포로 배정해야 로그인이 가능합니다
  (`lib/auth.php`의 `kw_is_logistics_department()` 참고).
