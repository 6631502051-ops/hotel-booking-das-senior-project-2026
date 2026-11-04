$ErrorActionPreference = 'Stop'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'ไม่พบ Docker กรุณาติดตั้งและเปิด Docker Desktop ก่อน'
}

$envFile = Join-Path $PSScriptRoot '.env'
$exampleFile = Join-Path $PSScriptRoot '.env.example'

function New-Secret {
    param([int]$Length = 32)
    $secret = ''
    while ($secret.Length -lt $Length) {
        $secret += [Guid]::NewGuid().ToString('N')
    }
    return $secret.Substring(0, $Length)
}

if (-not (Test-Path $envFile)) {
    $envText = Get-Content $exampleFile -Raw
    $envText = $envText.Replace('replace-with-a-long-random-root-password', (New-Secret))
    $envText = $envText.Replace('replace-with-a-long-random-app-password', (New-Secret))
    $envText = $envText.Replace('replace-with-a-long-random-webhook-secret', (New-Secret))
    $envText = $envText.Replace('replace-with-a-long-random-device-key', (New-Secret))
    $envText = $envText.Replace('replace-with-a-random-keypad-password', (New-Secret))
    $envText = $envText.Replace('replace-with-a-random-door-password', (New-Secret))
    Set-Content -Path $envFile -Value $envText -Encoding utf8
    Write-Host 'สร้าง .env พร้อมรหัสผ่านสุ่มสำหรับการใช้งานในเครื่องแล้ว'
} else {
    Write-Host 'พบ .env เดิม จึงไม่เขียนทับค่าความลับ'
}

docker compose up -d --build
docker compose ps
Write-Host ''
Write-Host 'เปิดระบบได้ที่ http://localhost:8080'
Write-Host 'หยุดระบบโดยใช้: docker compose down'
