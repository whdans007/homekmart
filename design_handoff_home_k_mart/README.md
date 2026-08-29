# Handoff: HOME K MART — 필리핀 K-Grocery 쇼핑 웹앱

## Overview
필리핀(메트로 마닐라) 거주자를 대상으로 한국 식품 그로서리(라면·김치·장류·김·즉석밥·냉동·과자·전통차)를 파는 모바일 커머스 앱의 전체 화면 세트입니다. 통화는 필리핀 페소(₱), UI는 **한국어/English 전환**을 지원하고, 필리핀 주소 체계의 한계를 보완하는 **지도 핀 배송지 저장**과 **회원 전용 주문(휴대폰 OTP 가입)**, **GCash·Maya 결제 승인 리다이렉트**가 핵심입니다.

대상 뷰포트: **402 × 874 (iPhone 16 Pro 논리 픽셀)**. 데스크톱 웹앱으로 확장할 경우 이 폭을 모바일 브레이크포인트의 기준으로 삼으세요.

## About the Design Files
이 번들의 HTML 파일은 **디자인 레퍼런스**입니다 — 의도한 외형과 동작을 보여주는 프로토타입이지, 그대로 가져다 쓰는 프로덕션 코드가 아닙니다. 해야 할 일은 **여러분의 코드베이스(React/Next.js/Vue 등)의 기존 환경·패턴·라이브러리로 이 디자인을 다시 구현하는 것**입니다.

- `HOME K MART App.dc.html` — 앱 전체(14개 화면). 커스텀 템플릿 런타임 위에 작성돼 있어 문법(`{{ }}`, `<sc-for>`, `<sc-if>`)은 그대로 옮기지 말고, **인라인 style 값·문구·상태 로직만** 참고해 여러분의 컴포넌트로 옮기세요.
- `map-pin.html` — 배송지 설정 화면. 이쪽은 **평범한 HTML + Leaflet**이라 로직을 거의 그대로 이식할 수 있습니다.

## Fidelity
**High-fidelity.** 색상·타이포·간격·인터랙션이 모두 확정값입니다. 픽셀 단위로 재현하되, 코드베이스에 이미 디자인 시스템이 있다면 아래 토큰을 그 시스템의 토큰에 매핑하세요.

기반 디자인 시스템은 **Wanted Design System**입니다(`wanted-tokens.css`에 색·타입 스케일 전량 포함). 폰트는 **Pretendard JP**(본문/UI), 숫자 타이머는 SF Mono.

---

## Design Tokens

### 색 (라이트)
| 용도 | 값 |
|---|---|
| 배경 | `--bg-normal #FFFFFF`, `--bg-alternative #F7F7F8` |
| 텍스트 | `--label-normal #171719`, `--label-neutral rgba(46,47,51,.88)`, `--label-alternative rgba(55,56,60,.61)`, `--label-assistive rgba(55,56,60,.28)` |
| 라인 | `--line-normal rgba(112,115,124,.22)`, `--line-alternative` 더 옅게 |
| 필 | `--fill-normal rgba(112,115,124,.05)`, `--fill-strong rgba(112,115,124,.16)` |
| 액션(브랜드 블루) | `--primary-normal #0066FF`, `--primary-strong`, `--primary-bg #EAF2FE` |
| 로고 딥블루 | `--brand-blue-deep #0B4EA2` |
| 할인/마감 레드 | `--brand-red #C8102E` (흰 배경 5.4:1) |
| 레드 위 글자 | `--on-brand-red #FFFFFF` |
| 성공 그린 | `--brand-green #00752E` |
| 별점 앰버 | `--brand-star #B87503` |
| 사진 위 라벨 플레이트 | `--plate rgba(255,255,255,.82)` + `backdrop-filter: blur(6px)` |
| 사진 타일 헤어라인 | `--tile-line rgba(23,23,25,.06)` (inset box-shadow 1px) |

### 색 (다크) — 같은 변수명을 오버라이드
`--bg-normal #17181A` / `--bg-alternative #0F1012` / `--label-normal #F2F3F5` / `--label-alternative rgba(233,236,242,.60)` / `--line-normal rgba(160,168,184,.24)` / `--fill-strong rgba(160,168,184,.20)` / `--primary-normal #4D8FFF` / `--primary-bg rgba(77,143,255,.18)` / **`--brand-red #FF7A85` + `--on-brand-red #17181A`**(7.1:1) / `--brand-green #4ADE80` / `--brand-star #FFC44D` / `--plate rgba(23,24,26,.72)` / `--tile-line rgba(255,255,255,.10)` / `--sticky-bg rgba(23,24,26,.92)`.

> **접근성 규칙**: 레드 배지 위 글자는 절대 흰색 고정 금지 — 테마별 `--on-brand-red`를 써야 AA(4.5:1)를 넘깁니다. 상품 톤 타일은 다크에서 배경색 쪽으로 80% 믹스, 카테고리 잉크는 흰색 쪽으로 62% 믹스해 생성합니다.

### 타이포 (Pretendard JP)
- Display3 / Title1~3 / Heading1~2 / Headline1 / Body1~2 / Label1~2 / Caption — `wanted-tokens.css`의 `--t-*`, `--ls-*` 사용.
- 실제 사용 빈도 높은 값: 화면 제목 `--t-title3`, 섹션 제목 `--t-title3`(작게는 `--t-heading2`), 상품명 600/15px/1.4, 가격 700/17px, 보조문구 500/12~13px/1.5, 버튼 700/15~16px.
- **본문 최소 15px, 캡션 12px 이하 금지**(전연령 대상).

### 형태·간격
- radius: 버튼 8/10/12, 카드·입력 12, 큰 카드 14~16, 기기 프레임 48, pill 999
- 터치 타깃 **최소 44×44**
- 화면 좌우 거터 20px, 섹션 간 8px 구분 밴드(`--bg-alternative`)
- 하단 고정 액션바: `position: sticky; bottom: 0` + `padding: 12px 16px 30px`(홈 인디케이터 여백)

---

## Screens / Views (14)

공통: 상단 안전영역 52~56px, 하단 탭바는 **홈·카테고리·검색·장바구니·마이**에서만 노출. 탭 아이콘은 선택 시 line → fill 변형(Wanted Icon).

1. **홈 (home)** — 로고+알림+장바구니(배지) / 검색 진입 버튼(테두리 `--primary-normal` 1.5px) / 배송지 행(핀 아이콘 + 짧은 주소 + "내일 도착") / 카테고리 8칸 그리드(56px 라운드 타일 + 한글 이니셜 마크) / 기획전 배너(블루 그라디언트 + 원형 오브) / 오늘의 특가(가로 스크롤 146px 카드 + 마감 타이머 `HH:MM:SS`) / 다시 담을 시간(3행 + "3개 한번에 담기") / 새로 들어온 한국 상품(2열 그리드).
2. **카테고리 (category)** — 좌측 118px 레일(선택 항목 3px 좌측 바 + 흰 배경) / 우측 하위분류 칩 + 상품 행(66px 썸네일, 상품명 최대 2줄, 가격 왼쪽·담기 버튼 오른쪽).
3. **검색 (search)** — 상단 검색 인풋(fill 배경) / 초기: 추천 검색어 칩 + "지금 많이 찾는 상품" 랭킹 5행(담기 포함) / 결과: 정렬 칩 3종 + 2열 그리드 / 무결과: 문구 2줄.
4. **상품 상세 (product)** — 1:1 히어로 이미지(뒤로/찜/장바구니 오버레이 헤더) / 배지(정품 직소싱·익일배송) / 이름·영문명 / 할인율+가격+정가 / 평점→리뷰 이동 / 스펙 5행(원산지·판매단위·보관·유통기한·배송) / 배송 안내 카드 / 함께 담으면 좋아요 / **하단 바: 구매방식(한 번만/정기배송 5%) + 수량 스테퍼 + 찜 + CTA**.
5. **리뷰 (review)** — 평균 평점 + 5단계 막대 / 필터 칩 4종 / 리뷰 카드(아바타·별·재구매 배지·사진 2장·본문·도움돼요).
6. **장바구니 (cart)** — 무료배송 진행바 / 상품 행(76px 썸네일, 수량 스테퍼, 삭제) / 결제 금액 요약 / 배송 정보 / 하단 CTA(비회원이면 "로그인하고 주문하기").
7. **회원가입·로그인 (auth)** — 3단계(가입) / 2단계(로그인). ① +63 번호 + 약관(전체 동의·필수 2·선택 1) ② OTP 6자리(재전송, 데모 자동입력) ③ 이름·이메일. 하단 CTA는 유효할 때만 활성(비활성은 `--fill-strong` + `--label-assistive`).
8. **배송지 설정 (address)** — `map-pin.html`. 탭 2개: **지도에서 핀** / **주소 입력**.
9. **주문서 (checkout)** — 배송지 카드(이름·전화·주소·랜드마크·핀 좌표) / 배송 방법 3종 라디오 / 결제수단 5종 / 포인트 토글 / 금액 요약 + VAT 12% 포함 고지 / 하단 결제 CTA.
10. **결제 승인 (pay)** — GCash·Maya·InstaPay 선택 시 노출. 회전 스피너 + 가맹점/수단/금액 + 3단계 진행 + 취소. 3초 후 자동으로 주문완료.
11. **주문완료 (done)** — 체크 원형 + 주문번호 + 4행 요약 + 주문상세/쇼핑계속.
12. **정기배송 (subscribe)** — 소개 카드 / 주기 2·4·6주 / 다음 배송일 / 담긴 품목 / 회차당 금액(5% 할인) / 시작 or 건너뛰기·해지.
13. **주문 내역 (orders)** — 주문 카드 3건, 배송중 건은 4단계 트래커, 액션(배송조회·리뷰쓰기·**재주문=전체 담기**).
14. **마이페이지 (my)** — 게스트: 가입 유도 카드(회원가입/로그인). 회원: 블루 그라디언트 프로필 + 포인트/쿠폰/주문 3분할 + 바로가기 2개 + 메뉴 8행(로그아웃 포함).

---

## Interactions & Behavior

- **빠른 담기(중요)**: 상품이 노출되는 모든 지점(특가·신상품·검색 결과/랭킹·카테고리·기획전)에서 담기 버튼 → 담긴 뒤 같은 자리가 **− 수량 +** 스테퍼로 전환(38px 높이, `--primary-bg` 배경 + 1.5px `--primary-normal` 테두리). 0이 되면 다시 담기 버튼.
- **일괄 담기**: "다시 담을 시간" 섹션 헤더의 `3개 한번에 담기`, 주문 내역의 `재주문`.
- **회원 게이트**: 비회원도 탐색·담기는 자유. 결제 진입 시에만 auth로 보내고, 가입 완료 후 **원래 목적지(checkout)로 복귀**.
- **배송지 저장**: 지도 화면은 iframe/별도 라우트로 띄우고 `postMessage`로 결과를 받습니다.
  - 저장: `{ type:"hkm-address-save", alias, mode:"pin"|"form"|"form+pin", line, short, landmark, coords, zip, name?, phone? }`
  - 취소: `{ type:"hkm-address-cancel" }`
- **결제**: `cod`·`card`는 즉시 주문완료. `gcash`·`maya`·`bank`는 pay 화면 3단계(0.9s → 2.1s → 3.0s) 후 완료. **결제 금액은 주문 시점에 스냅샷**으로 고정(장바구니를 비워도 완료 화면 금액이 0이 되면 안 됨).
- **토스트**: 하단 110px 위, `--toast-bg` 90% 불투명, 2.6초 후 자동 소멸, 우측에 "보기"(장바구니 이동).
- **애니메이션**: 화면 전환 `pushIn`(translateX 28px → 0, 240ms ease-out), 오버레이 `fadeIn` 200~300ms, 토스트 `toastIn` 200ms, 스피너 `spin` 0.9s linear. 과한 바운스 없음.
- **다크모드/언어**: 런타임 토글. 언어는 UI 문자열 80여 개 + 상품명·카테고리명·배송/결제 라벨·주문 상태·토스트까지 전환. 영문 모드에서는 상품 카드의 한글명이 보조 라인으로 내려갑니다(위계 반전).

### 폼 검증 (map-pin 주소 입력)
- 수령인: 필수
- 휴대폰: `^9\d{9}$` (+63 고정 prefix)
- 지역/시/바랑가이: **목록에 존재하는 값만** 허용(자유 입력 불가) — 지역 선택 시 시 초기화, 시 선택 시 ZIP 자동 입력·바랑가이 초기화
- 거리/건물명: 필수
- 랜드마크: **필수**(필리핀 배송 성공률의 핵심)
- 에러는 인풋 테두리 `--brand-red` + 하단 12px 메시지

---

## State Management

```
screen            현재 화면 키 (home | category | search | product | review | cart |
                  auth | address | checkout | pay | done | subscribe | orders | my | promo)
prev              뒤로가기 대상
user              null(게스트) | { name, phone, joined }
auth              { mode:'signup'|'login', step:0|1|2, phone, otp, name, email,
                    tos, privacy, marketing, err }
authNext          가입 완료 후 이동할 화면
cart              { [productId]: qty }
liked             { [productId]: bool }
addr              { alias, name, phone, line, short, landmark, coords, zip, mode }
pdId, qty         상세 상품 / 수량
buyMode           'once' | 'sub'
pay               'gcash'|'maya'|'card'|'bank'|'cod'
shipMode          'next'|'same'|'pick'
usePoints         boolean (기본 false)
paidTotal         주문 시점 결제금액 스냅샷
orderNo           주문번호
interval, subOn   정기배송 주기(2·4·6주), 활성 여부
query, sort, catIndex, reviewFilter, promoTab, secs(타이머)
lang, dark        'ko'|'en', boolean
```

### 커머스 규칙
- 무료배송 기준 **₱1,200**, 미만이면 기본 배송비 **₱79**
- 당일배송 추가 **₱120**(Makati·BGC·Ortigas 한정)
- 포인트 1pt = ₱1, 데모 잔액 240pts
- 정기배송 **5% 할인 + 배송비 무료**
- 표시가격은 **VAT 12% 포함**(별도 가산하지 않음)
- 가격 포맷: `₱` + `toLocaleString('en-PH')`

---

## Data Model

```ts
type Product = {
  id: number; kr: string; en: string;          // 한/영 상품명
  price: number; was: number;                   // was=0이면 할인 없음
  tint: string;                                 // 사진 없을 때의 톤 타일 색 (#RRGGBB)
  cat: number;                                  // CATS 인덱스
  rating: string; reviews: number;
  origin/originEn, keep/keepEn, unit/unitEn: string;
};
type Category = { kr; en; mark; tint; ink; subs: string[]; subsEn: string[] };
```
상품 15종 · 카테고리 8종의 실제 데이터가 `HOME K MART App.dc.html` 상단 `P` / `CATS` 배열에 있습니다. 그대로 시드로 쓰셔도 됩니다.

---

## Assets
- `assets/homekmart-logo.png` — 사용자 제공 로고. 다크 배경에서는 흰 플레이트(radius 8, padding 3/7) 위에 올립니다.
- 아이콘: **Wanted Icon** 24×24 글리프를 SVG 스프라이트(`<symbol id="i-*">`)로 인라인. 포함된 것: search, bell, bag, chev-left/right, plus, minus, close, check, location, send, menu, home(+fill), person(+fill), heart(+fill), star. `fill="currentColor"`이므로 부모의 `color`로 칠합니다. 별 아이콘만 Wanted 세트에 없어 임시 글리프이니, 실제 세트로 교체하세요.
- 상품 사진: 없음. 프로토타입은 드래그&드롭 슬롯(`image-slot.js`)으로 대체돼 있습니다. **실제 구현에서는 이 컴포넌트를 쓰지 말고** `<img>` + 상품 이미지 URL로 바꾸고, 톤 타일(`tint`)은 로딩 플레이스홀더 배경으로 재활용하세요.
- 지도: **Leaflet 1.9.4 + OpenStreetMap 타일**. 실서비스는 Google Maps JS API + Places/Geocoding으로 교체 권장 — 핀 UX와 저장 스키마(좌표+랜드마크)는 그대로 유지하세요. OSM을 계속 쓴다면 `© OpenStreetMap contributors` 저작자 표시는 필수입니다.
- 주소 데이터: `map-pin.html`의 `PH` 객체(8개 주 / 26개 시 / 바랑가이 + ZIP)와 `SPOTS`(핀 역지오코딩용 기준점 21개). 실서비스에서는 PSGC(Philippine Standard Geographic Code) 전체 데이터로 교체하세요.

## Files
| 파일 | 내용 |
|---|---|
| `HOME K MART App.dc.html` | 앱 14개 화면 전체 + 상품/카테고리 데이터 + 상태 로직 |
| `map-pin.html` | 배송지 설정(지도 핀 + 주소 입력 폼), Leaflet, 이식하기 쉬운 순수 HTML |
| `wanted-tokens.css` | Wanted Design System 색·타입·radius·shadow 토큰 전량 |
| `image-slot.js` | 프로토타입 전용 사진 슬롯 (프로덕션 이식 대상 아님) |
| `assets/homekmart-logo.png` | 로고 |

## 남은 작업(디자인 관점)
- 상품 실사진 적용 후 다크모드 대비 최종 점검
- Google Maps + Places 교체
- 소셜 로그인(Google·Apple) 실제 연동
- 타갈로그(Filipino) 로케일 추가
