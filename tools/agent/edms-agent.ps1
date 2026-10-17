<#
.SYNOPSIS
  EDMS scan agent: scan on this PC (or watch a folder) and send the files to the document system.

.DESCRIPTION
  Install on any office PC that has a scanner. Files arrive in the token owner's inbox
  ("ไฟล์จากเครื่องสแกนอื่น" on the Scan page), ready to be attached to documents.

  Create a token in the web app: เมนู "ความปลอดภัยบัญชี" > "Token เครื่องสแกน".

.EXAMPLE
  # Check the connection
  .\edms-agent.ps1 -Server http://192.168.1.10/Website-SDU-v2 -Token edms_xxx -Mode ping

.EXAMPLE
  # Scan the paper in the feeder (both sides, 300 DPI, black & white) and send it for document DOC000123
  .\edms-agent.ps1 -Server http://192.168.1.10/Website-SDU-v2 -Token edms_xxx -Mode scan -Source duplex -Color bw -Target DOC000123

.EXAMPLE
  # Send every PDF/JPG/PNG/TIFF saved into C:\Scans (e.g. by the scanner's own button), checking every 5 seconds
  .\edms-agent.ps1 -Server http://192.168.1.10/Website-SDU-v2 -Token edms_xxx -Mode watch -WatchFolder C:\Scans
#>
param(
    [Parameter(Mandatory = $true)][string]$Server,
    [Parameter(Mandatory = $true)][string]$Token,
    [ValidateSet('ping', 'scan', 'watch', 'send')][string]$Mode = 'ping',
    [string]$File,
    [string]$Target,
    [string]$WatchFolder = 'C:\Scans',
    [string]$Naps2Path = 'C:\Program Files\NAPS2\NAPS2.Console.exe',
    [string]$Device = '',
    [ValidateSet('twain', 'wia', 'escl')][string]$Driver = 'twain',
    [ValidateSet('feeder', 'duplex', 'glass')][string]$Source = 'feeder',
    [ValidateSet('color', 'gray', 'bw')][string]$Color = 'bw',
    [ValidateSet(150, 200, 300, 400, 600)][int]$Dpi = 300,
    [int]$IntervalSeconds = 5
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
$Server = $Server.TrimEnd('/')
$endpoint = "$Server/api/agent.php"

function New-Client {
    $c = New-Object System.Net.Http.HttpClient
    $c.Timeout = [TimeSpan]::FromMinutes(10)
    $c.DefaultRequestHeaders.Authorization = New-Object System.Net.Http.Headers.AuthenticationHeaderValue('Bearer', $Token)
    return $c
}

function Send-EdmsFile([string]$path, [string]$target) {
    $client = New-Client
    try {
        $form = New-Object System.Net.Http.MultipartFormDataContent
        $stream = [System.IO.File]::OpenRead($path)
        $content = New-Object System.Net.Http.StreamContent($stream)
        $form.Add($content, 'file', [System.IO.Path]::GetFileName($path))
        $form.Add((New-Object System.Net.Http.StringContent($env:COMPUTERNAME)), 'source')
        if ($target) { $form.Add((New-Object System.Net.Http.StringContent($target)), 'target') }
        $resp = $client.PostAsync($endpoint, $form).Result
        $body = $resp.Content.ReadAsStringAsync().Result
        $stream.Dispose()
        $json = $null
        try { $json = $body | ConvertFrom-Json } catch {}
        if (-not $resp.IsSuccessStatusCode -or -not $json -or -not $json.success) {
            $msg = if ($json -and $json.message) { $json.message } else { "HTTP $([int]$resp.StatusCode)" }
            throw "Upload failed: $msg"
        }
        return $json.inboxId
    } finally {
        $client.Dispose()
    }
}

switch ($Mode) {
    'ping' {
        $client = New-Client
        $body = $client.GetStringAsync("$endpoint`?ping=1").Result | ConvertFrom-Json
        Write-Host "Connected as $($body.user) (token: $($body.token)), max file size $([math]::Round($body.maxBytes / 1MB)) MB" -ForegroundColor Green
    }

    'send' {
        if (-not (Test-Path $File)) { throw "File not found: $File" }
        $id = Send-EdmsFile $File $Target
        Write-Host "Sent $File (inbox #$id)" -ForegroundColor Green
    }

    'scan' {
        if (-not (Test-Path $Naps2Path)) { throw "NAPS2 Console not found at $Naps2Path - install NAPS2 from https://www.naps2.com" }
        $out = Join-Path $env:TEMP ("edms_scan_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + ".pdf")
        $args = @('-o', $out, '--driver', $Driver, '--source', $Source, '--dpi', $Dpi, '--bitdepth', $Color, '--deskew', '--excludeblank', '--force')
        if ($Device) { $args += @('--device', $Device) }
        Write-Host "Scanning..." -ForegroundColor Cyan
        & $Naps2Path @args
        if (-not (Test-Path $out)) { throw "Scan produced no file (paper in the feeder? scanner on?)" }
        $id = Send-EdmsFile $out $Target
        Remove-Item $out -Force
        Write-Host "Scanned and sent (inbox #$id)" -ForegroundColor Green
    }

    'watch' {
        foreach ($sub in @('', 'sent', 'failed')) { New-Item -ItemType Directory -Force -Path (Join-Path $WatchFolder $sub) | Out-Null }
        Write-Host "Watching $WatchFolder - press Ctrl+C to stop" -ForegroundColor Cyan
        while ($true) {
            Get-ChildItem -Path $WatchFolder -File | Where-Object { $_.Extension -match '^\.(pdf|jpe?g|png|tiff?)$' } | ForEach-Object {
                # Skip files still being written by the scanner software
                if ($_.LastWriteTime -gt (Get-Date).AddSeconds(-3)) { return }
                try {
                    $id = Send-EdmsFile $_.FullName $Target
                    Move-Item $_.FullName (Join-Path $WatchFolder "sent\$($_.Name)") -Force
                    Write-Host "$(Get-Date -Format 'HH:mm:ss') sent $($_.Name) (inbox #$id)" -ForegroundColor Green
                } catch {
                    Move-Item $_.FullName (Join-Path $WatchFolder "failed\$($_.Name)") -Force
                    Write-Host "$(Get-Date -Format 'HH:mm:ss') FAILED $($_.Name): $($_.Exception.Message)" -ForegroundColor Red
                }
            }
            Start-Sleep -Seconds $IntervalSeconds
        }
    }
}
