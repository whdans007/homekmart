# Plan: attendance-tracking (ESP32 지문인식 출퇴근 시스템)

## Executive Summary

| 관점 | 내용 |
|------|------|
| **Problem** | 직원 출퇴근을 수기로 관리해 급여 정산 시 정확한 근무시간 산출이 어려움 |
| **Solution** | ESP32 + 지문센서 DIY 디바이스가 WiFi로 서버에 타임펀치를 기록하고, 기존 `office_employees` DB와 연동해 실근무시간·오버타임을 자동 계산 |
| **Functional UX Effect** | 직원 지문 인식 → 버튼 선택(출근/브레이크/퇴근) → LCD 확인 → 웹 대시보드에서 실시간 현황·월별 리포트·엑셀 출력 |
| **Core Value** | 급여 정산의 근거가 되는 정확한 실근무시간 데이터를 자동 수집 (오버타임 = 실근무 8시간 초과분) |

---

## 1. 사용자 의도 발견 (Intent Discovery)

### 핵심 목적
직원 출퇴근 기록의 신뢰성을 확보해 월별 급여 정산 근거 데이터를 자동화.

### 대상 사용자
- `office_staff` / `super_admin` — 웹 대시보드 조회·관리
- 현장 직원 — ESP32 디바이스에서 지문 인식

### 성공 기준
1. 직원이 지문 인식 후 버튼 1회 터치로 출근/브레이크/퇴근 기록 완료
2. 네트워크 단절 시 기록이 유실되지 않고 복구 후 자동 업로드
3. 월별 리포트에서 직원별 실근무시간·오버타임이 정확히 계산
4. 웹 어드민에서 지문 슬롯 ↔ 직원 매핑 등록·수정·삭제 가능

---

## 2. 탐색한 대안 (Alternatives Explored)

| 방식 | 설명 | 결정 |
|------|------|------|
| **A. 지문 슬롯 매핑 + SPIFFS 버퍼** | ESP32가 finger_slot_id를 서버에 전송, 서버에서 직원 매핑. 오프라인 시 SPIFFS 저장 후 복구 시 자동 업로드 | **선택** |
| B. 지문 슬롯 매핑만 (버퍼 없음) | 구현 단순하지만 WiFi 단절 시 기록 유실 | 미선택 (급여 데이터 신뢰성 문제) |
| C. BLE + 모바일 앱 연동 | 추가 앱 개발 필요, 운영 복잡도 상승 | 미선택 (기존 웹 스택과 불일치) |

---

## 3. YAGNI 검토 결과

### v1 포함 기능
- [ ] ESP32 펌웨어: 지문 인식 + 4버튼 이벤트 + LCD 피드백 + WiFi POST + SPIFFS 오프라인 버퍼
- [ ] PHP API: 타임펀치 수신 엔드포인트 (API Key 인증)
- [ ] 웹 UI: 지문 슬롯 ↔ 직원 매핑 등록·수정·삭제
- [ ] 웹 UI: 실시간 현황 대시보드 (오늘 출근 상태)
- [ ] 웹 UI: 일별/월별 실근무 리포트 (오버타임 포함)
- [ ] 웹 UI: 엑셀 내보내기 (월별 직원별 근무시간)

### v2 이후 (Deferred)
- 오버타임 승인 워크플로우 (관리자 이메일 알림 + 승인 모듈)
- 출근 비콘/알림음 (ESP32 스피커)
- QR 코드 fallback (지문 미등록 직원 대체 수단)
- 근태 이상 알림 (지각·조퇴 자동 감지)

---

## 4. 브레인스토밍 로그 (Key Decisions)

| 결정 | 근거 |
|------|------|
| WiFi HTTP POST 방식 | MQTT 대비 인프라 추가 없이 기존 PHP 서버와 직접 통신 가능 |
| 매장당 디바이스 1대 | store_id로 매장 구분, 초기 단순성 최대화 |
| SPIFFS 오프라인 버퍼 | 급여 정산 데이터 유실 불허 — 네트워크 복구 시 자동 업로드 |
| 4버튼 이벤트 선택 | 자동 감지 방식은 이전 이벤트 누락 시 오판 가능 — 명시적 버튼이 더 신뢰성 높음 |
| 오버타임 기준 = 실근무 8시간 초과 | 브레이크타임(1h) 제외 후 8시간 초과분이 오버타임 |
| 지문 데이터 ESP32 로컬 저장 | 지문 템플릿이 서버로 전송되지 않아 개인정보 보호 자연스럽게 해결 |

---

## 5. 기술 스택

| 영역 | 기술 |
|------|------|
| 하드웨어 | ESP32 DevKit + AS608/R503 지문센서 + I2C LCD(16x2 또는 OLED 128x64) + 택타일 버튼 4개 |
| 펌웨어 | Arduino C++ (WiFiClientSecure, HTTPClient, SPIFFS, Adafruit_Fingerprint) |
| 백엔드 API | PHP (기존 `office/` 모듈 패턴) |
| DB | MySQL — `u622428657_homekmart` |
| 프론트엔드 | PHP 템플릿 + TailwindCSS (기존 admin/office 패턴) |
| 엑셀 출력 | PhpSpreadsheet (기존 프로젝트 라이브러리 재사용) |

---

## 6. 데이터 모델

### 신규 테이블

```sql
-- 지문 슬롯 ↔ 직원 매핑
CREATE TABLE office_fingerprints (
  id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED    NOT NULL,
  employee_id  INT UNSIGNED    NOT NULL,
  finger_slot  TINYINT UNSIGNED NOT NULL COMMENT '센서 슬롯 번호 (1~127)',
  enrolled_at  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  enrolled_by  INT UNSIGNED    NULL,
  UNIQUE KEY uniq_slot (store_id, finger_slot),
  FOREIGN KEY (employee_id) REFERENCES office_employees(id) ON DELETE CASCADE
);

-- 타임펀치 이벤트 로그
CREATE TABLE office_attendance_logs (
  id           INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  store_id     INT UNSIGNED    NOT NULL,
  employee_id  INT UNSIGNED    NOT NULL,
  event_type   ENUM('clock_in','break_start','break_end','clock_out') NOT NULL,
  event_time   DATETIME        NOT NULL,
  source       ENUM('device','manual') NOT NULL DEFAULT 'device',
  device_id    VARCHAR(50)     NULL COMMENT 'ESP32 MAC 주소',
  created_at   TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_store_emp_date (store_id, employee_id, event_time),
  INDEX idx_store_date (store_id, event_time)
);
```

### 근무시간 계산 공식
```
실근무시간 = (clock_out - clock_in) - (break_end - break_start)
오버타임   = MAX(0, 실근무시간 - 8시간)
정규근무   = MIN(실근무시간, 8시간)
```

---

## 7. 파일 구조

```
office/
└── attendance/
    ├── index.php              ← 실시간 현황 대시보드
    ├── fingerprints.php       ← 지문 슬롯 관리
    ├── report.php             ← 일별/월별 리포트
    ├── export.php             ← 엑셀 내보내기
    └── api/
        └── punch.php          ← ESP32 타임펀치 수신 API

arduino/
└── attendance_device/
    └── attendance_device.ino  ← ESP32 펌웨어

office/sql/
└── attendance_create_tables.sql  ← DB 마이그레이션
office/
└── run_attendance_tables.php     ← 마이그레이션 실행 스크립트
```

---

## 8. API 명세

### POST /office/attendance/api/punch.php
```
Headers:
  X-Api-Key: {ATTENDANCE_API_KEY}
  Content-Type: application/json

Body:
{
  "store_id":    1,
  "finger_slot": 3,
  "event_type":  "clock_in",
  "event_time":  "2026-06-09T09:00:00",
  "device_id":   "AA:BB:CC:DD:EE:FF"
}

Response:
{
  "success": true,
  "employee_name": "홍길동",
  "event_type": "clock_in"
}
```

---

## 9. ESP32 하드웨어 연결 (핀맵)

| 모듈 | ESP32 핀 |
|------|---------|
| 지문센서 TX | GPIO 16 (RX2) |
| 지문센서 RX | GPIO 17 (TX2) |
| LCD SDA | GPIO 21 |
| LCD SCL | GPIO 22 |
| 버튼: 출근 | GPIO 25 |
| 버튼: 브레이크↑ | GPIO 26 |
| 버튼: 브레이크↓ | GPIO 27 |
| 버튼: 퇴근 | GPIO 14 |

---

## 10. 구현 우선순위

| 순서 | 작업 | 산출물 |
|------|------|--------|
| 1 | DB 마이그레이션 | attendance_create_tables.sql + run 스크립트 |
| 2 | PHP API (punch.php) | ESP32 수신 엔드포인트 |
| 3 | 지문 슬롯 관리 UI | fingerprints.php |
| 4 | ESP32 펌웨어 | attendance_device.ino |
| 5 | 실시간 대시보드 | index.php |
| 6 | 리포트 + 엑셀 출력 | report.php + export.php |

---

## 11. 보안 고려사항

- API Key는 `config/db_config.php` 상수로 관리 (`ATTENDANCE_API_KEY`)
- ESP32 펌웨어에 API Key 하드코딩 (내부 네트워크 전용 디바이스)
- 지문 템플릿 데이터는 ESP32 센서 모듈에만 저장, 서버 전송 없음
- 수동 보정(manual source)은 `office_staff` 이상 권한만 가능
