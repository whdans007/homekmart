---
template: design
version: 0.1
feature: mall-order-chat-push
date: 2026-09-08
author: claude-code (audit worker)
project: HOME K MART
version_project: '-'
---

# mall-order-chat-push Design Document (FCM 통합 계획)

> **Summary**: `mall-order-chat`(주문톡) v1은 계획대로 푸시알림을 제외했다(`mall-order-chat.design.md` §Context Anchor "SCOPE"). 앱을 백그라운드/종료 상태에서도 관리자·기사 응답을 놓치지 않도록, `mall-app`(Capacitor Android) 고객 앱에 Firebase Cloud Messaging(HTTP v1)을 통합한다. **이 문서는 감사(audit) 결과이며 코드/DB는 변경하지 않았다.** 실제 구현은 별도 Do 세션에서 진행한다.
>
> **Project**: HOME K MART
> **Status**: Draft (Plan+Design 통합, 구현 전)
> **Related**: [mall-order-chat.design.md](mall-order-chat.design.md), [mall-order-chat.plan.md](../../01-plan/features/mall-order-chat.plan.md)

---

## Context Anchor

| Key | Value |
|-----|-------|
| **WHY** | 주문톡 v1은 5초 폴링 기반이라 채팅창을 열어두지 않으면(백그라운드/앱 종료) 관리자·기사의 응답을 인지할 수 없다. 고객 리텐션과 응대 체감속도를 위해 네이티브 푸시가 필요하다 |
| **WHO** | `mall-app`(Capacitor Android)을 설치한 고객(`mall_members`). 관리자·기사는 이 앱을 쓰지 않으므로(관리자=데스크톱 웹, 기사=모바일 웹) 이번 범위에서 푸시 **수신자는 고객뿐**이다 |
| **RISK** | (1) FCM HTTP v1은 OAuth2 서비스 계정 인증이 필요해 기존 프로젝트에 없던 JWT 서명/외부 HTTP 의존이 생긴다 (2) 공유호스팅(Hostinger)에 서비스 계정 키를 안전하게 보관해야 한다 (3) 채팅 발송 요청 안에서 동기 호출 시 FCM 지연이 사용자 응답속도에 영향을 줄 수 있다 (4) 토큰은 기기 단위이지 회원 단위가 아니어서 로그인/로그아웃/기기공유 시나리오에서 오배송 가능성이 있다 |
| **SUCCESS** | 고객이 앱을 백그라운드/종료해도 관리자·기사 응답 시 OS 알림이 뜨고, 탭하면 해당 주문 채팅창(`/mall/order_chat.php?order_id=N`)으로 바로 이동한다. 푸시 실패가 채팅 발송 자체를 절대 막지 않는다 |
| **SCOPE** | v1: Android만(iOS/웹푸시 제외), 주문톡(관리자/기사→고객) 발송 방향만, 1기기당 1토큰(다중기기 지원은 스키마상 허용하되 UI는 단순 유지) |

---

## 0. 현재 상태 감사 요약 (Audit Findings)

| 항목 | 현재 상태 | 근거 |
|------|-----------|------|
| Capacitor 앱 | `mall-app/`, appId `net.homekmart.mall`, `server.url`로 `https://homekmart.net/mall/` 원격 로드(로컬 `www/`는 플레이스홀더) | `mall-app/capacitor.config.json` |
| 설치된 플러그인 | `@capacitor/core`, `@capacitor/android`, `@capacitor/app`, `@capgo/capacitor-social-login` — **push-notifications 플러그인 없음** | `mall-app/package.json` |
| Firebase 연동 | **전무**. `google-services.json` 없음, `app/build.gradle`에 `com.google.gms.google-services` 플러그인 미적용. 단, 루트 `android/build.gradle`에 `classpath 'com.google.gms:google-services:4.4.4'`만 이미 선언돼 있음(적용 안 됨 — 커밋된 스켈레톤 기본값, dirty 변경 아님) | `mall-app/android/build.gradle`, `git log -- mall-app/android/build.gradle` |
| Capacitor JS 브릿지 사용 | `mall/partials/footer.php`에서 `window.Capacitor.Plugins.App`으로 하드웨어 뒤로가기 처리 중 — **네이티브 플러그인을 원격 웹 콘텐츠에서 호출하는 기존 패턴 존재**(FCM 토큰 등록도 같은 자리에 추가 가능) | `mall/partials/footer.php:151-164` |
| 서버 메시지 발송 흐름 | 고객/관리자/기사 3개 ajax 엔드포인트가 모두 단일 헬퍼 `mall_order_chat_send($order_id, $sender_type, $sender_id, $message)`(`mall/lib/order_chat.php`)를 호출 → 훅 포인트 단일화 가능 | `mall/lib/order_chat.php:20-55`, `mall/ajax/send_order_chat_message.php`, `mall/admin/ajax/send_order_chat_reply.php`, `mall/driver/ajax/send_order_chat_message.php` |
| 회원 세션/인증 | `mall/lib/auth.php` — `MALLSESSID` 쿠키(30일), `mall_current_member()`, `mall_is_logged_in()` | `mall/lib/auth.php` |
| DB 커넥션 | `mall_get_db_connection()`(요청당 재사용 mysqli), `mysqli` prepared statement 컨벤션 | `mall/config/mall_config.php` |
| 외부 HTTP 호출 선례 | `mall_verify_google_id_token()`이 이미 `curl_init` + 5초 타임아웃으로 외부(Google) API 호출 중 — FCM 호출도 동일 방식 재사용 가능 | `mall/lib/auth.php:161-196` |
| 의존성 관리 | 루트에 `composer.json` 없음, `vendor/`에는 PhpSpreadsheet만 수동 vendored — **Google API 클라이언트/Guzzle/firebase-php-jwt 등 외부 라이브러리 없음** → OAuth2 JWT는 PHP 내장 `openssl_sign()`으로 직접 서명해야 함 | `find vendor -maxdepth 1`, `composer.json` 부재 확인 |
| 마이그레이션 컨벤션 | `sql/run_add_*_migration.php` — 실행 가능한 PHP, `SHOW TABLES`로 멱등성 확보, HTML 결과 화면 출력, "실행 후 삭제" 안내 | `sql/run_add_mall_order_chat_closed_migration.php` |
| 기존 푸시 미구현 백로그 | `DELIVERY_APP_SUMMARY.md`, `claudedocs/2025-10-04_작업_요약.md`에 "푸시 알림(FCM)"이 미구현 TODO로만 언급됨 | grep 결과 |

---

## 1. Architecture Options

### 1.1 발송 방식(동기 vs 큐)

| Criteria | Option A: 인라인 동기 발송 | Option B: DB 큐 + 별도 워커(cron) |
|----------|:-:|:-:|
| **Approach** | `mall_order_chat_send()` 끝에서 즉시 curl로 FCM 호출 | 발송 요청은 `mall_push_queue` 테이블에 INSERT만 하고, `mall/batch/`류 cron 스크립트가 주기적으로 처리 |
| **New Files** | ~4 (lib/push.php, migration, ajax 2개) | ~6 (+ 큐 테이블, 워커 스크립트) |
| **지연 영향** | 채팅 POST 응답이 FCM 왕복(수백ms~초)만큼 느려짐 | 채팅 POST는 즉시 응답, 푸시는 최대 cron 주기(예: 1분)만큼 지연 |
| **장애 격리** | try/catch로 감싸면 채팅 자체는 안 죽지만, FCM 응답 대기 자체는 요청 스레드를 점유 | 완전 분리 — FCM 장애가 채팅 기능에 0% 영향 |
| **복잡도** | Low | Medium (cron 등록 필요 — 공유호스팅 cron 슬롯 확인 필요) |
| **이 프로젝트 적합성** | 트래픽이 크지 않고(단일 점포), 기존 `mall_verify_google_id_token()`도 동기 curl 5초 타임아웃 선례가 있음 | 향후 발송량이 늘거나(주문상태 변경 알림 등 다른 이벤트로 확장) 지연에 민감해지면 전환 |
| **Recommendation** | **v1 기본 선택** — 단, curl 타임아웃을 짧게(연결 2초/전체 3초) 잡고 실패해도 예외를 삼켜 응답을 막지 않음 | v2 후보(발송 이벤트 종류가 늘어날 때) |

**권장**: Option A(인라인 동기, 타임아웃 방어) — 기존 컨벤션(`mall_verify_google_id_token`)과 일치하고 큐/cron 인프라를 새로 얹지 않아도 된다. 단, OAuth2 액세스 토큰은 매 발송마다 새로 발급하면 안 되므로 **토큰 캐싱은 필수**(§3.3).

### 1.2 페이로드 방식

| Criteria | Option A: `notification` + `data` 혼합 | Option B: `data`-only |
|----------|:-:|:-:|
| 백그라운드/종료 상태 표시 | OS가 자동으로 트레이 알림 표시(앱 코드 불필요) | 표시 안 됨(직접 로컬 알림을 만들어야 하며, 종료 상태에서는 OEM 배터리 최적화로 미수신 위험) |
| 포그라운드 중복 방지 | `pushNotificationReceived` 리스너에서 현재 열린 `order_id`와 비교해 배지/새로고침만 처리(트레이는 포그라운드에서 자동 표시 안 됨 — Android 표준 동작) | 동일하게 제어 가능하나 백그라운드 신뢰성이 낮음 |
| **Recommendation** | **채택** | 미채택 |

`notification.title/body` + `data.order_id`, `data.type=order_chat` 조합으로 전송한다.

---

## 2. Server Message Send Flow (변경 후)

```
[관리자 발송] admin/ajax/send_order_chat_reply.php
[기사 발송]   driver/ajax/send_order_chat_message.php
        │
        ▼
mall_order_chat_send($order_id, $sender_type, $sender_id, $message)   ← mall/lib/order_chat.php (기존)
        │ (INSERT 성공 후, sender_type이 'admin' 또는 'driver'일 때만)
        ▼
mall_push_notify_order_message($order_id, $sender_type, $message)     ← mall/lib/push.php (신규)
        │
        ├─ SELECT member_id FROM mall_orders WHERE id = order_id
        ├─ SELECT token, id FROM mall_device_tokens WHERE member_id = ? AND is_active = 1
        ├─ mall_fcm_get_access_token()  → 캐시된 OAuth2 액세스 토큰(§3.3) 재사용/갱신
        └─ 토큰별로 mall_fcm_send($token, $title, $body, $data) 호출(HTTP v1, 1req/토큰)
                ├─ 200 OK → 필요 시 last_seen_at 갱신
                ├─ 404/UNREGISTERED/INVALID_ARGUMENT → is_active=0 (또는 삭제)
                └─ 그 외 오류 → error_log만 하고 무시(채팅 발송 흐름에 영향 없음)

[고객 발송] mall_order_chat_send($order_id, 'member', ...)
        └─ 푸시 호출 안 함 (관리자=데스크톱 웹, 기사=모바일 웹 — Capacitor 앱 없음, 이번 범위 밖)
```

**핵심 설계 원칙**: 훅을 `mall_order_chat_send()` 내부(또는 그 직후 각 호출부가 아닌 **함수 안**)에 둔다. 3개 ajax 엔드포인트를 개별 수정하지 않아도 되고, 향후 발송 경로가 늘어도(예: 관리자 콘솔에서 다른 진입점 추가) 자동으로 푸시가 적용된다 — 기존 문서의 "단일 지점" 설계 철학(§0 `mall-order-chat.design.md`)과 동일한 이유.

**실패 격리 원칙**: `mall_push_notify_order_message()` 전체를 `mall_order_chat_send()` 쪽에서 `try { } catch (Throwable $e) { error_log(...); }`로 감싼다. 이 함수의 어떤 예외도 상위로 전파되어 채팅 INSERT 성공 응답을 실패시키면 안 된다 — 기존 `mall_order_chat_reopen()`이 이미 이 패턴(Throwable catch)을 쓰고 있으므로 동일 컨벤션.

---

## 3. Firebase HTTP v1 인증 및 설정

### 3.1 인증 방식

FCM Legacy API(서버 키 단순 헤더)는 2024년부로 신규/일부 종료 대상이라 **HTTP v1**을 채택한다. v1은 Google OAuth2 **서비스 계정(Service Account) JWT Bearer Flow**가 필요하다:

1. 서비스 계정 비공개 키(JSON, `private_key`+`client_email` 포함)로 자체 서명 JWT(RS256) 생성
2. `https://oauth2.googleapis.com/token`에 `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`로 교환 → 액세스 토큰(1시간 유효) 획득
3. `POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send` 에 `Authorization: Bearer {access_token}`로 발송

### 3.2 라이브러리 없이 구현 (컨벤션 준수)

이 저장소엔 Composer/vendor에 Google API 클라이언트나 `firebase/php-jwt`가 없다(§0). 새 의존성을 추가하는 대신 **PHP 내장 함수만으로 JWT 서명**한다(부가 라이브러리 0개):

```php
// mall/lib/push.php (신규, 개념 스케치 — 구현 단계에서 작성)
function mall_fcm_build_jwt($service_account) {
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $service_account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $b64 = fn($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
    $unsigned = $b64($header) . '.' . $b64($claims);
    openssl_sign($unsigned, $signature, $service_account['private_key'], 'SHA256');
    return $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
}
```
- `openssl_sign()`은 PHP 표준 OpenSSL 확장(대부분 기본 활성화)만 사용 — 신규 composer 패키지 불필요
- 액세스 토큰 교환은 기존 `mall_verify_google_id_token()`과 동일한 `curl_init`+타임아웃 패턴 재사용

### 3.3 액세스 토큰 캐싱 (필수)

발송마다 OAuth2 토큰을 새로 발급하면 (a) 채팅 1건당 curl 2회(토큰+발송)로 지연이 배가되고 (b) Google 토큰 엔드포인트 쿼터에도 불필요한 부하를 준다. **1시간 유효 토큰을 캐싱**한다.

- 캐시 위치 후보:
  - **DB 옵션(권장)**: 기존 `system_settings` 유사 패턴처럼 소규모 캐시 테이블(`mall_fcm_token_cache`: `access_token`, `expires_at` 단일 행) 또는 `system_settings` key-value 재사용
  - 파일 캐시는 공유호스팅에서 웹루트 노출 위험이 있어 비권장(§4.2 참고)
- 만료 55분 기준으로 갱신(clock skew 여유), 발급 실패 시 이전 캐시가 있으면 만료 전까지는 계속 재사용하지 않고 즉시 실패 처리(보안상 만료된 토큰으로 호출 금지)

### 3.4 서비스 계정 키 저장 위치 (보안 리스크 — 외부 준비사항과 연결)

- **문제**: 이 저장소의 기존 관례(`mall_config.php`)는 API 키를 PHP 상수로 웹루트 내에 저장한다(PHP는 실행되므로 소스가 그대로 유출되진 않음). 하지만 서비스 계정은 **탈취 시 프로젝트 전체 FCM 발송 권한**을 주므로 리스크가 더 크다.
- **권장**: `config/firebase_service_account.php`처럼 `return [...]` 형태의 PHP 파일로 저장(다운로드된 원본 `.json`을 웹루트에 그대로 두지 않는다 — Apache가 정적 파일로 서빙할 수 있는 확장자이기 때문). 가능하면 Hostinger 계정에서 `public_html`(또는 이 저장소 루트) **바깥** 경로에 두고 절대경로로 `require`한다(호스팅 환경에서 실제 가능 여부는 외부 준비사항에서 확인 필요, §6).
- 최소한 루트 `.htaccess`의 `<Files "*.md">`/`*.sql` 차단 패턴처럼 `<FilesMatch "firebase_service_account\.(php|json)$">` 차단 규칙을 추가할 것(이번 문서 범위 밖 — 구현 시 반영)
- `.gitignore`에 반드시 추가하여 커밋 금지(서비스 계정 키는 절대 저장소에 커밋하지 않는다)

---

## 4. 회원-기기 토큰 데이터 모델

### 4.1 `mall_device_tokens` (신규 테이블)

```sql
CREATE TABLE `mall_device_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL COMMENT 'mall_members.id — 이 토큰이 현재 귀속된 회원(최근 로그인한 사람 기준)',
  `platform` enum('android') NOT NULL DEFAULT 'android' COMMENT 'v1은 android만, 확장 대비 컬럼만 유지',
  `token_hash` char(64) NOT NULL COMMENT "SHA-256(token) — FCM 토큰(가변길이 최대 4KB급)은 UNIQUE 인덱스에 직접 못 쓰므로 해시로 유일성 보장",
  `token` text NOT NULL COMMENT '원본 FCM registration token(발송 시 사용)',
  `app_version` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'FCM이 UNREGISTERED/INVALID_ARGUMENT 응답 시 0으로 비활성화',
  `last_registered_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `member_id_active` (`member_id`, `is_active`),
  CONSTRAINT `mall_device_tokens_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `mall_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원별 FCM 기기 토큰(주문톡 푸시 발송용)';
```

**설계 근거**:
- **FK는 `mall_members`만** — 관리자/기사용 Capacitor 앱이 없으므로(§0) `owner_type` 컬럼으로 일반화하지 않는다(YAGNI, CLAUDE.md 원칙 "필요 이상의 추상화 금지"와 일치). 향후 기사 앱이 생기면 그때 별도 테이블 또는 컬럼 추가로 확장.
- **토큰 자체가 아니라 해시를 UNIQUE 키로** — FCM 토큰은 최대 4KB까지 가능(InnoDB 인덱스 길이 제한 회피), 같은 물리 기기가 재등록해도 `ON DUPLICATE KEY UPDATE member_id = VALUES(member_id)`로 최신 로그인 회원에게 자동 재귀속(기기 공유/재로그인 시나리오 자연 처리 — 별도 "로그아웃 시 토큰 해제" API 없이도 다음 로그인 계정이 알림을 받게 됨).
- **`is_active`로 소프트 삭제** — FCM이 토큰 무효를 응답하면 즉시 비활성화하되 이력은 남겨 디버깅 가능(하드 삭제하지 않음).

### 4.2 등록/재발급 흐름

```
[앱 시작 또는 로그인 성공 시] footer.php의 Capacitor 브릿지 스크립트
    → PushNotifications.requestPermissions() → 허용 시 PushNotifications.register()
    → 'registration' 이벤트에서 token 수신
    → fetch('/mall/ajax/register_device_token.php', {token, csrf_token})  (POST)
        → mall_is_logged_in() 확인(비로그인 상태면 토큰만 받고 회원 연결은 다음 로그인 때 재등록으로 처리 — v1은 로그인 후에만 등록 호출)
        → INSERT ... ON DUPLICATE KEY UPDATE (token_hash 기준)
```
- 등록 API는 **로그인된 상태에서만** 호출(비로그인 상태 게스트에게는 주문톡 알림 대상이 없으므로 불필요한 행 생성 방지)
- 토큰 리프레시(`PushNotifications.addListener('registration', ...)`는 OS가 토큰을 재발급할 때도 재호출됨) — 동일 엔드포인트로 재등록하면 `ON DUPLICATE KEY UPDATE`가 자연히 최신화

---

## 5. API Specification (신규)

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| POST | `mall/ajax/register_device_token.php` | 고객 앱이 발급받은 FCM 토큰을 등록/갱신 | 회원 로그인 + CSRF |

### `POST mall/ajax/register_device_token.php`

**Request (form-urlencoded)**: `token`, `platform=android`, `app_version`(optional), `csrf_token`

**Response (200)**:
```json
{"success": true}
```

**Error Responses** (기존 `mall/ajax/*.php` 포맷과 동일):
- `400 VALIDATION_ERROR`: `token` 공백/과도한 길이(4096자 초과 등 방어적 상한)
- `401 UNAUTHORIZED`: 비로그인
- `403 CSRF_INVALID`
- `500 SERVER_ERROR`: DB 오류

> 별도 `unregister` 엔드포인트는 v1 범위에 두지 않는다(§4.1 재귀속 설계로 자연 해결). 로그아웃 시 명시적 무효화가 필요하다고 판단되면 후속 범위에서 추가.

---

## 6. Android Capacitor 통합

### 6.1 패키지/설정 변경 (구현 단계에서 반영할 목록 — 이번 감사에서는 미적용)

| 파일 | 변경 내용 |
|------|-----------|
| `mall-app/package.json` | `@capacitor/push-notifications` 의존성 추가 |
| `mall-app/android/app/google-services.json` | Firebase Console에서 Android 앱(`net.homekmart.mall`) 등록 후 다운로드해 배치 (§8 외부 준비사항) |
| `mall-app/android/app/build.gradle` | 최상단에 `apply plugin: 'com.google.gms.google-services'` 추가(루트 `build.gradle`엔 classpath 이미 존재 — §0) |
| `mall-app/android/app/src/main/AndroidManifest.xml` | Android 13+(`targetSdkVersion 36`이므로 필수) `<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />` 추가, 기본 알림 아이콘/색상 `<meta-data>`(`com.google.firebase.messaging.default_notification_icon`, `default_notification_color`) |
| `mall-app/android/app/src/main/res/` | 알림 트레이용 흑백 아이콘 리소스(`ic_stat_notify` 등) 추가 — 컬러 런처 아이콘을 그대로 쓰면 Android가 흰 사각형으로 렌더링하는 문제 발생 |
| `mall-app/capacitor.config.json` | 특별 설정 불필요(플러그인은 기본 설정으로 동작) — 필요 시 `PushNotifications.presentationOptions` 조정 |
| `mall/partials/footer.php` | 토큰 등록/딥링크 JS 추가(§6.2) — 기존 `Capacitor.Plugins.App` 사용 블록과 같은 위치 |

`npx cap sync android` 재실행 필요(플러그인 네이티브 코드 반영).

### 6.2 JS 통합 지점 (`mall/partials/footer.php`, 기존 Capacitor 블록 옆에 추가)

```js
(function () {
    if (!window.Capacitor || !window.Capacitor.isNativePlatform || !window.Capacitor.isNativePlatform()) return;
    var Push = window.Capacitor.Plugins && window.Capacitor.Plugins.PushNotifications;
    if (!Push) return;

    // 로그인된 페이지에서만 등록 시도(비로그인 상태 register 호출은 서버가 401로 무시)
    Push.requestPermissions().then(function (res) {
        if (res.receive !== 'granted') return;
        Push.register();
    });

    Push.addListener('registration', function (token) {
        fetch('/mall/ajax/register_device_token.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'token=' + encodeURIComponent(token.value)
                + '&platform=android'
                + '&csrf_token=' + encodeURIComponent(MALL_CSRF_TOKEN) // 기존 페이지에서 이미 노출 중인 값 재사용
        });
    });

    // 앱이 포그라운드일 때 수신 — 새 알림 트레이를 만들지 않고, 현재 열려있는 주문톡이면 조용히 갱신만
    Push.addListener('pushNotificationReceived', function (notification) {
        var orderId = notification.data && notification.data.order_id;
        if (orderId && window.ORDER_ID === Number(orderId) && typeof window.mallOrderChatRefresh === 'function') {
            window.mallOrderChatRefresh();
        }
    });

    // 알림 탭(백그라운드/종료 상태에서 눌러서 앱 진입) — 해당 주문 채팅창으로 딥링크
    Push.addListener('pushNotificationActionPerformed', function (action) {
        var orderId = action.notification && action.notification.data && action.notification.data.order_id;
        if (orderId) {
            window.location.href = '/mall/order_chat.php?order_id=' + encodeURIComponent(orderId);
        }
    });
})();
```

- **딥링크 목적지**: `/mall/order_chat.php?order_id=N` — 기존 페이지가 이미 이 쿼리 파라미터로 채팅창을 렌더링하므로 서버 변경 불필요(§0 확인됨)
- `server.url` 방식(원격 페이지 로드)이라 `window.location.href` 이동은 Capacitor `allowNavigation`에 이미 등록된 `homekmart.net`이므로 그대로 동작(`capacitor.config.json` 확인됨)
- `MALL_CSRF_TOKEN` 같은 전역 JS 변수가 실제로 존재하는지는 구현 단계에서 `mall/lib/csrf.php`의 `mall_csrf_field()`/기존 페이지의 JS 노출 패턴을 확인해 맞춰야 함(이번 감사에서 footer.php 전체의 CSRF 노출 여부까지는 확인하지 않음 — **Do 단계 확인 필요 항목**)

### 6.3 포그라운드/백그라운드 동작 요약

| 앱 상태 | `notification`+`data` 혼합 페이로드 동작 |
|---------|---------------------------------------|
| 종료(kill) | OS가 자동으로 트레이 알림 표시 → 탭 시 앱 콜드스타트 후 `pushNotificationActionPerformed` 발생 |
| 백그라운드 | 동일하게 OS 자동 표시 → 탭 시 포그라운드 전환 + 액션 이벤트 |
| 포그라운드(채팅창 열람 중 포함) | 트레이에 표시되지 않고 `pushNotificationReceived`만 발생 → 이미 폴링 중이므로 즉시 새로고침만 트리거 |

---

## 7. Security Considerations

- [ ] `register_device_token.php`는 다른 `mall/ajax/*.php`와 동일하게 `mall_is_logged_in()` + `mall_csrf_verify()` 필수
- [ ] `token` 길이 서버단 상한(예: 4096자) — 악의적으로 과도한 payload를 저장하는 것 방지
- [ ] 서비스 계정 비공개 키는 저장소에 커밋 금지, 웹에서 직접 다운로드 가능한 위치/확장자 회피(§3.4)
- [ ] 발송 시 **항상 `order_id`로부터 조회한 `member_id`의 토큰만** 사용 — 클라이언트가 임의로 지정한 대상에게 보내지 않음(IDOR 방지, 기존 주문톡의 소유권 검증 철학과 동일)
- [ ] 관리자/기사가 보낸 메시지 내용이 그대로 알림 본문에 들어가므로, `notification.body` 조립 시에도 길이 제한(예: 100자 트렁케이션)과 개행 제거 — 알림 트레이 레이아웃 깨짐 방지(XSS는 네이티브 알림 특성상 해당 없음)
- [ ] `mall_fcm_send()`의 curl 응답/에러를 `error_log()`에 남기되, 서비스 계정 키나 액세스 토큰 원문은 로그에 남기지 않음
- [ ] Rate limiting은 기존 주문톡과 동일하게 v1 범위 밖(내부 소규모 서비스, §7 `mall-order-chat.design.md`와 동일 판단)

---

## 8. 실패 격리 & 마이그레이션

### 8.1 실패 격리 원칙 (재확인)

- `mall_push_notify_order_message()`는 **어떤 예외도 던지지 않는다**(`try/catch (Throwable $e)`로 함수 내부에서 완결) — 기존 `mall_order_chat_reopen()`/`mall_order_chat_unread_count_for_member()`의 방어적 패턴을 그대로 계승
- FCM 개별 토큰 오류(예: `UNREGISTERED`)는 해당 토큰만 비활성화하고 나머지 토큰 발송은 계속 진행(다중기기 대비)
- curl 타임아웃: 연결 2초 / 전체 3초 — FCM 응답이 느려도 채팅 POST 응답이 과도하게 지연되지 않도록 상한
- 마이그레이션(`mall_device_tokens`) 미실행 상태에서도 채팅 기능 자체는 죽지 않아야 함 — `mall_push_notify_order_message()` 안에서 테이블 부재 예외도 동일하게 삼킴(기존 `mall_order_chat_unread_map_for_admin()`이 마이그레이션 순서 문제를 이렇게 방어한 선례와 동일)

### 8.2 마이그레이션 파일 (신규, 구현 단계에서 작성)

`sql/run_add_mall_device_tokens_migration.php` — 기존 `run_add_mall_order_chat_closed_migration.php`와 동일한 형식(SHOW TABLES 멱등성 체크, 실행 결과 HTML, "실행 후 삭제" 안내)으로 작성한다. (메모리 규칙: DB 마이그레이션은 항상 실행 가능한 PHP 스크립트로 작성 — raw `.sql`만 남기지 않음.)

### 8.3 롤백 계획

- 신규 테이블 1개 추가만 있으므로 롤백은 `DROP TABLE mall_device_tokens`로 단순함(기존 데이터 영향 없음)
- 서버 코드 롤백 시 `mall_order_chat_send()`에서 푸시 훅 호출부만 제거하면 기존 주문톡 동작에는 영향 없음(설계상 완전히 부가 기능)

---

## 9. Test Plan

| Type | Target | Phase |
|------|--------|-------|
| L1: API | `register_device_token.php` 상태코드(200/400/401/403), 재등록 시 `ON DUPLICATE KEY UPDATE` 동작 | Do |
| L1: Server Push Logic | `mall_fcm_build_jwt()`/토큰 캐시 만료 로직 단위 확인(만료 임박 시 갱신, 유효 시 재사용) | Do |
| L2: 기기 통합 | 실제 Android 기기(에뮬레이터 FCM 미지원 이슈 있음 — 반드시 실기기 또는 Play Services 탑재 에뮬레이터)에서 (a) 포그라운드 (b) 백그라운드 (c) 완전 종료 3가지 상태별 알림 수신·탭 딥링크 확인 | Do |
| L2: 실패 격리 | 서비스 계정 키를 의도적으로 무효화한 상태에서 주문톡 발송이 정상 성공(200)하는지 확인(푸시만 실패, 채팅은 성공) | Do |
| L3: E2E | 관리자 응답 → 고객 앱 백그라운드 상태에서 알림 수신 → 탭 → 해당 주문 채팅창 진입 → 메시지 표시 확인 | Do |
| L3: 토큰 무효화 | FCM이 `UNREGISTERED` 응답하는 상황(앱 삭제 후 재발송) 재현 → `is_active=0` 갱신 확인 | Do |
| L3: 기기 공유/재로그인 | 회원A 로그인·토큰 등록 → 로그아웃 → 회원B 로그인·토큰 등록(같은 기기) → 이후 알림이 B에게만 감 | Do |

---

## 10. Implementation Guide (File Structure — 구현 단계 참고용, 이번 감사에서는 미생성)

```
mall/
├── lib/
│   └── push.php                                  (신규 — JWT 서명/토큰 캐시/mall_fcm_send/mall_push_notify_order_message)
├── ajax/
│   └── register_device_token.php                 (신규)
└── partials/
    └── footer.php                                (수정 — Push 등록/딥링크 JS 추가)

mall-app/
├── package.json                                   (수정 — @capacitor/push-notifications 추가)
└── android/app/
    ├── google-services.json                       (신규 — Firebase Console에서 다운로드)
    ├── build.gradle                                (수정 — google-services 플러그인 apply)
    └── src/main/AndroidManifest.xml                (수정 — POST_NOTIFICATIONS 권한, 기본 알림 아이콘/색)

config/
└── firebase_service_account.php                    (신규, 커밋 금지 — .gitignore 추가)

sql/
└── run_add_mall_device_tokens_migration.php         (신규)
```

### Recommended Session Plan

| Session | Scope | 비고 |
|---------|-------|------|
| 1 | 외부 준비사항 완료 확인(§11) 후 시작 | Firebase 프로젝트/키 없이는 이후 세션 진행 불가 |
| 2 | `mall_device_tokens` 마이그레이션 + `mall/lib/push.php`(JWT/토큰캐시/FCM 발송) + `register_device_token.php` | 서버측 순수 로직, 기기 없이 curl로 단위 확인 가능 |
| 3 | `mall_order_chat_send()` 훅 연결 + 실패격리 확인(L2 시나리오) | |
| 4 | Android 앱 변경(`@capacitor/push-notifications`, manifest, footer.php JS) + `npx cap sync` + 실기기 테스트(L2/L3) | 실물 Android 기기 필요 |
| 5 | Check + Report | |

---

## 11. 외부 Firebase 준비사항 (구현 착수 전 필수 — 이 세션에서는 수행 불가)

1. **Firebase 프로젝트 생성**(Firebase Console) — 기존 Google Cloud 프로젝트(구글 로그인용 OAuth 클라이언트가 이미 있다면 같은 GCP 프로젝트에 연결 가능)
2. **Android 앱 등록**: 패키지명 `net.homekmart.mall`(기존 `applicationId`와 반드시 일치), 디버그/릴리스 키스토어 각각의 **SHA-1 및 SHA-256** 지문 등록(`mall-app/android/keystore/`, `keystore.properties` 확인 필요 — 릴리스 키스토어 지문 추출 명령 준비 필요)
3. `google-services.json` 다운로드 → `mall-app/android/app/`에 배치(Git에 커밋할지 여부 결정 — 패키지명/프로젝트 번호만 있고 비밀값은 아니라 커밋 가능하나, 팀 정책에 따라 `.gitignore` 처리도 검토. `mall-app/android/.gitignore`에 이미 "Google Services" 관련 주석이 존재 — 현재 그 규칙이 실제로 이 파일을 막는지 구현 단계에서 재확인)
4. **Cloud Messaging API(V1)** 활성화(GCP Console → API 및 서비스, Legacy가 아닌 V1 확인)
5. **서비스 계정 생성**: IAM에서 "Firebase Cloud Messaging API 관리자" 역할 부여 → JSON 키 발급·다운로드(1회만 다운로드 가능하니 안전한 곳에 즉시 백업)
6. 서비스 계정 키를 서버에 안전하게 배치할 **호스팅 경로 확인**(Hostinger `u622428657` 계정에서 `public_html`/이 저장소 루트 바깥에 파일을 둘 수 있는지 hPanel/파일 관리자에서 확인 — 불가능하면 §3.4의 웹루트 내 차단 대안으로 진행)
7. 알림 트레이용 **단색(흑백) 아이콘 리소스** 준비(디자인팀/기존 로고에서 파생 — `mipmap` 컬러 아이콘 재사용 불가)
8. (권장) 실물 Android 테스트 기기 1대 이상 확보 — 에뮬레이터는 Google Play 서비스 포함 이미지가 아니면 FCM 수신 불가
9. 서비스 계정 키 로테이션 정책 결정(예: 유출 의심 시 즉시 폐기 후 재발급 — 코드 변경 없이 파일 교체만으로 대응되도록 설계됨)

---

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 0.1 | 2026-09-08 | 최초 작성 — 코드 감사 기반 FCM 통합 설계(구현 전, 파일 미변경) | claude-code (dispatched worker) |
