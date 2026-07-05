# Plan: Supplier 검색 + 빠른 등록 모달
**Feature**: supplier-search-modal
**Date**: 2026-05-31
**Phase**: Plan

---

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | 입고 등록 시 Supplier가 단순 드롭다운이라 업체가 많을수록 선택이 불편함 |
| Solution | 타이핑으로 실시간 검색하는 자동완성 UI + 없으면 모달로 즉시 등록 |
| UX Effect | 클릭 수 감소, 키보드만으로 Supplier 선택/등록 완결 가능 |
| Core Value | 입고 등록 흐름을 끊지 않고 Supplier 관리까지 인라인 처리 |

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| WHY | 업체 목록이 늘수록 드롭다운 UX 저하. 새 업체 등록 위해 다른 페이지로 이동하면 입고 입력 내용 날아감 |
| WHO | 물류센터 직원 (inbound_add.php 사용자) |
| RISK | suppliers 테이블은 메인 DB (sunset). 물류 DB와 다를 수 있으므로 DB 연결 분리 주의 |
| SUCCESS | 검색 타이핑 → 결과 선택 or 없으면 모달 등록 → 자동 선택까지 키보드 이탈 없이 완결 |
| SCOPE | inbound_add.php UI 변경 + AJAX 2개 추가. 기존 로직/DB 구조 변경 없음 |

---

## 1. 요구사항

### 1.1 기능 요구사항

| ID | 요구사항 | 우선순위 |
|----|---------|---------|
| F-01 | Supplier 드롭다운을 텍스트 입력 + 자동완성 드롭다운으로 교체 | 필수 |
| F-02 | 타이핑 시 AJAX 실시간 검색 (debounce 300ms) | 필수 |
| F-03 | 검색 결과 없을 때 "등록" 옵션 표시 | 필수 |
| F-04 | "등록" 클릭 시 인라인 모달 열림 (이름/담당자/전화/이메일) | 필수 |
| F-05 | 모달 저장 후 새 Supplier 자동 선택 + 드롭다운 닫힘 | 필수 |
| F-06 | 기존 hidden input (name="supplier_id") 값 유지 → POST 호환 | 필수 |
| F-07 | ESC로 드롭다운/모달 닫기, 키보드 탐색 지원 | 선택 |

### 1.2 비기능 요구사항

- 검색: 2글자 이상 타이핑 시 AJAX 호출
- 빈 입력: 전체 목록 표시 (최대 20개)
- 모달: 이름만 필수, 나머지 선택
- 저장 성공 시 페이지 새로고침 없이 인라인 처리

---

## 2. 범위

### In Scope
- `logistics/inbound_add.php` — Supplier 선택 UI 교체
- `logistics/ajax/search_supplier.php` — 신규 AJAX (검색)
- `logistics/ajax/quick_create_supplier.php` — 신규 AJAX (등록)

### Out of Scope
- Supplier 관리 페이지 (별도 기획)
- 다른 페이지의 Supplier 드롭다운 (단, 동일 패턴 재활용 가능)
- Supplier 수정/삭제

---

## 3. DB 분석

suppliers 테이블 (메인 DB):
```sql
id, name, contact_person, phone, email, address, created_at, updated_at
```

- `inbound_add.php`는 `get_lc_db()` (물류 DB) + suppliers 조회를 같이 함
- suppliers는 메인 DB에 있음 → `get_db_connection()` 또는 동일 DB 확인 필요
- 기존 코드: `$conn->query("SELECT id, name FROM suppliers ...")` → 물류 DB에서 조회 중
- **결론**: 현재 코드 기준으로 `get_lc_db()` 연결에서 suppliers 접근 가능 → 동일 DB

---

## 4. 구현 계획

### Phase 1: AJAX 엔드포인트
1. `logistics/ajax/search_supplier.php` — GET ?q= → JSON 배열
2. `logistics/ajax/quick_create_supplier.php` — POST → JSON {success, supplier}

### Phase 2: UI 교체 (inbound_add.php)
1. `<select name="supplier_id">` → `<div>` + hidden input + text input
2. 드롭다운 패널 (자동완성 결과)
3. "새 Supplier 등록" 모달 HTML
4. JavaScript: 검색 debounce, 선택, 모달 열기/닫기, AJAX 저장

---

## 5. 성공 기준

- [ ] 타이핑 후 300ms 내 검색 결과 드롭다운 표시
- [ ] 결과 선택 시 hidden input에 supplier_id 값 설정
- [ ] 결과 없을 때 "새로 등록" 버튼 노출
- [ ] 모달에서 이름만 입력 후 저장 → 새 supplier 자동 선택
- [ ] 기존 POST 제출 동작 정상 유지 (supplier_id 전달)
