@echo off
REM ============================================================
REM  Pavan Midway Residency - build the Android APK
REM
REM  Double click this file. It does everything except create
REM  your signing key, which it will walk you through.
REM ============================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo  ============================================
echo   Pavan Midway Residency - Android app build
echo  ============================================
echo.

REM ---------- check Node ----------
where node >nul 2>nul
if errorlevel 1 (
  echo  [X] Node.js is not installed.
  echo.
  echo      Download it from https://nodejs.org
  echo      Install it, then run this file again.
  echo.
  pause
  exit /b 1
)
echo  [OK] Node.js found
for /f "tokens=*" %%v in ('node --version') do echo       %%v

REM ---------- check Java ----------
where java >nul 2>nul
if errorlevel 1 (
  echo.
  echo  [!] Java is not installed.
  echo      Bubblewrap can install it for you - answer Yes when asked.
  echo.
) else (
  echo  [OK] Java found
)

REM ---------- install Bubblewrap ----------
echo.
echo  Installing the build tool...
call npm install -g @bubblewrap/cli >nul 2>nul
if errorlevel 1 (
  echo  [X] Could not install Bubblewrap.
  echo      Try running this file as Administrator.
  pause
  exit /b 1
)
echo  [OK] Build tool ready

REM ---------- signing key ----------
echo.
if exist "pmr-release.keystore" (
  echo  [OK] Signing key already exists
) else (
  echo  ============================================
  echo   Creating your signing key
  echo  ============================================
  echo.
  echo   This proves future updates come from you.
  echo   You will be asked for a password - write it
  echo   down somewhere safe. If you lose this file
  echo   you can never update the app.
  echo.
  pause
  keytool -genkeypair -v -keystore pmr-release.keystore -alias pmr -keyalg RSA -keysize 2048 -validity 10000
  if errorlevel 1 (
    echo.
    echo  [X] Could not create the key. Is Java installed?
    pause
    exit /b 1
  )
  echo.
  echo  [OK] Key created: pmr-release.keystore
  echo.
  echo  ********************************************
  echo   BACK THIS FILE UP TODAY
  echo   Copy pmr-release.keystore to Google Drive
  echo  ********************************************
  echo.
  pause
)

REM ---------- build ----------
echo.
echo  Building the app. This takes a few minutes the
echo  first time while Android tools download.
echo.

if not exist "twa-manifest.json" (
  echo  [X] twa-manifest.json is missing from this folder.
  pause
  exit /b 1
)

call bubblewrap build --skipPwaValidation
if errorlevel 1 (
  echo.
  echo  [X] The build failed. Scroll up to see why.
  echo      Common causes:
  echo        - wrong keystore password
  echo        - no internet connection
  pause
  exit /b 1
)

echo.
echo  ============================================
echo   Done
echo  ============================================
echo.
if exist "app-release-signed.apk" (
  echo   Your app:  app-release-signed.apk
  echo.
  echo   Copy it to an Android phone and open it.
  echo   To share with residents, put it on Google
  echo   Drive and send the link.
) else (
  echo   Build finished but the APK was not found.
  echo   Look for a .apk file in this folder.
)
echo.
echo   To remove the address bar at the top of the
echo   app, see the "Removing the browser address
echo   bar" section in README.md
echo.
pause
