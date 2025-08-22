@echo off
REM HOME K MART 데이터베이스 마이그레이션 스크립트
REM 로컬 데이터베이스를 운영 서버용 SQL 덤프로 생성

echo ===================================================================
echo HOME K MART 데이터베이스 마이그레이션 도구
echo ===================================================================
echo.

REM 현재 날짜/시간으로 파일명 생성
for /f "tokens=2 delims==" %%a in ('wmic OS Get localdatetime /value') do set "dt=%%a"
set "YY=%dt:~2,2%" & set "YYYY=%dt:~0,4%" & set "MM=%dt:~4,2%" & set "DD=%dt:~6,2%"
set "HH=%dt:~8,2%" & set "Min=%dt:~10,2%" & set "Sec=%dt:~12,2%"
set "timestamp=%YYYY%-%MM%-%DD%_%HH%-%Min%-%Sec%"

set "output_file=sql\full_migration_%timestamp%.sql"

echo 출력 파일: %output_file%
echo.

REM XAMPP MySQL 경로 설정 (일반적인 경로들)
set "mysql_path="
if exist "C:\xampp\mysql\bin\mysqldump.exe" set "mysql_path=C:\xampp\mysql\bin\"
if exist "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" set "mysql_path=C:\Program Files\MySQL\MySQL Server 8.0\bin\"
if exist "C:\mysql\bin\mysqldump.exe" set "mysql_path=C:\mysql\bin\"

if "%mysql_path%"=="" (
    echo ❌ MySQL 도구를 찾을 수 없습니다.
    echo 💡 대신 data_migration_tool.php를 사용하세요:
    echo    http://localhost/min/data_migration_tool.php
    echo.
    pause
    exit /b 1
)

echo ✅ MySQL 도구 발견: %mysql_path%
echo.

REM 디렉토리 생성
if not exist "sql" mkdir sql

echo 📊 데이터베이스 덤프 생성 중...
echo.

REM mysqldump 실행 (스키마 + 데이터)
"%mysql_path%mysqldump.exe" ^
    --host=localhost ^
    --user=root ^
    --password=Alswhd77**&& ^
    --single-transaction ^
    --routines ^
    --triggers ^
    --add-drop-database ^
    --databases min ^
    --result-file="%output_file%" ^
    --default-character-set=utf8mb4 ^
    --hex-blob ^
    --verbose

if %ERRORLEVEL% neq 0 (
    echo ❌ 덤프 생성 실패
    echo 💡 대신 data_migration_tool.php를 사용하세요:
    echo    http://localhost/min/data_migration_tool.php
    pause
    exit /b 1
)

echo.
echo ✅ 덤프 생성 완료!

REM 파일 크기 확인
for %%A in ("%output_file%") do set "filesize=%%~zA"
set /a "filesize_mb=%filesize%/1024/1024"

echo 📄 파일 정보:
echo    경로: %output_file%
echo    크기: %filesize% bytes (약 %filesize_mb%MB)

echo.
echo 🔧 운영 서버용으로 수정 중...

REM 임시 파일로 운영 서버용 수정
set "temp_file=%output_file%.temp"
set "final_file=sql\production_migration_%timestamp%.sql"

(
    echo -- ===================================================================
    echo -- HOME K MART 운영 서버 마이그레이션 SQL
    echo -- 생성일: %date% %time%
    echo -- 소스: 로컬 데이터베이스 (min^)
    echo -- 대상: 운영 서버 (if0_39723369_min^)
    echo -- ===================================================================
    echo.
    echo -- 기존 데이터베이스 삭제 및 재생성
    echo DROP DATABASE IF EXISTS `if0_39723369_min`;
    echo CREATE DATABASE IF NOT EXISTS `if0_39723369_min` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    echo USE `if0_39723369_min`;
    echo.
    echo -- 외래키 체크 비활성화 (빠른 삽입^)
    echo SET FOREIGN_KEY_CHECKS = 0;
    echo SET AUTOCOMMIT = 0;
    echo START TRANSACTION;
    echo.
) > "%final_file%"

REM 원본 덤프에서 데이터베이스명 변경하고 추가
powershell -Command "(Get-Content '%output_file%') -replace 'CREATE DATABASE.*min.*;', '' -replace 'USE.*min.*;', '' | Add-Content '%final_file%'"

(
    echo.
    echo -- 외래키 체크 재활성화
    echo COMMIT;
    echo SET FOREIGN_KEY_CHECKS = 1;
    echo SET AUTOCOMMIT = 1;
    echo.
    echo -- 마이그레이션 완료 확인
    echo SELECT 'Migration completed successfully!' as status;
) >> "%final_file%"

REM 원본 임시 파일 삭제
del "%output_file%"

echo ✅ 운영 서버용 SQL 파일 생성 완료!
echo 📄 최종 파일: %final_file%

echo.
echo 📖 사용 방법:
echo 1. InfinityFree phpMyAdmin 접속
echo 2. %final_file% 내용을 복사하여 실행
echo 3. deployment_test.php로 검증
echo.
echo ⚠️  주의: 운영 서버의 기존 데이터가 모두 삭제됩니다!
echo.

pause