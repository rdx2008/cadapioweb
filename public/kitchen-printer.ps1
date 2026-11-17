param(
    [Parameter(Mandatory = $true)][uri]$Site,
    [Parameter(Mandatory = $true)][string]$DefaultPrinter,
    [string]$StationMap
)

$ErrorActionPreference = 'Stop'
if ($Site.Scheme -ne 'https' -or $Site.UserInfo -or $Site.AbsolutePath -ne '/' -or $Site.Query -or $Site.Fragment) {
    throw 'Informe o endereco HTTPS do painel, sem caminho ou parametros.'
}
$api = $Site.GetLeftPart([System.UriPartial]::Authority) + '/api/v1/kitchen-printer'
$mapping = [System.Collections.Generic.Dictionary[string, string]]::new([StringComparer]::Ordinal)
if ($StationMap) {
    $settings = Get-Content -LiteralPath $StationMap -Raw -Encoding UTF8 | ConvertFrom-Json
    foreach ($property in $settings.PSObject.Properties) {
        $mapping.Add($property.Name, [string]$property.Value)
    }
}

$printers = @(Get-Printer | Select-Object -ExpandProperty Name)
foreach ($printer in (@($DefaultPrinter) + @($mapping.Values))) {
    if ($printer -notin $printers) {
        throw "Impressora nao encontrada no Windows: $printer"
    }
}

$secret = Read-Host 'Chave do agente exibida no painel' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
try {
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
}
if ($token -cnotmatch '^[a-zA-Z0-9]{64}$') {
    throw 'Chave invalida.'
}
$headers = @{ Authorization = "Bearer $token"; Accept = 'application/json' }
Remove-Variable token
$secret.Dispose()

Write-Host 'Agente iniciado. Pressione Ctrl+C para sair.'
while ($true) {
    try {
        $response = Invoke-RestMethod -Uri "$api/claim" -Method Post -Headers $headers -TimeoutSec 15 -MaximumRedirection 0
        $job = $response.job
        if ($job) {
            if ($job.id -notmatch '^[a-fA-F0-9-]{36}$' -or $job.ticket -isnot [string]) {
                throw 'Resposta invalida do servidor.'
            }
            $printer = $DefaultPrinter
            if ($null -ne $job.station -and $mapping.ContainsKey([string]$job.station)) {
                $printer = $mapping[[string]$job.station]
            }
            $status = 'submitted'
            try {
                $job.ticket | Out-Printer -Name $printer -ErrorAction Stop
                Write-Host "Via $($job.id) enviada ao spool de $printer."
            } catch {
                $status = 'failed'
                Write-Warning "Falha ao enviar a via $($job.id). Confira a impressora e a fila."
            }

            for ($attempt = 1; $attempt -le 3; $attempt++) {
                try {
                    Invoke-RestMethod -Uri "$api/$($job.id)/report" -Method Post -Headers $headers -ContentType 'application/json' -Body (@{ status = $status } | ConvertTo-Json -Compress) -TimeoutSec 15 -MaximumRedirection 0 | Out-Null
                    break
                } catch {
                    if ($attempt -eq 3) {
                        Write-Warning "Resultado da via $($job.id) nao confirmado no painel."
                    } else {
                        Start-Sleep -Seconds 3
                    }
                }
            }
        }
    } catch {
        Write-Warning 'Falha ao consultar a fila. Confira o endereco, a chave e a conexao.'
        Start-Sleep -Seconds 15
    }
    Start-Sleep -Seconds 5
}
