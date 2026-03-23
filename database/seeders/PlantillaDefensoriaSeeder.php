<?php

namespace Database\Seeders;

use App\Models\Plantilla;
use Illuminate\Database\Seeder;

class PlantillaDefensoriaSeeder extends Seeder
{
    /**
     * Assets para las plantillas de Defensoría del Deudor
     */
    private const LOGO_URL = 'https://sysgal.segal.cl/defensoria/assets/img/logo_defensoria.png';

    private const LINK_SMS = 'https://l1nk.dev/MOwKU';

    private const LINK_EMAIL_CTA = 'https://n9.cl/3ewo74';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedSmsTemplates();
        $this->seedEmailTemplates();
        $this->seedRetargetTemplates();

        $this->command->info('Plantillas de Defensoría del Deudor creadas exitosamente.');
    }

    /**
     * Seed SMS templates (8 total)
     */
    private function seedSmsTemplates(): void
    {
        $smsTemplates = [
            [
                'nombre' => 'DEF-SMS-D005-PrimerRecordatorio',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 5',
                'dia' => 5,
                'contenido' => 'Revisa si hoy sigues en DICOM. Haz tu revisión aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D015-SegundoRecordatorio',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 15',
                'dia' => 15,
                'contenido' => 'Podrías estar figurando nuevamente y no saberlo. Revísalo aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D045-SituacionComercial',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 45',
                'dia' => 45,
                'contenido' => 'Tu situación comercial podría estar afectando oportunidades hoy. Revísala aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D075-HistorialActivo',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 75',
                'dia' => 75,
                'contenido' => 'Tu historial comercial puede seguir activo. Revísalo aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D135-AccesoCredito',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 135',
                'dia' => 135,
                'contenido' => 'Podrías estar perdiendo acceso a crédito por tu situación actual. Revísala aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D210-RevisionDisponible',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 210',
                'dia' => 210,
                'contenido' => 'Aún puedes revisar tu situación comercial. Ingresa aquí: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D270-EstadoComercial',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 270',
                'dia' => 270,
                'contenido' => 'Revisa hoy tu estado comercial en pocos pasos: '.self::LINK_SMS,
            ],
            [
                'nombre' => 'DEF-SMS-D330-AvanzaHoy',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 330',
                'dia' => 330,
                'contenido' => 'Puedes revisar tu situación y avanzar hoy mismo. Entra aquí: '.self::LINK_SMS,
            ],
        ];

        foreach ($smsTemplates as $template) {
            Plantilla::firstOrCreate(
                ['nombre' => $template['nombre']],
                [
                    'descripcion' => $template['descripcion'],
                    'tipo' => 'sms',
                    'contenido' => $template['contenido'],
                    'asunto' => null,
                    'componentes' => null,
                    'activo' => true,
                ]
            );
        }

        $this->command->info('  - '.count($smsTemplates).' plantillas SMS creadas');
    }

    /**
     * Seed Email templates (12 total)
     */
    private function seedEmailTemplates(): void
    {
        $emailTemplates = [
            [
                'nombre' => 'DEF-EMAIL-D000-SiguesEnDicom',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 0',
                'dia' => 0,
                'asunto' => '¿Sigues apareciendo en DICOM?',
                'texto' => "Tu situación comercial puede haber cambiado sin que lo notes.\n\nHoy puedes:\n• Revisar si sigues publicado\n• Detectar nuevas deudas\n• Ver tu situación actual",
            ],
            [
                'nombre' => 'DEF-EMAIL-D010-VuelvenAparecer',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 10',
                'dia' => 10,
                'asunto' => 'Muchas personas vuelven a aparecer sin saberlo',
                'texto' => "Con el tiempo, muchas personas vuelven a figurar en registros comerciales sin darse cuenta.\n\nEso puede afectar:\n• Créditos\n• Arriendos\n• Evaluaciones",
            ],
            [
                'nombre' => 'DEF-EMAIL-D020-AfectandoteHoy',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 20',
                'dia' => 20,
                'asunto' => 'Esto podría estar afectándote hoy',
                'texto' => "Si hoy apareces informado comercialmente, eso puede impactar directamente en:\n• Créditos rechazados\n• Problemas para arrendar\n• Evaluaciones negativas",
            ],
            [
                'nombre' => 'DEF-EMAIL-D030-UltimoAviso',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 30',
                'dia' => 30,
                'asunto' => 'Último aviso: revisa tu situación comercial',
                'texto' => 'Si no has revisado tu estado, podrías estar manteniendo un problema activo sin saberlo.',
            ],
            [
                'nombre' => 'DEF-EMAIL-D060-HistorialNoBorra',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 60',
                'dia' => 60,
                'asunto' => 'Tu historial no se borra solo',
                'texto' => "El historial comercial no desaparece automáticamente.\n\nSi no lo revisas, puede seguir afectándote en créditos, evaluaciones y nuevas gestiones.",
            ],
            [
                'nombre' => 'DEF-EMAIL-D090-CuantoSinRevisar',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 90',
                'dia' => 90,
                'asunto' => '¿Hace cuánto no revisas esto?',
                'texto' => "Muchas personas dejan pasar el tiempo sin revisar su situación.\nCuando finalmente lo hacen, el problema ya creció.",
            ],
            [
                'nombre' => 'DEF-EMAIL-D120-PerdiendoCredito',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 120',
                'dia' => 120,
                'asunto' => 'Podrías estar perdiendo acceso a crédito',
                'texto' => "Tu situación comercial puede limitar:\n• Créditos\n• Financiamiento\n• Nuevos contratos",
            ],
            [
                'nombre' => 'DEF-EMAIL-D150-NoSeSolucionaSolo',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 150',
                'dia' => 150,
                'asunto' => 'Esto no se soluciona solo',
                'texto' => "Si no tomas acción, el problema puede mantenerse o empeorar.\n\nHoy puedes revisar tu situación y entender en qué estado estás.",
            ],
            [
                'nombre' => 'DEF-EMAIL-D180-AunATiempo',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 180',
                'dia' => 180,
                'asunto' => 'Aún estás a tiempo de revisarlo',
                'texto' => 'Todavía puedes revisar tu situación antes de que siga afectándote en nuevas gestiones.',
            ],
            [
                'nombre' => 'DEF-EMAIL-D240-RevisaHoy',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 240',
                'dia' => 240,
                'asunto' => 'Revisa tu situación hoy',
                'texto' => 'Si no sabes cómo estás hoy, estás tomando decisiones a ciegas.',
            ],
            [
                'nombre' => 'DEF-EMAIL-D300-ResolverHoy',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 300',
                'dia' => 300,
                'asunto' => 'Puedes resolver esto hoy',
                'texto' => 'Si detectas problemas en tu situación comercial, puedes revisar alternativas y siguientes pasos.',
            ],
            [
                'nombre' => 'DEF-EMAIL-D360-UltimaOportunidad',
                'descripcion' => 'Flujo Defensoría del Deudor - Día 360',
                'dia' => 360,
                'asunto' => 'Última oportunidad para revisar tu situación',
                'texto' => "Has postergado esta revisión por bastante tiempo.\n\nHoy todavía puedes tomar control de tu situación.",
            ],
        ];

        foreach ($emailTemplates as $template) {
            Plantilla::firstOrCreate(
                ['nombre' => $template['nombre']],
                [
                    'descripcion' => $template['descripcion'],
                    'tipo' => 'email',
                    'contenido' => null,
                    'asunto' => $template['asunto'],
                    'componentes' => $this->buildEmailComponents($template['texto']),
                    'activo' => true,
                ]
            );
        }

        $this->command->info('  - '.count($emailTemplates).' plantillas Email creadas');
    }

    /**
     * Seed Retarget templates (2 SMS + 1 Email)
     */
    private function seedRetargetTemplates(): void
    {
        // SMS Retarget (+3 días)
        Plantilla::firstOrCreate(
            ['nombre' => 'DEF-SMS-RETARGET-D003'],
            [
                'descripcion' => 'Flujo Defensoría del Deudor - Retarget +3 días',
                'tipo' => 'sms',
                'contenido' => 'Defensoría: Empezaste a revisar tu situación pero no completaste. Seguí aquí: '.self::LINK_SMS,
                'asunto' => null,
                'componentes' => null,
                'activo' => true,
            ]
        );

        // Email Retarget (+7 días)
        Plantilla::firstOrCreate(
            ['nombre' => 'DEF-EMAIL-RETARGET-D007'],
            [
                'descripcion' => 'Flujo Defensoría del Deudor - Retarget +7 días',
                'tipo' => 'email',
                'contenido' => null,
                'asunto' => 'No te quedes a medias — completá tu revisión',
                'componentes' => $this->buildEmailComponents('Vimos que empezaste a revisar tu situación comercial pero no llegaste a agendar.\n\nTu consulta sigue disponible y es gratuita.'),
                'activo' => true,
            ]
        );

        // SMS Cierre (+15 días)
        Plantilla::firstOrCreate(
            ['nombre' => 'DEF-SMS-CIERRE-D015'],
            [
                'descripcion' => 'Flujo Defensoría del Deudor - Cierre +15 días',
                'tipo' => 'sms',
                'contenido' => 'Defensoría: Última oportunidad para completar tu revisión gratuita. Ingresá aquí: '.self::LINK_SMS,
                'asunto' => null,
                'componentes' => null,
                'activo' => true,
            ]
        );

        $this->command->info('  - 3 plantillas Retarget creadas');
    }

    /**
     * Build standard email components array
     */
    private function buildEmailComponents(string $textoContenido): array
    {
        return [
            [
                'tipo' => 'logo',
                'url' => self::LOGO_URL,
                'alt' => 'Defensoría del Deudor',
                'altura' => 80,
                'alineacion' => 'center',
                'color_fondo' => '#1e3a8a',
                'padding' => 30,
            ],
            [
                'tipo' => 'texto',
                'texto' => $textoContenido,
                'alineacion' => 'left',
                'tamanio_fuente' => 16,
                'color' => '#333333',
            ],
            [
                'tipo' => 'boton',
                'texto' => 'Revisar mi situación',
                'url' => self::LINK_EMAIL_CTA,
                'color_fondo' => '#1e3a8a',
                'color_texto' => '#ffffff',
                'alineacion' => 'center',
            ],
            [
                'tipo' => 'footer',
                'texto' => 'Defensoría del Deudor © 2024 - Chile',
                'color_fondo' => '#1e3a8a',
                'color_texto' => '#ffffff',
                'padding' => 25,
            ],
        ];
    }
}
