# HOME K MART Mall App

Capacitor 기반 Android 고객용 앱입니다. 앱은 `https://homekmart.net/mall/`을 표시합니다.

## 테스트 APK 만들기

저장소 루트에서 다음 명령을 실행합니다.

```powershell
cd mall-app
npm run build:test
```

스크립트가 Capacitor 설정과 플러그인을 동기화하고 Debug APK를 빌드한 후 다음 위치에 복사합니다.

```text
mall-app/dist/HOME-K-MART-test.apk
```

APK를 Android 기기로 전송해 설치하면 됩니다. Play Protect가 출처를 확인할 수 있으며, 기기 설정에서 해당 파일 관리자 또는 브라우저의 `알 수 없는 앱 설치`를 허용해야 할 수 있습니다.

이미 Play Store용 앱이 설치되어 있고 서명이 다르면 테스트 APK가 덮어써지지 않습니다. 이 경우 기존 앱을 제거한 뒤 테스트 APK를 설치하거나, Play Console의 내부 테스트 트랙에는 서명된 AAB를 사용하세요.

## Android Studio에서 실행

```powershell
npm run android:open
```
