<#
Runs the release-readiness checks BEFORE you create/push a release tag —
the same checks release-zip.yml's verify-checks job runs AFTER, so a
missing/red check is caught while there's still nothing to undo (no tag
pushed, no draft release created).

Usage: pwsh scripts/pre-tag-check.ps1 [-Ref main]
-Ref defaults to the current branch's HEAD; pass a branch/SHA to check a
different commit. Requires $env:GITHUB_TOKEN (or GH_TOKEN) with `repo` scope
to query check-runs on GitHub; without it, that one check is skipped.
#>
param(
    [string]$Ref = "HEAD"
)

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path "$PSScriptRoot\..").Path
Set-Location $repoRoot

$fail = $false
function Report-Fail([string]$msg) {
    Write-Host "  FAIL: $msg" -ForegroundColor Red
    $script:fail = $true
}
function Report-Ok([string]$msg) {
    Write-Host "  OK: $msg" -ForegroundColor Green
}
function Report-Warn([string]$msg) {
    Write-Host "  WARN: $msg" -ForegroundColor Yellow
}

Write-Host "--- Working tree ---"
$gitStatus = git status --porcelain
if ($gitStatus) {
    Report-Fail "working tree is not clean:`n$gitStatus"
} else {
    Report-Ok "working tree is clean"
}

$branch = git rev-parse --abbrev-ref HEAD
if ($branch -ne "main") {
    Report-Warn "current branch is '$branch', not 'main' (release tags are cut from main)"
} else {
    Report-Ok "on main"
}

Write-Host ""
Write-Host "--- Version files in sync (mirrors source-integrity.yml) ---"
$verPhpContent = Get-Content "includes\version.php" -Raw
if ($verPhpContent -notmatch "OPENSPARROW_VERSION',\s*'([\d.]+)'") {
    Report-Fail "could not read OPENSPARROW_VERSION from includes\version.php"
    $phpVer = $null
} else {
    $phpVer = $Matches[1]
}
$txtVer = (Get-Content "includes\VERSION" -Raw).Trim()

if ($phpVer -and $txtVer -eq $phpVer) {
    Report-Ok "includes/version.php and includes/VERSION agree ($phpVer)"
} elseif ($phpVer) {
    Report-Fail "includes/version.php ($phpVer) and includes/VERSION ($txtVer) disagree"
}

if ($phpVer) {
    $migrations = Get-Content "config\migrations.json" -Raw | ConvertFrom-Json
    $entry = $migrations.$phpVer
    if (-not $entry) {
        Report-Fail "config/migrations.json has no entry for version $phpVer"
    } else {
        $requiredKeys = @("removed_files", "deprecated_files", "removed_config_keys", "notes")
        $missingKeys = $requiredKeys | Where-Object { -not ($entry.PSObject.Properties.Name -contains $_) }
        if ($missingKeys) {
            Report-Fail "config/migrations.json entry for $phpVer is missing: $($missingKeys -join ', ')"
        } else {
            Report-Ok "config/migrations.json has a complete entry for $phpVer"
        }
    }
}

Write-Host ""
Write-Host "--- Licence files unchanged (mirrors source-integrity.yml) ---"
function Test-LicenceHash([string]$file, [string]$expectedSha) {
    if (-not (Test-Path $file)) {
        Report-Fail "$file is missing"
        return
    }
    $bytes = [System.IO.File]::ReadAllBytes((Resolve-Path $file))
    $text = [System.Text.Encoding]::UTF8.GetString($bytes) -replace "`r", ""
    $sha = [System.BitConverter]::ToString(
        [System.Security.Cryptography.SHA256]::Create().ComputeHash([System.Text.Encoding]::UTF8.GetBytes($text))
    ).Replace("-", "").ToLower()
    if ($sha -ne $expectedSha) {
        Report-Fail "$file sha256 mismatch (got $sha, expected $expectedSha) - licence text must stay verbatim"
    } else {
        Report-Ok "$file matches pinned licence text"
    }
}
Test-LicenceHash "COPYING" "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986"
Test-LicenceHash "COPYING.LESSER" "e3a994d82e644b03a792a930f574002658412f62407f5fee083f2555c5f23118"

Write-Host ""
Write-Host "--- Required GitHub check-runs on $Ref (mirrors release-zip.yml verify-checks) ---"
$token = $env:GITHUB_TOKEN
if (-not $token) { $token = $env:GH_TOKEN }

$requiredCheckLines = @(
    "phpunit", "phpcs", "UTF-8 encoding check", "Version files in sync",
    "Licence files unchanged", "Docker Compose path",
    "Standalone image (Render / Railway path)", "Release ZIP file set (FTP path)"
)

if (-not $token) {
    Report-Warn "no GITHUB_TOKEN/GH_TOKEN in environment - skipping check-run lookup. Set one to verify CI is green before tagging."
} else {
    $remoteUrl = git remote get-url origin
    if ($remoteUrl -notmatch "github\.com[:/]([^/]+)/([^/.]+)") {
        Report-Fail "could not parse owner/repo from origin remote: $remoteUrl"
    } else {
        $owner = $Matches[1]
        $repo = $Matches[2]
        $sha = git rev-parse $Ref
        $headers = @{ Authorization = "Bearer $token"; Accept = "application/vnd.github+json" }
        try {
            $checkRuns = @()
            $page = 1
            do {
                $resp = Invoke-RestMethod -Headers $headers `
                    -Uri "https://api.github.com/repos/$owner/$repo/commits/$sha/check-runs?per_page=100&page=$page"
                $checkRuns += $resp.check_runs
                $page++
            } while ($resp.check_runs.Count -eq 100)

            $green = $checkRuns | Where-Object { $_.conclusion -eq "success" } | Select-Object -ExpandProperty name -Unique
            $missing = $requiredCheckLines | Where-Object { $_ -notin $green }
            if ($missing) {
                Report-Fail "commit $sha ($Ref) is missing green checks: $($missing -join ', ')"
            } else {
                Report-Ok "all required checks are green on commit $sha ($Ref)"
            }
        } catch {
            Report-Fail "GitHub API lookup failed: $($_.Exception.Message)"
        }
    }
}

Write-Host ""
if ($fail) {
    Write-Host "=== Not ready to tag. Fix the FAILs above first. ===" -ForegroundColor Red
    exit 1
} else {
    Write-Host "=== Ready to tag $phpVer. ===" -ForegroundColor Green
    Write-Host "  git tag $phpVer $(git rev-parse --short $Ref)"
    Write-Host "  git push origin $phpVer"
    exit 0
}
