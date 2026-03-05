# 🚀 MEJORAS SISTEMA NURTURING - DOCUMENTO COMPLETO

## Resumen Ejecutivo

Este documento detalla 10 mejoras para el sistema de nurturing de Grupo Segal, identificando dónde la Inteligencia Artificial puede tener mayor impacto.

### Tipos de IA a utilizar:

| Tipo | Qué es | Para qué sirve | Costo |
|------|--------|----------------|-------|
| **ML Tradicional** | Modelos matemáticos entrenados con datos propios | Scoring, predicciones, clasificación masiva | ~$0 (servidor propio) |
| **LLM (Claude/OpenAI)** | Modelos de lenguaje | Generar contenido, decisiones complejas, análisis | ~$0.01-0.05/consulta |

---

## 1. 🧠 LEAD SCORING — ¿Quién está más cerca de comprar?

### El problema hoy
Envían las mismas ofertas a 300k+ prospectos sin saber quién tiene interés y quién no. Gastan plata enviándole emails a gente que nunca los abre.

### Solución sin IA
Sistema de puntos fijos por acción:

| Acción | Puntos |
|--------|--------|
| Abrió email | +5 |
| Clickeó enlace de oferta | +15 |
| Abrió 3+ emails en una semana | +25 |
| Clickeó en 2+ ofertas distintas | +30 |
| Respondió SMS (si se puede trackear) | +20 |
| No abrió ningún email en 15 días | -10 |
| No abrió en 30 días | -25 |
| Se desuscribió | -100 |

### 🤖 Solución con IA

#### Capa 1: ML Tradicional (Modelo Predictivo)
```
Modelo: Random Forest / XGBoost
Entrenamiento: Prospectos que SÍ convirtieron en clientes
Features:
  - tasa_apertura_personal
  - tasa_clicks_personal
  - dias_desde_ultima_apertura
  - total_emails_recibidos
  - hora_promedio_apertura
  - monto_deuda
  - tipo_prospecto
  - origen (IC, Sysgal, etc.)
  - dias_en_sistema

Output: probabilidad_conversion (0.0 - 1.0)
```

**Beneficio IA:** El modelo DESCUBRE qué combinaciones predicen conversión. Por ejemplo: "Abrir emails de noche + clickear 2+ veces + deuda > 5M = 78% probabilidad de comprar". Esto NO se puede descubrir con reglas fijas.

#### Capa 2: LLM (Claude) para Hot Leads
```php
// Cuando un prospecto llega a hot_lead, Claude recomienda acción
$prompt = "Este prospecto tiene score 85, abrió 10 emails, 
           clickeó oferta de refinanciamiento 3 veces.
           ¿Llamada directa, email con urgencia, o esperar?";

$recomendacion = Claude::ask($prompt);
```

**Beneficio IA:** Decisiones contextuales para los prospectos más valiosos.

### Arquitectura técnica
```
┌─────────────────────────────────────────────────────────────────┐
│                     DATOS DE ENTRADA                            │
├─────────────────────────────────────────────────────────────────┤
│  • Eventos: aperturas, clicks, envíos, desuscripciones          │
│  • Perfil: tipo_prospecto, monto_deuda, origen, antigüedad      │
│  • Histórico: emails recibidos, tasa apertura personal          │
│  • Temporal: hora de apertura, día de semana, frecuencia        │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   CAPA 1: SCORING POR REGLAS                    │
├─────────────────────────────────────────────────────────────────┤
│  Cálculo determinístico - Resultado: rule_score (0-100)         │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   CAPA 2: MODELO ML                             │
├─────────────────────────────────────────────────────────────────┤
│  Microservicio Python (FastAPI) con modelo entrenado            │
│  Output: probabilidad_conversion (0.0 - 1.0)                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   CAPA 3: LLM (HOT LEADS)                       │
├─────────────────────────────────────────────────────────────────┤
│  Claude API para recomendaciones personalizadas                 │
│  Solo para top 1-2% de prospectos                               │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                   SCORE FINAL + SEGMENTO                        │
├─────────────────────────────────────────────────────────────────┤
│  lead_score = (rule_score * 0.4) + (ml_score * 100 * 0.6)       │
│  Segmento: hot_lead | interesado | tibio | dormido | frio       │
└─────────────────────────────────────────────────────────────────┘
```

### Implementación técnica

#### Migración - Nuevos campos en `prospectos`:
```php
$table->integer('lead_score')->default(0);
$table->decimal('probabilidad_conversion', 5, 4)->nullable();
$table->string('segmento', 20)->default('nuevo');
$table->timestamp('lead_score_updated_at')->nullable();
```

#### Nueva tabla `prospecto_eventos`:
```php
$table->id();
$table->foreignId('prospecto_id');
$table->integer('emails_enviados_7d')->default(0);
$table->integer('emails_abiertos_7d')->default(0);
$table->integer('clicks_7d')->default(0);
$table->integer('emails_enviados_30d')->default(0);
$table->integer('emails_abiertos_30d')->default(0);
$table->integer('clicks_30d')->default(0);
$table->decimal('tasa_apertura_historica', 5, 4)->default(0);
$table->decimal('tasa_clicks_historica', 5, 4)->default(0);
$table->timestamp('ultima_apertura')->nullable();
$table->timestamp('ultimo_click')->nullable();
```

#### Servicio LeadScoringService:
```php
class LeadScoringService
{
    public function calcularScore(Prospecto $prospecto): array
    {
        // Capa 1: Reglas
        $ruleScore = $this->calcularRuleScore($prospecto);
        
        // Capa 2: ML (llamada a microservicio Python)
        $mlScore = $this->mlService->predict($prospecto->getFeatures());
        
        // Combinación
        $finalScore = ($ruleScore * 0.4) + ($mlScore * 100 * 0.6);
        $segmento = $this->determinarSegmento($finalScore);
        
        return [
            'lead_score' => $finalScore,
            'probabilidad_conversion' => $mlScore,
            'segmento' => $segmento,
        ];
    }
    
    private function determinarSegmento(float $score): string
    {
        return match(true) {
            $score >= 80 => 'hot_lead',
            $score >= 60 => 'interesado',
            $score >= 40 => 'tibio',
            $score >= 20 => 'dormido',
            default => 'frio',
        };
    }
}
```

### Beneficio directo
- Dejan de enviar a los que NUNCA van a comprar → ahorro en costos de envío
- Concentran esfuerzos en los interesados → más conversiones
- Pueden pasarle al equipo comercial los "hot leads" para llamada directa

### Tiempo estimado
- Sin IA: 1 semana
- Con IA (ML): 2-3 semanas
- Con IA (ML + LLM): 3-4 semanas

### Dependencias
- Data de conversiones (qué prospectos se convirtieron en clientes) para entrenar el modelo ML

---

## 2. 📊 SEGMENTACIÓN AUTOMÁTICA — Tratar distinto a cada uno

### El problema hoy
Todos reciben lo mismo. El que abrió 10 emails recibe la misma oferta que el que nunca abrió nada.

### Solución sin IA
Clasificación por reglas fijas en 5 segmentos:

| Segmento | Quién es | Qué hacer con ellos |
|----------|----------|---------------------|
| 🔥 Hot Lead | Abre Y clickea seguido | Oferta directa, urgencia, o pasarlo a un ejecutivo |
| 👀 Interesado | Abre pero no clickea | Cambiar el CTA, probar otra oferta, más visual |
| 😐 Tibio | Abrió alguna vez, después nada | Re-engagement: "¿Todavía te interesa?" |
| 😴 Dormido | No abre hace 30+ días | Último intento con asunto disruptivo o SMS |
| ❄️ Frío | Nunca abrió nada | EXCLUIR de envíos → ahorro directo |

### 🤖 Solución con IA

#### ML: Clustering automático (K-Means / DBSCAN)
```python
# En vez de 5 segmentos predefinidos, el modelo DESCUBRE segmentos
from sklearn.cluster import KMeans

features = [
    'tasa_apertura',
    'tasa_clicks', 
    'hora_promedio_apertura',
    'dias_desde_registro',
    'monto_deuda',
    'frecuencia_interaccion'
]

# El modelo encuentra grupos naturales en los datos
kmeans = KMeans(n_clusters=6)  # Puede descubrir 6 segmentos distintos
segmentos = kmeans.fit_predict(prospectos_features)
```

**Segmentos que podría descubrir:**
- "Profesionales nocturnos" - Abren solo después de las 9pm desde móvil
- "Curiosos sin intención" - Abren todo pero nunca clickean
- "Indecisos" - Abren la misma oferta 5+ veces sin decidir
- "Compradores rápidos" - Clickean en menos de 1 hora desde el envío

**Beneficio IA:** Descubre segmentos que NO imaginabas, basados en patrones reales de comportamiento.

#### LLM: Análisis y naming de segmentos
```php
$prompt = "Analicé los datos y encontré un grupo de prospectos con estas características:
- Abren emails solo entre 6-8am
- 90% desde dispositivo móvil
- Clickean en ofertas de 'ahorro' pero no en 'premium'
- Promedio de deuda: 3M

¿Qué nombre le pondrías a este segmento y qué estrategia recomiendas?";

$analisis = Claude::ask($prompt);
// "Segmento: 'Madrugadores Ahorradores'. Estrategia: Emails a las 6am 
// con ofertas de bajo costo y mensajes de ahorro/economía."
```

### Beneficio directo
- Los ❄️ Fríos (probablemente 30-40% de la lista) se excluyen → ahorro directo
- Los 🔥 Hot Leads reciben ofertas agresivas que convierten
- Cada segmento recibe contenido relevante para su nivel de interés

### Tiempo estimado
- Sin IA: 3-4 días
- Con IA (Clustering): 1-2 semanas

### Dependencias
- Lead Scoring implementado (Mejora #1)

---

## 3. ⏰ SEND TIME OPTIMIZATION — Enviar cuando abren

### El problema hoy
Los envíos salen cuando se ejecuta el flujo, sin importar la hora. Si se ejecuta a las 3AM, el email llega a las 3AM y queda enterrado.

### Solución sin IA

#### Nivel simple: Ventana horaria por flujo
```php
// En la configuración del flujo
$flujo->ventana_envio_inicio = '10:00';
$flujo->ventana_envio_fin = '12:00';
$flujo->zona_horaria = 'America/Santiago';
```

#### Nivel medio: Análisis de mejor horario global
```sql
-- Encuentra las horas con mejor tasa de apertura
SELECT 
    HOUR(opened_at) as hora,
    COUNT(*) as aperturas,
    AVG(TIMESTAMPDIFF(MINUTE, sent_at, opened_at)) as minutos_hasta_apertura
FROM envios
WHERE opened_at IS NOT NULL
GROUP BY HOUR(opened_at)
ORDER BY aperturas DESC;
```

### 🤖 Solución con IA

#### ML: Hora óptima POR PROSPECTO
```python
# Modelo que predice la mejor hora para CADA prospecto
class SendTimePredictor:
    def predict_best_hour(self, prospecto_features):
        # Features: historial de aperturas, zona horaria inferida, 
        # tipo de dispositivo, día de la semana
        
        # Output: hora óptima (0-23) con probabilidad de apertura
        return {
            'hora_optima': 19,  # 7pm
            'probabilidad_apertura': 0.45,
            'segunda_mejor_hora': 8,  # 8am
        }
```

**Ejemplo de personalización:**
- Juan abre emails a las 7am (viaja en metro) → enviar 6:50am
- María abre a las 10pm (después de acostar a los niños) → enviar 9:50pm
- Pedro abre al mediodía (pausa de almuerzo) → enviar 12:00pm

### Beneficio directo
- Mejora tasa de apertura entre 15-30% (según estudios de Mailchimp)
- Más aperturas = más gente viendo las ofertas = más ventas

### Tiempo estimado
- Sin IA (ventanas fijas): 3-4 días
- Con IA (por prospecto): 1-2 semanas

### Dependencias
- Datos históricos de aperturas con timestamp

---

## 4. 🔄 CROSS-CHANNEL — Si no abre email, le mando SMS

### El problema hoy
Si un prospecto no abre el email con la oferta, no pasa nada. Se perdió.

### Solución sin IA
Patrón automático en los flujos usando condiciones existentes:

```
Email con oferta
    ↓
[Esperar 24h]
    ↓
Condición: ¿Abrió el email?
    ├── Sí → Siguiente etapa normal
    └── No → SMS: "Tenemos una oferta para vos, mirá: [link]"
```

### 🤖 Solución con IA

#### ML: Predicción de canal preferido
```python
# Modelo que predice qué canal funciona mejor para cada prospecto
class ChannelPredictor:
    def predict_best_channel(self, prospecto):
        return {
            'canal_primario': 'sms',      # 65% probabilidad respuesta
            'canal_secundario': 'email',   # 20% probabilidad
            'canal_evitar': 'whatsapp',    # 5% - casi nunca responde
        }
```

### Beneficio directo
- Recupera 25-40% de prospectos que ignoraron el email pero SÍ leen SMS
- Maximiza el alcance de cada campaña

### Tiempo estimado
- Sin IA: 4-5 días (usando condiciones existentes)
- Con IA: 1-2 semanas adicionales

### Dependencias
- Sistema de condiciones en flujos (ya existe)
- Tracking de aperturas (ya existe)

---

## 5. 📈 DASHBOARD DE CONVERSIONES — ¿Está funcionando o no?

### El problema hoy
Saben cuántos emails enviaron y cuántos se abrieron, pero NO saben cuántos prospectos se convirtieron en clientes.

### Solución sin IA

#### Opciones para registrar conversiones:

**Opción A: Importación CSV**
```php
POST /api/conversiones/importar
// El sistema cruza con prospectos y marca como convertidos
```

**Opción B: API/Webhook desde sistema de ventas**
```php
POST /api/webhooks/conversion
{
    "rut": "12345678-9",
    "fecha": "2026-02-20",
    "monto": 150000,
    "producto": "Informe Comercial Premium"
}
```

**Opción C: Botón manual**
En el timeline del prospecto → Botón "Marcar como convertido"

### 🤖 Solución con IA

#### ML: Predicción de conversión
```python
# Predice qué prospectos VAN a convertir en los próximos 30 días
class ConversionPredictor:
    def predict_conversions(self, prospectos):
        return [
            {'prospecto_id': 123, 'probabilidad': 0.82, 'dias_estimados': 5},
            {'prospecto_id': 456, 'probabilidad': 0.67, 'dias_estimados': 12},
        ]
```

### Beneficio directo
- Pueden responder "¿este sistema nos sirve?"
- Identifican qué flujos generan más ventas
- Calculan ROI real: "cada $1.000 invertido genera $15.000"

### Tiempo estimado
- Sin IA: 1-2 semanas
- Con IA (predicción): 2-3 semanas adicionales

### Dependencias
- Acceso a datos de conversiones (CSV, API, o manual)

---

## 6. 🧪 A/B TESTING — ¿Qué oferta funciona mejor?

### El problema hoy
Mandan UNA versión del email. Si no funciona, no saben por qué.

### Solución sin IA
Configuración de variantes en etapa (50% A, 50% B), medir resultados.

### 🤖 Solución con IA

#### ML: Multi-Armed Bandit (Optimización automática)
```python
# En vez de esperar al final, ajusta en TIEMPO REAL
class MultiArmedBandit:
    def seleccionar_variante(self):
        # Thompson Sampling: balancea exploración vs explotación
        # Automáticamente envía más a la variante ganadora
```

**Cómo funciona:**
1. Empieza 50/50 entre A y B
2. Después de 100 envíos, B tiene mejor apertura
3. Automáticamente ajusta a 30% A / 70% B
4. Minimiza "desperdicio" en la variante perdedora

### Beneficio directo
- Mejora continua automática
- No "desperdicia" envíos en variantes perdedoras

### Tiempo estimado
- Sin IA (A/B simple): 1 semana
- Con IA (Multi-Armed Bandit): 2 semanas adicionales

---

## 7. 📋 TIMELINE 360° — Todo sobre un prospecto en un lugar

### El problema hoy
Si alguien de Segal quiere saber qué pasó con un prospecto, tiene que buscar en varias partes.

### Solución sin IA
Vista cronológica de todos los eventos del prospecto.

### 🤖 Solución con IA

#### LLM: Resumen ejecutivo para el comercial
```php
$prompt = "Este es el timeline de un prospecto: [eventos]
Genera un resumen de 3 líneas para un ejecutivo comercial que va a llamarlo.";

// "Juan abre emails al mediodía y clickeó 3 veces en refinanciamiento.
// Parece interesado pero no decide. Recomiendo llamar ofreciendo 
// descuento adicional por decisión inmediata."
```

### Beneficio directo
- Contexto total para cerrar la venta
- Ya tienen TODA la data, solo falta la vista

### Tiempo estimado
- Sin IA: 4-5 días
- Con IA (resumen): 2-3 días adicionales

---

## 8. 🔔 TRIGGERS AUTOMÁTICOS — Flujos que se disparan solos

### El problema hoy
Todo se ejecuta manualmente.

### Solución sin IA
Triggers por eventos: prospecto_creado, click, inactividad, etc.

### 🤖 Solución con IA

#### ML: Triggers predictivos
```python
# PREDICE quién se va a dormir antes de que pase
class ChurnPredictor:
    def predict_churn_risk(self, prospecto):
        return {
            'probabilidad_churn': 0.73,
            'dias_hasta_churn': 5,
        }
# Trigger: Si churn_risk > 0.7 → Intervención preventiva
```

### Beneficio directo
- El sistema trabaja 24/7 sin intervención humana
- Reacción inmediata (o predictiva) a comportamientos

### Tiempo estimado
- Sin IA: 1-2 semanas
- Con IA (predictivo): 2-3 semanas adicionales

---

## 9. 🛡️ FREQUENCY CAPPING — No saturar

### El problema hoy
Un prospecto puede recibir 3 emails el mismo día de distintos flujos.

### Solución sin IA
Límites configurables: máx 2 emails/semana, 1 SMS/semana.

### 🤖 Solución con IA

#### ML: Límite personalizado por prospecto
```python
# El límite óptimo varía por persona
class FrequencyOptimizer:
    def get_optimal_frequency(self, prospecto):
        return {
            'emails_por_semana': 3,  # Este prospecto tolera más
            'sms_por_semana': 2,
        }
```

### Beneficio directo
- -30% desuscripciones
- Mejor reputación del dominio

### Tiempo estimado
- Sin IA: 3-4 días
- Con IA (personalizado): 1-2 semanas adicionales

---

## 10. 📤 REPORTERÍA EJECUTIVA — Números para gerencia

### El problema hoy
Para ver resultados hay que entrar al sistema. Gerencia no entra.

### Solución sin IA
Reporte semanal/mensual automático por email con métricas.

### 🤖 Solución con IA

#### LLM: Análisis narrativo del reporte
```php
$prompt = "Estos son los datos de la semana: [métricas]
Escribe un resumen ejecutivo de 5 líneas destacando lo positivo, 
alertando sobre problemas, y dando una recomendación accionable.";

// "Esta semana fue positiva: +12% en envíos con -8% en costo. 
// ALERTA: El flujo de Bienvenida sigue muy bajo (0.9%). 
// Recomiendo hacer A/B testing en ese flujo."
```

### Beneficio directo
- Gerencia ve el valor sin esfuerzo
- Justificación automática de la inversión

### Tiempo estimado
- Sin IA: 4-5 días
- Con IA (análisis narrativo): 2-3 días adicionales

---

## 📊 RESUMEN CONSOLIDADO

### Prioridad e impacto de IA:

| # | Mejora | IA? | Impacto IA | Prioridad | Tiempo |
|---|--------|-----|------------|-----------|--------|
| 1 | Lead Scoring | ✅ ML + LLM | 🔥 Muy Alto | Alta | 2-4 sem |
| 2 | Segmentación | ✅ ML + LLM | 🔥 Muy Alto | Alta | 1-2 sem |
| 3 | Send Time Optimization | ✅ ML | 🔶 Alto | Media | 1-2 sem |
| 4 | Cross-Channel | ✅ ML + LLM | 🔶 Alto | Media | 1-2 sem |
| 5 | Dashboard Conversiones | ✅ ML + LLM | 🔥 Muy Alto | Alta | 2-3 sem |
| 6 | A/B Testing | ✅ ML (Bandit) | 🔶 Alto | Media | 2-3 sem |
| 7 | Timeline 360° | ✅ LLM | 🔷 Medio | Media | 1 sem |
| 8 | Triggers Automáticos | ✅ ML + LLM | 🔶 Alto | Baja | 2-3 sem |
| 9 | Frequency Capping | ✅ ML | 🔷 Medio | Alta | 1 sem |
| 10 | Reportería Ejecutiva | ✅ LLM | 🔷 Medio | Baja | 1 sem |

### Costos estimados IA (mensual):

| Componente | Volumen | Costo |
|------------|---------|-------|
| Microservicio Python (VM pequeña) | 24/7 | ~$20-50 |
| Claude API - Hot Leads | ~1,000 consultas/día | ~$30-50 |
| Claude API - Reportes | ~30 reportes/mes | ~$5-10 |
| Claude API - Contenido | ~500/día | ~$15-25 |
| **Total estimado** | | **~$70-135/mes** |

### Plan de implementación sugerido:

| Fase | Mejoras | Tiempo | Requiere IA |
|------|---------|--------|-------------|
| **Fase 1** | Lead Scoring + Segmentación | 3-4 semanas | ML + LLM |
| **Fase 2** | Frequency Capping + Timeline 360° | 2 semanas | LLM opcional |
| **Fase 3** | Dashboard Conversiones | 2-3 semanas | ML + LLM |
| **Fase 4** | Send Time + Cross-Channel | 2-3 semanas | ML |
| **Fase 5** | A/B Testing + Triggers | 3-4 semanas | ML (Bandit) |
| **Fase 6** | Reportería Ejecutiva | 1-2 semanas | LLM |

---

## Próximos pasos

1. **Validar prioridades** con el negocio
2. **Confirmar acceso a datos de conversiones** (crítico para ML)
3. **Decidir infraestructura** para microservicio Python
4. **Obtener API key** de Claude/OpenAI
5. **Comenzar Fase 1** (Lead Scoring)

---

*Documento generado: Febrero 2026*
*Versión: 1.0*
