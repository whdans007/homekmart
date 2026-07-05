# Plan: Discount Sticker Print (할인스티커 출력)

## Executive Summary

| 관점 | 내용 |
|------|------|
| Problem | 가격표 출력 메뉴에서 할인스티커를 별도 출력할 수 없음 |
| Solution | 라벨 종류 섹션 옆에 할인스티커 버튼 5종 추가, 클릭 즉시 2개 연속 출력 |
| UX Effect | 버튼 1번으로 즉시 출력 — 상품 스캔 불필요, 흐름 단절 없음 |
| Core Value | 할인행사 시 스티커 인쇄 속도 향상 |

## Context Anchor

| | |
|---|---|
| **WHY** | 할인행사 시 물리적 할인스티커를 빠르게 인쇄할 수 있어야 함 |
| **WHO** | 매장 직원 (가격표 출력 화면 사용자) |
| **RISK** | 기존 프라이싱/바코드 2p 흐름 방해 없어야 함 |
| **SUCCESS** | 버튼 1번 → 2개 스티커 인쇄 완료 |
| **SCOPE** | pricing/index.php + pricing/print.php 만 수정 |

## 1. Requirements

### 기능 요구사항
- FR-01: 라벨 종류 섹션 옆(또는 바로 아래)에 **할인스티커** 영역 추가
- FR-02: 선택 가능 할인율: `20%` `30%` `50%` `75%` `1+1`
- FR-03: 버튼 클릭 즉시 `print.php?mode=discount&discount=XX` 호출
- FR-04: 출력 레이아웃: 70mm×30mm 1장에 동일 스티커 2개 (좌/우 반반, 바코드 2p 참고)
- FR-05: 스티커 내용: 할인율(숫자) 크게 + "OFF" 텍스트 (1+1은 "1+1" 그대로)
- FR-06: 상품 스캔 불필요 — 할인율만 선택 후 출력

### 비기능 요구사항
- NFR-01: 기존 프라이싱/바코드 2p 기능 영향 없음
- NFR-02: autoprint=1 파라미터로 창 열리면 자동 인쇄 후 닫힘 (기존 패턴 동일)

## 2. Scope

**수정 파일:**
- `pricing/index.php` — 할인스티커 UI 추가 (버튼 5개)
- `pricing/print.php` — `mode=discount` 처리 추가

**신규 파일:** 없음

## 3. Implementation Notes

### index.php
- `라벨 종류` div 옆에 `할인스티커` div 추가 (동일한 flex 컨테이너 내)
- `printDiscountSticker(rate)` 함수: `window.open('print.php?mode=discount&discount='+rate+'&autoprint=1')`

### print.php
- `$printMode === 'discount'` 분기 추가
- `$discount = $_GET['discount'] ?? ''` 수신
- CSS: 빨간 배경, 흰 텍스트, 큰 숫자
- 레이아웃: `.label-2p` 참고하여 좌/우 반반
- JS barcode 렌더 불필요 (텍스트만)
