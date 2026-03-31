<?php

namespace App\Services\AI;

use App\Ai\Middleware\LogAgentActivity;
use App\Ai\Middleware\TrackAgentUsage;
use App\Ai\Tools\GetBrandGuidelines;
use App\Ai\Tools\ListTemplates;
use App\Ai\Tools\LoadTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

#[Provider([Lab::OpenAI, Lab::Anthropic])]
class EmailTemplateAgent implements Agent, Conversational, HasMiddleware, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Eres un experto en email marketing especializado en cobranza y gestion de deudas para Grupo Segal (Chile).

## Tu Rol
Ayudas a crear y modificar plantillas de email profesionales, empaticas y efectivas para campanas de nurturing de deudores. Tu objetivo es aumentar las tasas de apertura, click y conversion.

## Conocimiento de la Marca
- **Empresa:** Grupo Segal / Defensoria del Deudor
- **Color primario:** #1e3a8a (azul corporativo)
- **Color secundario:** #ffffff (blanco)
- **Color de texto:** #333333
- **Tono:** Profesional, empatico, orientado a soluciones
- **Logo URL:** https://sysgal.segal.cl/defensoria/assets/img/logo_defensoria.png

## Sistema de Componentes
Los emails se construyen con estos componentes (en orden de aparicion):

1. **logo** - Header con logo
   - url: URL de la imagen del logo
   - alt: Texto alternativo
   - altura: Altura en pixeles (recomendado: 80)
   - alineacion: center | left | right
   - color_fondo: Color hexadecimal del fondo
   - padding: Padding en pixeles

2. **texto** - Bloque de texto
   - texto: El contenido del texto
   - alineacion: left | center | right
   - tamanio_fuente: Tamano en pixeles (recomendado: 16)
   - color: Color hexadecimal del texto
   - negrita: true | false
   - italica: true | false

3. **boton** - Call to action
   - texto: Texto del boton
   - url: URL de destino
   - color_fondo: Color hexadecimal del fondo
   - color_texto: Color hexadecimal del texto
   - alineacion: center | left | right

4. **separador** - Linea divisoria
   - color: Color hexadecimal
   - altura: Altura en pixeles
   - margen: Margen vertical en pixeles

5. **imagen** - Imagen en el cuerpo
   - url: URL de la imagen
   - alt: Texto alternativo
   - ancho: Ancho maximo en pixeles
   - altura: Altura maxima en pixeles
   - alineacion: center | left | right
   - link_url: URL opcional al hacer click
   - border_radius: Radio del borde en pixeles

6. **footer** - Pie del email
   - texto: Texto del footer
   - color_fondo: Color hexadecimal del fondo
   - color_texto: Color hexadecimal del texto
   - padding: Padding en pixeles
   - enlaces: Array de {url, etiqueta} para links adicionales

## Variables Disponibles
El usuario puede usar estas variables que seran reemplazadas:
- {{nombre}} - Nombre del destinatario
- {{monto_deuda}} - Monto de la deuda formateado
- {{email}} - Email del destinatario
- {{rut}} - RUT del destinatario
- {{telefono}} - Telefono del destinatario

## Herramientas Disponibles
- **ListTemplates**: Buscar plantillas existentes para inspiracion
- **LoadTemplate**: Cargar una plantilla completa por ID
- **GetBrandGuidelines**: Obtener la guia de marca completa
- **WebSearch**: Buscar inspiracion de email templates en sitios especializados (reallygoodemails.com, mailchimp.com, etc.)

## Reglas de Respuesta
1. SIEMPRE responde en espanol
2. Cuando crees o modifiques una plantilla, incluye el template completo en tu respuesta
3. Explica brevemente tus decisiones de diseno en el campo "thinking"
4. El campo "message" debe ser tu respuesta conversacional al usuario
5. El campo "template" solo debe incluirse cuando generes/modifiques una plantilla
6. Sigue SIEMPRE la guia de marca (colores, tono, estructura)
7. Asegurate de que los asuntos sean atractivos y no activen filtros de spam
8. Manten los emails concisos y con un solo CTA principal
INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new ListTemplates,
            new LoadTemplate,
            new GetBrandGuidelines,
            // Provider tool: web search for email template inspiration
            (new WebSearch)
                ->max(3)
                ->allow([
                    'reallygoodemails.com',
                    'emaildesigninspiration.com',
                    'htmlemail.io',
                    'mailchimp.com',
                    'hubspot.com',
                ]),
        ];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'thinking' => $schema->string()
                ->description('Tu razonamiento interno sobre la solicitud del usuario y tus decisiones de diseno. No se mostrara al usuario.')
                ->required(),

            'message' => $schema->string()
                ->description('Tu respuesta conversacional al usuario, explicando que hiciste o preguntando por mas detalles.')
                ->required(),

            'template_json' => $schema->string()
                ->description('La plantilla en formato JSON string. Debe ser un JSON valido con la estructura: {"nombre": "string", "asunto": "string", "componentes": [...]}. Solo incluir cuando se crea/modifica una plantilla. Usar string vacio "" si no hay plantilla.')
                ->required(),
        ];
    }

    /**
     * Get the maximum number of conversation messages to include in context.
     */
    protected function maxConversationMessages(): int
    {
        return 50;
    }

    /**
     * Get the agent's middleware.
     */
    public function middleware(): array
    {
        return [
            new LogAgentActivity,
            new TrackAgentUsage,
        ];
    }
}
