@echo off
REM ==========================================================================
REM  HOME K MART - Price Label Auto Print Launcher
REM  Chrome --kiosk-printing : scan a barcode -> print to the DEFAULT printer
REM                            with NO print dialog (fully automatic).
REM
REM  HOW TO USE:
REM   1) Set your LABEL printer as the Windows DEFAULT printer.
REM      (Settings > Printers > turn OFF "Let Windows manage my default printer")
REM   2) Double-click this file. The pricing page opens in auto-print mode.
REM   3) Keep the console window open while printing.
REM ==========================================================================

set "URL=http://homekmart.net/pricing/"
set "PROFILE=%LOCALAPPDATA%\chrome-pricing-kiosk"

REM ---- locate chrome.exe ----
set "CHROME="
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" set "CHROME=%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe"
if not defined CHROME if exist "%LocalAppData%\Google\Chrome\Application\chrome.exe" set "CHROME=%LocalAppData%\Google\Chrome\Application\chrome.exe"

if not defined CHROME goto nochrome

echo.
echo  HOME K MART - Price Label Auto Print
echo  ------------------------------------
echo   Chrome  : %CHROME%
echo   URL     : %URL%
echo   Profile : %PROFILE%
echo   Mode    : --kiosk-printing (no dialog, default printer)
echo.
echo  Do NOT close this window while printing.
echo.

REM Run Chrome directly (this window stays open until Chrome is closed).
REM For fullscreen kiosk too, add  --kiosk  to the line below (exit: Alt+F4).
"%CHROME%" --kiosk-printing --user-data-dir="%PROFILE%" --no-first-run --no-default-browser-check "%URL%"

goto end

:nochrome
echo.
echo  [ERROR] Google Chrome was not found.
echo  Please install Google Chrome, then run this file again.
echo.
pause
exit /b 1

:end
exit /b 0
