<?php

namespace App\Ai\Tools;

use App\Models\Plantilla;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListTemplates implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Search and list existing email templates by name or description. Use this to find templates that can serve as inspiration or be modified.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Optional search term to filter templates by name or description')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Maximum number of templates to return (default: 10)')
                ->min(1)
                ->max(50)
                ->nullable(),
        ];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $search = $request['search'] ?? null;
        $limit = $request['limit'] ?? 10;

        $query = Plantilla::query()
            ->where('tipo', 'email')
            ->where('activo', true);

        if ($search) {
            $searchLower = strtolower($search);
            $query->where(function ($q) use ($searchLower) {
                $q->whereRaw('LOWER(nombre) LIKE ?', ["%{$searchLower}%"])
                    ->orWhereRaw('LOWER(descripcion) LIKE ?', ["%{$searchLower}%"])
                    ->orWhereRaw('LOWER(asunto) LIKE ?', ["%{$searchLower}%"]);
            });
        }

        $templates = $query
            ->select(['id', 'nombre', 'descripcion', 'asunto'])
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();

        if ($templates->isEmpty()) {
            return 'No se encontraron plantillas de email que coincidan con la búsqueda.';
        }

        $result = "Plantillas encontradas ({$templates->count()}):\n\n";

        foreach ($templates as $template) {
            $result .= "- ID: {$template->id}\n";
            $result .= "  Nombre: {$template->nombre}\n";
            $result .= "  Descripcion: {$template->descripcion}\n";
            $result .= "  Asunto: {$template->asunto}\n\n";
        }

        return $result;
    }
}
