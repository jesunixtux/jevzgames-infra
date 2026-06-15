$ErrorActionPreference = "Stop"

$project = Join-Path $PSScriptRoot "JevzGames.CefClient.csproj"
$output = Join-Path $PSScriptRoot "dist"

if (Test-Path $output) {
    Remove-Item -LiteralPath $output -Recurse -Force
}

dotnet publish $project -c Release -p:Platform=x64 -r win-x64 --self-contained false -o $output

Write-Host "Cliente publicado en: $output"
