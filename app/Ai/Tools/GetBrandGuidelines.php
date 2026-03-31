<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetBrandGuidelines implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Get the brand guidelines for Grupo Segal, including colors, logo URL, tone of voice, and style recommendations for email templates.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return <<<'GUIDELINES'
## Grupo Segal - Guia de Marca para Emails

### Colores Corporativos
- **Color Primario:** #1e3a8a (Azul Segal) - Usar en header, footer, botones
- **Color Secundario:** #ffffff (Blanco) - Texto sobre fondos azules
- **Color de Texto:** #333333 (Gris oscuro) - Texto principal del cuerpo
- **Color de Separadores:** #e0e0e0 (Gris claro)

### Logo
- **URL:** https://sysgal.segal.cl/defensoria/assets/img/logo_defensoria.png
- **Altura recomendada:** 80px
- **Fondo:** Usar color primario (#1e3a8a)
- **Padding:** 30px

### Tono de Comunicacion
- **Profesional pero cercano:** No usar lenguaje demasiado formal ni coloquial
- **Empatico:** Reconocer la situacion del deudor sin juzgar
- **Orientado a la accion:** Siempre incluir un llamado claro
- **Positivo:** Enfocarse en soluciones, no en problemas
- **Directo:** Mensajes cortos y claros

### Estructura Recomendada de Emails
1. **Logo** - Header con fondo azul corporativo
2. **Texto principal** - Mensaje empatico y directo
3. **Boton CTA** - Accion clara ("Revisar mi situacion", "Agendar consulta", etc.)
4. **Footer** - Copyright y datos de contacto

### Variables Disponibles
Puedes usar estas variables que seran reemplazadas con datos del destinatario:
- `{{nombre}}` - Nombre del destinatario
- `{{monto_deuda}}` - Monto de la deuda formateado
- `{{email}}` - Email del destinatario
- `{{rut}}` - RUT del destinatario
- `{{telefono}}` - Telefono del destinatario

### Buenas Practicas
- Mantener emails concisos (max 150 palabras en el cuerpo)
- Un solo CTA principal por email
- Asuntos de 5-8 palabras maximo
- Evitar palabras que activen filtros de spam ("gratis", "urgente", "dinero")
- Usar preguntas en el asunto para aumentar apertura
GUIDELINES;
    }
}
