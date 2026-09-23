# Plan: 카테고리명 한글→영문 자동번역 버튼 + 카테고리 이미지 검색 버튼

**Feature**: mall-category-name-translate
**Date**: 2026-09-23
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | (1) 카테고리 관리 모달에서 영문명을 매번 관리자가 직접 입력해야 함. (2) 대분류 이미지를 고를 때(직전 기능으로 추가된 업로드 UI) 적당한 이미지를 찾으려면 매번 새 탭에서 직접 검색해야 함 |
| Solution | (1) 한글명 입력칸 옆에 "번역" 버튼 — 서버가 무료 비공식 Google 번역 엔드포인트로 한글→영문을 호출해 영문명 입력칸을 자동으로 채운다(자동 저장 아님, 기존처럼 "적용"을 눌러야 저장). (2) 대분류 행의 이미지 업로드 컨트롤 옆에 "사진 검색" 버튼 — 기존 상품 목록의 "Search Photo"(`products.php:835`, `name_ko + ' png'`로 구글 이미지 검색 새 탭)와 완전히 동일한 패턴이되, 검색어만 "카테고리 영문명 + 이모티콘 png"로 구성 |
| UX Effect | 한글명만 입력 → 번역 버튼으로 영문명 채움 → 사진 검색 버튼으로 그 영문명 기준 이모티콘풍 이미지를 새 탭에서 바로 검색 → 마음에 드는 이미지를 저장해 기존 업로드 버튼으로 올리는 흐름이 한 화면에서 이어짐 |
| Core Value | 영문명 입력 + 대분류 아이콘 이미지 찾기 수고 절감. 결과는 항상 초안/참고용이고 최종 확정은 관리자가 직접 함 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 사용자가 직접 요청. 프로젝트에 연결된 번역 API가 없어 사용자에게 방식을 물었고, "무료 비공식 Google 번역 엔드포인트"를 선택함(유료 API 키 발급/과금 원치 않음) |
| WHO | 카테고리를 관리하는 admin (`category_management` 권한) |
| RISK | 비공식 엔드포인트(`translate.googleapis.com/translate_a/single`)는 Google이 공식 지원하지 않아 예고 없이 막히거나 응답 형식이 바뀔 수 있음 — 실패해도 기존 수동 입력 흐름을 절대 막지 않아야 함(번역 실패 시 에러만 안내, 입력 자체는 그대로 가능) |
| SUCCESS | 한글명을 입력한 뒤 "번역" 버튼을 누르면 영문명 칸에 번역 결과가 채워진다. 실패해도 기존 입력/저장 흐름에 영향 없음 |
| SCOPE | 서버에 번역 프록시 AJAX 엔드포인트 1개 신규 추가 + `products.php` 카테고리 모달의 모든 한글명/영문명 쌍(대분류 행/소분류 행/소분류 추가폼/대분류 추가폼/"NEW" 대기행 — 총 5곳)에 번역 버튼 추가 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | 신규 AJAX 엔드포인트 `mall/admin/ajax/translate_text.php` — POST `{ text, csrf_token }` → `{ success: true, data: { translated: "..." } }`. 서버에서 비공식 번역 엔드포인트를 호출해 결과 파싱(정확한 URL은 섹션 4 참고 — 직접 curl로 검증 완료) | 필수 |
| F-02 | 인증: `is_logged_in()` + `has_permission('category_management')` 확인(다른 category 관련 엔드포인트와 동일 수준). CSRF 토큰 검증 | 필수 |
| F-03 | 카테고리 관리 모달의 5곳(대분류 행, 소분류 행, 소분류 추가폼, 대분류 추가폼, JS로 추가되는 "NEW" 대기행)에 한글명 입력칸 옆 "번역"(아이콘) 버튼 추가 — 클릭 시 그 행의 한글명 입력값을 번역 요청, 성공하면 같은 행의 영문명 입력칸에 결과를 채운다(기존 값은 덮어씀, 별도 확인 없음 — 초안이므로) | 필수 |
| F-04 | 한글명이 비어있으면 번역 버튼 클릭 시 아무 요청도 보내지 않고 조용히 무시 | 필수 |
| F-05 | 번역 요청 중에는 해당 버튼을 비활성화(중복 클릭 방지), 실패 시 `showFlash`로 에러 안내(기존 저장 실패 메시지와 동일한 톤) | 필수 |
| F-06 | 번역 버튼을 눌러 채운 영문명은 "변경/추가 내용 한번에 적용" 버튼을 눌러야 실제 저장됨 — 자동저장 아님(기존 흐름과 동일) | 필수 |
| F-07 | 대분류 행의 `.category-image-controls`(이미지 업로드/제거 버튼이 있는 영역, `mall-category-modal-image` 기능에서 추가됨)에 "사진 검색" 버튼 추가 — 클릭 시 그 행의 `.edit-category-name-en` **현재 입력값**(번역 버튼으로 방금 채운 값이든 직접 입력한 값이든, 저장 여부 무관)을 읽어 `https://www.google.com/search?tbm=isch&q=<영문명 + ' 이모티콘 png'를 urlencode>`를 새 탭(`window.open(..., '_blank')`)으로 연다 | 필수 |
| F-08 | 영문명이 비어있으면 "사진 검색" 버튼 클릭 시 아무 동작도 하지 않는다(F-04와 동일한 조용한 무시 원칙) | 필수 |
| F-09 | 기존 상품 목록의 "Search Photo"(`products.php:835`)는 `name_ko`를 서버 렌더링 시점 값으로 정적 `<a href>`를 생성하지만, 이 기능은 **입력 중인 값을 즉시 반영**해야 하므로(번역 직후 바로 검색하는 흐름) 정적 `<a>`가 아니라 클릭 시 JS가 현재 input 값을 읽어 `window.open()`하는 방식으로 구현한다 | 필수 |

### 1.2 비기능 요구사항

- 외부 API 타임아웃을 짧게 설정(예: 5초) — 응답이 없으면 실패 처리하고 관리자 입력을 막지 않는다.
- 번역 대상 텍스트는 카테고리명(짧은 단어/구)이므로 대량 텍스트 처리나 요청 제한(rate limit) 로직은 범위 밖.
- 다국어 라벨(`lang/ko.json`/`lang/en.json`)에 번역 버튼 title/에러 메시지 키 추가.

---

## 2. 범위

### In Scope
- `mall/admin/ajax/translate_text.php` — **신규** 번역 프록시 엔드포인트
- `mall/admin/products.php` — 카테고리 모달 마크업(번역 버튼 5곳 + 대분류 사진 검색 버튼 1곳) + JS(클릭 핸들러 전부)
- `lang/ko.json`, `lang/en.json` — 신규 라벨/메시지 키

### Out of Scope
- 유료 번역 API 연동(선택 안 함)
- 카테고리명 외 다른 필드(상품명 등) 번역 — 요청 범위 아님
- 번역 결과의 자동 저장(항상 기존 "적용" 버튼을 거쳐야 함)
- 다른 언어 쌍(한↔영 외) 지원
- 사진 검색 결과 이미지를 자동으로 가져와 업로드하는 것 — 어디까지나 새 탭에서 검색만 보여주고, 실제 업로드는 기존 "이미지 업로드" 버튼으로 관리자가 수동으로 함
- 소분류 행에 사진 검색 버튼 추가 (이미지 업로드 자체가 대분류 전용이므로 동일하게 대분류만)

---

## 3. 현재 구조 분석

- `mall/admin/products.php:1082-1092` — 대분류 행 (`.edit-category-name` / `.edit-category-name-en`)
- `mall/admin/products.php:1096-1102` — 소분류 행 (동일 클래스)
- `mall/admin/products.php:1108-1112` — 소분류 추가 폼 (`name="name"` / `name="name_en"`, 클래스 없음)
- `mall/admin/products.php:1123-1127` — 대분류 추가 폼 (동일 구조)
- `mall/admin/products.php:1600~` — `addPendingCategoryRow()` JS 함수가 생성하는 "NEW" 대기 행 (`.pending-name` / `.pending-name-en`)
- 참고할 기존 AJAX 패턴: `mall/admin/ajax/save_category.php` 상단의 인증/CSRF 체크, `json_error()` 헬퍼

---

## 4. 신규 AJAX 계약 (translate_text.php)

**Request** (POST, `application/x-www-form-urlencoded`):
```
text        : string (번역할 한글 카테고리명, 필수)
csrf_token  : string
```

**동작** (Claude가 curl로 직접 검증한 실제 동작하는 엔드포인트/형식 — 아래 그대로 구현):
```php
$url = 'https://clients5.google.com/translate_a/t?client=dict-chrome-ex&sl=ko&tl=en&q=' . rawurlencode($text);
// cURL, CURLOPT_CONNECTTIMEOUT=5, CURLOPT_TIMEOUT=5, User-Agent: Mozilla/5.0 헤더 지정(없으면 막히는 경우 있음)
// 응답 형식: 단순 JSON 배열 1개 — 예: ["frozen food"] (rawurlencode 필수 — urlencode/미인코딩 시 인코딩 깨짐 확인됨)
// $translated = json_decode($body, true); $result = $translated[0] ?? null;
```
참고: `translate.googleapis.com/translate_a/single?client=gtx&...` (일반적으로 널리 쓰이는 엔드포인트)는 **이 서버 네트워크에서 현재 차단되어 "Sorry..." HTML 응답**을 반환하는 것을 확인함 — 반드시 위 `clients5.google.com` + `client=dict-chrome-ex` 조합을 사용할 것. 응답이 HTML(차단 페이지)이면 `json_decode`가 실패하므로 이를 번역 실패로 처리한다.

**Response**:
```json
{ "success": true, "data": { "translated": "Ramen" } }
{ "success": false, "error": { "code": "TRANSLATE_FAILED", "message": "번역에 실패했습니다" } }
```

---

## 5. Success Criteria

- SC-1: 카테고리 관리 모달의 5곳 모두에서 한글명 입력 후 "번역" 버튼을 누르면 영문명 칸이 채워진다.
- SC-2: 한글명이 비어있으면 번역 버튼이 아무 동작도 하지 않는다.
- SC-3: 외부 API가 실패/타임아웃 되어도 화면이 멈추거나 에러로 전체가 깨지지 않고, 안내 메시지만 뜨고 기존처럼 수동 입력이 가능하다.
- SC-4: 번역 버튼을 눌러 채운 값은 "적용" 버튼을 눌러야 실제 DB에 저장된다(기존 흐름 그대로).
- SC-5: 기존 카테고리 추가/수정/삭제/이미지 업로드 기능은 회귀 없이 그대로 동작한다.
- SC-6: 대분류 행에서 영문명을 입력(또는 번역 버튼으로 채움)한 뒤 "사진 검색" 버튼을 누르면 `google.com/search?tbm=isch&q=<영문명>+이모티콘+png`가 새 탭으로 열린다.
- SC-7: 영문명이 비어있는 상태에서 "사진 검색"을 누르면 아무 일도 일어나지 않는다(빈 검색어로 새 탭이 열리지 않음).
- SC-8: 소분류 행에는 사진 검색 버튼이 없다.

---

## 6. 리스크 & 대응

| 리스크 | 대응 |
|--------|------|
| 비공식 엔드포인트가 막히거나 형식이 바뀌어 파싱 실패 | try/catch + JSON 파싱 실패 시 명확한 에러 반환, 프론트는 실패해도 수동 입력 흐름 그대로 유지 |
| 외부 요청이 느려서 관리자 화면이 멈춘 것처럼 보임 | cURL 타임아웃 5초 고정 + 버튼에 로딩 상태 표시 |
| 관리자 화면이 외부로 나가는 요청의 통로(오픈 프록시)로 악용될 위험 | 로그인 + `category_management` 권한 필수, 텍스트 길이 제한(예: 100자) |

---

## 7. 다음 단계

Codex가 이 Plan 문서를 스펙으로 구현 (워크트리 `codex/mall-category-name-translate`에서 작업 → Claude Code가 로컬에서 실제 번역 호출 테스트 후 `/code-review`, 병합).
