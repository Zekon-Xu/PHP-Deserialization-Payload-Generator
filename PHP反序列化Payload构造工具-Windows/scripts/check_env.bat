@echo off
setlocal
cd /d "%~dp0.."

echo [1/3] PHP CLI
"runtime\php\php.exe" -v
if errorlevel 1 exit /b 1

echo.
echo [2/3] PHPGGC path
if not exist "vendor\phpggc\phpggc" (
  echo Missing vendor\phpggc\phpggc
  exit /b 1
)
echo vendor\phpggc\phpggc

echo.
echo [3/3] PHPGGC chain list smoke test
"runtime\php\php.exe" "vendor\phpggc\phpggc" -i Laravel/RCE1
