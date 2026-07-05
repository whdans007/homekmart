/*
 * Design Ref: §6 — ESP32 지문인식 출퇴근 단말 펌웨어
 * HOME K MART 출퇴근 기록 시스템
 *
 * 상태머신: IDLE -> SCANNING -> WAITING_BUTTON -> SENDING -> IDLE
 * - 지문 인식 성공 시 3초 내 버튼(출근/브레이크시작/브레이크종료/퇴근) 입력 대기
 * - WiFi 정상: 서버로 즉시 POST
 * - WiFi 불가: SPIFFS /offline_buffer.jsonl 에 적재 후, 연결 복구 시 일괄 업로드
 *
 * 필요 라이브러리 (Arduino Library Manager):
 *  - Adafruit Fingerprint Sensor Library
 *  - Adafruit SSD1306 + Adafruit GFX
 *  - ArduinoJson
 *  - WiFi / HTTPClient / SPIFFS / time.h (ESP32 내장)
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <SPIFFS.h>
#include <time.h>
#include <Wire.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <Adafruit_Fingerprint.h>

// ── 설정 상수 (Design §6.5) ─────────────────────────────────────
const char* WIFI_SSID     = "HOMEKMART";
const char* WIFI_PASSWORD = "PLDTWIFIns9NM";
const char* SERVER_URL    = "http://192.168.1.116/sunset/office/attendance/api/punch.php";
const char* ENROLL_POLL_URL   = "http://192.168.1.116/sunset/office/attendance/api/enroll_poll.php";
const char* ENROLL_RESULT_URL = "http://192.168.1.116/sunset/office/attendance/api/enroll_result.php";
const char* API_KEY       = "att_change_this_to_a_strong_32char_secret";
const int   STORE_ID      = 9;  // SUNSET (선셋점)
const unsigned long ENROLL_POLL_INTERVAL_MS = 5000;
const unsigned long ENROLL_FINGER_TIMEOUT_MS = 10000;
const int   ENROLL_VERIFY_ATTEMPTS = 3;
const char* NTP_SERVER    = "pool.ntp.org";
const long  GMT_OFFSET_SEC = 28800;  // UTC+8 (필리핀)
const int   DAYLIGHT_OFFSET_SEC = 0;

// ── 핀맵 (Design §6.3) ──────────────────────────────────────────
#define FINGER_RX_PIN 16   // Serial2 RX (센서 TX와 연결)
#define FINGER_TX_PIN 17   // Serial2 TX (센서 RX와 연결)
#define OLED_SDA_PIN  21
#define OLED_SCL_PIN  22

#define BTN_CLOCK_IN     25  // 출근
#define BTN_BREAK_START  26  // 브레이크 시작
#define BTN_BREAK_END    27  // 브레이크 종료
#define BTN_CLOCK_OUT    14  // 퇴근

// ── OLED 설정 ────────────────────────────────────────────────────
#define SCREEN_WIDTH 128
#define SCREEN_HEIGHT 64
#define OLED_RESET   -1
Adafruit_SSD1306 display(SCREEN_WIDTH, SCREEN_HEIGHT, &Wire, OLED_RESET);

// ── 지문 센서 (AS608, Serial2) ───────────────────────────────────
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);

// ── 오프라인 버퍼 파일 ───────────────────────────────────────────
const char* BUFFER_FILE = "/offline_buffer.jsonl";

// ── 상태 머신 ────────────────────────────────────────────────────
// 흐름: IDLE(버튼 대기) -> WAITING_SCAN(지문 스캔 대기) -> SENDING -> IDLE
enum State { STATE_IDLE, STATE_WAITING_SCAN, STATE_SENDING, STATE_ENROLLING };
State currentState = STATE_IDLE;

int waitingFingerSlot = -1;
unsigned long waitingStartMs = 0;
unsigned long lastEnrollPollMs = 0;
const char* pendingEventType = "";
const unsigned long SCAN_TIMEOUT_MS = 5000;  // 버튼 입력 후 지문 스캔 대기 시간
int scanFailCount = 0;
const int SCAN_MAX_RETRIES = 5;  // 미등록(미인식) 시 버튼 재입력 없이 재시도할 최대 횟수

// ── 함수 선언 ────────────────────────────────────────────────────
void connectWiFi();
void syncTime();
void showMessage(const String& line1, const String& line2 = "");
int  scanFingerprint();
void handleButtonPress(const char* eventType);
String eventTypeLabel(const char* eventType);
bool sendPunch(int fingerSlot, const char* eventType, const String& eventTime, bool* isOffline, String* employeeName);
String getIsoTime();
void appendOfflineBuffer(int fingerSlot, const char* eventType, const String& eventTime);
void flushOfflineBuffer();
bool pollEnrollRequest(int* requestId, int* employeeId, String* requestType, int* fingerSlot);
void runEnrollSequence(int requestId, int employeeId);
void runDeleteSequence(int requestId, int fingerSlot);
void runDeleteAllSequence(int requestId);
int  findFreeFingerSlot();
void reportEnrollResult(int requestId, bool success, int fingerSlot, const char* errorMsg);

void setup() {
    Serial.begin(115200);

    // OLED 초기화
    Wire.begin(OLED_SDA_PIN, OLED_SCL_PIN);
    if (!display.begin(SSD1306_SWITCHCAPVCC, 0x3C)) {
        Serial.println("OLED init failed");
    }
    display.clearDisplay();
    display.setTextSize(1);
    display.setTextColor(SSD1306_WHITE);
    showMessage("Booting...", "Connecting WiFi");
    Serial.println("[부팅] 시작... WiFi 연결 시도 중");

    // 지문 센서 초기화
    fingerSerial.begin(57600, SERIAL_8N1, FINGER_RX_PIN, FINGER_TX_PIN);
    finger.begin(57600);
    if (!finger.verifyPassword()) {
        Serial.println("[오류] 지문센서를 찾을 수 없습니다. 배선을 확인하세요.");
        showMessage("Sensor Error", "Check wiring");
    } else {
        Serial.println("[지문센서] 연결 확인 완료");
    }

    // 버튼 초기화
    pinMode(BTN_CLOCK_IN, INPUT_PULLUP);
    pinMode(BTN_BREAK_START, INPUT_PULLUP);
    pinMode(BTN_BREAK_END, INPUT_PULLUP);
    pinMode(BTN_CLOCK_OUT, INPUT_PULLUP);

    // SPIFFS 초기화
    if (!SPIFFS.begin(true)) {
        Serial.println("SPIFFS mount failed");
    }

    connectWiFi();
    if (WiFi.status() == WL_CONNECTED) {
        syncTime();
        flushOfflineBuffer();
    }

    showMessage("Attendance", "Press a button");
    Serial.println("[준비 완료] 버튼을 눌러 출퇴근을 선택하세요");
    currentState = STATE_IDLE;
}

void loop() {
    // 백그라운드: WiFi 상태 확인 + 오프라인 버퍼 업로드
    static unsigned long lastWifiCheck = 0;
    if (millis() - lastWifiCheck > 10000) {
        lastWifiCheck = millis();
        if (WiFi.status() != WL_CONNECTED) {
            WiFi.reconnect();
        } else {
            flushOfflineBuffer();
        }
    }

    switch (currentState) {
        case STATE_IDLE: {
            const char* eventType = nullptr;

            if (digitalRead(BTN_CLOCK_IN) == LOW) {
                eventType = "clock_in";
            } else if (digitalRead(BTN_BREAK_START) == LOW) {
                eventType = "break_start";
            } else if (digitalRead(BTN_BREAK_END) == LOW) {
                eventType = "break_end";
            } else if (digitalRead(BTN_CLOCK_OUT) == LOW) {
                eventType = "clock_out";
            }

            if (eventType != nullptr) {
                pendingEventType = eventType;
                waitingStartMs = millis();
                scanFailCount = 0;
                Serial.println("[버튼] " + String(eventType) + " 입력됨 - 지문 스캔 대기");
                showMessage(eventTypeLabel(eventType), "Scan fingerprint");
                currentState = STATE_WAITING_SCAN;
                break;
            }

            // 버튼이 없을 때, 주기적으로 지문 등록 요청을 확인 (Design Ref: §4.3)
            if (WiFi.status() == WL_CONNECTED && millis() - lastEnrollPollMs > ENROLL_POLL_INTERVAL_MS) {
                lastEnrollPollMs = millis();
                Serial.println("[지문 등록] 등록 요청 폴링...");
                int requestId = -1, employeeId = -1, fingerSlot = -1;
                String requestType = "";
                if (pollEnrollRequest(&requestId, &employeeId, &requestType, &fingerSlot)) {
                    currentState = STATE_ENROLLING;
                    if (requestType == "delete") {
                        runDeleteSequence(requestId, fingerSlot);
                    } else if (requestType == "delete_all") {
                        runDeleteAllSequence(requestId);
                    } else {
                        runEnrollSequence(requestId, employeeId);
                    }
                    showMessage("Attendance", "Press a button");
                    currentState = STATE_IDLE;
                }
            }
            break;
        }

        case STATE_WAITING_SCAN: {
            int slot = scanFingerprint();
            if (slot >= 0) {
                waitingFingerSlot = slot;
                Serial.println("[지문 인식] 슬롯 " + String(slot) + " 인식 완료 - 전송 중");
                handleButtonPress(pendingEventType);
            } else if (slot == -2) {
                // 미등록 지문 - 버튼 재입력 없이 최대 SCAN_MAX_RETRIES회까지 재시도
                scanFailCount++;
                Serial.println("[지문 인식] 미등록 지문입니다 (" + String(scanFailCount) + "/" + String(SCAN_MAX_RETRIES) + ")");
                if (scanFailCount < SCAN_MAX_RETRIES) {
                    showMessage("Not Recognized", "Retry " + String(scanFailCount) + "/" + String(SCAN_MAX_RETRIES));
                    delay(1000);
                    showMessage(eventTypeLabel(pendingEventType), "Scan fingerprint");
                    waitingStartMs = millis();
                } else {
                    showMessage("Not Registered", "");
                    delay(1500);
                    showMessage("Attendance", "Press a button");
                    currentState = STATE_IDLE;
                }
            } else if (millis() - waitingStartMs > SCAN_TIMEOUT_MS) {
                Serial.println("[취소] 지문 스캔 시간 초과");
                showMessage("Cancelled", "Timeout");
                delay(1000);
                showMessage("Attendance", "Press a button");
                currentState = STATE_IDLE;
            }
            break;
        }

        default:
            currentState = STATE_IDLE;
            break;
    }
}

// ── WiFi 연결 ────────────────────────────────────────────────────
void connectWiFi() {
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    unsigned long start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) {
        delay(300);
    }
    if (WiFi.status() == WL_CONNECTED) {
        Serial.println("[WiFi] 연결 성공 - IP: " + WiFi.localIP().toString());
    } else {
        Serial.println("[WiFi] 연결 실패 - 오프라인 모드로 동작합니다");
    }
}

// ── NTP 시각 동기화 ──────────────────────────────────────────────
void syncTime() {
    configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, NTP_SERVER);
    struct tm timeinfo;
    int retries = 0;
    while (!getLocalTime(&timeinfo) && retries < 10) {
        delay(500);
        retries++;
    }
    if (retries < 10) {
        Serial.println("[시각 동기화] NTP 동기화 완료: " + getIsoTime());
    } else {
        Serial.println("[시각 동기화] NTP 동기화 실패");
    }
}

// ── OLED 표시 ────────────────────────────────────────────────────
// line1이 길면(10자 초과) 1배 크기로 줄여 잘림/겹침 방지
void showMessage(const String& line1, const String& line2) {
    display.clearDisplay();

    uint8_t line1Size = (line1.length() > 10) ? 1 : 2;
    display.setTextSize(line1Size);
    display.setCursor(0, 0);
    display.println(line1);

    if (line2.length() > 0) {
        display.setTextSize(1);
        display.setCursor(0, (line1Size == 2) ? 24 : 14);
        display.println(line2);
    }
    display.display();
}

// ── 지문 스캔 ────────────────────────────────────────────────────
// 반환: 인식된 finger_slot (0~127), -1 = 손가락 없음, -2 = 미등록 지문
int scanFingerprint() {
    if (finger.getImage() != FINGERPRINT_OK) return -1;
    if (finger.image2Tz() != FINGERPRINT_OK) return -1;
    if (finger.fingerSearch() != FINGERPRINT_OK) return -2;
    return finger.fingerID;
}

// ── 이벤트 타입 -> OLED 표시 라벨 (영문) ────────────────────────────
String eventTypeLabel(const char* eventType) {
    String t(eventType);
    if (t == "clock_in")    return "Clock In";
    if (t == "break_start") return "Break Start";
    if (t == "break_end")   return "Break End";
    if (t == "clock_out")   return "Clock Out";
    return "Attendance";
}

// ── 지문 스캔 완료 후 서버 전송 처리 ──────────────────────────────
void handleButtonPress(const char* eventType) {
    currentState = STATE_SENDING;
    String label = eventTypeLabel(eventType);
    showMessage(label, "Sending...");

    String eventTime = getIsoTime();
    bool isOffline = false;
    String employeeName = "";
    bool ok = sendPunch(waitingFingerSlot, eventType, eventTime, &isOffline, &employeeName);

    if (ok) {
        if (isOffline) {
            Serial.println("[전송] 오프라인 저장됨 (슬롯 " + String(waitingFingerSlot) + ", " + String(eventType) + ")");
            showMessage(label, "Offline saved");
        } else {
            Serial.println("[전송] 완료 - " + employeeName + " (" + String(eventType) + ") @ " + eventTime);
            showMessage(label, employeeName);
        }
    } else {
        Serial.println("[전송] 실패 (슬롯 " + String(waitingFingerSlot) + ", " + String(eventType) + ")");
        showMessage(label, "Failed");
    }

    delay(2000);
    showMessage("Attendance", "Press a button");
    waitingFingerSlot = -1;
    currentState = STATE_IDLE;
}

// ── 현재 시각 ISO 8601 문자열 ────────────────────────────────────
String getIsoTime() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) {
        return "1970-01-01T00:00:00";
    }
    char buf[25];
    strftime(buf, sizeof(buf), "%Y-%m-%dT%H:%M:%S", &timeinfo);
    return String(buf);
}

// ── 서버로 펀치 전송 (실패 시 오프라인 버퍼 적재) ─────────────────
bool sendPunch(int fingerSlot, const char* eventType, const String& eventTime, bool* isOffline, String* employeeName) {
    *isOffline = false;
    *employeeName = "";

    if (WiFi.status() != WL_CONNECTED) {
        appendOfflineBuffer(fingerSlot, eventType, eventTime);
        *isOffline = true;
        return true;
    }

    HTTPClient http;
    http.begin(SERVER_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Api-Key", API_KEY);

    StaticJsonDocument<256> doc;
    doc["store_id"]    = STORE_ID;
    doc["finger_slot"] = fingerSlot;
    doc["event_type"]  = eventType;
    doc["event_time"]  = eventTime;
    doc["device_id"]   = WiFi.macAddress();

    String body;
    serializeJson(doc, body);

    int httpCode = http.POST(body);
    String response = http.getString();
    http.end();

    if (httpCode == 200) {
        StaticJsonDocument<256> resDoc;
        if (deserializeJson(resDoc, response) == DeserializationError::Ok) {
            *employeeName = resDoc["employee_name"] | "Unknown";
        } else {
            *employeeName = "Unknown";
        }
        return true;
    }

    // 전송 실패 → 오프라인 버퍼 적재
    appendOfflineBuffer(fingerSlot, eventType, eventTime);
    *isOffline = true;
    return true;
}

// ── 오프라인 버퍼 적재 (Design §6.4) ──────────────────────────────
void appendOfflineBuffer(int fingerSlot, const char* eventType, const String& eventTime) {
    StaticJsonDocument<256> doc;
    doc["store_id"]    = STORE_ID;
    doc["finger_slot"] = fingerSlot;
    doc["event_type"]  = eventType;
    doc["event_time"]  = eventTime;
    doc["device_id"]   = WiFi.macAddress();

    String line;
    serializeJson(doc, line);

    File f = SPIFFS.open(BUFFER_FILE, FILE_APPEND);
    if (f) {
        f.println(line);
        f.close();
    }
}

// ── 오프라인 버퍼 일괄 업로드 ──────────────────────────────────────
// 각 줄을 순서대로 POST → 실패 시 해당 줄부터 다시 저장하고 중단
void flushOfflineBuffer() {
    if (!SPIFFS.exists(BUFFER_FILE)) return;

    File f = SPIFFS.open(BUFFER_FILE, FILE_READ);
    if (!f) return;

    String remaining = "";
    bool failed = false;

    while (f.available()) {
        String line = f.readStringUntil('\n');
        line.trim();
        if (line.length() == 0) continue;

        if (failed) {
            remaining += line + "\n";
            continue;
        }

        HTTPClient http;
        http.begin(SERVER_URL);
        http.addHeader("Content-Type", "application/json");
        http.addHeader("X-Api-Key", API_KEY);
        int httpCode = http.POST(line);
        http.end();

        if (httpCode != 200) {
            failed = true;
            remaining += line + "\n";
        }
    }
    f.close();

    if (remaining.length() == 0) {
        SPIFFS.remove(BUFFER_FILE);
    } else {
        File fw = SPIFFS.open(BUFFER_FILE, FILE_WRITE);
        if (fw) {
            fw.print(remaining);
            fw.close();
        }
    }
}

// ── 지문 등록/삭제 요청 polling (Design §4.3) ──────────────────────────
bool pollEnrollRequest(int* requestId, int* employeeId, String* requestType, int* fingerSlot) {
    HTTPClient http;
    String url = String(ENROLL_POLL_URL) + "?store_id=" + String(STORE_ID);
    http.begin(url);
    http.addHeader("X-Api-Key", API_KEY);

    int httpCode = http.GET();
    if (httpCode != 200) {
        Serial.println("[지문 등록] 폴링 실패 (HTTP " + String(httpCode) + ")");
        http.end();
        return false;
    }

    String response = http.getString();
    http.end();

    StaticJsonDocument<256> doc;
    if (deserializeJson(doc, response) != DeserializationError::Ok) {
        Serial.println("[지문 등록] 폴링 응답 파싱 실패: " + response);
        return false;
    }
    if (!doc["success"] || !doc["has_request"]) {
        Serial.println("[지문 등록] 대기 중인 요청 없음");
        return false;
    }

    *requestId   = doc["request_id"];
    *employeeId  = doc["employee_id"];
    *requestType = doc["request_type"] | "enroll";
    *fingerSlot  = doc["finger_slot"].isNull() ? -1 : (int)doc["finger_slot"];
    Serial.println("[지문 등록] 요청 발견 - #" + String(*requestId) + " (" + *requestType + ")");
    return true;
}

// ── 지문 삭제 시퀀스 — 웹에서 삭제된 슬롯을 센서에서도 삭제 (Design §4.3) ──────
void runDeleteSequence(int requestId, int fingerSlot) {
    Serial.println("[지문 삭제] 요청 #" + String(requestId) + " - 슬롯 " + String(fingerSlot) + " 삭제 시작");
    showMessage("Delete", "Removing...");

    if (fingerSlot < 1 || fingerSlot > 127) {
        Serial.println("[지문 삭제] 잘못된 슬롯 번호");
        reportEnrollResult(requestId, false, fingerSlot, "invalid_slot");
        showMessage("Delete Failed", "");
        delay(1500);
        return;
    }

    if (finger.deleteModel(fingerSlot) == FINGERPRINT_OK) {
        Serial.println("[지문 삭제] 슬롯 " + String(fingerSlot) + " 삭제 완료");
        reportEnrollResult(requestId, true, fingerSlot, "");
        showMessage("Delete", "Done");
    } else {
        Serial.println("[지문 삭제] 슬롯 " + String(fingerSlot) + " 삭제 실패");
        reportEnrollResult(requestId, false, fingerSlot, "delete_error");
        showMessage("Delete Failed", "");
    }
    delay(1500);
}

// ── 센서 전체 초기화 — 저장된 모든 지문 템플릿 삭제 (Design §4.3) ──────────
void runDeleteAllSequence(int requestId) {
    Serial.println("[지문 초기화] 요청 #" + String(requestId) + " - 센서 전체 초기화 시작");
    showMessage("Reset Sensor", "Erasing...");

    if (finger.emptyDatabase() == FINGERPRINT_OK) {
        Serial.println("[지문 초기화] 센서 전체 초기화 완료");
        reportEnrollResult(requestId, true, -1, "");
        showMessage("Reset Sensor", "Done");
    } else {
        Serial.println("[지문 초기화] 센서 전체 초기화 실패");
        reportEnrollResult(requestId, false, -1, "reset_error");
        showMessage("Reset Sensor", "Failed");
    }
    delay(1500);
}

// ── 손가락 등록 시퀀스 (AS608 2회 스캔, Design §4.3) ────────────────
void runEnrollSequence(int requestId, int employeeId) {
    Serial.println("[지문 등록] 요청 #" + String(requestId) + " (직원 #" + String(employeeId) + ") 시작");

    // 빈 슬롯 탐색을 스캔 '전'에 수행한다.
    // findFreeFingerSlot()은 loadModel()로 센서를 읽으며 CharBuffer를 덮어쓰므로,
    // createModel() 이후에 호출하면 방금 만든 등록 템플릿이 손상된다(2번째 등록부터 검증 실패의 원인).
    int freeSlot = findFreeFingerSlot();
    if (freeSlot < 0) {
        Serial.println("[지문 등록] 사용 가능한 슬롯 없음");
        reportEnrollResult(requestId, false, -1, "no_free_slot");
        showMessage("Enroll Failed", "Full");
        delay(1500);
        return;
    }
    Serial.println("[지문 등록] 사용할 슬롯: " + String(freeSlot));

    showMessage("Enroll", "Scan fingerprint");

    unsigned long startMs = millis();
    int p = -1;

    // 1차 스캔
    while ((p = finger.getImage()) != FINGERPRINT_OK) {
        if (p != FINGERPRINT_NOFINGER) {
            Serial.println("[지문 등록] 1차 스캔 오류 (코드 " + String(p) + ")");
            reportEnrollResult(requestId, false, -1, "scan_error");
            showMessage("Enroll Failed", "");
            delay(1500);
            return;
        }
        if (millis() - startMs > ENROLL_FINGER_TIMEOUT_MS) {
            Serial.println("[지문 등록] 1차 스캔 시간 초과");
            reportEnrollResult(requestId, false, -1, "timeout");
            showMessage("Enroll Failed", "Timeout");
            delay(1500);
            return;
        }
    }

    if (finger.image2Tz(1) != FINGERPRINT_OK) {
        Serial.println("[지문 등록] 1차 이미지 변환 실패");
        reportEnrollResult(requestId, false, -1, "image_error");
        showMessage("Enroll Failed", "");
        delay(1500);
        return;
    }

    Serial.println("[지문 등록] 1차 스캔 완료 - 손가락을 떼주세요");
    showMessage("Remove finger", "");

    // 손가락 떼기 대기
    startMs = millis();
    while (finger.getImage() != FINGERPRINT_NOFINGER) {
        if (millis() - startMs > ENROLL_FINGER_TIMEOUT_MS) {
            Serial.println("[지문 등록] 손가락 떼기 시간 초과");
            reportEnrollResult(requestId, false, -1, "timeout");
            showMessage("Enroll Failed", "Timeout");
            delay(1500);
            return;
        }
    }

    Serial.println("[지문 등록] 동일한 손가락을 다시 올려주세요");
    showMessage("Enroll", "Scan again");

    // 2차 스캔
    startMs = millis();
    while ((p = finger.getImage()) != FINGERPRINT_OK) {
        if (p != FINGERPRINT_NOFINGER) {
            Serial.println("[지문 등록] 2차 스캔 오류 (코드 " + String(p) + ")");
            reportEnrollResult(requestId, false, -1, "scan_error");
            showMessage("Enroll Failed", "");
            delay(1500);
            return;
        }
        if (millis() - startMs > ENROLL_FINGER_TIMEOUT_MS) {
            Serial.println("[지문 등록] 2차 스캔 시간 초과");
            reportEnrollResult(requestId, false, -1, "timeout");
            showMessage("Enroll Failed", "Timeout");
            delay(1500);
            return;
        }
    }

    if (finger.image2Tz(2) != FINGERPRINT_OK) {
        Serial.println("[지문 등록] 2차 이미지 변환 실패");
        reportEnrollResult(requestId, false, -1, "image_error");
        showMessage("Enroll Failed", "");
        delay(1500);
        return;
    }

    if (finger.createModel() != FINGERPRINT_OK) {
        Serial.println("[지문 등록] 두 이미지가 일치하지 않음");
        reportEnrollResult(requestId, false, -1, "mismatch");
        showMessage("Enroll Failed", "No match");
        delay(1500);
        return;
    }

    // freeSlot은 스캔 전에 이미 확보됨 (CharBuffer 손상 방지)
    if (finger.storeModel(freeSlot) != FINGERPRINT_OK) {
        Serial.println("[지문 등록] 슬롯 " + String(freeSlot) + " 저장 실패");
        reportEnrollResult(requestId, false, -1, "store_error");
        showMessage("Enroll Failed", "");
        delay(1500);
        return;
    }

    Serial.println("[지문 등록] 슬롯 " + String(freeSlot) + " 저장 완료 - 검증 중");

    // 검증 스캔: 등록된 지문이 정상적으로 인식되는지 확인 (검증 통과 후에만 서버에 성공 보고)
    // 최대 ENROLL_VERIFY_ATTEMPTS회까지 재시도하여 한 번의 스캔 실패로 인한 오탐을 줄임
    bool verified = false;
    for (int attempt = 1; attempt <= ENROLL_VERIFY_ATTEMPTS && !verified; attempt++) {
        Serial.println("[지문 등록] 검증 " + String(attempt) + "/" + String(ENROLL_VERIFY_ATTEMPTS) + " - 손가락을 떼주세요");
        String attemptLabel = "Test " + String(attempt) + "/" + String(ENROLL_VERIFY_ATTEMPTS);
        showMessage("Enroll Complete", attemptLabel + " - remove");

        startMs = millis();
        while (finger.getImage() != FINGERPRINT_NOFINGER) {
            if (millis() - startMs > ENROLL_FINGER_TIMEOUT_MS) break;
        }

        Serial.println("[지문 등록] 검증 " + String(attempt) + "/" + String(ENROLL_VERIFY_ATTEMPTS) + " - 같은 손가락을 다시 올려주세요");
        showMessage("Enroll Complete", attemptLabel + " - scan");

        startMs = millis();
        while ((p = finger.getImage()) != FINGERPRINT_OK) {
            if (p != FINGERPRINT_NOFINGER) break;
            if (millis() - startMs > ENROLL_FINGER_TIMEOUT_MS) break;
        }
        if (p == FINGERPRINT_OK &&
            finger.image2Tz() == FINGERPRINT_OK &&
            finger.fingerSearch() == FINGERPRINT_OK &&
            finger.fingerID == freeSlot) {
            verified = true;
        } else if (attempt < ENROLL_VERIFY_ATTEMPTS) {
            Serial.println("[지문 등록] 검증 " + String(attempt) + "/" + String(ENROLL_VERIFY_ATTEMPTS) + " 실패 - 재시도");
            showMessage("Try Again", attemptLabel + " failed");
            delay(1000);
        }
    }

    if (verified) {
        Serial.println("[지문 등록] 검증 성공 - 슬롯 " + String(freeSlot) + " 정상 인식 - 서버에 보고 중");
        reportEnrollResult(requestId, true, freeSlot, "");
        showMessage("Enroll Complete", "");
    } else {
        Serial.println("[지문 등록] 검증 실패 - 슬롯 " + String(freeSlot) + " 삭제 후 실패 보고");
        finger.deleteModel(freeSlot);
        reportEnrollResult(requestId, false, -1, "verify_failed");
        showMessage("Enroll Failed", "Test failed");
    }
    delay(1500);
}

// ── 빈 슬롯 탐색 (1~127) ────────────────────────────────────────
int findFreeFingerSlot() {
    for (int id = 1; id <= 127; id++) {
        if (finger.loadModel(id) != FINGERPRINT_OK) {
            return id;
        }
    }
    return -1;
}

// ── 등록 결과 서버 보고 (Design §4.3) ───────────────────────────────
void reportEnrollResult(int requestId, bool success, int fingerSlot, const char* errorMsg) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[지문 등록] WiFi 연결 안됨 - 결과 보고 실패");
        return;
    }

    HTTPClient http;
    http.begin(ENROLL_RESULT_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Api-Key", API_KEY);

    StaticJsonDocument<256> doc;
    doc["request_id"]  = requestId;
    doc["success"]     = success;
    doc["finger_slot"] = fingerSlot;
    doc["error"]       = errorMsg;

    String body;
    serializeJson(doc, body);

    int httpCode = http.POST(body);
    http.end();

    if (httpCode != 200) {
        Serial.println("[지문 등록] 결과 보고 실패 (HTTP " + String(httpCode) + ")");
    }
}
