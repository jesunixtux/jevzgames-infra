$ErrorActionPreference = "Stop"

$project = Join-Path $PSScriptRoot "JevzGames.CefClient.csproj"

Write-Host "Restaurando dependencias CEF/CefSharp..."
dotnet restore $project -p:Platform=x64

Write-Host "Ejecutando JevzGames CEF Client..."
dotnet run --project $project -c Debug -p:Platform=x64
