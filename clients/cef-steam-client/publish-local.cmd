@echo off
setlocal

set "PROJECT=%~dp0JevzGames.CefClient.csproj"
set "OUTPUT=%~dp0dist"

if exist "%OUTPUT%" rmdir /s /q "%OUTPUT%"

dotnet publish "%PROJECT%" -c Release -p:Platform=x64 -r win-x64 --self-contained false -o "%OUTPUT%"
if errorlevel 1 exit /b %errorlevel%

echo Cliente publicado en: %OUTPUT%
