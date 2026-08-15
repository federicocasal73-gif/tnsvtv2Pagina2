<#
.SYNOPSIS
  PowerShell reimplementation of bin/pre-deploy-check.sh
  Equivalent read-only verification before deploying TNSVT.
.NOTES
  Run from project root:  powershell -File bin\pre-deploy-check.ps1
#>

$ErrorActionPreference = 'Continue'

$pass = 0
$fail = 0
$warn = 0

function ok   { param([string]$m) Write-Host "PASS: $m"; $script:pass++ }
function bad  { param([string]$m) Write-Host "FAIL: $m"; $script:fail++ }
function warn { param([string]$m) Write-Host "WARN: $m"; $script:warn++ }

#  1. PHP syntax 
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($phpCmd) {
    $ver = (php -r 'echo PHP_VERSION;') 2>$null
    ok "PHP $ver"
} else {
    bad "php not found in PATH"
}

$badFiles = 0
Get-ChildItem -Path src, templates, bin -Filter *.php -Recurse -File -ErrorAction SilentlyContinue |
    ForEach-Object {
        $out = (php -l $_.FullName) 2>&1
        if ($LASTEXITCODE -ne 0) { $badFiles++; Write-Host "  $($out)" }
    }
if ($badFiles -eq 0) { ok "All PHP files lint clean" } else { bad "$badFiles PHP files failed lint" }

#  2. Templates exist 
$required = @(
    "templates\public\home.html.twig",
    "templates\public\login.html.twig",
    "templates\public\shell.html.twig",
    "templates\public\_public_nav.html.twig",
    "templates\sanctum\guardian.html.twig",
    "templates\shell.html.twig"
)
foreach ($t in $required) {
    if (Test-Path $t) { ok $t } else { bad "missing: $t" }
}
if (Test-Path "templates\home.html.twig") {
    bad "Legacy templates/home.html.twig still exists"
} else {
    ok "Legacy templates/home.html.twig removed"
}

#  3. Guardian services present 
$services = @(
    "src\Service\Guardian\GuardianSignal.php",
    "src\Service\Guardian\GuardianSignalCollector.php",
    "src\Service\Guardian\DisciplineScoreCalculator.php",
    "src\Controller\Api\GuardianController.php",
    "src\Event\TradeSavedEvent.php",
    "src\EventSubscriber\GuardianSubscriber.php"
)
foreach ($s in $services) {
    if (Test-Path $s) { ok $s } else { bad "missing: $s" }
}

#  4. Security 
if (Test-Path ".env")      { ok ".env present (committed)" } else { bad ".env missing" }
$tracked = git ls-files --error-unmatch .env.local 2>$null
if ($LASTEXITCODE -eq 0)   { bad ".env.local is tracked in git (SECURITY: rotate + remove)" }
else                       { ok ".env.local is gitignored" }

$envContent   = (Get-Content .env -ErrorAction SilentlyContinue) -join "`n"
$localContent = (Get-Content .env.local -ErrorAction SilentlyContinue) -join "`n"

if ($envContent -match '(?m)^JWT_PASSPHRASE=[a-f0-9]{20,}') {
    bad "Real JWT_PASSPHRASE committed in .env (rotate + replace with placeholder)"
} else {
    ok "No real JWT passphrase committed in .env"
}

if (($envContent + $localContent) -match 'dev-secret-change-in-production!!') {
    bad "Mercure default secret still present"
} else {
    ok "Mercure default secret replaced"
}

if (Test-Path "stitch_tnsvt_app_m_vil") {
    warn "stitch_tnsvt_app_m_vil/ exists locally (gitignored) - consider removing before commit"
}
$vendorTracked = git ls-files vendor 2>$null
if ($vendorTracked)      { bad "vendor/ is tracked in git (should be gitignored)" }
else                     { ok "vendor/ is gitignored" }

#  5. Git state 
if (Test-Path ".git") {
    ok "Git repository present"
    $branch = (git rev-parse --abbrev-ref HEAD 2>$null).Trim()
    ok "Current branch: $branch"
    $uncommitted = (git status --porcelain 2>$null).Count
    if ($uncommitted -gt 0) { warn "$uncommitted uncommitted file(s) - commit before deploy" }
    else                    { ok "Working tree clean" }
} else {
    warn "Not a git repository"
}

#  6. Composer sanity 
if (Test-Path "composer.json") { ok "composer.json present" } else { bad "composer.json missing" }
if (Test-Path "composer.lock") { ok "composer.lock present" } else { bad "composer.lock missing" }
if (Test-Path "vendor")        { ok "vendor/ installed locally" }
else                           { warn "vendor/ missing  run composer install" }

#  Summary 
Write-Host ""
Write-Host "  PASS: $pass"
Write-Host "  WARN: $warn"
Write-Host "  FAIL: $fail"
Write-Host ""

if ($fail -gt 0) {
    Write-Host "[BLOCKED] Deploy blocked. Fix the failures above and re-run."
    exit 1
}
if ($warn -gt 0) {
    Write-Host "[WARN] Warnings present. Review before deploying."
}
Write-Host "[OK] Pre-deploy checks passed."
exit 0
