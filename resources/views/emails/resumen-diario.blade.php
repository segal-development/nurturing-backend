<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #0284c7; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #fff; padding: 20px; border: 1px solid #e5e7eb; border-top: none; }
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 20px 0; }
        .stat-card { background: #f9fafb; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-value { font-size: 28px; font-weight: bold; color: #0284c7; }
        .stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; }
        .stat-success { color: #16a34a; }
        .stat-error { color: #dc2626; }
        .mensaje { background: #f0f9ff; border-left: 4px solid #0284c7; padding: 15px; margin: 15px 0; white-space: pre-line; }
        .footer { background: #f9fafb; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Resumen Diario</h1>
        </div>
        <div class="content">
            <h2>{{ $titulo }}</h2>

            @if(isset($contexto['total_envios']))
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">{{ number_format($contexto['total_envios']) }}</div>
                    <div class="stat-label">Total Envíos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-success">{{ $contexto['tasa_exito'] }}%</div>
                    <div class="stat-label">Tasa de Éxito</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-success">{{ number_format($contexto['exitosos']) }}</div>
                    <div class="stat-label">Exitosos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value stat-error">{{ number_format($contexto['fallidos']) }}</div>
                    <div class="stat-label">Fallidos</div>
                </div>
            </div>

            @if(isset($contexto['por_canal']))
            <h3>Por Canal</h3>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">{{ number_format($contexto['por_canal']['email'] ?? 0) }}</div>
                    <div class="stat-label">📧 Emails</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">{{ number_format($contexto['por_canal']['sms'] ?? 0) }}</div>
                    <div class="stat-label">📱 SMS</div>
                </div>
            </div>
            @endif
            @else
            <div class="mensaje">
                {!! nl2br(e($mensaje)) !!}
            </div>
            @endif

            <p style="margin-top: 20px; color: #6b7280;">
                <strong>Generado:</strong> {{ $timestamp }}
            </p>
        </div>
        <div class="footer">
            Sistema de Nurturing - Grupo Segal<br>
            Este es un resumen automático del sistema.
        </div>
    </div>
</body>
</html>
