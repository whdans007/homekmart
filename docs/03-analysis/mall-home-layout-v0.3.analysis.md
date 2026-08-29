---
template: analysis
feature: mall-home-layout
date: 2026-08-13
author: whdans007
project: HOME K MART
---

# mall-home-layout v0.3 (module-4) Analysis Document (Check Phase)

> **Plan**: [mall-home-layout.plan.md](../01-plan/features/mall-home-layout.plan.md) (v0.2, FR-11~16)
> **Design**: [mall-home-layout.design.md](../02-design/features/mall-home-layout.design.md) (v0.3)
> **Scope**: module-4 (초안/발행 워크플로우) — 이전 module-1~3 Check 결과는 [mall-home-layout.analysis.md](./mall-home-layout.analysis.md) 참조
> **Method**: Static-only gap analysis (gap-detector agent), no live server available in this environment.

## 1. Match Rate

| Axis | Score | Basis |
|------|:-----:|-------|
| Structural | 100% | Design §11.1 module-4 파일 9개 전부 존재, 함수 시그니처 설계와 일치 |
| Functional | 92% | §5.4 v0.3 체크리스트 11항목 구현. 관리자 빌더 목록 자체에는 배너 플레이스홀더 없음(미리보기 화면에만 존재) |
| Contract | 95% | `publish_home_layout.php` 요청/응답이 §4.2와 3-way 일치 |
| **Overall** | **95%** | (100×0.2)+(92×0.4)+(95×0.4) = 94.8% |

## 2. bind_param / 핵심 불변식 재검증 — 전부 통과 (Critical 0건)

- **상수 참조 전달 버그 클래스** (shopping-mall/mall-home-layout에서 각 1회 발견됐던 것): 9개 파일 전체 재발 없음. `MALL_STORE_ID`는 모든 지점에서 지역변수로 먼저 대입됨.
- **타입 문자열 개수**: 전 호출 정확 일치.
- **"published는 오직 publish_home_layout.php로만 생성된다" 불변식**: `save_home_section.php`는 INSERT에 `status` 컬럼 자체가 없어 DEFAULT('draft')에만 의존, `$_POST['status']`를 읽지 않음. `delete`/`reorder`도 실제 SQL에 `status='draft'` 조건 존재. `publish_home_layout.php`의 트랜잭션(DELETE→INSERT...SELECT)이 BEGIN/COMMIT으로 묶여 있어 "0개 순간"이 없음.
- **고객 화면 플레이스홀더 미노출**: `index.php`는 `mall_render_home_section()`을 4번째 인자 없이 호출 → `$show_placeholder=false` 기본값 → 이미지 없는 배너는 고객에게 렌더링되지 않음. 확인됨.
- **`published_by` FK 대상**: `mall_get_last_publish_info()`가 `mall_members`가 아닌 `users` 테이블로 정확히 JOIN됨. 확인됨.
- **마이그레이션 재실행 안전성**: `SHOW COLUMNS ... LIKE 'status'` 가드가 컬럼 추가와 부트스트랩 발행 INSERT를 함께 감싸 재실행 시 중복 발행 없음. 확인됨.

## 3. Gap List — 발견 및 즉시 수정

### Important (전부 수정 완료)

| ID | 위치 | 문제 | 조치 |
|----|------|------|------|
| I-1 | `save_home_section.php` UPDATE | WHERE절에 `status='draft'` 없음(SELECT 가드에만 의존, 방어 계층 부재) | UPDATE 자체에도 `AND status = 'draft'` 추가 |
| I-2 | `publish/save/delete/reorder_home_sections.php` 4개 | `catch (Exception)`은 PHP `Error`(예: `prepare()`가 false 반환 후 `->bind_param()` 호출 시 발생하는 fatal)를 못 잡음 — 환경에 따라 JSON 대신 HTML 에러 페이지가 나가 프론트 파싱 실패 가능 | 4개 파일 전부 `catch (Throwable $e)`로 교체 |

### Minor (2건 수정, 1건 기각)

| ID | 위치 | 문제 | 조치 |
|----|------|------|------|
| M-1 | `publish_home_layout.php` draft count 쿼리 | raw 문자열 보간(값은 상수라 주입 위험 없음) | prepared statement로 교체 |
| M-2 | `save_home_section.php` MAX(sort_order) 쿼리 | `status='draft'` 미필터 → published 행까지 포함해 번호에 구멍 발생 가능 | `AND status = 'draft'` 추가 |
| M-3 | `reorder_home_sections.php` | `$updated`가 실제 매칭 건수가 아닌 "시도 횟수" — 잘못된/다른점포/이미발행된 id를 보내도 항상 성공으로 응답 | 사전 COUNT 쿼리로 실제 매칭 건수를 계산해 응답에 사용하도록 변경 |
| M-4(기각) | `admin/home_layout.php:17-18` | 카테고리 목록 쿼리에 try/catch 없음 | **기각** — 이 프로젝트의 다른 admin 페이지 전체가 동일한 관례(페이지 레벨 쿼리는 try/catch 없이 실패 시 백지 화면)를 따르고 있어, 이 파일만 다르게 만드는 것이 오히려 일관성을 해침. 별도 리팩토링 없이 유지 |

전체 재검증: **mall/ 트리 58개 파일 `php -l` 통과**.

## 4. Recommendation

Critical 0건, Important 2건은 즉시 수정, Minor는 실질적 위험이 있는 2건만 수정하고 1건은 기존 코드베이스 관례와의 일관성을 이유로 의도적으로 보류했습니다. Match Rate 95%로 Report 단계 진입 기준을 충족하나, module-1~3과 마찬가지로 **아직 라이브 DB에 마이그레이션을 적용하지 않았고 브라우저 실동작도 검증하지 않았습니다.**

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-08-13 | module-4 Check phase 분석, I-1/I-2/M-1/M-2/M-3 수정 | whdans007 |
