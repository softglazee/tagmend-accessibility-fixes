<#
.SYNOPSIS
    Builds and audits the release ZIP of the plugin folder.

.DESCRIPTION
    1. Reads <Slug>/.distignore and leaves out every matching path.
    2. Independently refuses (hard failure) any remaining path that matches the
       list after the .distignore block in docs/01-BLUEPRINT.md section 16.1:
       dotfiles and dot-folders at any depth, any .md file, *.dist, *.sh,
       archives, tests/, docs/, tools/, release/, node_modules/, vendor/, dev/,
       runs/, phpcs.xml*, phpunit.xml*, package*.json, composer.*, any .json,
       *.log, *.map.
    3. Checks the headers: Plugin Name equals the readme title byte for byte
       (both files read as UTF-8; the name contains an en dash), the slug derived
       from Plugin Name equals the folder, the main file name and Text Domain,
       Version equals Stable tag and SOFTGLAZE_A11Y_VERSION, License and License
       URI strings are identical, Requires at least / Requires PHP agree, Author
       is "SoftGlaze", Author URI is https://softglaze.com, Plugin URI is absent
       or https://softglaze.com/plugins/<slug>/, and the readme has no Donate link.
    4. Checks every text file: UTF-8, no BOM, LF line endings only.
    5. Checks .distignore against docs/01-BLUEPRINT.md section 16.1, when present.
    6. Writes dist/<Slug>.zip with System.IO.Compression (never
       Compress-Archive), forward-slash entry names under "<Slug>/", then reopens
       it and audits the entries again.

    -Strict adds source greps: an ABSPATH guard in every PHP file, and none of
    ob_start, <script, <style, admin_notices, error_log, var_dump, print_r, eval,
    base64_decode, heredoc/nowdoc, wp_remote_, curl_, file_get_contents,
    wp_add_inline_, set_time_limit, ini_set in PHP files.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools/build-zip.ps1 -Slug tagmend-accessibility-fixes
    powershell -ExecutionPolicy Bypass -File tools/build-zip.ps1 -Slug tagmend-accessibility-fixes -Strict
#>
[CmdletBinding()]
param(
    [string] $Slug = 'tagmend-accessibility-fixes',
    [switch] $Strict
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 2.0

$root      = Split-Path -Parent $PSScriptRoot
$pluginDir = Join-Path $root $Slug
$mainFile  = Join-Path $pluginDir ($Slug + '.php')
$readme    = Join-Path $pluginDir 'readme.txt'
$distFile  = Join-Path $pluginDir '.distignore'
$blueprint = Join-Path $root 'docs\01-BLUEPRINT.md'

$script:failures = New-Object System.Collections.Generic.List[string]
$utf8Strict = New-Object System.Text.UTF8Encoding($false, $true)

function Pass([string] $msg) { Write-Host ('PASS  ' + $msg) }
function Fail([string] $msg) { Write-Host ('FAIL  ' + $msg); $script:failures.Add($msg) }

function Read-Utf8([string] $path) {
    $bytes = [System.IO.File]::ReadAllBytes($path)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        Fail ('BOM found in ' + $path)
        $bytes = $bytes[3..($bytes.Length - 1)]
    }
    return $utf8Strict.GetString($bytes)
}

function Get-Hex([string] $text) {
    return (([System.Text.Encoding]::UTF8.GetBytes($text) | ForEach-Object { $_.ToString('x2') }) -join ' ')
}

function Get-HeaderField([string] $text, [string] $field) {
    $head = $text
    if ($head.Length -gt 8192) { $head = $head.Substring(0, 8192) }
    $pattern = '(?mi)^[ \t/*#@]*' + [regex]::Escape($field) + ':(.*)$'
    $m = [regex]::Match($head, $pattern)
    if (-not $m.Success) { return $null }
    $value = $m.Groups[1].Value -replace '\s*(?:\*/|\?>).*$', ''
    return $value.Trim()
}

function Get-ReadmeField([string] $headerBlock, [string] $field) {
    $m = [regex]::Match($headerBlock, '(?mi)^' + [regex]::Escape($field) + ':[ \t]*(.*?)[ \t]*$')
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value
}

function ConvertTo-Slug([string] $name) {
    $s = $name.ToLowerInvariant() -replace '[^a-z0-9]+', '-'
    return $s.Trim('-')
}

function Test-Refused([string] $rel) {
    $segments = $rel.Split('/')
    $base     = $segments[$segments.Length - 1]
    foreach ($seg in $segments) {
        if ($seg.StartsWith('.')) { return 'dotfile or dot-folder' }
    }
    $dirs = @()
    if ($segments.Length -gt 1) { $dirs = $segments[0..($segments.Length - 2)] }
    foreach ($d in $dirs) {
        if (@('tests', 'docs', 'tools', 'release', 'node_modules', 'vendor', 'dev', 'runs') -contains $d.ToLowerInvariant()) {
            return ('development folder ' + $d + '/')
        }
    }
    $rules = [ordered]@{
        '*.md'          = 'Markdown file'
        '*.dist'        = '*.dist file'
        '*.sh'          = 'shell script'
        '*.zip'         = 'archive'
        '*.gz'          = 'archive'
        '*.tgz'         = 'archive'
        '*.tar'         = 'archive'
        '*.7z'          = 'archive'
        '*.rar'         = 'archive'
        '*.bz2'         = 'archive'
        '*.phar'        = 'archive'
        'phpcs.xml*'    = 'PHPCS ruleset'
        'phpunit.xml*'  = 'PHPUnit configuration'
        'package*.json' = 'npm manifest'
        'composer.*'    = 'Composer file'
        '*.json'        = 'JSON file (the plugin ships none; axe or pa11y output?)'
        '*.log'         = 'log file'
        '*.map'         = 'source map'
        'Thumbs.db'     = 'OS metadata'
    }
    foreach ($key in $rules.Keys) {
        if ($base -like $key) { return $rules[$key] }
    }
    return $null
}

function Read-DistIgnore([string] $path) {
    $patterns = @()
    foreach ($line in [System.IO.File]::ReadAllLines($path)) {
        $p = $line.Trim()
        if ($p -eq '' -or $p.StartsWith('#')) { continue }
        $patterns += $p
    }
    return ,$patterns
}

function Test-DistIgnored([string] $rel, [string[]] $patterns) {
    $segments = $rel.Split('/')
    foreach ($p in $patterns) {
        $pat = $p.TrimEnd('/')
        if ($pat.StartsWith('/')) {
            $anchored = $pat.Substring(1)
            if ($rel -like $anchored -or $rel -like ($anchored + '/*')) { return $true }
        } elseif ($pat.Contains('/')) {
            if ($rel -like $pat -or $rel -like ($pat + '/*')) { return $true }
        } else {
            foreach ($seg in $segments) {
                if ($seg -like $pat) { return $true }
            }
        }
    }
    return $false
}

Write-Host ('Building ' + $Slug + ' from ' + $pluginDir)
Write-Host ''

foreach ($required in @($pluginDir, $mainFile, $readme, $distFile)) {
    if (-not (Test-Path -LiteralPath $required)) {
        Write-Host ('FAIL  missing ' + $required)
        exit 1
    }
}

# ---- 1. File list after .distignore ---------------------------------------
$patterns = Read-DistIgnore $distFile
$included = New-Object System.Collections.Generic.List[string]
$ignored  = 0
$allFiles = Get-ChildItem -LiteralPath $pluginDir -Recurse -Force -File | Sort-Object FullName
foreach ($f in $allFiles) {
    $rel = $f.FullName.Substring($pluginDir.Length + 1).Replace('\', '/')
    if (Test-DistIgnored $rel $patterns) { $ignored++; continue }
    $included.Add($rel)
}
Pass ('.distignore read: ' + $patterns.Count + ' patterns, ' + $ignored + ' file(s) left out, ' + $included.Count + ' file(s) to ship')

# ---- 2. Independent refusal list --------------------------------------------
$refusedCount = 0
foreach ($rel in $included) {
    $reason = Test-Refused $rel
    if ($null -ne $reason) { Fail ('refused: ' + $rel + ' (' + $reason + ')'); $refusedCount++ }
}
if ($refusedCount -eq 0) { Pass 'refusal list: no dotfile, Markdown, *.dist, *.sh, archive, dev folder, manifest or JSON in the file list' }

# ---- 3. Headers ----------------------------------------------------------------
$mainText   = Read-Utf8 $mainFile
$readmeText = Read-Utf8 $readme

$name        = Get-HeaderField $mainText 'Plugin Name'
$version     = Get-HeaderField $mainText 'Version'
$license     = Get-HeaderField $mainText 'License'
$licenseUri  = Get-HeaderField $mainText 'License URI'
$author      = Get-HeaderField $mainText 'Author'
$authorUri   = Get-HeaderField $mainText 'Author URI'
$pluginUri   = Get-HeaderField $mainText 'Plugin URI'
$textDomain  = Get-HeaderField $mainText 'Text Domain'
$reqWp       = Get-HeaderField $mainText 'Requires at least'
$reqPhp      = Get-HeaderField $mainText 'Requires PHP'

$readmeLines = $readmeText -split "`n"
$titleMatch  = [regex]::Match($readmeLines[0].TrimEnd("`r"), '^===\s*(.+?)\s*===$')
$headerEnd   = $readmeText.IndexOf("`n==")
$headerBlock = $readmeText
if ($headerEnd -gt 0) { $headerBlock = $readmeText.Substring(0, $headerEnd) }

if (-not $name) { Fail 'Plugin Name header missing' }
if (-not $titleMatch.Success) { Fail 'readme.txt first line is not "=== Name ==="' }
if ($name -and $titleMatch.Success) {
    $title = $titleMatch.Groups[1].Value
    $a = [System.Text.Encoding]::UTF8.GetBytes($name)
    $b = [System.Text.Encoding]::UTF8.GetBytes($title)
    if ([System.Linq.Enumerable]::SequenceEqual([byte[]]$a, [byte[]]$b)) {
        Pass ('Plugin Name = readme title, byte for byte (' + (Get-Hex $name) + ')')
    } else {
        Fail ('Plugin Name (' + (Get-Hex $name) + ') differs from readme title (' + (Get-Hex $title) + ')')
    }
    if ($name.Contains([string][char]0x2014) -or $name.Contains(' - ')) {
        Fail 'Plugin Name contains an em dash or a hyphen used as a dash; the house style is the en dash U+2013'
    }
}

if ($name) {
    $derived = ConvertTo-Slug $name
    $folder  = Split-Path -Leaf $pluginDir
    $mainBase = [System.IO.Path]::GetFileNameWithoutExtension($mainFile)
    if ($derived -eq $folder -and $derived -eq $mainBase -and $derived -eq $Slug) {
        Pass ('slug from Plugin Name = folder = main file = ' + $derived)
    } else {
        Fail ('slug from Plugin Name "' + $derived + '", folder "' + $folder + '", main file "' + $mainBase + '", -Slug "' + $Slug + '" must all match')
    }
}
if ($textDomain -eq $Slug) { Pass ('Text Domain = ' + $textDomain) } else { Fail ('Text Domain "' + $textDomain + '" differs from slug "' + $Slug + '"') }

$stable = Get-ReadmeField $headerBlock 'Stable tag'
if ($version -and $version -eq $stable) { Pass ('Version = Stable tag = ' + $version) } else { Fail ('Version "' + $version + '" differs from Stable tag "' + $stable + '"') }

$constMatch = [regex]::Match($mainText, "define\(\s*'SOFTGLAZE_A11Y_VERSION'\s*,\s*'([^']+)'\s*\)")
if ($constMatch.Success) {
    if ($constMatch.Groups[1].Value -eq $version) { Pass ('SOFTGLAZE_A11Y_VERSION = ' + $version) } else { Fail ('SOFTGLAZE_A11Y_VERSION "' + $constMatch.Groups[1].Value + '" differs from Version "' + $version + '"') }
} else {
    Fail 'SOFTGLAZE_A11Y_VERSION constant not found in the main file'
}

$rLicense    = Get-ReadmeField $headerBlock 'License'
$rLicenseUri = Get-ReadmeField $headerBlock 'License URI'
if ($license -and $license -ceq $rLicense) { Pass ('License identical: ' + $license) } else { Fail ('License "' + $license + '" vs readme "' + $rLicense + '"') }
if ($licenseUri -and $licenseUri -ceq $rLicenseUri) { Pass ('License URI identical: ' + $licenseUri) } else { Fail ('License URI "' + $licenseUri + '" vs readme "' + $rLicenseUri + '"') }

$rReqWp  = Get-ReadmeField $headerBlock 'Requires at least'
$rReqPhp = Get-ReadmeField $headerBlock 'Requires PHP'
if ($reqWp -and $reqWp -eq $rReqWp) { Pass ('Requires at least identical: ' + $reqWp) } else { Fail ('Requires at least "' + $reqWp + '" vs readme "' + $rReqWp + '"') }
if ($reqPhp -and $reqPhp -eq $rReqPhp) { Pass ('Requires PHP identical: ' + $reqPhp) } else { Fail ('Requires PHP "' + $reqPhp + '" vs readme "' + $rReqPhp + '"') }
if (Get-ReadmeField $headerBlock 'Tested up to') { Pass ('Tested up to: ' + (Get-ReadmeField $headerBlock 'Tested up to')) } else { Fail 'readme has no Tested up to' }

if ($author -ceq 'SoftGlaze') { Pass 'Author = SoftGlaze' } else { Fail ('Author "' + $author + '" is not "SoftGlaze"') }
if ($authorUri -ceq 'https://softglaze.com') { Pass 'Author URI = https://softglaze.com' } else { Fail ('Author URI "' + $authorUri + '" is not https://softglaze.com') }

$expectedPluginUri = 'https://softglaze.com/plugins/' + $Slug + '/'
if ($null -eq $pluginUri) {
    Pass 'Plugin URI absent'
} elseif ($pluginUri -ceq $expectedPluginUri) {
    Pass ('Plugin URI = ' + $pluginUri)
} else {
    Fail ('Plugin URI "' + $pluginUri + '" is neither absent nor ' + $expectedPluginUri)
}

if ($readmeText -match '(?mi)^Donate link:') { Fail 'readme.txt has a Donate link' } else { Pass 'no Donate link' }

# ---- 4. Text files: UTF-8, no BOM, LF only --------------------------------------
$textExt = @('.php', '.txt', '.css', '.js', '.pot', '.po', '.svg', '.html', '.xml')
$textBad = 0
foreach ($rel in $included) {
    $ext = [System.IO.Path]::GetExtension($rel).ToLowerInvariant()
    if ($textExt -notcontains $ext) { continue }
    $bytes = [System.IO.File]::ReadAllBytes((Join-Path $pluginDir $rel))
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { Fail ('BOM: ' + $rel); $textBad++ }
    if ([Array]::IndexOf($bytes, [byte]13) -ge 0) { Fail ('CR line ending: ' + $rel); $textBad++ }
    try { [void]$utf8Strict.GetString($bytes) } catch { Fail ('not valid UTF-8: ' + $rel); $textBad++ }
}
if ($textBad -eq 0) { Pass 'text files: UTF-8, no BOM, LF only' }

# ---- 5. .distignore matches the blueprint ---------------------------------------
if (Test-Path -LiteralPath $blueprint) {
    $bp = Read-Utf8 $blueprint
    $m  = [regex]::Match($bp, '(?s)### 16\.1[^\n]*\n.*?```\r?\n(.*?)```')
    if ($m.Success) {
        $want = ($m.Groups[1].Value -replace "`r", '').TrimEnd("`n")
        $have = ((Read-Utf8 $distFile) -replace "`r", '').TrimEnd("`n")
        if ($want -ceq $have) { Pass '.distignore matches docs/01-BLUEPRINT.md section 16.1' } else { Fail '.distignore differs from docs/01-BLUEPRINT.md section 16.1' }
    } else {
        Fail 'could not find the .distignore block in docs/01-BLUEPRINT.md section 16.1'
    }
} else {
    Write-Host 'NOTE  docs/01-BLUEPRINT.md not found; .distignore comparison skipped'
}

# ---- Strict: source greps ----------------------------------------------------------
if ($Strict) {
    $banned = [ordered]@{
        'ob_start\s*\('            = 'ob_start'
        '<script'                  = '<script'
        '<style'                   = '<style'
        'admin_notices'            = 'admin_notices'
        '\berror_log\s*\('         = 'error_log'
        '\bvar_dump\s*\('          = 'var_dump'
        '\bprint_r\s*\('           = 'print_r'
        '\beval\s*\('              = 'eval'
        'base64_decode'            = 'base64_decode'
        '<<<'                      = 'heredoc/nowdoc'
        'wp_remote_'               = 'wp_remote_*'
        '\bcurl_'                  = 'curl_*'
        'file_get_contents\s*\('   = 'file_get_contents'
        'wp_add_inline_'           = 'wp_add_inline_*'
        'set_time_limit'           = 'set_time_limit'
        '\bini_set\s*\('           = 'ini_set'
    }
    # Lead decision (DECISIONS-APPLIED.md): WP-CLI "analyse --file" reads one local file the
    # operator names (URLs and stream wrappers refused). That single call is allowed there only.
    $allowedOnce = @{
        'file_get_contents' = 'includes/cli/class-command.php'
    }
    $strictBad = 0
    foreach ($rel in $included) {
        if (-not $rel.EndsWith('.php')) { continue }
        $relSlash = $rel -replace '\\', '/'
        $src = Read-Utf8 (Join-Path $pluginDir $rel)
        if ($src -notmatch "defined\(\s*'ABSPATH'\s*\)") { Fail ('no ABSPATH guard: ' + $rel); $strictBad++ }
        foreach ($key in $banned.Keys) {
            $label = $banned[$key]
            if ($allowedOnce.ContainsKey($label) -and $allowedOnce[$label] -eq $relSlash) {
                $hits = [regex]::Matches($src, $key).Count
                if ($hits -gt 1) { Fail ('banned construct ' + $label + ' used ' + $hits + ' times in ' + $rel + ' (one allowed)'); $strictBad++ }
                elseif ($hits -eq 1) { Write-Host ('NOTE  allowed: one ' + $label + ' call in ' + $relSlash + ' (local file read in WP-CLI)') }
                continue
            }
            if ($src -match $key) { Fail ('banned construct ' + $label + ' in ' + $rel); $strictBad++ }
        }
    }
    if ($strictBad -eq 0) { Pass 'strict: ABSPATH guard in every PHP file, no banned construct' }
}

if ($script:failures.Count -gt 0) {
    Write-Host ''
    Write-Host ('BUILD REFUSED: ' + $script:failures.Count + ' problem(s). No ZIP written.')
    if ($version -and ($version -match '^[0-9A-Za-z.\-]+$')) {
        $stale = Join-Path (Join-Path $root 'dist') ($Slug + '.zip')
        if (Test-Path -LiteralPath $stale) {
            Remove-Item -LiteralPath $stale -Force
            Write-Host ('Removed the earlier ZIP at ' + $stale + ' so it cannot be mistaken for this build.')
        }
    }
    exit 1
}

# ---- 6. Write the ZIP ---------------------------------------------------------------
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$outDir  = Join-Path $root 'dist'
$zipPath = Join-Path $outDir ($Slug + '.zip')
if (-not (Test-Path -LiteralPath $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }
if (Test-Path -LiteralPath $zipPath) { Remove-Item -LiteralPath $zipPath -Force }

$dirs = New-Object System.Collections.Generic.SortedSet[string]
[void]$dirs.Add($Slug + '/')
foreach ($rel in $included) {
    $parts = $rel.Split('/')
    for ($i = 1; $i -lt $parts.Length; $i++) {
        [void]$dirs.Add($Slug + '/' + (($parts[0..($i - 1)]) -join '/') + '/')
    }
}

$stream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::CreateNew)
$zip    = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($d in $dirs) { [void]$zip.CreateEntry($d) }
    foreach ($rel in $included) {
        $src   = Join-Path $pluginDir $rel
        $entry = $zip.CreateEntry($Slug + '/' + $rel, [System.IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = (Get-Item -LiteralPath $src).LastWriteTime
        $bytes = [System.IO.File]::ReadAllBytes($src)
        $es    = $entry.Open()
        try { $es.Write($bytes, 0, $bytes.Length) } finally { $es.Dispose() }
    }
} finally {
    $zip.Dispose()
    $stream.Dispose()
}

# ---- 7. Audit the written ZIP ------------------------------------------------------
$audit = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
$auditBad = 0
$fileEntries = 0
try {
    foreach ($e in $audit.Entries) {
        $n = $e.FullName
        if ($n.Contains('\')) { Fail ('backslash in entry: ' + $n); $auditBad++ }
        if (-not $n.StartsWith($Slug + '/')) { Fail ('entry outside ' + $Slug + '/: ' + $n); $auditBad++ }
        if ($n.EndsWith('/')) { continue }
        $fileEntries++
        $reason = Test-Refused ($n.Substring($Slug.Length + 1))
        if ($null -ne $reason) { Fail ('refused entry in ZIP: ' + $n + ' (' + $reason + ')'); $auditBad++ }
    }
    if ($fileEntries -ne $included.Count) { Fail ('ZIP has ' + $fileEntries + ' files, expected ' + $included.Count); $auditBad++ }
    Write-Host ''
    Write-Host 'ZIP entries:'
    $audit.Entries | ForEach-Object { Write-Host ('  ' + $_.FullName + '  (' + $_.Length + ' bytes)') }
} finally {
    $audit.Dispose()
}

$size = (Get-Item -LiteralPath $zipPath).Length
if ($size -ge 10MB) { Fail ('ZIP is ' + $size + ' bytes; the limit is 10 MB'); $auditBad++ }
if ($auditBad -eq 0) { Pass ('ZIP audit: ' + $fileEntries + ' files, forward-slash entries under ' + $Slug + '/, nothing refused, ' + $size + ' bytes') }

if ($script:failures.Count -gt 0) {
    Remove-Item -LiteralPath $zipPath -Force
    Write-Host ''
    Write-Host ('BUILD FAILED AUDIT: ' + $script:failures.Count + ' problem(s). ZIP removed.')
    exit 1
}

$hash = (Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant()
Write-Host ''
Write-Host ('Wrote ' + $zipPath)
Write-Host ('SHA256 ' + $hash)
exit 0
