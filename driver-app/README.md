# HOME K MART Driver — 배송기사 전용 Android 앱

`mall/driver/` PHP 배송기사 포털(`https://homekmart.net/mall/driver/`)을 그대로 감싸는 Capacitor
WebView 래퍼 앱입니다. 로그인/세션은 서버가 관리하는 PHP 세션 쿠키를 그대로 사용하며, 이 앱은
"네이티브 앱 아이콘 + 스플래시 + 안드로이드 뒤로가기 처리"만 담당합니다.

고객용 `mall-app/`(패키지 `net.homekmart.mall`)과 동일한 구조를 따르되, 패키지명과 서버 경로만
배송기사 전용으로 분리했습니다.

| 항목 | 값 |
| --- | --- |
| Package (applicationId) | `net.homekmart.driver` |
| App name | HOME K MART Driver |
| 서버 URL | `https://homekmart.net/mall/driver/` |
| 로그인/세션 | `mall/lib/driver.php`의 PHP 세션 쿠키 그대로 사용 (앱은 관여하지 않음) |

## 왜 별도 앱인가

- `mall-app/`은 `net.homekmart.mall` 패키지의 고객용 쇼핑몰 앱이라 배송기사 포털
  (`/mall/driver/`)과 무관합니다. `capacitor.config.json`의 `server.url`이 다르면 완전히 다른 앱이
  필요합니다.
- 고객용 Firebase 프로젝트의 `google-services.json`은 패키지명이 `net.homekmart.mall`로 고정되어
  있어 이 앱(`net.homekmart.driver`)에 그대로 쓸 수 없습니다 — 안드로이드 앱 무결성 검증에 실패합니다.
  자세한 내용은 [Firebase(선택)](#firebase-선택--아직-설정되지-않음) 참고.

## 폴더 구조

```
driver-app/
  capacitor.config.json   # appId/appName/server.url 정의
  package.json            # @capacitor/core, /android, /cli 만 의존 (푸시/소셜로그인 플러그인 없음)
  resources/               # 아이콘·스플래시 원본 PNG (mall-app과 동일 브랜딩 재사용)
  www/index.html           # 사용되지 않는 placeholder — server.url이 실제 로드됨
  android/                 # cap add android로 생성된 네이티브 프로젝트
    app/src/main/java/net/homekmart/driver/MainActivity.java  # 뒤로가기 처리
    app/google-services.example.json  # Firebase 연동 시 참고용 (실제 파일 아님)
```

## 사전 준비물

- Node.js (이미 설치됨 확인: `node --version`)
- **JDK 17 또는 21** — Android Gradle Plugin 8.13 / Gradle 8.14.3 조합은 JDK 25에서
  Groovy 빌드 스크립트를 새로 컴파일할 때 `Unsupported class file major version 69` 오류로
  실패합니다(Gradle의 내장 ASM이 아직 JDK 25 클래스 포맷을 못 읽음). 이 환경의 기본
  `JAVA_HOME` 후보인 `C:\Program Files\Android\Android Studio\jbr`는 JDK 25이므로 **그대로 쓰면
  안 됩니다.** 이번 작업에서는 `C:\Users\<user>\.jdks\jdk-21.0.12.1+1`에 Temurin 21을 내려받아
  사용했습니다(Eclipse Adoptium 배포판, 프로젝트 폴더 밖에 위치 — 저장소에는 포함되지 않음).
  같은 방식으로 재현하려면:
  ```powershell
  Invoke-WebRequest -Uri "https://api.adoptium.net/v3/binary/latest/21/ga/windows/x64/jdk/hotspot/normal/eclipse" -OutFile temurin21.zip
  Expand-Archive temurin21.zip -DestinationPath C:\Users\<user>\.jdks
  ```
- Android SDK (`sdk.dir`은 `android/local.properties`에 로컬 경로로 설정 — 커밋되지 않음)

## 빌드 절차

```bash
cd driver-app
npm install
npx cap sync android

cd android
# JAVA_HOME을 JDK 17/21로 지정한 뒤 빌드 (Android Studio에서 열 경우 Settings ▸ Build Tools ▸
# Gradle ▸ Gradle JDK를 17/21로 지정하면 IDE 빌드도 동일하게 동작합니다)
export JAVA_HOME="C:\Users\<user>\.jdks\jdk-21.0.12.1+1"
./gradlew.bat assembleDebug
```

빌드 결과 APK: `driver-app/android/app/build/outputs/apk/debug/app-debug.apk`

## 뒤로가기(백버튼) 동작

서버가 렌더링하는 일반 페이지(SPA 아님)를 로드하므로 로컬 `www/`에 JS 백버튼 핸들러를 둘 수
없습니다. 대신 `MainActivity.java`에서 네이티브로 처리합니다:

- WebView 히스토리가 있으면 → 뒤로 이동 (`goBack()`)
- 최상단(로그인 화면, 배달목록 첫 진입 등)이면 → 앱을 종료하지 않고 `moveTaskToBack(true)`로
  홈으로 내려보냄 (배송 중 실수로 로그인 세션이 끊기거나 GPS 추적이 중단되지 않도록 종료 대신
  백그라운드 전환을 선택)

## 위치 권한

`mall/driver/order_detail.php`가 배송 중(`delivering`) 상태일 때 30초 간격으로
`navigator.geolocation.getCurrentPosition()`을 호출해 `/mall/driver/ajax/update_location.php`로
위치를 전송합니다. `AndroidManifest.xml`에 `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION`을
선언해 두면 Capacitor의 `BridgeWebChromeClient`가 이 권한을 보고 런타임 동의 다이얼로그를
자동으로 띄웁니다(추가 네이티브 코드 불필요). 포그라운드에서만 동작하며 백그라운드 위치 권한은
사용하지 않습니다.

## 브랜딩 자산

런처 아이콘·스플래시 이미지(`mipmap-*`, `drawable-*land/port*`)는 `mall-app/android/app/src/main/res`에서
그대로 복사했습니다 — 같은 HOME K MART 브랜딩을 쓰므로 `@capacitor/assets`로 재생성할 필요가
없었습니다. 원본 PNG(`resources/icon.png`, `resources/splash.png`)도 동일하게 복사해 두었습니다.
앱 이름 문자열(`values/strings.xml`)만 "HOME K MART Driver"로 다릅니다.

## Firebase(선택) — 아직 설정되지 않음

이 앱은 **Firebase 없이 빌드됩니다.** `app/build.gradle`은 `google-services.json`이 없으면
`com.google.gms.google-services` 플러그인 적용을 건너뛰도록 되어 있습니다:

```groovy
if (file('google-services.json').exists()) {
    apply plugin: 'com.google.gms.google-services'
} else {
    logger.info("google-services.json not found, google-services plugin not applied. ...")
}
```

(mall-app의 `try { servicesJSON.text } catch(Exception e)` 패턴과 동일한 목적이지만, `.exists()`로
바꿔 예외를 던지지 않도록 했습니다 — 이 환경에서 JDK 25로 처음 빌드를 시도했을 때 바로 이
try/catch 블록이 `Unsupported class file major version 69` 오류를 유발하는 것을 확인해서
수정했습니다. 근본 원인은 JDK 25 자체였지만 — `.exists()` 쪽이 예외 기반 제어흐름을 피하는
더 안전한 방식이라 그대로 유지했습니다.)

향후 배송기사 앱에 푸시 알림 등 Firebase 기능을 추가하려면(현재 범위 아님):

1. Firebase 콘솔에서 **패키지명 `net.homekmart.driver`로 Android 앱을 새로 등록** —
   mall-app의 `net.homekmart.mall` 앱과는 별개입니다. 같은 Firebase 프로젝트에 앱만 추가해도 되고,
   완전히 새 프로젝트를 만들어도 됩니다.
2. 다운로드한 `google-services.json`을 `driver-app/android/app/google-services.json`에 배치
   (`.gitignore`에 걸려 있어 커밋되지 않음 — 형식은 같은 폴더의
   `google-services.example.json` 참고).
3. 디버그/릴리스 키스토어의 SHA-1·SHA-256 지문을 Firebase 프로젝트 설정에 등록.
4. 푸시가 필요하면 `@capacitor/push-notifications`를 `package.json`에 추가하고
   `npx cap sync android` 재실행.

## 릴리스 서명 (아직 미설정)

이번 작업 범위는 디버그 빌드까지입니다. 릴리스(서명된 APK/AAB)를 만들려면 mall-app의
`android/keystore.properties` + `keystore/*.keystore` 패턴처럼 별도 릴리스 키스토어를 새로
생성하고 `android/app/build.gradle`에 `signingConfigs`를 추가해야 합니다(다른 앱 간 키스토어
공유는 권장하지 않음 — 스토어 등록 시 앱마다 별도 서명 키를 쓰는 것이 일반적입니다).
