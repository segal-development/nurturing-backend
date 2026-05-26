<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datos a corregir en SYSGAL</title>
</head>
<body style="margin:0; padding:24px; background:#f9fafb; font-family: Arial, Helvetica, sans-serif; color:#1f2937; line-height:1.5;">
    <div style="max-width:640px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; border-radius:8px; padding:24px;">
        <p style="margin-top:0;">Hola,</p>

        <p>
            Los siguientes <strong>{{ $cantidad }}</strong> cliente(s) entraron al flujo de nurturing
            pero tienen el dato de contacto mal y <strong>no se les puede enviar</strong>. Hay que
            corregirlo en <strong>SYSGAL</strong>:
        </p>

        <table cellpadding="8" cellspacing="0" style="border-collapse:collapse; width:100%; margin:16px 0;">
            <thead>
                <tr style="background:#f3f4f6; text-align:left;">
                    <th style="border:1px solid #e5e7eb;">Cliente</th>
                    <th style="border:1px solid #e5e7eb;">RUT</th>
                    <th style="border:1px solid #e5e7eb;">Problema</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $it)
                    <tr>
                        <td style="border:1px solid #e5e7eb;">{{ $it['nombre'] ?: '(sin nombre)' }}</td>
                        <td style="border:1px solid #e5e7eb;">{{ $it['rut'] ?: '—' }}</td>
                        <td style="border:1px solid #e5e7eb;">
                            {{ $it['motivo'] }}@if (!empty($it['detalle'])) <span style="color:#6b7280;">({{ $it['detalle'] }})</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="color:#6b7280;">
            Una vez corregido en SYSGAL, el sistema los retoma automáticamente en la próxima
            sincronización.
        </p>

        <hr style="border:none; border-top:1px solid #e5e7eb; margin:20px 0;">
        <p style="color:#9ca3af; font-size:12px; margin-bottom:0;">
            Mensaje automático del sistema de Nurturing · {{ now()->format('d/m/Y H:i') }}
        </p>
    </div>
</body>
</html>
