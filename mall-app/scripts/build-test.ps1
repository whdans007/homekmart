$ErrorActionPreference = 'Stop'

$appRoot = Split-Path -Parent $PSScriptRoot
$bundledJdk = Join-Path $appRoot '.jdk-download\jdk-21.0.12.1+1'
$gradleRoot = Join-Path $appRoot 'android'
$sourceApk = Join-Path $gradleRoot 'app\build\outputs\apk\debug\app-debug.apk'
$distDir = Join-Path $appRoot 'dist'
$distApk = Join-Path $distDir 'HOME-K-MART-test.apk'

if (-not (Test-Path -LiteralPath (Join-Path $bundledJdk 'bin\java.exe'))) {
    throw "Bundled JDK was not found: $bundledJdk"
}

$env:JAVA_HOME = $bundledJdk
& npx cap sync android
if ($LASTEXITCODE -ne 0) { throw 'Capacitor Android sync failed.' }

Push-Location $gradleRoot
try {
    & .\gradlew.bat assembleDebug
    if ($LASTEXITCODE -ne 0) { throw 'Android debug build failed.' }
} finally {
    Pop-Location
}

New-Item -ItemType Directory -Path $distDir -Force | Out-Null
Copy-Item -LiteralPath $sourceApk -Destination $distApk -Force
$hash = (Get-FileHash -LiteralPath $distApk -Algorithm SHA256).Hash

Write-Host "Test APK: $distApk"
Write-Host "SHA-256: $hash"
