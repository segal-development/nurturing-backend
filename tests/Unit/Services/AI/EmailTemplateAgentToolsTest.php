<?php

namespace Tests\Unit\Services\AI;

use App\Ai\Tools\GetBrandGuidelines;
use App\Ai\Tools\ListTemplates;
use App\Ai\Tools\LoadTemplate;
use App\Models\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class EmailTemplateAgentToolsTest extends TestCase
{
    use RefreshDatabase;

    // ============================================
    // TESTS: ListTemplates Tool
    // ============================================

    /** @test */
    public function list_templates_returns_empty_message_when_no_templates_exist(): void
    {
        $tool = new ListTemplates;
        $request = new Request(['search' => null, 'limit' => 10]);

        $result = $tool->handle($request);

        $this->assertEquals('No se encontraron plantillas de email que coincidan con la búsqueda.', $result);
    }

    /** @test */
    public function list_templates_returns_active_email_templates(): void
    {
        // Create active email templates
        Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Recordatorio Pago',
            'descripcion' => 'Template para recordatorio de pago',
            'asunto' => 'Recordatorio de pago pendiente',
        ]);

        Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Bienvenida',
            'descripcion' => 'Template de bienvenida',
            'asunto' => 'Bienvenido a Segal',
        ]);

        // Create inactive template (should not appear)
        Plantilla::factory()->email()->inactiva()->create([
            'nombre' => 'Inactiva',
        ]);

        // Create SMS template (should not appear)
        Plantilla::factory()->sms()->activa()->create([
            'nombre' => 'SMS Template',
        ]);

        $tool = new ListTemplates;
        $request = new Request(['search' => null, 'limit' => 10]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('Plantillas encontradas (2)', $result);
        $this->assertStringContainsString('Recordatorio Pago', $result);
        $this->assertStringContainsString('Bienvenida', $result);
        $this->assertStringNotContainsString('Inactiva', $result);
        $this->assertStringNotContainsString('SMS Template', $result);
    }

    /** @test */
    public function list_templates_filters_by_search_term(): void
    {
        Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Recordatorio Segmento 1',
            'descripcion' => 'Template para deuda baja',
            'asunto' => 'Tu cuenta pendiente',
        ]);

        Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Bienvenida General',
            'descripcion' => 'Template de bienvenida',
            'asunto' => 'Bienvenido',
        ]);

        $tool = new ListTemplates;
        $request = new Request(['search' => 'recordatorio', 'limit' => 10]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('Plantillas encontradas (1)', $result);
        $this->assertStringContainsString('Recordatorio Segmento 1', $result);
        $this->assertStringNotContainsString('Bienvenida General', $result);
    }

    /** @test */
    public function list_templates_respects_limit_parameter(): void
    {
        // Create 5 templates
        Plantilla::factory()->email()->activa()->count(5)->create();

        $tool = new ListTemplates;
        $request = new Request(['search' => null, 'limit' => 2]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('Plantillas encontradas (2)', $result);
    }

    /** @test */
    public function list_templates_searches_in_asunto_field(): void
    {
        Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Template ABC',
            'descripcion' => 'Descripcion XYZ',
            'asunto' => 'Urgente: Revisar tu deuda',
        ]);

        $tool = new ListTemplates;
        $request = new Request(['search' => 'Urgente', 'limit' => 10]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('Plantillas encontradas (1)', $result);
        $this->assertStringContainsString('Template ABC', $result);
    }

    // ============================================
    // TESTS: LoadTemplate Tool
    // ============================================

    /** @test */
    public function load_template_returns_error_for_nonexistent_id(): void
    {
        $tool = new LoadTemplate;
        $request = new Request(['template_id' => 999]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('No se encontro la plantilla con ID 999', (string) $result);
    }

    /** @test */
    public function load_template_returns_error_for_sms_template(): void
    {
        $smsTemplate = Plantilla::factory()->sms()->activa()->create();

        $tool = new LoadTemplate;
        $request = new Request(['template_id' => $smsTemplate->id]);

        $result = $tool->handle($request);

        $this->assertStringContainsString('No se encontro la plantilla', (string) $result);
    }

    /** @test */
    public function load_template_returns_full_template_with_components(): void
    {
        $template = Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Recordatorio Test',
            'descripcion' => 'Template de prueba',
            'asunto' => 'Asunto de prueba',
            'componentes' => [
                [
                    'tipo' => 'logo',
                    'url' => 'https://example.com/logo.png',
                    'alt' => 'Logo Test',
                    'altura' => 80,
                    'alineacion' => 'center',
                    'color_fondo' => '#1e3a8a',
                ],
                [
                    'tipo' => 'texto',
                    'texto' => 'Hola {{nombre}}, este es un mensaje.',
                    'alineacion' => 'left',
                    'tamanio_fuente' => 16,
                    'color' => '#333333',
                ],
                [
                    'tipo' => 'boton',
                    'texto' => 'Ver mi cuenta',
                    'url' => 'https://example.com/cuenta',
                    'color_fondo' => '#1e3a8a',
                    'color_texto' => '#ffffff',
                ],
                [
                    'tipo' => 'footer',
                    'texto' => 'Grupo Segal - Santiago, Chile',
                    'color_fondo' => '#1e3a8a',
                    'color_texto' => '#ffffff',
                ],
            ],
        ]);

        $tool = new LoadTemplate;
        $request = new Request(['template_id' => $template->id]);

        $result = (string) $tool->handle($request);

        // Verify template metadata
        $this->assertStringContainsString('Plantilla: Recordatorio Test', $result);
        $this->assertStringContainsString("ID:** {$template->id}", $result);
        $this->assertStringContainsString('Descripcion:** Template de prueba', $result);
        $this->assertStringContainsString('Asunto:** Asunto de prueba', $result);

        // Verify components
        $this->assertStringContainsString('Componente 1: logo', $result);
        $this->assertStringContainsString('https://example.com/logo.png', $result);
        $this->assertStringContainsString('Componente 2: texto', $result);
        $this->assertStringContainsString('Hola {{nombre}}', $result);
        $this->assertStringContainsString('Componente 3: boton', $result);
        $this->assertStringContainsString('Ver mi cuenta', $result);
        $this->assertStringContainsString('Componente 4: footer', $result);
    }

    /** @test */
    public function load_template_handles_empty_components(): void
    {
        $template = Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Template Vacio',
            'componentes' => [],
        ]);

        $tool = new LoadTemplate;
        $request = new Request(['template_id' => $template->id]);

        $result = (string) $tool->handle($request);

        $this->assertStringContainsString('Esta plantilla no tiene componentes definidos', $result);
    }

    /** @test */
    public function load_template_handles_separator_component(): void
    {
        $template = Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Template Con Separador',
            'componentes' => [
                [
                    'tipo' => 'separador',
                    'color' => '#e0e0e0',
                    'altura' => 2,
                ],
            ],
        ]);

        $tool = new LoadTemplate;
        $request = new Request(['template_id' => $template->id]);

        $result = (string) $tool->handle($request);

        $this->assertStringContainsString('Componente 1: separador', $result);
        $this->assertStringContainsString('#e0e0e0', $result);
        $this->assertStringContainsString('2px', $result);
    }

    /** @test */
    public function load_template_handles_image_component(): void
    {
        $template = Plantilla::factory()->email()->activa()->create([
            'nombre' => 'Template Con Imagen',
            'componentes' => [
                [
                    'tipo' => 'imagen',
                    'url' => 'https://example.com/image.jpg',
                    'alt' => 'Imagen promocional',
                    'ancho' => 400,
                ],
            ],
        ]);

        $tool = new LoadTemplate;
        $request = new Request(['template_id' => $template->id]);

        $result = (string) $tool->handle($request);

        $this->assertStringContainsString('Componente 1: imagen', $result);
        $this->assertStringContainsString('https://example.com/image.jpg', $result);
        $this->assertStringContainsString('Imagen promocional', $result);
    }

    // ============================================
    // TESTS: GetBrandGuidelines Tool
    // ============================================

    /** @test */
    public function get_brand_guidelines_returns_expected_content(): void
    {
        $tool = new GetBrandGuidelines;
        $request = new Request([]);

        $result = $tool->handle($request);

        // Check brand colors
        $this->assertStringContainsString('#1e3a8a', $result);
        $this->assertStringContainsString('#ffffff', $result);
        $this->assertStringContainsString('#333333', $result);

        // Check logo URL
        $this->assertStringContainsString('https://sysgal.segal.cl/defensoria/assets/img/logo_defensoria.png', $result);

        // Check tone guidelines
        $this->assertStringContainsString('Profesional pero cercano', $result);
        $this->assertStringContainsString('Empatico', $result);

        // Check available variables
        $this->assertStringContainsString('{{nombre}}', $result);
        $this->assertStringContainsString('{{monto_deuda}}', $result);
        $this->assertStringContainsString('{{email}}', $result);

        // Check recommended structure
        $this->assertStringContainsString('Logo', $result);
        $this->assertStringContainsString('Texto principal', $result);
        $this->assertStringContainsString('Boton CTA', $result);
        $this->assertStringContainsString('Footer', $result);
    }

    /** @test */
    public function get_brand_guidelines_returns_string(): void
    {
        $tool = new GetBrandGuidelines;
        $request = new Request([]);

        $result = $tool->handle($request);

        $this->assertIsString((string) $result);
        $this->assertNotEmpty($result);
    }

    // ============================================
    // TESTS: Tool Schema Validation
    // ============================================

    /** @test */
    public function list_templates_has_correct_description(): void
    {
        $tool = new ListTemplates;

        $description = (string) $tool->description();

        $this->assertStringContainsString('email templates', $description);
        // Description uses "Search" with capital S
        $this->assertStringContainsString('Search', $description);
    }

    /** @test */
    public function load_template_has_correct_description(): void
    {
        $tool = new LoadTemplate;

        $description = (string) $tool->description();

        $this->assertStringContainsString('email template', $description);
        $this->assertStringContainsString('ID', $description);
    }

    /** @test */
    public function get_brand_guidelines_has_correct_description(): void
    {
        $tool = new GetBrandGuidelines;

        $description = (string) $tool->description();

        $this->assertStringContainsString('brand guidelines', $description);
        $this->assertStringContainsString('Grupo Segal', $description);
    }
}
