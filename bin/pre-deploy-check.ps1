# bin/pre-deploy-check.ps1
#
# PowerShell version of bin/pre-deploy-check.sh — runnable from a Windows
# dev machine before invoking deploy.
#
# Usage:  .\bin\pre-deploy-check.ps1
# Exit:   0 = OK, 1 = blocked.

$ErrorActionPreference = 'Stop'

# Resolve project root
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Resolve-Path (Join-Path $ScriptDir '..')
Set-Location $ProjectRoot

$pass = 0
$fail = 0
$warn = 0

function Ok($msg) { Write-Host "✓ $msg" -ForegroundColor Green; $script:pass++ }
function Bad($msg) { Write-Host "✗ $msg" -ForegroundColor Red;   $script:fail++ }
function Warn($msg) { Write-Host "! $msg" -ForegroundColor Yellow; $script:warn++ }
function Section($msg) { Write-Host "`n── $msg ──" -ForegroundColor Cyan }

# ─────────────────────────────────────────
Section "1. PHP syntax (all PHP files)"
# ─────────────────────────────────────────
if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Bad "php not found in PATH"; exit 1
}
$phpVer = php -r 'echo PHP_VERSION;'
Ok "PHP $phpVer"

$badFiles = @()
Get-ChildItem -Path 'src','templates','bin' -Recurse -Filter '*.php' -ErrorAction SilentlyContinue | ForEach-Object {
    $out = & php -l $_.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $badFiles += $_.FullName
        Bad "  $($_.FullName)"
    }
}
if ($badFiles.Count -eq 0) {
    Ok "All PHP files lint clean"
} else {
    Bad "$($badFiles.Count) PHP files failed lint"
}

# ─────────────────────────────────────────
Section "2. Templates exist"
# ─────────────────────────────────────────
$requiredTemplates = @(
    'templates\public\home.html.twig',
    'templates\public\login.html.twig',
    'templates\public\shell.html.twig',
    'templates\public\_public_nav.html.twig',
    'templates\sanctum\guardian.html.twig',
    'templates\shell.html.twig'
)
foreach ($t in $requiredTemplates) {
    if (Test-Path $t) { Ok $t } else { Bad "missing: $t" }
}

if (Test-Path 'templates\home.html.twig') {
    Bad 'Legacy templates\home.html.twig still exists (should be deleted)'
} else {
    Ok 'Legacy templates\home.html.twig removed'
}

# ─────────────────────────────────────────
Section "3. Guardian services present"
# ─────────────────────────────────────────
$requiredServices = @(
    'src\Service\Guardian\GuardianSignal.php',
    'src\Service\Guardian\GuardianSignalCollector.php',
    'src\Service\Guardian\DisciplineScoreCalculator.php',
    'src\Controller\Api\GuardianController.php',
    'src\Event\TradeSavedEvent.php',
    'src\EventSubscriber\GuardianSubscriber.php'
)
foreach ($s in $requiredServices) {
    if (Test-Path $s) { Ok $s } else { Bad "missing: $s" }
}

# ─────────────────────────────────────────
Section "4. Security: no committed secrets"
# ─────────────────────────────────────────
if (Test-Path '.env') { Ok '.env present (committed)' } else { Bad '.env missing' }

if (Test-Path '.git') {
    $tracked = & git ls-files --error-unmatch .env.local 2>&1
    if ($LASTEXITCODE -eq 0) {
        Bad '.env.local is tracked in git (SECURITY: rotate + remove)'
    } else {
        Ok '.env.local is gitignored'
    }
}

$envContent = Get-Content '.env' -ErrorAction SilentlyContinue -Raw
if ($envContent -and ($envContent -match '(?m)^JWT_PASSPHRASE=[a-f0-9]{20,}')) {
    Bad 'Real JWT_PASSPHRASE in .env (rotate + replace with placeholder)'
} else {
    Ok 'No real JWT passphrase committed in .env'
}

$envLocalContent = Get-Content '.env.local' -ErrorAction SilentlyContinue -Raw
if (($envContent -and ($envContent -match 'dev-secret-change-in-production!!')) -or `
    ($envLocalContent -and ($envLocalContent -match 'dev-secret-change-in-production!!'))) {
    Bad 'Mercure default secret still present'
} else {
    Ok 'Mercure default secret replaced'
}

if (Test-Path 'stitch_tnsvt_app_m_vil') {
    Warn 'stitch_tnsvt_app_m_vil/ exists locally — gitignored, but consider removing before commit'
}

# ─────────────────────────────────────────
Section "5. Git state"
# ─────────────────────────────────────────
if (Test-Path '.git') {
    Ok 'Git repository present'
    $branch = & git rev-parse --abbrev-ref HEAD 2>$null
    if ($LASTEXITCODE -eq 0) { Ok "Current branch: $branch" }
    $status = & git status --porcelain 2>$null
    if ($status) {
        $count = ($status | Measure-Object -Line).Lines
        Warn "$count uncommitted file(s) — commit before deploy"
    } else {
        Ok 'Working tree clean'
    }
} else {
    Warn 'Not a git repository'
}

# ─────────────────────────────────────────
Section "6. Composer sanity"
# ─────────────────────────────────────────
if (Test-Path 'composer.json') { Ok 'composer.json present' }
if (Test-Path 'composer.lock') { Ok 'composer.lock present' }
if (Test-Path 'vendor') { Ok 'vendor/ installed locally' } else { Warn 'vendor/ missing — run composer install' }

# ─────────────────────────────────────────
Section "Summary"
# ─────────────────────────────────────────
Write-Host "`n  PASS: $pass"
Write-Host "  WARN: $warn"
Write-Host "  FAIL: $fail`n"

if ($fail -gt 0) {
    Write-Host "⛔ Deploy blocked. Fix the failures above and re-run." -ForegroundColor Red
    exit 1
}
if ($warn -gt 0) {
    Write-Host "⚠ Warnings present. Review before deploying." -ForegroundColor Yellow
}
Write-Host "✓ Pre-deploy checks passed." -ForegroundColor Green
exit 0
