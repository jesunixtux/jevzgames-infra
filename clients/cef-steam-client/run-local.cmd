@echo off
setlocal

set "PROJECT=%~dp0JevzGames.CefClient.csproj"

echo Restaurando dependencias CEF/CefSharp...
dotnet restore "%PROJECT%" -p:Platform=x64
if errorlevel 1 exit /b %errorlevel%

echo Ejecutando JevzGames CEF Client...
dotnet run --project "%PROJECT%" -c Debug -p:Platform=x64
exit /b %errorlevel%
