# Replanta Care - WordPress Maintenance Plugin

Un plugin completo de mantenimiento para WordPress que proporciona automatización de tareas, monitoreo de seguridad, optimización de rendimiento y reportes detallados.

**Versión actual:** 1.0.2  
**Probado hasta:** WordPress 6.6  
**Requiere:** WordPress 5.0+, PHP 7.4+

## Changelog

### v1.0.2 (2025-09-11)
- **Fix:** Corregido error de sintaxis PHP en task-security.php (faltaba tag <?php)
- **Mejora:** Plugin ahora se instala correctamente desde GitHub
- **Mejora:** Sistema de auto-actualización mejorado

### v1.0.1 (2025-09-11)
- Versión inicial con sistema de auto-actualización
- Implementación completa de todas las funcionalidades

## Características Principales

### 🔧 Automatización de Tareas
- Actualizaciones automáticas de WordPress, plugins y temas
- Copias de seguridad programadas
- Limpieza automática de caché
- Optimización de base de datos
- Monitoreo de enlaces rotos (404)

### 🔒 Seguridad
- Escaneo de vulnerabilidades
- Monitoreo de malware
- Verificación de integridad de archivos
- Análisis de permisos de archivos
- Monitoreo de uptime

### 📊 Reportes y Análisis
- Reportes HTML personalizados con marca blanca
- Métricas de rendimiento
- Estado de SEO
- Análisis de salud del sitio
- Historial de tareas realizadas

### 🎯 Planes de Servicio
- **Semilla (€49/mes)**: Actualizaciones básicas y monitoreo
- **Raíz (€89/mes)**: Incluye copias de seguridad y optimización
- **Ecosistema (€149/mes)**: Suite completa con soporte prioritario

## Instalación

1. Sube el plugin a `/wp-content/plugins/replanta-care/`
2. Activa el plugin desde el panel de administración de WordPress
3. Ve a `Configuración > Replanta Care` para configurar
4. Introduce la URL del Hub y el token del sitio
5. Selecciona tu plan y configura las opciones

## Configuración

### Configuración Básica
```php
// En wp-config.php, puedes definir constantes para automatizar la configuración
define('RPCARE_HUB_URL', 'https://hub.replanta.es');
define('RPCARE_SITE_TOKEN', 'tu_token_unico');
define('RPCARE_PLAN', 'raiz'); // semilla, raiz, o ecosistema
```

### Variables de Entorno
El plugin detecta automáticamente el tipo de entorno:
- **WHM/cPanel**: Para servidores con panel de control
- **External**: Para sitios alojados externamente
- **Local**: Para desarrollo local

## API REST

El plugin proporciona endpoints REST para comunicación con el Hub:

```
GET  /wp-json/replanta/v1/status       - Estado del sitio
POST /wp-json/replanta/v1/task/{type}  - Ejecutar tarea específica
GET  /wp-json/replanta/v1/logs         - Obtener registros
GET  /wp-json/replanta/v1/metrics      - Métricas del sitio
```

### Autenticación
Todas las llamadas requieren autenticación JWT:
```
Authorization: Bearer {jwt_token}
```

## Tareas Disponibles

### Actualizaciones (`updates`)
- WordPress core
- Plugins activos
- Temas instalados
- Traducciones

### Copias de Seguridad (`backup`)
- Base de datos completa
- Archivos esenciales
- Integración con plugins de backup populares
- Limpieza automática de backups antiguos

### Optimización (`wpo`)
- Compresión de imágenes
- Minificación de CSS/JS
- Optimización de base de datos
- Limpieza de archivos temporales

### Seguridad (`security`)
- Escaneo de malware
- Verificación de vulnerabilidades
- Análisis de permisos
- Monitoreo de cambios

### SEO (`seo`)
- Verificación de meta tags
- Análisis de sitemap
- Comprobación de robots.txt
- Optimización de imágenes

### Salud (`health`)
- Estado de SSL
- Verificación de memoria
- Estado de la base de datos
- Funcionalidad de email
- Estado de cron jobs

## Integraciones

### Plugins de Caché Compatibles
- WP Rocket
- W3 Total Cache
- WP Super Cache
- LiteSpeed Cache
- WP Fastest Cache
- Cachify
- Y muchos más...

### Plugins de Backup Compatibles
- UpdraftPlus
- BackupBuddy
- Duplicator
- BackWPup

### Plugins de Optimización
- Autoptimize
- WP Optimize
- Swift Performance
- SG Optimizer

## Desarrollo

### Estructura del Plugin
```
replanta-care/
├── replanta-care.php          # Archivo principal
├── inc/                       # Classes principales
│   ├── class-plan.php        # Gestión de planes
│   ├── class-scheduler.php   # Programación de tareas
│   ├── class-security.php    # Autenticación JWT
│   ├── class-rest.php        # API REST
│   ├── class-utils.php       # Utilidades
│   ├── task-*.php           # Implementación de tareas
│   ├── integrations-*.php   # Integraciones con plugins
│   └── settings-page.php    # Página de configuración
├── assets/                   # Recursos CSS/JS
└── README.md                # Este archivo
```

### Hooks y Filtros

```php
// Modificar configuración de plan
add_filter('rpcare_plan_features', function($features, $plan) {
    // Personalizar características por plan
    return $features;
}, 10, 2);

// Antes de ejecutar tarea
add_action('rpcare_before_task', function($task_type, $args) {
    // Lógica personalizada antes de tareas
}, 10, 2);

// Después de ejecutar tarea
add_action('rpcare_after_task', function($task_type, $result) {
    // Lógica personalizada después de tareas
}, 10, 2);
```

### Logs Personalizados

```php
// Registrar evento personalizado
RP_Care_Utils::log('custom_task', 'success', 'Tarea personalizada completada', [
    'details' => 'Información adicional'
]);
```

## Seguridad

### Autenticación JWT
- Tokens firmados con HMAC-SHA256
- Expiración automática de tokens
- Verificación de IP opcional

### Permisos
- Verificación de capacidades de WordPress
- Validación de nonces en AJAX
- Sanitización de datos de entrada

### Auditoría
- Registro completo de todas las acciones
- Trazabilidad de cambios
- Alertas de seguridad

## Troubleshooting

### Problemas Comunes

**Error de conexión con el Hub**
```bash
# Verificar conectividad
curl -I https://hub.replanta.es/api/status

# Comprobar certificados SSL
openssl s_client -connect hub.replanta.es:443
```

**Tareas no se ejecutan**
```php
// Verificar WP-Cron
wp_next_scheduled('rpcare_daily_tasks');

// Forzar ejecución manual
do_action('rpcare_daily_tasks');
```

**Problemas de permisos**
```bash
# Verificar permisos de archivos
find wp-content/ -type f -not -perm 644
find wp-content/ -type d -not -perm 755
```

### Debug Mode

Activa el modo debug en `wp-config.php`:
```php
define('RPCARE_DEBUG', true);
```

Esto habilitará:
- Logs detallados
- Información de debug en respuestas API
- Métricas de rendimiento

## Changelog

### 1.0.0
- Lanzamiento inicial
- Sistema completo de automatización
- Integración con Hub de Replanta
- Soporte para 3 planes de servicio

## Soporte

Para soporte técnico o consultas sobre el plugin:

- **Email**: soporte@replanta.es
- **Documentación**: https://docs.replanta.es
- **Hub**: https://hub.replanta.es

## Licencia

Este plugin es propiedad de Replanta y está licenciado para uso exclusivo en sitios web gestionados por Replanta.

---

**Replanta Care** - Mantenimiento WordPress Profesional
Versión 1.0.0 | © 2024 Replanta
