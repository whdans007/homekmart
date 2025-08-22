@echo off
REM 빠른 데이터베이스 내보내기 스크립트

echo ===================================================================
echo HOME K MART 빠른 데이터 내보내기
echo ===================================================================
echo.

REM 현재 날짜/시간으로 파일명 생성
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "YYYY=%dt:~0,4%" & set "MM=%dt:~4,2%" & set "DD=%dt:~6,2%"
set "HH=%dt:~8,2%" & set "Min=%dt:~10,2%"
set "timestamp=%YYYY%-%MM%-%DD%_%HH%-%Min%"

set "output_file=backup_%timestamp%.sql"

echo 출력 파일: %output_file%
echo.

REM XAMPP MySQL 경로 설정
set "mysql_path="
if exist "C:\xampp\mysql\bin\mysqldump.exe" set "mysql_path=C:\xampp\mysql\bin\"
if exist "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" set "mysql_path=C:\Program Files\MySQL\MySQL Server 8.0\bin\"

if "%mysql_path%"=="" (
    echo ❌ MySQL 도구를 찾을 수 없습니다.
    echo 💡 수동으로 phpMyAdmin에서 내보내기를 사용하세요.
    pause
    exit /b 1
)

echo ✅ MySQL 도구 발견: %mysql_path%
echo 📊 데이터베이스 백업 생성 중...
echo.

REM 간단한 mysqldump 실행
"%mysql_path%mysqldump.exe" -u root -pAlswhd77**&& min > %output_file%

if %ERRORLEVEL% neq 0 (
    echo ❌ 백업 생성 실패
    echo 💡 비밀번호나 데이터베이스명을 확인하세요.
    pause
    exit /b 1
)

echo ✅ 백업 생성 완료!
echo.

REM 파일 크기 확인
for %%A in ("%output_file%") do set "filesize=%%~zA"
if %filesize% LSS 1024 (
    echo ⚠️ 파일 크기가 너무 작습니다 (%filesize% bytes)
    echo 데이터베이스에 데이터가 있는지 확인하세요.
) else (
    set /a "filesize_kb=%filesize%/1024"
    echo 📄 백업 파일 크기: %filesize_kb%KB
)

echo.
echo 📖 다음 단계:
echo 1. %output_file% 파일을 텍스트 에디터로 열기
echo 2. 파일 맨 위에 다음 줄들 추가:
echo.
echo DROP DATABASE IF EXISTS `if0_39723369_min`;
echo CREATE DATABASE `if0_39723369_min` CHARACTER SET utf8mb4;
echo USE `if0_39723369_min`;
echo SET FOREIGN_KEY_CHECKS = 0;
echo.
echo 3. 파일 맨 아래에 다음 줄 추가:
echo.
echo SET FOREIGN_KEY_CHECKS = 1;
echo.
echo 4. 수정된 파일을 InfinityFree phpMyAdmin에서 실행
echo.

pause