# Design: attendance-tracking (ESP32 지문인식 출퇴근 시스템)

**Feature**: attendance-tracking  
**Plan**: `docs/01-plan/features/attendance-tracking.plan.md`  
**Architecture**: Option C — Pragmatic (기존 office/ 패턴 일관성)  
**Date**: 2026-06-09

---

## Context Anchor

| 항목 | 내용 |
|------|------|
| **WHY** | 수기 출퇴근 관리 → 급여 정산 오류. 신뢰할 수 있는 실근무시간 데이터 자동 수집 |
| **WHO** | 현장 직원 (지문 인식), office_staff/super_admin (웹 대시보드) |
| **RISK** | WiFi 단절 시 기록 유실 → SPIFFS 버퍼로 해결. 지문 오인식 → 명시적 버튼 확인 단계 |
| **SUCCESS** | 지문+버튼 1회로 기록 완료 / 오프라인 자동 복구 / 월별 오버타임 정확 계산 |
| **SCOPE** | ESP32 펌웨어 + PHP API + 지문 관리 UI + 대시보드 + 월별 리포트 + 엑셀 출력 |

---

## 1. Overview

### 선택된 아키텍처: Option C — Pragmatic

```
office/attendance/          ← 새 서브모듈 (employees.php 패턴 답습)
├── index.php               ← 실시간 현황 대시보드
├── fingerprints.php        ← 지문 슬롯 ↔ 직원 매핑 관리
├── report.php              ← 일별/월별 실근무 리포트
├── export.php              ← 엑셀 내보내기 (PhpSpreadsheet)
└── api/
    └── punch.php           ← ESP32 타임펀치 수신 (API Key 인증)

office/lib/office_helper.php  ← attendance 함수 섹션 추가
office/sql/attendance_create_tables.sql
office/run_attendance_tables.php

arduino/attendance_device/
└── attendance_device.ino   ← ESP32 펌웨어 (전체 구현)
```

### 시스템 데이터 흐름

```
[직원 손가락]
      │
      ▼
[ESP32 지문센서 스캔]
      │ finger_slot 획득
      ▼
[버튼 대기 (3초 타임아웃)]
      │ 버튼 선택: clock_in / break_start / break_end / clock_out
      ▼
[WiFi 연결 확인]
      ├─ 성공 → HTTP POST /office/attendance/api/punch.php
      │         └ 응답 수신 → LCD 표시 (직원명 + 이벤트)
      └─ 실패 → SPIFFS /offline_buffer.jsonl 에 추가
                └ WiFi 복구 감지 → 버퍼 일괄 업로드 → 삭제

[punch.php]
      │ X-Api-Key 검증
      │ finger_slot → employee_id 조회 (office_fingerprints)
      │ INSERT office_attendance_logs
      └ {success, employee_name, event_type} 반환

[웹 대시보드]
      └ office_attendance_logs 조회 → 실시간 현황 / 리포트 / 엑셀
```

---

## 2. 데이터 모델 (상세)

### 2.1 신규 테이블

```sql
-- 지문 슬롯 ↔ 직원 매핑
CREATE TABLE office_fingerprints (
  id           INT UNSIGNED      AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED      NOT NULL,
  employee_id  INT UNSIGNED      NOT NULL,
  finger_slot  TINYINT UNSIGNED  NOT NULL COMMENT '센서 슬롯 번호 1~127',
  enrolled_at  TIMESTAMP         DEFAULT CURRENT_TIMESTAMP,
  enrolled_by  INT UNSIGNED      NULL,
  UNIQUE KEY uniq_slot (store_id, finger_slot),
  UNIQUE KEY uniq_employee (store_id, employee_id),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 타임펀치 이벤트 로그
CREATE TABLE office_attendance_logs (
  id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED  NOT NULL,
  employee_id  INT UNSIGNED  NOT NULL,
  event_type   ENUM('clock_in','break_start','break_end','clock_out') NOT NULL,
  event_time   DATETIME      NOT NULL,
  source       ENUM('device','manual') NOT NULL DEFAULT 'device',
  device_id    VARCHAR(50)   NULL COMMENT 'ESP32 MAC 주소',
  created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_emp_date (store_id, employee_id, event_time),
  INDEX idx_store_date (store_id, event_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 근무시간 계산 쿼리 패턴

하루치 직원 근무 요약은 PHP에서 계산:

```php
// 하루 기록 조회
SELECT event_type, event_time
FROM office_attendance_logs
WHERE store_id=? AND employee_id=? AND DATE(event_time)=?
ORDER BY event_time ASC

// 계산 로직 (PHP)
$work_minutes = 0;
$break_minutes = 0;
foreach ($events as $e) { ... }

$net_work    = $work_minutes - $break_minutes;          // 실근무 (분)
$regular     = min($net_work, 8 * 60);                  // 정규근무 (최대 8h)
$overtime    = max(0, $net_work - 8 * 60);              // 오버타임
```

### 2.3 config/db_config.php 추가 상수

```php
define('ATTENDANCE_API_KEY', 'att_YOUR_SECRET_32CHAR_KEY_HERE');
```

---

## 3. API 명세

### POST /office/attendance/api/punch.php

**인증**: `X-Api-Key: {ATTENDANCE_API_KEY}` 헤더

**요청 Body** (application/json):
```json
{
  "store_id":    1,
  "finger_slot": 3,
  "event_type":  "clock_in",
  "event_time":  "2026-06-09T09:00:05",
  "device_id":   "AA:BB:CC:DD:EE:FF"
}
```

**성공 응답** (200):
```json
{
  "success": true,
  "employee_name": "홍길동",
  "event_type": "clock_in"
}
```

**오류 응답**:
```json
{ "success": false, "error": "unauthorized" }          // 401 - API Key 불일치
{ "success": false, "error": "employee_not_found" }    // 404 - 슬롯 미등록
{ "success": false, "error": "invalid_params" }        // 400 - 파라미터 오류
```

**처리 흐름**:
1. `X-Api-Key` 검증
2. JSON body 파싱 및 유효성 검사
3. `office_fingerprints`에서 `store_id + finger_slot` → `employee_id` 조회
4. `office_attendance_logs` INSERT (event_time은 클라이언트 제공값 사용)
5. 직원명 반환

---

## 4. 컴포넌트 상세 설계

### 4.1 punch.php (API 엔드포인트)

`ajax_save_attendance.php` 패턴 참조:
- `ob_start()` / `ob_end_clean()` 패턴
- try-catch로 감싼 전체 로직
- JSON response만 출력
- 세션 불필요 (API Key 인증)

```php
<?php
require_once __DIR__ . '/../../lib/office_helper.php';
header('Content-Type: application/json; charset=utf-8');
ob_start();

// 1. API Key 검증
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key !== ATTENDANCE_API_KEY) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'unauthorized']); exit;
}

// 2. POST 전용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ... }

// 3. JSON 파싱
$body = json_decode(file_get_contents('php://input'), true);

// 4. 유효성 검사
$valid_events = ['clock_in','break_start','break_end','clock_out'];
// ... 검증 로직

// 5. finger_slot → employee_id
// 6. INSERT 로그
// 7. 직원명 반환
```

### 4.2 fingerprints.php (지문 관리 UI)

`employees.php` 패턴 참조 (동일 파일에서 POST 처리 + GET 렌더링):

**POST 액션**:
- `add`: `INSERT office_fingerprints (store_id, employee_id, finger_slot, enrolled_by)`
- `delete`: `DELETE FROM office_fingerprints WHERE id=? AND store_id=?`

**GET 렌더링**:
- 현재 등록된 지문 목록 (직원명, 슬롯번호, 등록일)
- 미등록 직원 드롭다운 + 슬롯번호 입력 → 등록 버튼
- 사용 중인 슬롯 번호 표시 (중복 방지 UI)

### 4.3 index.php (실시간 대시보드)

오늘 날짜 기준 출퇴근 현황 표시:

```sql
SELECT
  e.name, e.job_role,
  MAX(CASE WHEN l.event_type='clock_in'    THEN l.event_time END) AS clock_in,
  MAX(CASE WHEN l.event_type='break_start' THEN l.event_time END) AS break_start,
  MAX(CASE WHEN l.event_type='break_end'   THEN l.event_time END) AS break_end,
  MAX(CASE WHEN l.event_type='clock_out'   THEN l.event_time END) AS clock_out
FROM office_employees e
LEFT JOIN office_attendance_logs l
  ON e.id = l.employee_id AND DATE(l.event_time) = CURDATE()
WHERE e.store_id = ? AND e.status = 'active'
GROUP BY e.id
ORDER BY e.job_role, e.name
```

상태 뱃지: 출근전(회색) / 근무중(초록) / 브레이크(노랑) / 퇴근(파랑)

### 4.4 report.php (일별/월별 리포트)

필터: 연도 + 월 선택 (기본: 현재 월)

출력 컬럼: 직원명 | 날짜별 출근시각 | 퇴근시각 | 브레이크 | 실근무시간 | 오버타임

### 4.5 export.php (엑셀 출력)

PhpSpreadsheet으로 월별 근무 시간표 xlsx 생성.  
기존 `exports/print_schedule.php` 패턴 참조.

---

## 5. office_helper.php 추가 함수

```php
// ── Attendance helpers ────────────────────────────────────────────

function get_attendance_fingerprints(int $store_id): array
// office_fingerprints + office_employees JOIN

function get_today_attendance(int $store_id): array
// 오늘 출퇴근 현황 피벗 쿼리

function get_monthly_attendance(int $store_id, int $year, int $month): array
// 월별 직원별 날짜별 이벤트 목록

function calc_work_summary(array $events): array
// ['net_minutes'=>int, 'regular_minutes'=>int, 'overtime_minutes'=>int]
// $events = [{event_type, event_time}, ...]
```

---

## 6. ESP32 펌웨어 설계 (attendance_device.ino)

### 6.1 상태 머신

```
IDLE
  │ finger detected
  ▼
SCANNING
  │ recognized (finger_slot >= 0)    │ not recognized
  ▼                                   ▼
WAITING_BUTTON                      IDLE (LCD: "미등록 지문")
  │ button pressed (3s timeout)      │ timeout
  ▼                                   ▼
SENDING                             IDLE (LCD: "취소됨")
  │ WiFi OK                          │ WiFi fail
  ▼                                   ▼
IDLE (LCD: "홍길동\n출근 완료")      BUFFERING → IDLE (LCD: "오프라인 저장")

백그라운드 태스크 (loop() 마다):
  - WiFi 연결 상태 확인
  - SPIFFS 버퍼 있으면 → 일괄 업로드 시도
```

### 6.2 라이브러리 목록

| 라이브러리 | 용도 | Arduino Library Manager 이름 |
|-----------|------|------------------------------|
| `Adafruit Fingerprint Sensor Library` | AS608/R503 제어 | Adafruit Fingerprint Sensor Library |
| `LiquidCrystal I2C` (또는 `Adafruit SSD1306`) | LCD/OLED 표시 | LiquidCrystal I2C / Adafruit SSD1306 |
| `WiFi.h` | ESP32 내장 | (내장) |
| `HTTPClient.h` | HTTP POST | (내장) |
| `ArduinoJson` | JSON 직렬화 | ArduinoJson |
| `SPIFFS.h` | 오프라인 버퍼 파일 | (내장 ESP32 FS) |
| `time.h` + NTP | 시각 동기화 | (내장) |

### 6.3 핀맵

| 모듈 | ESP32 핀 | 비고 |
|------|---------|------|
| 지문센서 TX | GPIO 16 (RX2) | Serial2 |
| 지문센서 RX | GPIO 17 (TX2) | Serial2 |
| LCD SDA | GPIO 21 | I2C |
| LCD SCL | GPIO 22 | I2C |
| 버튼: 출근 | GPIO 25 | INPUT_PULLUP |
| 버튼: 브레이크↑ | GPIO 26 | INPUT_PULLUP |
| 버튼: 브레이크↓ | GPIO 27 | INPUT_PULLUP |
| 버튼: 퇴근 | GPIO 14 | INPUT_PULLUP |

### 6.4 SPIFFS 버퍼 포맷

파일: `/offline_buffer.jsonl`  
형식: 줄당 1개의 JSON 객체

```json
{"store_id":1,"finger_slot":3,"event_type":"clock_in","event_time":"2026-06-09T09:00:05","device_id":"AA:BB:CC:DD:EE:FF"}
```

업로드 로직: 파일 내 각 줄을 순서대로 POST → 성공 시 해당 줄 제거 → 전체 성공 시 파일 삭제.

### 6.5 설정 상수 (펌웨어 상단)

```cpp
const char* WIFI_SSID     = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* SERVER_URL    = "http://192.168.1.116/office/attendance/api/punch.php";
const char* API_KEY       = "att_YOUR_SECRET_32CHAR_KEY_HERE";
const int   STORE_ID      = 1;
const int   BUTTON_TIMEOUT_MS = 3000;
const char* NTP_SERVER    = "pool.ntp.org";
const long  GMT_OFFSET_SEC = 32400;  // UTC+9 (한국)
```

---

## 7. 보안 설계

| 위협 | 대응 |
|------|------|
| API 무단 호출 | `X-Api-Key` 헤더 검증, 불일치 시 401 즉시 반환 |
| SQL Injection | 전 쿼리 prepared statement 사용 |
| 지문 데이터 유출 | 지문 템플릿 ESP32 센서 모듈 내 저장, 서버 전송 없음 |
| 버퍼 파일 변조 | 내부 WiFi 네트워크 전용, 외부 접근 차단 (`.htaccess`) |
| 수동 보정 권한 | `source='manual'` 입력은 `office_staff` 이상만 가능 |

---

## 8. 테스트 계획

| 레벨 | 테스트 항목 | 방법 |
|------|------------|------|
| L1 API | punch.php 정상 요청 → 200 + success:true | curl |
| L1 API | 잘못된 API Key → 401 | curl |
| L1 API | 미등록 슬롯 → employee_not_found | curl |
| L1 API | 오프라인 버퍼 업로드 → 일괄 처리 성공 | curl 복수 건 |
| L2 UI | 지문 슬롯 등록 → 목록 갱신 확인 | 브라우저 |
| L2 UI | 대시보드 출근 상태 뱃지 정확성 | 브라우저 |
| L2 UI | 월별 리포트 오버타임 계산 정확성 | 브라우저 |
| L3 HW | ESP32 부팅 → WiFi 연결 → 지문 인식 → 서버 기록 | 실기기 |
| L3 HW | WiFi 차단 후 지문 인식 → SPIFFS 저장 확인 | 실기기 |
| L3 HW | WiFi 복구 후 버퍼 자동 업로드 확인 | 실기기 |

---

## 9. 마이그레이션 계획

```
office/sql/attendance_create_tables.sql  ← CREATE TABLE 2개
office/run_attendance_tables.php         ← 브라우저에서 1회 실행
config/db_config.php                     ← ATTENDANCE_API_KEY 상수 추가
```

---

## 10. 파일 목록 (신규/수정)

| 파일 | 신규/수정 | 설명 |
|------|---------|------|
| `office/sql/attendance_create_tables.sql` | 신규 | DB 테이블 생성 |
| `office/run_attendance_tables.php` | 신규 | 마이그레이션 실행 |
| `config/db_config.php` | 수정 | `ATTENDANCE_API_KEY` 상수 추가 |
| `office/lib/office_helper.php` | 수정 | attendance 함수 4개 추가 |
| `office/attendance/api/punch.php` | 신규 | ESP32 타임펀치 수신 API |
| `office/attendance/fingerprints.php` | 신규 | 지문 슬롯 관리 UI |
| `office/attendance/index.php` | 신규 | 실시간 현황 대시보드 |
| `office/attendance/report.php` | 신규 | 일별/월별 리포트 |
| `office/attendance/export.php` | 신규 | 엑셀 내보내기 |
| `arduino/attendance_device/attendance_device.ino` | 신규 | ESP32 펌웨어 |

**총계**: 신규 9개 / 수정 2개

---

## 11. 구현 가이드

### 11.1 구현 순서

| 순서 | 모듈 | 산출물 | 이전 의존 |
|------|------|--------|---------|
| 1 | DB + Config | SQL 파일, run 스크립트, config 상수 | - |
| 2 | PHP API | `punch.php` | 모듈 1 |
| 3 | 지문 관리 UI | `fingerprints.php` | 모듈 1 |
| 4 | ESP32 펌웨어 | `attendance_device.ino` | 모듈 2 |
| 5 | 대시보드 | `index.php` | 모듈 1,2,3 |
| 6 | 리포트 + 엑셀 | `report.php`, `export.php` | 모듈 5 |

### 11.2 참조 파일

| 새 파일 | 참조 패턴 |
|---------|---------|
| `punch.php` | `office/schedule/ajax_save_attendance.php` |
| `fingerprints.php` | `office/schedule/employees.php` |
| `index.php` | `office/schedule/schedule.php` |
| `export.php` | `office/exports/print_schedule.php` |
| `office_helper.php` (attendance 섹션) | 기존 섹션 구조 그대로 |

### 11.3 Session Guide (Module Map)

```
Module 1 — DB + Config  (~30 min)
  - attendance_create_tables.sql
  - run_attendance_tables.php
  - config/db_config.php (ATTENDANCE_API_KEY)
  - office_helper.php (attendance 함수 4개)

Module 2 — PHP API  (~45 min)
  - office/attendance/api/punch.php

Module 3 — 지문 관리 UI  (~60 min)
  - office/attendance/fingerprints.php

Module 4 — ESP32 펌웨어  (~120 min)
  - arduino/attendance_device/attendance_device.ino
  - 상태머신 + SPIFFS 버퍼 + NTP 시각 동기화

Module 5 — 대시보드 + 리포트 + 엑셀  (~90 min)
  - office/attendance/index.php
  - office/attendance/report.php
  - office/attendance/export.php
```

**추천 세션 분할**:
- Session A: Module 1 + 2 (DB부터 API까지, curl로 즉시 검증 가능)
- Session B: Module 3 + 5 (웹 UI 전체)
- Session C: Module 4 (ESP32 펌웨어, 하드웨어 준비 후)
