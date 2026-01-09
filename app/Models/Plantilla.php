<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plantilla extends Model
{
    use HasFactory;

    protected $table = 'plantillas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'tipo',
        'contenido',
        'asunto',
        'componentes',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'componentes' => 'array',
            'activo' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para filtrar plantillas activas
     */
    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Validar si es plantilla SMS
     */
    public function esSMS(): bool
    {
        return $this->tipo === 'sms';
    }

    /**
     * Validar si es plantilla Email
     */
    public function esEmail(): bool
    {
        return $this->tipo === 'email';
    }

    /**
     * Validar longitud de SMS (considerando caracteres especiales)
     */
    public function validarLongitudSMS(): array
    {
        if (! $this->esSMS()) {
            return ['valido' => false, 'error' => 'No es una plantilla SMS'];
        }

        $contenido = $this->contenido ?? '';
        $longitud = $this->calcularLongitudSMS($contenido);

        return [
            'valido' => $longitud <= 160,
            'longitud' => $longitud,
            'disponibles' => max(0, 160 - $longitud),
            'porcentaje' => min(100, ($longitud / 160) * 100),
        ];
    }

    /**
     * Calcular longitud real de SMS considerando caracteres especiales
     */
    private function calcularLongitudSMS(string $texto): int
    {
        // Caracteres que cuentan como 2 en GSM 7-bit
        $caracteresDobles = ['€', '[', ']', '{', '}', '\\', '^', '~', '|'];

        $longitud = mb_strlen($texto);

        foreach ($caracteresDobles as $char) {
            $ocurrencias = substr_count($texto, $char);
            $longitud += $ocurrencias; // Cada uno cuenta como 1 adicional
        }

        return $longitud;
    }

    /**
     * Generar preview de la plantilla
     */
    public function generarPreview(): ?string
    {
        if ($this->esSMS()) {
            return $this->contenido;
        }

        if ($this->esEmail()) {
            return $this->generarHTMLEmail();
        }

        return null;
    }

    /**
     * Ancho estándar del email en píxeles
     */
    private const EMAIL_WIDTH = 600;

    /**
     * Padding horizontal del contenido
     */
    private const CONTENT_PADDING = 30;

    /**
     * Generar HTML del email a partir de componentes
     * Usa estructura de tablas para máxima compatibilidad con clientes de email
     */
    private function generarHTMLEmail(): string
    {
        if (empty($this->componentes)) {
            return '';
        }

        // Estructura base del email con tablas para compatibilidad total
        $html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
        $html .= '<html xmlns="http://www.w3.org/1999/xhtml">';
        $html .= '<head>';
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0" />';
        $html .= '<title>' . htmlspecialchars($this->asunto ?? 'Email') . '</title>';
        $html .= '</head>';
        $html .= '<body style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: Arial, Helvetica, sans-serif;">';
        
        // Tabla contenedora externa (para centrar y dar fondo)
        $html .= '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f4f4;">';
        $html .= '<tr>';
        $html .= '<td align="center" style="padding: 20px 10px;">';
        
        // Tabla principal del email (600px de ancho)
        $html .= sprintf(
            '<table border="0" cellpadding="0" cellspacing="0" width="%d" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">',
            self::EMAIL_WIDTH
        );

        foreach ($this->componentes as $componente) {
            $html .= $this->renderizarComponente($componente);
        }

        $html .= '</table>'; // Fin tabla principal
        
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '</table>'; // Fin tabla contenedora
        
        $html .= '</body>';
        $html .= '</html>';

        return $html;
    }

    /**
     * Renderizar un componente individual a HTML
     */
    private function renderizarComponente(array $componente): string
    {
        $tipo = $componente['tipo'] ?? '';
        
        // Si 'contenido' es un JSON string, parsearlo y mergear con el componente
        if (isset($componente['contenido']) && is_string($componente['contenido'])) {
            $contenidoData = json_decode($componente['contenido'], true);
            if (is_array($contenidoData)) {
                $componente = array_merge($componente, $contenidoData);
            }
        }

        return match ($tipo) {
            'logo' => $this->renderLogo($componente),
            'texto' => $this->renderTexto($componente),
            'boton' => $this->renderBoton($componente),
            'separador' => $this->renderSeparador($componente),
            'imagen' => $this->renderImagen($componente),
            'footer' => $this->renderFooter($componente),
            default => '',
        };
    }

    private function renderLogo(array $comp): string
    {
        $url = $comp['url'] ?? '';
        $alturaMax = $comp['altura'] ?? 80;
        $anchoMax = $comp['ancho'] ?? null;
        $alineacion = $comp['alineacion'] ?? 'center';
        $colorFondo = $comp['color_fondo'] ?? '#1e3a8a'; // Azul Segal por defecto
        $padding = $comp['padding'] ?? 30;
        $alt = $comp['alt'] ?? 'Logo';

        // Estilo de la imagen - mantiene aspect ratio usando max-height/max-width
        // height: auto es clave para que no se deforme
        $imgStyle = 'display: block; border: 0; outline: none; height: auto;';
        if ($alturaMax) {
            $imgStyle .= sprintf(' max-height: %dpx;', $alturaMax);
        }
        if ($anchoMax) {
            $imgStyle .= sprintf(' max-width: %dpx;', $anchoMax);
        }

        // Estructura de tabla para el header con logo
        $html = '<tr>';
        $html .= sprintf(
            '<td align="%s" style="background-color: %s; padding: %dpx;">',
            $alineacion,
            $colorFondo,
            $padding
        );
        $html .= sprintf(
            '<img src="%s" alt="%s" style="%s" />',
            htmlspecialchars($url),
            htmlspecialchars($alt),
            $imgStyle
        );
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderTexto(array $comp): string
    {
        // El texto puede venir en 'texto' (desde JSON parseado) o 'contenido' (legacy)
        $texto = $comp['texto'] ?? $comp['contenido'] ?? '';
        $alineacion = $comp['alineacion'] ?? 'left';
        $tamano = $comp['tamanio_fuente'] ?? $comp['tamano'] ?? 16;
        $color = $comp['color'] ?? '#333333';
        $negrita = $comp['negrita'] ?? false;
        $italica = $comp['italica'] ?? false;

        $textStyle = sprintf(
            'font-family: Arial, Helvetica, sans-serif; font-size: %dpx; color: %s; line-height: 1.6; margin: 0; white-space: pre-line;%s%s',
            $tamano,
            $color,
            $negrita ? ' font-weight: bold;' : ' font-weight: normal;',
            $italica ? ' font-style: italic;' : ''
        );

        $html = '<tr>';
        $html .= sprintf(
            '<td align="%s" style="padding: %dpx %dpx;">',
            $alineacion,
            20, // padding vertical
            self::CONTENT_PADDING // padding horizontal
        );
        $html .= sprintf(
            '<p style="%s">%s</p>',
            $textStyle,
            nl2br(htmlspecialchars($texto))
        );
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderBoton(array $comp): string
    {
        $texto = $comp['texto'] ?? 'Click aquí';
        $url = $comp['url'] ?? '#';
        $colorFondo = $comp['color_fondo'] ?? '#1e3a8a';
        $colorTexto = $comp['color_texto'] ?? '#ffffff';
        $alineacion = $comp['alineacion'] ?? 'center';
        $borderRadius = 6;
        $paddingV = 14;
        $paddingH = 32;

        // Botón "bulletproof" compatible con Outlook usando VML
        // Esta técnica usa una tabla anidada que funciona en todos los clientes
        $html = '<tr>';
        $html .= sprintf(
            '<td align="%s" style="padding: 25px %dpx;">',
            $alineacion,
            self::CONTENT_PADDING
        );
        
        // Tabla contenedora del botón para border-radius en todos los clientes
        $html .= '<table border="0" cellpadding="0" cellspacing="0" role="presentation">';
        $html .= '<tr>';
        $html .= sprintf(
            '<td align="center" style="background-color: %s; border-radius: %dpx;">',
            $colorFondo,
            $borderRadius
        );
        $html .= sprintf(
            '<a href="%s" target="_blank" style="display: inline-block; padding: %dpx %dpx; font-family: Arial, Helvetica, sans-serif; font-size: 16px; font-weight: bold; color: %s; text-decoration: none; border-radius: %dpx; background-color: %s;">%s</a>',
            htmlspecialchars($url),
            $paddingV,
            $paddingH,
            $colorTexto,
            $borderRadius,
            $colorFondo,
            htmlspecialchars($texto)
        );
        $html .= '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderSeparador(array $comp): string
    {
        $color = $comp['color'] ?? '#e0e0e0';
        $altura = $comp['altura'] ?? 1;
        $margen = $comp['margen'] ?? 10;

        $html = '<tr>';
        $html .= sprintf(
            '<td style="padding: %dpx %dpx;">',
            $margen,
            self::CONTENT_PADDING
        );
        $html .= sprintf(
            '<table border="0" cellpadding="0" cellspacing="0" width="100%%" role="presentation"><tr><td style="border-top: %dpx solid %s; font-size: 1px; line-height: 1px;">&nbsp;</td></tr></table>',
            $altura,
            $color
        );
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderImagen(array $comp): string
    {
        $url = $comp['url'] ?? '';
        $alt = $comp['alt'] ?? 'Imagen';
        $ancho = $comp['ancho'] ?? null;
        $altura = $comp['altura'] ?? null;
        $alineacion = $comp['alineacion'] ?? 'center';
        $linkUrl = $comp['link_url'] ?? '';
        $linkTarget = $comp['link_target'] ?? '_blank';
        $borderRadius = $comp['border_radius'] ?? 0;
        $padding = $comp['padding'] ?? 10;

        if (empty($url)) {
            return '';
        }

        // Construir estilo de la imagen
        $imgStyle = 'display: block; border: 0; outline: none;';
        if ($ancho) {
            $imgStyle .= sprintf(' max-width: %dpx;', $ancho);
        } else {
            // Si no hay ancho específico, usar 100% pero máximo el ancho del email menos padding
            $imgStyle .= sprintf(' max-width: %dpx;', self::EMAIL_WIDTH - (self::CONTENT_PADDING * 2));
        }
        if ($altura) {
            $imgStyle .= sprintf(' max-height: %dpx;', $altura);
        }
        $imgStyle .= ' height: auto;'; // Mantener aspect ratio
        if ($borderRadius > 0) {
            $imgStyle .= sprintf(' border-radius: %dpx;', $borderRadius);
        }

        // Generar HTML de la imagen
        $imgHtml = sprintf(
            '<img src="%s" alt="%s" style="%s" />',
            htmlspecialchars($url),
            htmlspecialchars($alt),
            $imgStyle
        );

        // Si tiene link, envolver en anchor
        if (!empty($linkUrl)) {
            $imgHtml = sprintf(
                '<a href="%s" target="%s" style="display: inline-block; text-decoration: none;">%s</a>',
                htmlspecialchars($linkUrl),
                $linkTarget,
                $imgHtml
            );
        }

        // Estructura de tabla para la imagen
        $html = '<tr>';
        $html .= sprintf(
            '<td align="%s" style="padding: %dpx %dpx;">',
            $alineacion,
            $padding,
            self::CONTENT_PADDING
        );
        $html .= $imgHtml;
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderFooter(array $comp): string
    {
        // El texto puede venir en 'texto' (desde JSON parseado) o 'contenido' (legacy)
        $texto = $comp['texto'] ?? $comp['contenido'] ?? '';
        $colorTexto = $comp['color_texto'] ?? $comp['color'] ?? '#ffffff';
        $colorFondo = $comp['color_fondo'] ?? '#1e3a8a';
        $padding = $comp['padding'] ?? 25;
        $enlaces = $comp['enlaces'] ?? [];

        $html = '<tr>';
        $html .= sprintf(
            '<td align="center" style="background-color: %s; padding: %dpx %dpx;">',
            $colorFondo,
            $padding,
            self::CONTENT_PADDING
        );
        
        // Texto principal del footer
        $html .= sprintf(
            '<p style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: %s; margin: 0 0 10px 0; line-height: 1.5; white-space: pre-line;">%s</p>',
            $colorTexto,
            nl2br(htmlspecialchars($texto))
        );
        
        // Enlaces del footer
        if (!empty($enlaces)) {
            $linksHtml = [];
            foreach ($enlaces as $enlace) {
                $linksHtml[] = sprintf(
                    '<a href="%s" style="color: %s; text-decoration: underline;">%s</a>',
                    htmlspecialchars($enlace['url'] ?? '#'),
                    $colorTexto,
                    htmlspecialchars($enlace['etiqueta'] ?? $enlace['url'] ?? 'Enlace')
                );
            }
            $html .= sprintf(
                '<p style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: %s; margin: 0;">%s</p>',
                $colorTexto,
                implode(' &nbsp;|&nbsp; ', $linksHtml)
            );
        }
        
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }
}
