<#
Builds the release ZIP locally, the same way release-zip.yml does, and reports
what got included/excluded so drift from .github/release-excludes.txt is caught
before a real release run. Not shipped in the release ZIP itself (scripts/ is
excluded).

Usage: pwsh scripts/build-release-local.ps1 [-Version 3.1]
Without -Version, reads OPENSPARROW_VERSION from includes/version.php.
#>
param(
    [string]$Version
)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path "$PSScriptRoot\..").Path
Set-Location $repoRoot

if (-not $Version) {
    $verPhp = Get-Content "includes\version.php" -Raw
    if ($verPhp -notmatch "OPENSPARROW_VERSION',\s*'([\d.]+)'") {
        throw "Could not read OPENSPARROW_VERSION from includes\version.php"
    }
    $Version = $Matches[1]
}

$pkg = "opensparrow-$Version"
$stageRoot = "$repoRoot\dist-local"
$stage = "$stageRoot\$pkg"
$zipPath = "$stageRoot\$pkg.zip"

if (Test-Path $stageRoot) { Remove-Item -Recurse -Force $stageRoot }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

$excludeFile = "$repoRoot\.github\release-excludes.txt"
$rawLines = Get-Content $excludeFile | Where-Object { $_ -and -not $_.StartsWith("#") }

$forceIncludes = @()
$excludes = @()
foreach ($line in $rawLines) {
    if ($line.StartsWith("+ ")) {
        $forceIncludes += $line.Substring(2).Trim()
    } else {
        $excludes += $line.Trim()
    }
}

Write-Host "Copying repo tree (excluding .git only; fine-grained excludes applied next)..."
& "$env:SystemRoot\System32\Robocopy.exe" $repoRoot $stage /E /XD ".git" "dist" "dist-local" /NFL /NDL /NJH /NJS /NP | Out-Null
$global:LASTEXITCODE = 0

function Test-ExcludeMatch {
    param([string]$RelPath, [string]$Pattern)
    if ($Pattern -notmatch "[/*]") {
        # bare name (e.g. "vendor", "tests") - matches any path segment
        return ($RelPath -split "/") -contains $Pattern
    }
    $regex = "^" + [regex]::Escape($Pattern).Replace("\*", "[^/]*") + "(/.*)?$"
    return $RelPath -match $regex
}

Write-Host "Applying release-excludes.txt (excludes: $($excludes.Count), force-includes: $($forceIncludes.Count))..."
$allItems = Get-ChildItem -Path $stage -Recurse -Force | Sort-Object { $_.FullName.Length } -Descending
$removed = @()
foreach ($item in $allItems) {
    if (-not (Test-Path $item.FullName)) { continue }
    $rel = $item.FullName.Substring($stage.Length + 1) -replace "\\", "/"
    foreach ($pattern in $excludes) {
        if (Test-ExcludeMatch -RelPath $rel -Pattern $pattern) {
            Remove-Item -Recurse -Force $item.FullName -ErrorAction SilentlyContinue
            $removed += $rel
            break
        }
    }
}

Write-Host "Restoring force-included files..."
$restored = @()
foreach ($rel in $forceIncludes) {
    $srcFile = "$repoRoot\$rel"
    $dstFile = "$stage\$rel"
    if (-not (Test-Path $srcFile)) { continue }
    New-Item -ItemType Directory -Force -Path (Split-Path $dstFile) | Out-Null
    Copy-Item $srcFile $dstFile -Force
    $restored += $rel
}

# storage/*/.gitkeep isn't tracked by git as a real 0-byte marker in all cases -
# ensure the expected placeholder directories exist even if empty upstream.
foreach ($dir in @("storage/files", "storage/sessions")) {
    $gitkeep = "$stage\$dir\.gitkeep".Replace("/", "\")
    if (-not (Test-Path $gitkeep)) {
        New-Item -ItemType Directory -Force -Path (Split-Path $gitkeep) | Out-Null
        New-Item -ItemType File -Force -Path $gitkeep | Out-Null
    }
}

Set-Content -Path "$stage\includes\VERSION" -Value $Version -NoNewline

if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Compress-Archive -Path $stage -DestinationPath $zipPath -Force
$zipSize = (Get-Item $zipPath).Length

Write-Host ""
Write-Host "=== Package built: $zipPath ($([math]::Round($zipSize/1MB, 2)) MB) ==="
Write-Host ""

Write-Host "--- Force-included files restored ---"
$forceIncludes | ForEach-Object {
    $ok = if (Test-Path "$stage\$_") { "OK" } else { "MISSING" }
    Write-Host "  [$ok] $_"
}

Write-Host ""
Write-Host "--- Sanity checks ---"
$secretFiles = Get-ChildItem -Path $stage -Recurse -Force -Filter ".secret_*" -ErrorAction SilentlyContinue
if ($secretFiles) {
    Write-Host "  FAIL: secret files leaked into package:"
    $secretFiles | ForEach-Object { Write-Host "    $($_.FullName)" }
} else {
    Write-Host "  OK: no .secret_salt / .secret_key in package"
}

$devLeftovers = @("Dockerfile", "docker-compose.yml", "composer.json", "package.json", "phpcs.xml", "phpunit.xml", "phpstan.neon", "phpstan-baseline.neon", ".github", ".claude", "tests", "cypress", "creator") |
    Where-Object { Test-Path "$stage\$_" }
if ($devLeftovers) {
    Write-Host "  FAIL: dev-only files/dirs present that should have been excluded:"
    $devLeftovers | ForEach-Object { Write-Host "    $_" }
} else {
    Write-Host "  OK: no dev-only files/dirs found at top level"
}

$stampedVersion = Get-Content "$stage\includes\VERSION" -Raw
if ($stampedVersion.Trim() -ne $Version) {
    Write-Host "  FAIL: includes/VERSION stamped as '$($stampedVersion.Trim())', expected '$Version'"
} else {
    Write-Host "  OK: includes/VERSION stamped correctly ($Version)"
}

Write-Host ""
Write-Host "--- Top-level diff: repo vs. package ---"
$repoTop = Get-ChildItem -Path $repoRoot -Force | Select-Object -ExpandProperty Name | Sort-Object
$pkgTop = Get-ChildItem -Path $stage -Force | Select-Object -ExpandProperty Name | Sort-Object
Compare-Object $repoTop $pkgTop | ForEach-Object {
    $side = if ($_.SideIndicator -eq "<=") { "repo only (expected excluded)" } else { "package only (unexpected!)" }
    Write-Host "  $($_.InputObject)  -- $side"
}

Write-Host ""
Write-Host "Done. Staged tree: $stage"
Write-Host "Delete $stageRoot when you're done inspecting it."
