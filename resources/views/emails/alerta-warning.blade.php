<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #fff; padding: 20px; border: 1px solid #e5e7eb; border-top: none; }
        .mensaje { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; margin: 15px 0; }
        .contexto { background: #f9fafb; padding: 15px; border-radius: 4px; margin-top: 15px; }
        .contexto h3 { margin-top: 0; color: #6b7280; font-size: 14px; }
        .contexto-item { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #e5e7eb; }
        .footer { background: #f9fafb; padding: 15px; text-align: center; font-size: 12px; color: #6b7280; border-radius: 0 0 8px 8px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ ADVERTENCIA</h1>
        </div>
        <div class="content">
            <h2>{{ $titulo }}</h2>
            
            <div class="mensaje">
                {!! nl2br(e($mensaje)) !!}
            </div>

            @if(!empty($contexto))
            <div class="contexto">
                <h3>Detalles:</h3>
                @foreach($contexto as $key => $value)
                <div class="contexto-item">
                    <span><strong>{{ ucfirst(str_replace('_', ' ', $key)) }}:</strong></span>
                    <span>{{ is_array($value) ? json_encode($value) : $value }}</span>
                </div>
                @endforeach
            </div>
            @endif

            <p style="margin-top: 20px; color: #6b7280;">
                <strong>Hora del evento:</strong> {{ $timestamp }}
            </p>
        </div>
        <div class="footer">
            Sistema de Nurturing - Grupo Segal<br>
            Esta es una alerta automática del sistema.
        </div>
    </div>
</body>
</html>
