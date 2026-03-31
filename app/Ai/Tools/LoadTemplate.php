<?php

namespace App\Ai\Tools;

use App\Models\Plantilla;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class LoadTemplate implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Load a complete email template by ID, including all its components. Use this to view the full structure of a template for modification or reference.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->integer()
                ->description('The ID of the template to load')
                ->required(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $templateId = $request['template_id'];

        $template = Plantilla::where('tipo', 'email')
            ->where('id', $templateId)
            ->first();

        if (! $template) {
            return "No se encontro la plantilla con ID {$templateId} o no es una plantilla de email.";
        }

        $result = "## Plantilla: {$template->nombre}\n\n";
        $result .= "**ID:** {$template->id}\n";
        $result .= "**Descripcion:** {$template->descripcion}\n";
        $result .= "**Asunto:** {$template->asunto}\n";
        $result .= "**Activo:** ".($template->activo ? 'Si' : 'No')."\n\n";

        $result .= "### Componentes\n\n";

        if (empty($template->componentes)) {
            $result .= "Esta plantilla no tiene componentes definidos.\n";

            return $result;
        }

        foreach ($template->componentes as $index => $componente) {
            $tipo = $componente['tipo'] ?? 'desconocido';
            $result .= "**Componente ".($index + 1).": {$tipo}**\n";

            switch ($tipo) {
                case 'logo':
                    $result .= "  - URL: ".($componente['url'] ?? 'N/A')."\n";
                    $result .= "  - Alt: ".($componente['alt'] ?? 'N/A')."\n";
                    $result .= "  - Altura: ".($componente['altura'] ?? 'auto')."px\n";
                    $result .= "  - Alineacion: ".($componente['alineacion'] ?? 'center')."\n";
                    $result .= "  - Color fondo: ".($componente['color_fondo'] ?? '#1e3a8a')."\n";
                    break;

                case 'texto':
                    $texto = $componente['texto'] ?? $componente['contenido'] ?? '';
                    $result .= "  - Texto: \"{$texto}\"\n";
                    $result .= "  - Alineacion: ".($componente['alineacion'] ?? 'left')."\n";
                    $result .= "  - Tamanio fuente: ".($componente['tamanio_fuente'] ?? 16)."px\n";
                    $result .= "  - Color: ".($componente['color'] ?? '#333333')."\n";
                    break;

                case 'boton':
                    $result .= "  - Texto: ".($componente['texto'] ?? 'Click aqui')."\n";
                    $result .= "  - URL: ".($componente['url'] ?? '#')."\n";
                    $result .= "  - Color fondo: ".($componente['color_fondo'] ?? '#1e3a8a')."\n";
                    $result .= "  - Color texto: ".($componente['color_texto'] ?? '#ffffff')."\n";
                    break;

                case 'separador':
                    $result .= "  - Color: ".($componente['color'] ?? '#e0e0e0')."\n";
                    $result .= "  - Altura: ".($componente['altura'] ?? 1)."px\n";
                    break;

                case 'imagen':
                    $result .= "  - URL: ".($componente['url'] ?? 'N/A')."\n";
                    $result .= "  - Alt: ".($componente['alt'] ?? 'Imagen')."\n";
                    $result .= "  - Ancho: ".($componente['ancho'] ?? 'auto')."\n";
                    break;

                case 'footer':
                    $texto = $componente['texto'] ?? $componente['contenido'] ?? '';
                    $result .= "  - Texto: \"{$texto}\"\n";
                    $result .= "  - Color fondo: ".($componente['color_fondo'] ?? '#1e3a8a')."\n";
                    $result .= "  - Color texto: ".($componente['color_texto'] ?? '#ffffff')."\n";
                    break;

                default:
                    $result .= "  - Datos: ".json_encode($componente)."\n";
            }

            $result .= "\n";
        }

        return $result;
    }
}
