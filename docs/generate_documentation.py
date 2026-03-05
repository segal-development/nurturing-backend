#!/usr/bin/env python3
"""
Generador de Documentación Corporativa - Sistema Nurturing
Grupo Segal

Este script genera un documento Word profesional con la documentación
completa del sistema de nurturing para leads/prospectos.
"""

from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.style import WD_STYLE_TYPE
from docx.enum.table import WD_TABLE_ALIGNMENT
from datetime import datetime
import os

def create_styles(doc):
    """Crea estilos personalizados para el documento."""
    styles = doc.styles
    
    # Estilo para código
    if 'Code' not in [s.name for s in styles]:
        code_style = styles.add_style('Code', WD_STYLE_TYPE.PARAGRAPH)
        code_style.font.name = 'Courier New'
        code_style.font.size = Pt(9)
        code_style.paragraph_format.left_indent = Inches(0.5)

def add_heading_with_number(doc, text, level, number=None):
    """Añade un heading con numeración opcional."""
    if number:
        full_text = f"{number}. {text}"
    else:
        full_text = text
    doc.add_heading(full_text, level)

def add_table(doc, headers, rows, col_widths=None):
    """Añade una tabla formateada."""
    table = doc.add_table(rows=1, cols=len(headers))
    table.style = 'Table Grid'
    
    # Header row
    header_cells = table.rows[0].cells
    for i, header in enumerate(headers):
        header_cells[i].text = header
        header_cells[i].paragraphs[0].runs[0].bold = True
    
    # Data rows
    for row_data in rows:
        row = table.add_row()
        for i, cell_data in enumerate(row_data):
            row.cells[i].text = str(cell_data)
    
    doc.add_paragraph()  # Espacio después de la tabla
    return table

def generate_documentation():
    """Genera el documento de documentación completo."""
    doc = Document()
    create_styles(doc)
    
    # =========================================================================
    # PORTADA
    # =========================================================================
    doc.add_paragraph()
    doc.add_paragraph()
    
    title = doc.add_heading('Sistema de Nurturing', 0)
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    
    subtitle = doc.add_paragraph('Documentación Técnica y Funcional')
    subtitle.alignment = WD_ALIGN_PARAGRAPH.CENTER
    
    doc.add_paragraph()
    
    company = doc.add_paragraph('Grupo Segal')
    company.alignment = WD_ALIGN_PARAGRAPH.CENTER
    company.runs[0].bold = True
    company.runs[0].font.size = Pt(16)
    
    doc.add_paragraph()
    doc.add_paragraph()
    
    version_info = doc.add_paragraph()
    version_info.alignment = WD_ALIGN_PARAGRAPH.CENTER
    version_info.add_run(f'Versión 1.0\n')
    version_info.add_run(f'Fecha: {datetime.now().strftime("%d de %B de %Y")}\n')
    version_info.add_run('Clasificación: Documento Interno')
    
    doc.add_page_break()
    
    # =========================================================================
    # TABLA DE CONTENIDOS
    # =========================================================================
    doc.add_heading('Tabla de Contenidos', 1)
    
    toc_items = [
        ('1', 'Resumen Ejecutivo', '3'),
        ('2', 'Visión General del Sistema', '4'),
        ('3', 'Arquitectura Técnica', '6'),
        ('4', 'Modelo de Datos', '10'),
        ('5', 'Funcionalidades del Sistema', '14'),
        ('6', 'API REST - Endpoints', '20'),
        ('7', 'Guía de Operaciones', '28'),
        ('8', 'Seguridad', '32'),
        ('9', 'Glosario', '34'),
    ]
    
    for num, title, page in toc_items:
        p = doc.add_paragraph()
        p.add_run(f'{num}. {title}').bold = True
        p.add_run(f' {"." * (60 - len(title))} {page}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 1. RESUMEN EJECUTIVO
    # =========================================================================
    doc.add_heading('1. Resumen Ejecutivo', 1)
    
    doc.add_heading('1.1 Propósito del Sistema', 2)
    doc.add_paragraph(
        'El Sistema de Nurturing de Grupo Segal es una plataforma integral diseñada para '
        'automatizar y optimizar la comunicación con prospectos (leads) a través de flujos '
        'de marketing multicanal. El sistema permite gestionar campañas de email y SMS de '
        'manera programada, con seguimiento completo de métricas de engagement.'
    )
    
    doc.add_heading('1.2 Beneficios Clave', 2)
    benefits = [
        'Automatización completa de campañas de nurturing',
        'Comunicación multicanal (Email + SMS)',
        'Segmentación inteligente por tipo de prospecto y origen',
        'Tracking de aperturas, clicks y conversiones',
        'Reportes y métricas en tiempo real',
        'Gestión de costos por campaña',
        'Cumplimiento de normativas de desuscripción',
    ]
    for benefit in benefits:
        doc.add_paragraph(f'• {benefit}')
    
    doc.add_heading('1.3 Alcance', 2)
    doc.add_paragraph(
        'Este documento cubre la documentación completa del sistema, incluyendo:'
    )
    scope_items = [
        'Arquitectura técnica (Backend Laravel + Frontend React)',
        'Modelo de datos y relaciones',
        'APIs REST disponibles',
        'Funcionalidades para usuarios finales',
        'Guías de operación y mantenimiento',
    ]
    for item in scope_items:
        doc.add_paragraph(f'• {item}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 2. VISIÓN GENERAL DEL SISTEMA
    # =========================================================================
    doc.add_heading('2. Visión General del Sistema', 1)
    
    doc.add_heading('2.1 Descripción Funcional', 2)
    doc.add_paragraph(
        'El sistema permite a los usuarios de Grupo Segal crear y gestionar flujos de '
        'comunicación automatizada con prospectos. Cada flujo define una secuencia de '
        'mensajes (emails y/o SMS) que se envían automáticamente según condiciones '
        'configurables.'
    )
    
    doc.add_heading('2.2 Flujo de Trabajo Principal', 2)
    workflow_steps = [
        ('Importación', 'Los prospectos se importan desde archivos Excel o APIs externas'),
        ('Categorización', 'Se clasifican por tipo (monto de deuda) y origen'),
        ('Asignación', 'Se asignan automáticamente a flujos según sus características'),
        ('Ejecución', 'El flujo envía mensajes según la programación definida'),
        ('Seguimiento', 'Se registran aperturas, clicks y conversiones'),
        ('Análisis', 'Se generan métricas y reportes de rendimiento'),
    ]
    
    for step, description in workflow_steps:
        p = doc.add_paragraph()
        p.add_run(f'{step}: ').bold = True
        p.add_run(description)
    
    doc.add_heading('2.3 Componentes Principales', 2)
    
    components = [
        ['Componente', 'Descripción', 'Tecnología'],
        ['Backend API', 'Servidor de aplicación y lógica de negocio', 'Laravel 12 (PHP 8.2)'],
        ['Frontend Dashboard', 'Interfaz de usuario para gestión', 'React 19 + TypeScript'],
        ['Base de Datos', 'Almacenamiento de datos', 'PostgreSQL / SQLite'],
        ['Cola de Trabajos', 'Procesamiento asíncrono de envíos', 'Laravel Queue + Redis'],
        ['Servicio de Email', 'Envío de correos electrónicos', 'AthenaCampaign API'],
        ['Servicio de SMS', 'Envío de mensajes de texto', 'API SMS externa'],
    ]
    
    add_table(doc, components[0], components[1:])
    
    doc.add_heading('2.4 Usuarios del Sistema', 2)
    users = [
        ['Rol', 'Permisos', 'Casos de Uso'],
        ['Super Admin', 'Acceso completo', 'Configuración, gestión de usuarios, operaciones'],
        ['Usuario', 'Lectura de prospectos y flujos', 'Consulta de datos y métricas'],
    ]
    add_table(doc, users[0], users[1:])
    
    doc.add_page_break()
    
    # =========================================================================
    # 3. ARQUITECTURA TÉCNICA
    # =========================================================================
    doc.add_heading('3. Arquitectura Técnica', 1)
    
    doc.add_heading('3.1 Arquitectura General', 2)
    doc.add_paragraph(
        'El sistema sigue una arquitectura de aplicación web moderna con separación '
        'clara entre frontend y backend, comunicándose a través de una API REST.'
    )
    
    # Diagrama en texto
    doc.add_paragraph('Diagrama de Arquitectura:')
    arch_diagram = """
    ┌─────────────────────────────────────────────────────────────┐
    │                     FRONTEND (React)                        │
    │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐          │
    │  │Dashboard│ │Prospectos│ │  Flujos │ │ Métricas│          │
    │  └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘          │
    └───────┼──────────┼──────────┼──────────┼──────────────────┘
            │          │          │          │
            └──────────┴──────────┴──────────┘
                           │
                    ┌──────┴──────┐
                    │  API REST   │
                    │ (Laravel)   │
                    └──────┬──────┘
                           │
    ┌─────────────────────────────────────────────────────────────┐
    │                     BACKEND (Laravel)                       │
    │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐          │
    │  │Controllers│ │Services │ │  Jobs   │ │ Models  │          │
    │  └────┬────┘ └────┬────┘ └────┬────┘ └────┬────┘          │
    └───────┼──────────┼──────────┼──────────┼──────────────────┘
            │          │          │          │
            └──────────┴──────────┴──────────┘
                           │
            ┌──────────────┼──────────────┐
            │              │              │
    ┌───────┴──────┐ ┌─────┴─────┐ ┌──────┴──────┐
    │  PostgreSQL  │ │   Redis   │ │External APIs│
    │  (Database)  │ │  (Queue)  │ │(Email/SMS)  │
    └──────────────┘ └───────────┘ └─────────────┘
    """
    
    p = doc.add_paragraph()
    p.style = 'Code' if 'Code' in [s.name for s in doc.styles] else 'Normal'
    p.add_run(arch_diagram)
    
    doc.add_heading('3.2 Stack Tecnológico', 2)
    
    doc.add_heading('3.2.1 Backend', 3)
    backend_stack = [
        ['Tecnología', 'Versión', 'Propósito'],
        ['PHP', '8.2+', 'Lenguaje de programación'],
        ['Laravel', '12.x', 'Framework web'],
        ['Laravel Sanctum', '4.x', 'Autenticación API'],
        ['Spatie Permission', '6.x', 'Control de acceso (RBAC)'],
        ['Laravel Queue', 'Integrado', 'Procesamiento asíncrono'],
    ]
    add_table(doc, backend_stack[0], backend_stack[1:])
    
    doc.add_heading('3.2.2 Frontend', 3)
    frontend_stack = [
        ['Tecnología', 'Versión', 'Propósito'],
        ['React', '19.x', 'Biblioteca de UI'],
        ['TypeScript', '5.x', 'Tipado estático'],
        ['Zustand', '5.x', 'Estado global'],
        ['React Query', '5.x', 'Estado del servidor'],
        ['Tailwind CSS', '4.x', 'Estilos'],
        ['Vite', '6.x', 'Build tool'],
    ]
    add_table(doc, frontend_stack[0], frontend_stack[1:])
    
    doc.add_heading('3.3 Estructura de Directorios', 2)
    
    doc.add_heading('3.3.1 Backend (Laravel)', 3)
    backend_dirs = """
nurturing-backend/
├── app/
│   ├── Console/           # Comandos Artisan
│   ├── Http/
│   │   ├── Controllers/   # Controladores API
│   │   ├── Middleware/    # Middlewares personalizados
│   │   └── Requests/      # Form Requests (validación)
│   ├── Jobs/              # Trabajos en cola (envíos)
│   ├── Models/            # Modelos Eloquent
│   ├── Services/          # Servicios de negocio
│   └── Enums/             # Enumeraciones
├── config/                # Configuración
├── database/
│   ├── migrations/        # Migraciones de BD
│   └── seeders/           # Datos iniciales
├── routes/
│   └── api.php            # Rutas de la API
└── tests/                 # Tests automatizados
"""
    p = doc.add_paragraph(backend_dirs)
    
    doc.add_heading('3.3.2 Frontend (React)', 3)
    frontend_dirs = """
nurturing-dashboard/
├── src/
│   ├── api/               # Servicios de API
│   ├── components/        # Componentes compartidos
│   ├── features/          # Módulos por funcionalidad
│   │   ├── auth/          # Autenticación
│   │   ├── flujos/        # Gestión de flujos
│   │   ├── prospectos/    # Gestión de prospectos
│   │   ├── envios/        # Historial de envíos
│   │   ├── plantillas/    # Plantillas de mensajes
│   │   └── metricas/      # Analytics y reportes
│   ├── hooks/             # Custom hooks
│   ├── stores/            # Estado global (Zustand)
│   └── types/             # Tipos TypeScript
└── tests/                 # Tests automatizados
"""
    p = doc.add_paragraph(frontend_dirs)
    
    doc.add_heading('3.4 Autenticación y Autorización', 2)
    doc.add_paragraph(
        'El sistema utiliza un esquema de autenticación basado en tokens con Laravel Sanctum:'
    )
    auth_features = [
        'Access Token: JWT de corta duración (60 minutos)',
        'Refresh Token: Token de larga duración (7 días) para renovación',
        'Tokens almacenados en cookies httpOnly (seguridad contra XSS)',
        'RBAC con Spatie Laravel Permission para control de acceso',
    ]
    for feature in auth_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 4. MODELO DE DATOS
    # =========================================================================
    doc.add_heading('4. Modelo de Datos', 1)
    
    doc.add_heading('4.1 Entidades Principales', 2)
    
    # Prospectos
    doc.add_heading('4.1.1 Prospectos', 3)
    doc.add_paragraph(
        'Representa a los leads o contactos que recibirán las comunicaciones.'
    )
    prospecto_fields = [
        ['Campo', 'Tipo', 'Descripción'],
        ['id', 'integer', 'Identificador único'],
        ['nombre', 'string', 'Nombre completo del prospecto'],
        ['email', 'string (nullable)', 'Correo electrónico'],
        ['telefono', 'string (nullable)', 'Teléfono con formato +56XXXXXXXXX'],
        ['rut', 'string (nullable)', 'RUT chileno'],
        ['monto_deuda', 'integer', 'Monto de deuda en pesos'],
        ['tipo_prospecto_id', 'foreign key', 'Categoría por monto'],
        ['importacion_id', 'foreign key', 'Importación de origen'],
        ['estado', 'enum', 'activo, inactivo, convertido, desuscrito, archivado'],
        ['email_invalido', 'boolean', 'Marca si el email rebotó'],
        ['metadata', 'json', 'Datos adicionales flexibles'],
    ]
    add_table(doc, prospecto_fields[0], prospecto_fields[1:])
    
    # Flujos
    doc.add_heading('4.1.2 Flujos', 3)
    doc.add_paragraph(
        'Define una secuencia de comunicaciones automatizadas.'
    )
    flujo_fields = [
        ['Campo', 'Tipo', 'Descripción'],
        ['id', 'integer', 'Identificador único'],
        ['nombre', 'string', 'Nombre descriptivo del flujo'],
        ['descripcion', 'text', 'Descripción detallada'],
        ['tipo_prospecto_id', 'foreign key', 'Tipo de prospectos objetivo'],
        ['origen', 'string', 'Origen de los prospectos (infocom, sysgal, etc.)'],
        ['canal_envio', 'enum', 'email, sms, ambos'],
        ['activo', 'boolean', 'Si el flujo está activo'],
        ['auto_asignar_nuevos', 'boolean', 'Asignar nuevos prospectos automáticamente'],
        ['config_visual', 'json', 'Configuración del editor visual (nodos/edges)'],
        ['config_structure', 'json', 'Estructura de etapas y condiciones'],
        ['metadata', 'json', 'Costos y configuración adicional'],
    ]
    add_table(doc, flujo_fields[0], flujo_fields[1:])
    
    # Envíos
    doc.add_heading('4.1.3 Envíos', 3)
    doc.add_paragraph(
        'Registra cada mensaje enviado a un prospecto.'
    )
    envio_fields = [
        ['Campo', 'Tipo', 'Descripción'],
        ['id', 'integer', 'Identificador único'],
        ['prospecto_id', 'foreign key', 'Prospecto destinatario'],
        ['flujo_id', 'foreign key', 'Flujo al que pertenece'],
        ['canal', 'enum', 'email o sms'],
        ['estado', 'enum', 'pendiente, enviado, abierto, clickeado, fallido'],
        ['asunto', 'string', 'Asunto del email (si aplica)'],
        ['contenido_enviado', 'text', 'Contenido del mensaje'],
        ['destinatario', 'string', 'Email o teléfono del destinatario'],
        ['tracking_token', 'string', 'Token único para tracking'],
        ['fecha_programada', 'datetime', 'Fecha programada de envío'],
        ['fecha_enviado', 'datetime', 'Fecha real de envío'],
        ['fecha_abierto', 'datetime', 'Primera apertura'],
        ['fecha_clickeado', 'datetime', 'Primer click'],
    ]
    add_table(doc, envio_fields[0], envio_fields[1:])
    
    doc.add_heading('4.2 Entidades de Soporte', 2)
    
    support_entities = [
        ['Entidad', 'Descripción'],
        ['TipoProspecto', 'Categorías por rango de monto (ej: 0-1M, 1M-5M, 5M+)'],
        ['Importacion', 'Registro de archivos importados con prospectos'],
        ['Lote', 'Agrupación de importaciones'],
        ['Plantilla', 'Templates reutilizables de email y SMS'],
        ['FlujoEjecucion', 'Instancia de ejecución de un flujo'],
        ['FlujoEjecucionEtapa', 'Estado de cada etapa en una ejecución'],
        ['FlujoCondicion', 'Condiciones de ramificación en flujos'],
        ['EmailApertura', 'Registro de cada apertura de email'],
        ['EmailClick', 'Registro de cada click en enlaces'],
        ['Desuscripcion', 'Registro de prospectos desuscritos'],
        ['EnvioMensual', 'Agregados mensuales para reportes'],
    ]
    add_table(doc, support_entities[0], support_entities[1:])
    
    doc.add_heading('4.3 Diagrama de Relaciones', 2)
    doc.add_paragraph('Relaciones principales entre entidades:')
    
    relations_diagram = """
    ┌──────────────┐         ┌──────────────┐
    │ Importacion  │◄────────│    Lote      │
    └──────┬───────┘         └──────────────┘
           │
           │ 1:N
           ▼
    ┌──────────────┐         ┌──────────────┐
    │  Prospecto   │────────►│TipoProspecto │
    └──────┬───────┘   N:1   └──────────────┘
           │
           │ N:M (via ProspectoEnFlujo)
           ▼
    ┌──────────────┐         ┌──────────────┐
    │    Flujo     │────────►│TipoProspecto │
    └──────┬───────┘   N:1   └──────────────┘
           │
           │ 1:N
           ▼
    ┌──────────────┐         ┌──────────────┐
    │FlujoEjecucion│────────►│FlujoEjecEtapa│
    └──────┬───────┘   1:N   └──────────────┘
           │
           │ 1:N
           ▼
    ┌──────────────┐
    │    Envio     │
    └──────┬───────┘
           │ 1:N
           ▼
    ┌──────────────┐    ┌──────────────┐
    │EmailApertura │    │  EmailClick  │
    └──────────────┘    └──────────────┘
    """
    p = doc.add_paragraph(relations_diagram)
    
    doc.add_page_break()
    
    # =========================================================================
    # 5. FUNCIONALIDADES DEL SISTEMA
    # =========================================================================
    doc.add_heading('5. Funcionalidades del Sistema', 1)
    
    doc.add_heading('5.1 Dashboard', 2)
    doc.add_paragraph(
        'Pantalla principal que muestra un resumen ejecutivo del sistema:'
    )
    dashboard_features = [
        'Total de prospectos activos',
        'Envíos del día (emails y SMS)',
        'Tasas de apertura y click',
        'Flujos activos en ejecución',
        'Costos acumulados del mes',
        'Gráficos de tendencias',
    ]
    for feature in dashboard_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_heading('5.2 Gestión de Prospectos', 2)
    doc.add_paragraph('Módulo para administrar la base de contactos:')
    
    prospecto_features = [
        ('Listado y búsqueda', 'Vista tabular con filtros por origen, tipo, estado'),
        ('Importación', 'Carga masiva desde archivos Excel'),
        ('Detalle', 'Ficha completa con historial de comunicaciones'),
        ('Calidad de emails', 'Identificación de emails inválidos'),
        ('Desuscripciones', 'Gestión de prospectos que solicitan baja'),
    ]
    for name, desc in prospecto_features:
        p = doc.add_paragraph()
        p.add_run(f'{name}: ').bold = True
        p.add_run(desc)
    
    doc.add_heading('5.3 Gestión de Flujos', 2)
    doc.add_paragraph('Módulo central para crear y administrar flujos de nurturing:')
    
    doc.add_heading('5.3.1 Editor Visual de Flujos', 3)
    doc.add_paragraph(
        'Interfaz drag-and-drop para diseñar flujos con nodos conectados:'
    )
    flow_nodes = [
        ('Nodo de Etapa', 'Representa un envío de email o SMS'),
        ('Nodo de Condición', 'Ramificación basada en comportamiento (abrió/no abrió)'),
        ('Nodo Final', 'Marca la finalización del flujo (éxito o abandono)'),
        ('Conexiones', 'Definen el orden y las transiciones entre nodos'),
    ]
    for name, desc in flow_nodes:
        p = doc.add_paragraph()
        p.add_run(f'{name}: ').bold = True
        p.add_run(desc)
    
    doc.add_heading('5.3.2 Estadísticas de Flujos', 3)
    doc.add_paragraph('Panel de analytics para cada flujo:')
    stats_features = [
        'Funnel de conversión (prospectos → enviados → abiertos → clicks → conversión)',
        'Tasa de apertura y click por etapa',
        'Costo por conversión',
        'Prospectos completados vs en proceso vs cancelados',
        'Rendimiento por etapa con gráficos de barras',
    ]
    for feature in stats_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_heading('5.4 Ejecución de Flujos', 2)
    doc.add_paragraph('Sistema de ejecución automatizada:')
    
    execution_features = [
        ('Inicio manual', 'El usuario puede iniciar la ejecución de un flujo'),
        ('Programación', 'Los envíos se programan según el día de cada etapa'),
        ('Procesamiento en cola', 'Los envíos se procesan de forma asíncrona'),
        ('Pausar/Reanudar', 'Control sobre ejecuciones activas'),
        ('Monitoreo en tiempo real', 'Vista del progreso de la ejecución'),
    ]
    for name, desc in execution_features:
        p = doc.add_paragraph()
        p.add_run(f'{name}: ').bold = True
        p.add_run(desc)
    
    doc.add_heading('5.5 Plantillas de Mensajes', 2)
    doc.add_paragraph('Sistema de templates reutilizables:')
    
    template_features = [
        'Editor de email con variables dinámicas ({{nombre}}, {{monto_deuda}})',
        'Editor de SMS con contador de caracteres',
        'Vista previa antes del envío',
        'Versionado de plantillas',
    ]
    for feature in template_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_heading('5.6 Historial de Envíos', 2)
    doc.add_paragraph(
        'Registro completo de todas las comunicaciones enviadas con filtros '
        'por fecha, estado, canal y flujo. Incluye detalle del contenido enviado '
        'y tracking de interacciones.'
    )
    
    doc.add_heading('5.7 Métricas y Reportes', 2)
    doc.add_paragraph('Dashboard de analytics con:')
    
    metrics_features = [
        'Resumen de envíos por período',
        'Tasas de apertura y click históricas',
        'Top flujos por rendimiento',
        'Tendencias de engagement',
        'Desuscripciones por período',
        'Costos acumulados por flujo',
    ]
    for feature in metrics_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_heading('5.8 Monitoreo del Sistema', 2)
    doc.add_paragraph('Panel técnico para supervisar:')
    
    monitor_features = [
        'Estado de la cola de trabajos',
        'Circuit breaker para servicios externos',
        'Rate limits y throttling',
        'Jobs fallidos con opción de reintento',
        'Health checks del sistema',
    ]
    for feature in monitor_features:
        doc.add_paragraph(f'• {feature}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 6. API REST - ENDPOINTS
    # =========================================================================
    doc.add_heading('6. API REST - Endpoints', 1)
    
    doc.add_heading('6.1 Autenticación', 2)
    doc.add_paragraph('Endpoints públicos para gestión de sesión:')
    
    auth_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['POST', '/api/login', 'Iniciar sesión'],
        ['POST', '/api/register', 'Registrar nuevo usuario'],
        ['POST', '/api/logout', 'Cerrar sesión (requiere auth)'],
        ['GET', '/api/me', 'Obtener usuario actual (requiere auth)'],
    ]
    add_table(doc, auth_endpoints[0], auth_endpoints[1:])
    
    doc.add_heading('6.2 Prospectos', 2)
    prospecto_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/prospectos', 'Listar prospectos (paginado)'],
        ['GET', '/api/prospectos/{id}', 'Obtener detalle de prospecto'],
        ['POST', '/api/prospectos', 'Crear prospecto'],
        ['PUT', '/api/prospectos/{id}', 'Actualizar prospecto'],
        ['DELETE', '/api/prospectos/{id}', 'Eliminar prospecto'],
        ['GET', '/api/prospectos/estadisticas', 'Estadísticas generales'],
        ['GET', '/api/prospectos/calidad-emails', 'Análisis de calidad de emails'],
        ['GET', '/api/prospectos/opciones-filtrado', 'Opciones para filtros'],
    ]
    add_table(doc, prospecto_endpoints[0], prospecto_endpoints[1:])
    
    doc.add_heading('6.3 Flujos', 2)
    flujo_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/flujos', 'Listar flujos'],
        ['GET', '/api/flujos/{id}', 'Obtener flujo con estadísticas'],
        ['POST', '/api/flujos', 'Crear flujo'],
        ['PUT', '/api/flujos/{id}', 'Actualizar flujo'],
        ['DELETE', '/api/flujos/{id}', 'Eliminar flujo'],
        ['GET', '/api/flujos/{id}/estadisticas-nodos', 'Stats por nodo'],
        ['GET', '/api/flujos/{id}/estadisticas-completas', 'Analytics completas'],
        ['POST', '/api/flujos/{id}/agregar-prospectos', 'Agregar prospectos al flujo'],
        ['GET', '/api/flujos/estadisticas-costos', 'Costos de todos los flujos'],
    ]
    add_table(doc, flujo_endpoints[0], flujo_endpoints[1:])
    
    doc.add_heading('6.4 Ejecuciones', 2)
    ejecucion_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['POST', '/api/flujos/{id}/ejecutar', 'Iniciar ejecución'],
        ['GET', '/api/flujos/{id}/ejecuciones', 'Listar ejecuciones'],
        ['GET', '/api/flujos/{id}/ejecuciones/activa', 'Ejecución activa actual'],
        ['GET', '/api/flujos/{id}/ejecuciones/{eid}', 'Detalle de ejecución'],
        ['POST', '/api/flujos/{id}/ejecuciones/{eid}/pausar', 'Pausar ejecución'],
        ['POST', '/api/flujos/{id}/ejecuciones/{eid}/reanudar', 'Reanudar ejecución'],
        ['DELETE', '/api/flujos/{id}/ejecuciones/{eid}', 'Cancelar ejecución'],
    ]
    add_table(doc, ejecucion_endpoints[0], ejecucion_endpoints[1:])
    
    doc.add_heading('6.5 Envíos', 2)
    envio_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/envios', 'Listar envíos (paginado)'],
        ['GET', '/api/envios/{id}', 'Detalle de envío'],
        ['GET', '/api/envios/estadisticas', 'Estadísticas globales'],
        ['GET', '/api/envios/estadisticas/hoy', 'Estadísticas del día'],
    ]
    add_table(doc, envio_endpoints[0], envio_endpoints[1:])
    
    doc.add_heading('6.6 Plantillas', 2)
    plantilla_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/plantillas', 'Listar plantillas'],
        ['GET', '/api/plantillas/{id}', 'Obtener plantilla'],
        ['POST', '/api/plantillas/email', 'Crear plantilla de email'],
        ['POST', '/api/plantillas/sms', 'Crear plantilla de SMS'],
        ['PUT', '/api/plantillas/{id}', 'Actualizar plantilla'],
        ['DELETE', '/api/plantillas/{id}', 'Eliminar plantilla'],
        ['POST', '/api/plantillas/preview/email', 'Preview de email'],
    ]
    add_table(doc, plantilla_endpoints[0], plantilla_endpoints[1:])
    
    doc.add_heading('6.7 Métricas', 2)
    metricas_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/metricas/dashboard', 'Dashboard completo'],
        ['GET', '/api/metricas/resumen', 'Resumen ejecutivo'],
        ['GET', '/api/metricas/aperturas', 'Métricas de aperturas'],
        ['GET', '/api/metricas/clicks', 'Métricas de clicks'],
        ['GET', '/api/metricas/tendencias', 'Tendencias históricas'],
        ['GET', '/api/metricas/top-flujos', 'Top flujos por rendimiento'],
    ]
    add_table(doc, metricas_endpoints[0], metricas_endpoints[1:])
    
    doc.add_heading('6.8 Importaciones', 2)
    import_endpoints = [
        ['Método', 'Endpoint', 'Descripción'],
        ['GET', '/api/importaciones', 'Listar importaciones'],
        ['POST', '/api/importaciones', 'Subir archivo de prospectos'],
        ['GET', '/api/importaciones/{id}', 'Estado de importación'],
        ['GET', '/api/importaciones/{id}/progreso', 'Progreso en tiempo real'],
        ['POST', '/api/importaciones/{id}/retry', 'Reintentar importación fallida'],
    ]
    add_table(doc, import_endpoints[0], import_endpoints[1:])
    
    doc.add_heading('6.9 Códigos de Respuesta', 2)
    response_codes = [
        ['Código', 'Significado', 'Uso'],
        ['200', 'OK', 'Solicitud exitosa'],
        ['201', 'Created', 'Recurso creado'],
        ['400', 'Bad Request', 'Error de validación'],
        ['401', 'Unauthorized', 'Token inválido o expirado'],
        ['403', 'Forbidden', 'Sin permisos'],
        ['404', 'Not Found', 'Recurso no encontrado'],
        ['422', 'Unprocessable', 'Error de validación (Laravel)'],
        ['429', 'Too Many Requests', 'Rate limit excedido'],
        ['500', 'Server Error', 'Error interno'],
    ]
    add_table(doc, response_codes[0], response_codes[1:])
    
    doc.add_page_break()
    
    # =========================================================================
    # 7. GUÍA DE OPERACIONES
    # =========================================================================
    doc.add_heading('7. Guía de Operaciones', 1)
    
    doc.add_heading('7.1 Requisitos del Sistema', 2)
    requirements = [
        ['Componente', 'Requisito Mínimo', 'Recomendado'],
        ['PHP', '8.2', '8.3'],
        ['Node.js', '18.x', '20.x LTS'],
        ['PostgreSQL', '14', '16'],
        ['Redis', '6.x', '7.x'],
        ['Memoria RAM', '2 GB', '4 GB'],
        ['Almacenamiento', '10 GB', '50 GB'],
    ]
    add_table(doc, requirements[0], requirements[1:])
    
    doc.add_heading('7.2 Instalación', 2)
    
    doc.add_heading('7.2.1 Backend', 3)
    install_backend = """
# Clonar repositorio
git clone <repo-url> nurturing-backend
cd nurturing-backend

# Instalar dependencias
composer install

# Configurar entorno
cp .env.example .env
php artisan key:generate

# Configurar base de datos en .env
# DB_CONNECTION=pgsql
# DB_HOST=localhost
# DB_DATABASE=nurturing
# etc.

# Ejecutar migraciones
php artisan migrate --seed

# Iniciar servidor de desarrollo
composer run dev
"""
    doc.add_paragraph(install_backend)
    
    doc.add_heading('7.2.2 Frontend', 3)
    install_frontend = """
# Clonar repositorio
git clone <repo-url> nurturing-dashboard
cd nurturing-dashboard

# Instalar dependencias
npm install

# Configurar entorno
cp .env.example .env
# VITE_API_URL=http://localhost:8000/api

# Iniciar servidor de desarrollo
npm run dev

# Build para producción
npm run build
"""
    doc.add_paragraph(install_frontend)
    
    doc.add_heading('7.3 Variables de Entorno', 2)
    
    doc.add_heading('7.3.1 Backend (.env)', 3)
    env_vars = [
        ['Variable', 'Descripción', 'Ejemplo'],
        ['APP_ENV', 'Entorno de ejecución', 'production'],
        ['APP_KEY', 'Clave de encriptación', 'base64:...'],
        ['DB_CONNECTION', 'Driver de base de datos', 'pgsql'],
        ['DB_HOST', 'Host de la base de datos', 'localhost'],
        ['DB_DATABASE', 'Nombre de la base de datos', 'nurturing'],
        ['REDIS_HOST', 'Host de Redis', 'localhost'],
        ['SMS_API_TOKEN', 'Token del servicio SMS', 'sk_...'],
        ['ATHENACAMPAIGN_API_KEY', 'API Key de email', 'ak_...'],
        ['ATHENACAMPAIGN_BASE_URL', 'URL del servicio email', 'https://api...'],
    ]
    add_table(doc, env_vars[0], env_vars[1:])
    
    doc.add_heading('7.4 Comandos Útiles', 2)
    
    commands = [
        ['Comando', 'Descripción'],
        ['php artisan serve', 'Iniciar servidor de desarrollo'],
        ['php artisan queue:listen', 'Procesar cola de trabajos'],
        ['php artisan migrate', 'Ejecutar migraciones pendientes'],
        ['php artisan db:seed', 'Cargar datos iniciales'],
        ['php artisan test', 'Ejecutar tests automatizados'],
        ['vendor/bin/pint', 'Formatear código PHP'],
        ['npm run dev', 'Servidor de desarrollo frontend'],
        ['npm run build', 'Build de producción frontend'],
        ['npm test', 'Ejecutar tests frontend'],
    ]
    add_table(doc, commands[0], commands[1:])
    
    doc.add_heading('7.5 Despliegue', 2)
    doc.add_paragraph(
        'El sistema está configurado para despliegue automático mediante CI/CD. '
        'Al hacer push a la rama staging, se despliega automáticamente al ambiente '
        'de staging.'
    )
    
    deployment_steps = [
        'Asegurar que todos los tests pasen localmente',
        'Crear commit con mensaje descriptivo',
        'Push a la rama staging',
        'El CI/CD ejecuta tests y despliega automáticamente',
        'Verificar el despliegue en el ambiente de staging',
        'Realizar merge a main para producción',
    ]
    for i, step in enumerate(deployment_steps, 1):
        doc.add_paragraph(f'{i}. {step}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 8. SEGURIDAD
    # =========================================================================
    doc.add_heading('8. Seguridad', 1)
    
    doc.add_heading('8.1 Autenticación', 2)
    security_auth = [
        'Tokens JWT con expiración configurable',
        'Refresh tokens con rotación automática',
        'Almacenamiento en cookies httpOnly (protección XSS)',
        'Atributo SameSite para protección CSRF',
        'Rate limiting en endpoints de login (5 req/min)',
    ]
    for item in security_auth:
        doc.add_paragraph(f'• {item}')
    
    doc.add_heading('8.2 Autorización', 2)
    security_authz = [
        'Control de acceso basado en roles (RBAC)',
        'Verificación de permisos en cada endpoint',
        'Middleware de autenticación en rutas protegidas',
        'Separación de roles: super_admin vs usuario',
    ]
    for item in security_authz:
        doc.add_paragraph(f'• {item}')
    
    doc.add_heading('8.3 Protección de Datos', 2)
    data_protection = [
        'Validación de entrada en todos los endpoints (Form Requests)',
        'Escape de salida para prevenir XSS',
        'Queries parametrizadas (Eloquent ORM) para prevenir SQL Injection',
        'Encriptación de datos sensibles en la base de datos',
        'HTTPS obligatorio en producción',
    ]
    for item in data_protection:
        doc.add_paragraph(f'• {item}')
    
    doc.add_heading('8.4 Rate Limiting', 2)
    rate_limits = [
        ['Grupo', 'Límite', 'Endpoints'],
        ['auth', '5 req/min', 'Login, Register'],
        ['api', '60 req/min', 'Endpoints generales'],
        ['heavy', '10 req/min', 'Dashboard, Métricas'],
        ['cron', 'Sin límite', 'Endpoints internos'],
    ]
    add_table(doc, rate_limits[0], rate_limits[1:])
    
    doc.add_heading('8.5 Cumplimiento', 2)
    doc.add_paragraph('El sistema implementa:')
    compliance = [
        'Sistema de desuscripción para cumplir con regulaciones de email marketing',
        'Tracking de consentimiento de comunicaciones',
        'Logs de auditoría de acciones críticas',
        'Gestión de emails inválidos (bounces)',
    ]
    for item in compliance:
        doc.add_paragraph(f'• {item}')
    
    doc.add_page_break()
    
    # =========================================================================
    # 9. GLOSARIO
    # =========================================================================
    doc.add_heading('9. Glosario', 1)
    
    glossary = [
        ['Término', 'Definición'],
        ['Prospecto', 'Lead o contacto potencial que recibe comunicaciones'],
        ['Flujo', 'Secuencia automatizada de mensajes de nurturing'],
        ['Etapa', 'Paso individual dentro de un flujo (email o SMS)'],
        ['Ejecución', 'Instancia activa de un flujo procesando prospectos'],
        ['Nurturing', 'Proceso de cultivar relación con leads mediante comunicaciones'],
        ['Tracking', 'Seguimiento de aperturas y clicks en emails'],
        ['Bounce', 'Email que no pudo ser entregado al destinatario'],
        ['Desuscripción', 'Solicitud de un prospecto para no recibir más comunicaciones'],
        ['Tasa de apertura', 'Porcentaje de emails abiertos sobre enviados'],
        ['Tasa de click', 'Porcentaje de clicks sobre emails abiertos'],
        ['CTR (Click Through Rate)', 'Tasa de click sobre total de enviados'],
        ['Conversión', 'Prospecto que completó el flujo exitosamente'],
        ['Canal', 'Medio de comunicación (email o SMS)'],
        ['Plantilla', 'Template reutilizable de mensaje con variables'],
        ['Importación', 'Proceso de carga masiva de prospectos desde archivo'],
        ['Lote', 'Agrupación de importaciones relacionadas'],
        ['API', 'Application Programming Interface - interfaz de programación'],
        ['JWT', 'JSON Web Token - formato de token de autenticación'],
        ['RBAC', 'Role-Based Access Control - control de acceso basado en roles'],
    ]
    add_table(doc, glossary[0], glossary[1:])
    
    # =========================================================================
    # PIE DE PÁGINA - CONTROL DE VERSIONES
    # =========================================================================
    doc.add_page_break()
    doc.add_heading('Control de Versiones del Documento', 1)
    
    version_control = [
        ['Versión', 'Fecha', 'Autor', 'Descripción'],
        ['1.0', datetime.now().strftime('%Y-%m-%d'), 'Equipo de Desarrollo', 'Versión inicial del documento'],
    ]
    add_table(doc, version_control[0], version_control[1:])
    
    doc.add_paragraph()
    doc.add_paragraph('Documento generado automáticamente.')
    
    # Guardar documento
    output_dir = os.path.dirname(os.path.abspath(__file__))
    output_path = os.path.join(output_dir, 'Sistema_Nurturing_Documentacion_v1.0.docx')
    doc.save(output_path)
    print(f'Documento generado exitosamente: {output_path}')
    return output_path

if __name__ == '__main__':
    generate_documentation()
