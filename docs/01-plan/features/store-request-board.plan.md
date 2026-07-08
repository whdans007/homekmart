# Plan: 점포 요청사항 게시판 (store-request-board)

> **Feature**: store-request-board
> **Phase**: Plan
> **Date**: 2026-07-07
> **Author**: whdans007

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 점포(store)가 재고부족·상품요청·기타 건의사항을 물류센터(logistics)에 전달할 공식 채널이 없어 전화/구두로만 소통되어 누락·지연이 발생함 |
| **Solution** | `store` 앱에 요청 등록/조회 화면을, `logistics` 앱에 전체 요청 조회·답변·상태변경 화면을 추가하는 게시판형 기능. 두 앱이 동일 DB(`u622428657_homekmart`, 공용 `lc_*` 테이블)를 공유하므로 신규 테이블 2개로 즉시 연동 |
| **Function/UX Effect** | 점포: 요청 등록 후 상태(대기/처리중/완료)와 물류센터 답변을 게시판 상세에서 확인. 물류센터: 사이드바 뱃지로 미확인(대기) 요청 건수를 즉시 인지, 목록에서 상태변경·답변 작성 |
| **Core Value** | 점포↔물류센터 요청/응답 이력이 게시판 형태로 남아 추적 가능. 기존 주문(Order) 플로우와 동일한 인증/DB 접근 패턴을 재사용하여 낮은 리스크로 빠르게 구현 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 점포의 요청사항이 비공식 채널로만 전달되어 누락/지연됨 |
| **WHO** | 점포 사용자(`store_require_store_user`), 물류센터 스태프(`lc_is_staff`) |
| **RISK** | 다른 점포의 요청 열람(권한 누수), 상태/답변 갱신 시 동시성 문제 |
| **SUCCESS** | 점포가 등록한 요청을 물류센터가 사이드바 뱃지로 인지하고, 답변·상태변경 후 점포가 결과를 확인할 수 있다 |
| **SCOPE** | 요청 등록/목록/상세 + 댓글형 답변 + 상태(대기/처리중/완료) 관리. 카테고리·이미지첨부·실시간 알림은 범위 외 |

---

## 1. User Intent Discovery

### 1.1 핵심 문제
- 점포는 재고부족, 상품요청, 기타 건의사항이 있어도 물류센터에 전달할 시스템상의 창구가 없음
- 물류센터는 어떤 점포가 무엇을 요청했는지 이력으로 남지 않아 추적 불가능
- 처리 여부(확인했는지/진행중인지/완료했는지)를 점포가 알 방법이 없음

### 1.2 대상 사용자
| 역할 | 수행 업무 |
|------|-----------|
| 점포(store) 사용자 | 요청 등록, 본인 점포 요청 목록/상태/답변 확인, 추가 댓글 작성 |
| 물류센터(logistics) 스태프 | 전체 점포 요청 목록 확인(사이드바 뱃지), 답변 작성, 상태 변경 |

### 1.3 성공 기준 (Success Criteria)
- [ ] **SC-1**: 점포는 제목+내용으로 요청을 등록할 수 있다 (`store/request_new.php`)
- [ ] **SC-2**: 점포는 본인 점포가 등록한 요청만 목록/상세로 볼 수 있다 (타 점포 요청 열람 불가)
- [ ] **SC-3**: 물류센터는 전 점포의 요청을 목록으로 볼 수 있고, 상태(대기/처리중/완료) 필터가 가능하다
- [ ] **SC-4**: 물류센터 사이드바에 상태='대기'인 요청 건수가 뱃지로 표시된다 (기존 Order List 뱃지와 동일 패턴)
- [ ] **SC-5**: 물류센터·점포 양측 모두 요청 상세 화면에서 댓글(답변)을 남길 수 있다
- [ ] **SC-6**: 물류센터는 요청 상세에서 상태를 변경할 수 있다 (점포는 상태 변경 불가, 조회만)

---

## 2. Alternatives Explored

| 방식 | 장점 | 단점 | 결정 |
|------|------|------|------|
| **A: 신규 전용 테이블(`lc_store_requests` + 댓글 테이블) + 페이지 신규 추가** | 기존 주문 테이블과 독립적, 게시판 이력 관리에 적합, 기존 인증/DB 패턴 재사용 | 테이블 2개 신규 생성 필요 | **✅ 채택** |
| B: `lc_orders.notes` 필드를 요청 창구로 겸용 | 신규 테이블 불필요 | 주문과 요청은 목적이 다름(재고부족/건의 등은 특정 주문에 종속되지 않음), 이력·상태 관리 불가 | ❌ |
| C: 외부 협업툴(Slack 등) 연동 | 구현 최소 | 이 프로젝트 스택(PHP/MySQL, 인증 분리)과 무관, 별도 계정/알림 인프라 필요 | ❌ 과도 |

---

## 3. YAGNI Review

### 3.1 1차 버전 포함 (In Scope)
- [x] 신규 테이블 `lc_store_requests` (요청 원글: 점포/제목/내용/상태/작성자/작성일)
- [x] 신규 테이블 `lc_store_request_comments` (답변 스레드: 요청/작성측(store|logistics)/작성자/내용/작성일)
- [x] `store/request_new.php` — 요청 등록 폼 (제목/내용)
- [x] `store/requests.php` — 본인 점포 요청 목록 (상태 뱃지 표시)
- [x] `store/request_detail.php` — 요청 상세 + 댓글 스레드 열람 + 댓글 작성
- [x] `logistics/requests.php` — 전체 점포 요청 목록 (점포명/상태 필터), 사이드바 뱃지(대기 건수)
- [x] `logistics/request_detail.php` — 요청 상세 + 댓글 스레드 열람/작성 + 상태 변경(대기/처리중/완료)
- [x] `store/partials/header.php`, `logistics/partials/header.php` 내비게이션에 메뉴 추가

### 3.2 2차 버전으로 미룸 (Out of Scope)
- [ ] 요청 유형 카테고리 구분(재고부족/상품요청/기타 등)
- [ ] 이미지 첨부
- [ ] 실시간 팝업 알림 (신규 주문 팝업과 유사한 폴링 알림)
- [ ] 요청 원글 수정/삭제
- [ ] 이메일/문자 등 외부 알림 연동

---

## 4. Requirements

### 4.1 기능 요구사항 (FR)
| ID | 요구사항 |
|----|----------|
| FR-1 | 점포는 제목(필수)+내용(필수)으로 요청을 등록한다. 등록 시 상태는 자동으로 '대기'가 된다 |
| FR-2 | 점포 요청 목록/상세는 `store_id = 로그인한 점포`로 필터링되어, 본인 점포 요청만 조회 가능하다 |
| FR-3 | 물류센터 요청 목록은 전 점포 대상이며 점포명·상태로 필터링 가능하다 |
| FR-4 | 물류센터 사이드바 메뉴에 상태='대기'인 요청 건수가 뱃지로 표시된다 |
| FR-5 | 요청 상세 화면에서 점포/물류센터 양측 모두 댓글(답변)을 작성할 수 있다 |
| FR-6 | 물류센터는 요청 상세에서 상태를 대기/처리중/완료 중 하나로 변경할 수 있다. 점포는 상태를 변경할 수 없다 |
| FR-7 | 목록/상세 화면 모두 상태값에 따라 배지 색상(대기=회색·노랑 계열, 처리중=파랑, 완료=초록)으로 구분 표시한다 |

### 4.2 비기능 요구사항 (NFR)
| ID | 요구사항 |
|----|----------|
| NFR-1 | 모든 쓰기 작업은 Prepared Statement + CSRF 토큰 검증(`store_verify_csrf`/`lc_verify_csrf`) |
| NFR-2 | 점포 사용자가 URL 조작으로 타 점포 요청 상세에 접근 시 404/거부 처리 |
| NFR-3 | 물류센터 전용 화면(`logistics/requests.php`, `request_detail.php`)은 `lc_is_staff()`인 경우만 접근 가능 |
| NFR-4 | 기존 Order 관련 페이지/네비게이션과 스타일(teal 테마, FontAwesome, 뱃지 UI) 일관성 유지 |

---

## 5. 데이터 / 구현 스케치 (Plan 수준)

**신규 테이블**
```sql
CREATE TABLE lc_store_requests (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    store_id    INT NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     TEXT NOT NULL,
    status      ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
    created_by  INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 게시판';

CREATE TABLE lc_store_request_comments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    author_side ENUM('store','logistics') NOT NULL,
    author_id   INT NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    content     TEXT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES lc_store_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='점포 요청사항 댓글(답변) 스레드';
```

- **점포 작성자 표기**: `author_name`에 작성 시점 `full_name`/`username` 스냅샷 저장 (기존 `lc_orders` 등에서 이름 변경 이력 문제 없이 표시하는 패턴 없음 — 스냅샷으로 단순화)
- **사이드바 뱃지**: `logistics/partials/header.php`의 기존 `$_lc_pending_orders` 카운트 패턴과 동일하게 `SELECT COUNT(*) FROM lc_store_requests WHERE status='pending'` 추가
- **신규 파일**: `store/request_new.php`, `store/requests.php`, `store/request_detail.php`, `logistics/requests.php`, `logistics/request_detail.php`, `sql/lc_store_requests.sql` (마이그레이션)
- **수정 파일**: `store/partials/header.php`, `logistics/partials/header.php` (내비게이션 메뉴 추가)

> 상세 설계(화면 레이아웃, 폼 검증 규칙, 상태 전이 UI, 권한 체크 위치)는 Design 단계에서 확정.

---

## 6. Risks & Mitigations

| 리스크 | 영향 | 완화 |
|--------|------|------|
| 점포 사용자가 URL의 `id` 파라미터 조작으로 타 점포 요청 열람 시도 | 정보 노출 | 상세 조회 쿼리에 항상 `AND store_id = ?` 조건 포함, 없으면 404 |
| 물류센터 미확인 요청이 쌓여도 인지 못함 | 응대 지연 | 사이드바 뱃지 + (2차) 폴링 알림으로 확장 여지 남김 |
| 상태값과 댓글이 따로 놀아 "답변은 있는데 상태는 대기" 등 불일치 | 혼란 | 1차는 수동 변경으로 단순화하고 UI상 안내 문구로 보완 (자동 전이는 범위 외) |

---

## 7. Out of Scope (명시)
- 요청 카테고리 분류
- 이미지/파일 첨부
- 실시간 팝업 알림, 이메일/SMS 알림
- 요청 원글 수정/삭제, 댓글 수정/삭제
- 점포측 상태 변경 권한
