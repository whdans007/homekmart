# POS Daily Sales Report — Chart Improvements (Plan Addendum)

**Feature**: pos-daily-sales-report
**Date**: 2026-09-23
**Status**: Plan (small enhancement, no prior plan doc existed for `office/pos_data/report.php`)

---

## 배경

`office/pos_data/report.php`는 기존에 별도 Plan 문서 없이 구현되어 있던 화면. 디자인 피드백 도구를 통해 아래 요청이 들어와 이번에 문서화하고 개선한다.

- 대상 파일: `office/pos_data/report.php` (차트/JS 렌더링 부분만, PHP 집계 로직·`ajax_report_daily.php`는 변경 없음)
- 현재 데이터 흐름: `ajax_report_daily.php` → `pos_report_get_daily()`가 실제 데이터가 있는 날짜만 `rows`로 반환 (없는 날짜는 아예 응답에서 빠짐) → 프론트에서 `rows.map(r => r.sale_date)`를 그대로 차트 라벨로 사용.
- `sale_date`는 원본 텍스트 그대로 `MM-DD-YYYY` 포맷 (예: `09-01-2026`), `parsed_date`는 MySQL `DATE`→ 문자열로 내려오는 `YYYY-MM-DD` 정규화 값.

## 1. Daily NET SALES 차트 — 월 전체 표시 + 이월 공백 + 어제 강조

### 요청 (원문)
> 1일부터 말일까지 한번에 보여 주고. 아직 데이터가 없는 날은 비워 두면 좋을거 같아. 그리고 어제 날짜는 다른 색으로 표시 해 주면 쉽게 알아 볼 수 있을꺼 같아.

### 결정
- **적용 범위**: `date_from`이 그 달의 1일이고, `date_from`·`date_to`가 같은 연/월일 때만 "1일~말일 전체 표시"를 적용한다 (= `This Month`/`Last Month` 퀵 필터 케이스). `This Year`나 임의의 커스텀 범위(달을 걸치거나 1일부터 시작하지 않는 경우)는 기존처럼 실제 데이터가 있는 날짜만 표시한다 — 그렇지 않으면 연 단위 조회 시 수백 개의 빈 막대가 생겨 오히려 가독성이 떨어짐.
- 이 조건에 해당하면 JS에서 1일~해당 월 말일까지의 전체 날짜 목록을 만들고, `rows`를 `parsed_date` 기준으로 매핑해서 데이터 없는 날은 `null`로 채운다 (Chart.js는 `null` 데이터 포인트를 막대 없이 건너뜀 → "비워두기" 요구사항 충족).
- **어제 강조**: 위 조건과 무관하게 항상 적용. 브라우저 로컬 기준 "어제" 날짜(`parsed_date` 기준)가 현재 차트에 표시되는 날짜 범위 안에 있으면 그 막대만 다른 색(호박색 계열, 예: `rgba(245,158,11,0.85)`)으로 표시하고 나머지는 기존 파란색 유지.
- 데이터가 없는 날(= null)의 datalabel(막대 위 숫자)은 표시하지 않는다.

### 영향 파일
- `office/pos_data/report.php` — `salesChart` 생성 스크립트 부분만 수정 (약 318~384번째 줄). PHP/AJAX 백엔드 변경 없음.

## 2. 신규: Daily PCS(판매 수량) 차트

### 요청 (원문)
> 그리고 판매 수량을 일짜별로 알고 싶어.

### 결정
- 기존 "Daily NET SALES" 카드 바로 아래에 동일한 스타일의 새 카드 "Daily PCS(판매 수량)"를 추가한다.
- 데이터는 이미 `ajax_report_daily.php` 응답의 `rows[].total_pcs`에 있으므로 **추가 AJAX 호출 없이** 기존 `rows`를 재사용한다.
- 위 1번과 동일한 날짜 채우기 규칙(월 전체 표시 조건이 맞으면 1일~말일, 아니면 실데이터만) + 어제 강조 규칙을 그대로 적용해 시각적 일관성을 유지한다 (공용 헬퍼 함수로 날짜 목록 생성 로직을 재사용).
- 막대 색상은 NET SALES와 구분되는 색(예: 보라 계열 `rgba(139,92,246,0.7)`)을 사용해 두 차트를 혼동하지 않게 한다.

### 영향 파일
- `office/pos_data/report.php` — 새 카드 HTML(`<canvas id="pcsChart">` 등)과 렌더링 스크립트 추가. 기존 `ajax_report_daily.php` 응답을 그대로 재사용 (백엔드 변경 없음).

## Success Criteria
- SC-01: "This Month"/"Last Month" 조회 시 차트 X축이 1일~말일까지 전부 표시되고, 데이터 없는 날은 막대가 비어있음(0이 아니라 아예 없음)
- SC-02: "This Year" 등 여러 달에 걸친 조회는 기존처럼 실제 데이터 날짜만 표시 (회귀 없음)
- SC-03: 어제 날짜 막대가 두 차트 모두에서 다른 색으로 눈에 띔
- SC-04: 새 Daily PCS 차트가 NET SALES 차트와 동일한 날짜 축을 가지며, 기존 요약 카드/테이블/다른 차트에는 영향 없음

## 3. Addendum — 두 차트 나란히 배치 (2026-09-23)

### 요청
매출(NET SALES)과 판매수량(PCS) 차트를 함께 볼 수 있었으면 함. 두 차트는 그대로 유지하고, 위아래로 쌓인 배치를 좌우로 나란히 배치.

### 결정
- 차트 로직/데이터는 변경 없음. `Daily NET SALES`, `Daily PCS` 두 카드 div를 `flex gap-4` 컨테이너로 감싸서 한 줄에 나란히 배치 (기존 "일별 테이블 + 부서별 테이블" 섹션의 `flex gap-4 flex-wrap` 패턴과 동일한 방식, 좁은 화면에서는 자동으로 줄바꿈).
- 각 카드는 `flex-1 min-w-0`로 폭을 균등 분배.

### 영향 파일
- `office/pos_data/report.php` — 122~141번째 줄 근처, 두 카드를 감싸는 wrapper div 추가만 (HTML/CSS only, JS·PHP 변경 없음)

## 4. Addendum — 나란히 배치 후 툴팁이 첫 막대에 고정되는 버그 (2026-09-23)

### 증상
두 차트를 나란히 배치(#3)한 뒤, 차트에 마우스를 올리면 툴팁 숫자가 뜨는데, 다른 막대로 마우스를 옮겨도 툴팁이 갱신되지 않고 처음 hover했던 막대 값에 고정됨.

### 원인 추정
`salesChart`/`pcsChart` 둘 다 `responsive:true`인데 감싸는 `<div class="relative" style="max-height:240px;">`는 `max-height`만 지정하고 고정 `height`가 없음. 두 캔버스가 한 줄에 나란히 배치되면서 초기 레이아웃 계산 시점에 캔버스 크기가 불안정해지고, `maintainAspectRatio`가 기본값(true)이라 Chart.js가 종횡비 기준으로 내부 크기를 계산 — 결과적으로 마우스 좌표→데이터 인덱스 매핑이 어긋나 항상 index 0(첫 막대)로 잡히는 것으로 보임. (Chart.js 공식 문서에서도 CSS로 고정 높이를 줄 경우 `maintainAspectRatio:false`를 함께 쓰라고 권장.)

### 결정
- `salesChart`, `pcsChart` 두 캔버스를 감싸는 `.relative` div의 `style="max-height:240px;"`를 `style="height:240px;"`로 변경 (canvas 자체의 인라인 style도 동일하게 `height:240px;`로 통일).
- 두 Chart.js `options`에 `maintainAspectRatio: false`를 추가 (`responsive: true`는 유지).
- 그 외 데이터/로직/색상 등은 변경하지 않음.

### 영향 파일
- `office/pos_data/report.php` — `salesChart`/`pcsChart` 관련 wrapper div의 style 속성과 두 Chart.js `options` 객체만 수정

## 5. Addendum — 툴팁 콜백 null 미가드로 인한 hover 멈춤 버그 (2026-09-23)

### 증상
#4 수정 후에도 재현됨: 특히 Daily PCS 차트 쪽으로 마우스를 옮기면 툴팁이 멈춰버림(더 이상 갱신 안 됨).

### 진짜 원인
#1에서 빈 날짜(데이터 없는 날)는 `null`로 채우도록 했고, datalabel formatter는 null 가드를 넣었지만 **tooltip 콜백(`plugins.tooltip.callbacks.label`)에는 null 가드를 빠뜨림**:
```js
label: ctx => 'PCS: ' + ctx.parsed.y.toLocaleString(...)   // ctx.parsed.y가 null이면 TypeError
```
빈 날짜 막대 위(또는 그 x축 인덱스)로 마우스가 지나가는 순간 `null.toLocaleString()`에서 예외가 발생 → Chart.js 툴팁 업데이트가 중간에 멈춰서 이전 툴팁이 그대로 남아있는 것처럼(멈춘 것처럼) 보임. `salesChart`(NET SALES, Gross Profit 둘 다)와 `pcsChart` 모두 동일한 문제를 가지고 있음. (#4의 `maintainAspectRatio:false` 수정 자체는 무해하지만 이 증상의 진짜 원인은 아니었던 것으로 보임 — 유지는 하되 이번 수정이 핵심.)

### 결정
`salesChart`, `pcsChart` 두 곳의 `tooltip.callbacks.label` 함수 전부에 `ctx.parsed.y == null` 가드를 추가해서, null이면 빈 문자열(또는 그 dataset 라인 자체를 숨김)을 반환하도록 수정.

### 영향 파일
- `office/pos_data/report.php` — 두 Chart.js `options.plugins.tooltip.callbacks.label` 함수만 수정

## 6. Addendum — 전월 대비(Last Month) 차트 겹쳐 표시 (2026-09-23)

### 요청
"전월 대비를 표시해줬으면 해." → 카드에 %로 넣는 게 아니라, **차트에 전월 데이터를 겹쳐서** 같은 일자(day-of-month) 위치에 비교선으로 보여준다.

### 적용 조건
- #1에서 정의한 "월 전체 표시" 조건(`isFullMonth`: `dateFrom`이 1일이고 `dateFrom`/`dateTo`가 같은 연/월)일 때만 적용. `This Year` 등 여러 달에 걸친 조회에서는 전월 비교선을 추가하지 않는다 (의미가 없음).
- 전월 데이터가 아예 없으면(응답 rows가 0건) 비교선 자체를 추가하지 않는다 (평평한 0/null 라인이 뜨는 것 방지).

### 동작 방식
1. `isFullMonth`가 true일 때, 현재 조회 중인 달의 "전월" 범위(전월 1일 ~ 전월 말일)를 계산해서 **기존 `ajax_report_daily.php`에 두 번째 fetch**를 병렬로 요청한다 (같은 `dept` 필터 유지). 새 AJAX 엔드포인트는 만들지 않는다 — 기존 걸 다른 from/to로 한 번 더 호출.
2. 전월 응답의 각 row를 실제 날짜가 아니라 **"그 달의 며칠째(day-of-month)"** 기준으로 매핑한다 (`parsed_date`에서 day 숫자만 추출). 예: 전월 5일 데이터 → 이번달 dayList의 5번째 자리에 대응.
3. 이번달 `dayList`와 같은 길이/순서로 전월 값을 정렬한 배열을 만들어(대응하는 날짜가 전월에 없으면 null), 기존 차트에 새 dataset으로 추가한다:
   - `salesChart`: "Last Month" (전월 NET SALES) — 회색 계열 점선 라인(`borderDash:[5,5]`, `borderColor:'rgba(107,114,128,0.7)'`, `backgroundColor:'transparent'`, `pointRadius:0`, `fill:false`, `type:'line'`, `datalabels:{display:false}`)
   - `pcsChart`: "Last Month" (전월 PCS) — 동일한 회색 점선 스타일
4. X축 라벨(이번달 날짜)은 그대로 유지 — 전월 데이터는 같은 인덱스 위치에 겹쳐 그리기만 한다.
5. `pcsChart`의 기존 tooltip 콜백은 항상 `'PCS: ' + ...`로 하드코딩되어 있는데, 두 번째 dataset("Last Month")이 추가되면 그것도 "PCS: "로 잘못 표시되므로, `salesChart`처럼 `ctx.dataset.label + ': ' + ...` 형태로 일반화한다 (#5에서 넣은 null 가드는 유지).
6. `pcsChart`의 legend는 현재 `display:false`인데, "Last Month" dataset이 실제로 추가된 경우에만 `display:true`로 켜서 두 선을 구분할 수 있게 한다 (전월 비교가 없는 조회에서는 기존처럼 legend 숨김 유지).

### 영향 파일
- `office/pos_data/report.php` — daily 데이터 fetch 부분(두 번째 병렬 fetch 추가), `dayList` 생성 이후 전월 매핑 로직 추가, `salesChart`/`pcsChart`의 datasets/legend/tooltip 콜백 수정. PHP 백엔드(`ajax_report_daily.php`)는 변경하지 않고 기존 엔드포인트를 다른 파라미터로 재호출.

### Success Criteria
- SC-05: "This Month" 조회 시 두 차트에 회색 점선으로 전월 데이터가 겹쳐 보임
- SC-06: "This Year" 등 월 단위가 아닌 조회에서는 전월 비교선이 나타나지 않음 (회귀 없음)
- SC-07: 전월에 데이터가 아예 없는 경우 비교선이 그려지지 않음
- SC-08: 빈(null) 지점에 마우스를 올려도 #5에서 고친 것처럼 멈추지 않음

## 7. Addendum — 2달 전 데이터도 다른 색으로 추가 (2026-09-23)

### 요청
"2달 전꺼까지 다른색으로 표시해 주면 좋을거 같아" — #6에서 만든 "Last Month"(전월, 회색 점선) 비교선에 더해, **2달 전(전전월)** 데이터도 추가하되 전월과 구분되는 다른 색으로.

### 결정
- #6과 완전히 동일한 방식(같은 `isFullMonth` 조건, day-of-month 정렬, 데이터 없으면 미표시)을 그대로 2달 전에도 적용한다.
- 병렬 fetch를 하나 더 추가: `Promise.all([당월, 전월, 전전월])` 3개로 확장.
- 새 dataset "2 Months Ago" 스타일: 전월(회색 `rgba(107,114,128,0.7)`, `borderDash:[5,5]`)과 명확히 구분되도록 **틸/청록 계열** `borderColor:'rgba(20,184,166,0.7)'`, `borderDash:[2,3]`(점 패턴으로 더 촘촘하게 해서 전월 점선과도 구분), 나머지 스타일(`pointRadius:0`, `fill:false`, `type:'line'`, `backgroundColor:'transparent'`, `datalabels:{display:false}`, `order:4`)은 전월과 동일 패턴.
- `salesChart`, `pcsChart` 둘 다 적용. `pcsChart`의 legend 표시 조건(`display: !!prevPcsData`)에 2달 전 dataset 유무도 포함해서, 전월/2달전 둘 중 하나라도 있으면 legend를 켠다.
- 전월(#6)은 있는데 2달 전만 데이터가 없는 경우 등 — 각 dataset은 독립적으로 데이터 존재 여부에 따라 추가/생략한다 (서로 영향 없음).

### 영향 파일
- `office/pos_data/report.php` — #6에서 만든 전월 fetch/매핑/dataset 로직 바로 옆에 2달 전용 로직 추가 (같은 패턴 복붙 후 월 계산만 -2로 변경). PHP 백엔드 변경 없음.

### Success Criteria
- SC-09: "This Month" 조회 시 회색 점선(전월) + 틸색 점선(2달 전)이 각각 구분되어 보임
- SC-10: 2달 전 데이터가 없으면 그 선만 안 보이고 나머지(전월 비교선 등)는 정상 동작

## 8. Addendum — 비교선 라벨을 실제 월(N월)로 표시 (2026-09-23)

### 요청
"몇월 몇월 이런식으로 적어줘" — 범례/툴팁에 "Last Month", "2 Months Ago"라는 상대적 표현 대신, 실제 몇 월 데이터인지 숫자로 보여준다 (예: 이번 달이 9월이면 "8월", "7월").

### 결정
- `prevFrom`/`prev2From` 계산할 때 이미 구해둔 `py`(연도), `pm`(0-indexed 월)을 이용해서 라벨 문자열을 `(pm + 1) + '월'` 형태로 만든다 (예: pm=7 → "8월").
- 이 라벨을 dataset의 `label`로 그대로 사용한다 (`'Last Month'` → `prevLabel`, `'2 Months Ago'` → `prev2Label`). 범례와 툴팁 둘 다 `dataset.label`을 그대로 쓰고 있으므로 이 값만 바꾸면 둘 다 자동 반영됨.
- 연도가 다른 경우(예: 1월 조회 시 전월=작년 12월)는 "12월"로만 표시해도 충분 — 화면이 좁아 연도까지 넣으면 복잡해지므로 월 숫자만 표시.

### 영향 파일
- `office/pos_data/report.php` — `prevFetchPromise`/`prev2FetchPromise` 블록에서 라벨 변수 추가, 두 차트의 "Last Month"/"2 Months Ago" 문자열을 그 변수로 교체 (총 4곳: salesChart 2개 dataset label, pcsChart 2개 dataset label)
