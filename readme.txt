=== Replanta Care ===
Contributors: replanta
Tags: maintenance, security, performance, updates, monitoring
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.8
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Plugin de mantenimiento WordPress automatico para clientes de Replanta.

== Description ==

Replanta Care es un plugin completo de mantenimiento para WordPress que proporciona automatización de tareas, monitoreo de seguridad, optimización de rendimiento y reportes detallados.

= Características principales =

* **Automatización de Tareas:**
  * Actualizaciones automáticas de WordPress, plugins y temas
  * Copias de seguridad programadas
  * Limpieza automática de caché
  * Optimización de base de datos
  * Monitoreo de enlaces rotos (404)

* **Seguridad:**
  * Escaneo de vulnerabilidades
  * Monitoreo de malware
  * Verificación de integridad de archivos
  * Análisis de permisos de archivos
  * Monitoreo de uptime

* **Optimización de Rendimiento:**
  * Análisis de velocidad de página
  * Optimización de imágenes
  * Minificación de CSS/JS
  * Optimización de base de datos
  * Monitoreo de recursos del servidor

* **Reportes y Monitoreo:**
  * Dashboard centralizado de métricas
  * Reportes automáticos por email
  * Integración con servicios externos
  * Logs detallados de actividad
  * Alertas en tiempo real

* **Integraciones:**
  * WHM/cPanel para gestión de hosting
  * Servicios de backup (UpdraftPlus, BackWPup, etc.)
  * Plugins de caché populares
  * Servicios de CDN
  * APIs de monitoreo externo

= Instalación y Configuración =

1. Sube e instala el plugin
2. Actívalo desde el panel de WordPress
3. Ve a Ajustes > Replanta Care
4. Configura tus preferencias y conexiones
5. El plugin comenzará a monitorear automáticamente

= Sistema de Planes =

El plugin incluye diferentes niveles de servicio:
* **Básico:** Monitoreo esencial y actualizaciones
* **Avanzado:** Incluye optimización y reportes
* **Premium:** Funcionalidades completas con soporte prioritario

== Installation ==

= Instalación automática =
1. Ve a Plugins > Añadir nuevo
2. Busca "Replanta Care"
3. Instala y activa el plugin

= Instalación manual =
1. Descarga el archivo ZIP del plugin
2. Ve a Plugins > Añadir nuevo > Subir plugin
3. Selecciona el archivo ZIP y haz clic en "Instalar ahora"
4. Activa el plugin

= Desde GitHub =
1. Descarga desde: https://github.com/replantadev/care
2. Sube el archivo ZIP a WordPress
3. Activa el plugin

== Frequently Asked Questions ==

= ¿El plugin funciona con cualquier tema? =
Sí, Replanta Care es compatible con cualquier tema de WordPress que siga los estándares de desarrollo.

= ¿Se puede usar en sitios multisite? =
Actualmente está diseñado para sitios individuales. La compatibilidad multisite está en desarrollo.

= ¿Requiere configuración especial del servidor? =
No, funciona con cualquier hosting que soporte WordPress. Para funcionalidades avanzadas pueden requerirse permisos específicos.

= ¿Los datos se envían a servidores externos? =
Solo si configuras integraciones específicas. El plugin respeta tu privacidad y solo envía datos cuando lo autorizas explícitamente.

== Screenshots ==

1. Dashboard principal con métricas en tiempo real
2. Panel de configuración del plugin
3. Reportes de seguridad y rendimiento
4. Sistema de notificaciones y alertas

== Changelog ==

= 1.2.5 (2026-02-09) =
* Mayor: Dashboard widget premium completamente rediseñado
* Mayor: Iconos SVG en toda la UI (sin emojis)
* Mayor: Integracion con Backuply para copias de seguridad
* Nuevo: Sincronizacion silenciosa con Hub cada 6 horas
* Nuevo: Cabecera con gradiente segun plan (semilla/raiz/ecosistema)
* Nuevo: Metricas de salud, seguridad, backups y actualizaciones
* Fix: Corregido handler de sincronizacion manual

= 1.2.4 (2026-02-09) =
* Test: Verificacion de deteccion de actualizaciones desde branch main

= 1.2.3 (2026-02-09) =
* Fix: Configurado update checker para usar branch main
* Fix: Añadido setBranch('main') al Plugin Update Checker

= 1.2.2 (2026-02-09) =
* Fix: Corregido error UpdraftPlus API cuando el plugin no esta activo
* Fix: Eliminados handlers duplicados de tareas AJAX
* Fix: Corregidas inconsistencias de nonces

= 1.2.1 (2026-02-09) =
* Fix: Configuracion de Hub URL corregida
* Mejora: Deteccion de entorno mejorada

= 1.1.5 (2025-09-15) =
* Fix: Arreglado widget del dashboard que causaba errores fatales
* Fix: Método get_pending_updates_count() agregado para mostrar actualizaciones pendientes
* Fix: Manejo seguro de configuración de plan cuando no está disponible
* Mejora: Auto-sanitización de URLs del Hub para evitar configuraciones incorrectas
* Mejora: Conexión más robusta y estable con el Hub Replanta
* Fix: Eliminados errores PHP de acceso a arrays nulos

= 1.1.4 (2025-09-15) =
* Fix: Corregida conexión con Hub usando endpoint AJAX correcto
* Mejora: Widget del dashboard ahora obtiene el plan desde el Hub automáticamente
* Nuevo: Detección automática del plan asignado desde sitios.replanta.dev
* Fix: URL de conexión simplificada para evitar errores 404
* Mejora: Sistema de respaldo exponencial para conexiones fallidas
* Mantenimiento: Código optimizado para conexiones más estables

= 1.1.0 (2025-09-11) =
* Mayor: Auto-detección de plan desde sitios.replanta.dev
* Mayor: Eliminada necesidad de token manual de configuración
* Mayor: Conexión automática al Hub Replanta
* Mejora: Indicador de estado de conexión en admin bar
* Mejora: Activación automática cuando se detecta en el hub
* Cambio: Planes renombrados a Básico/Avanzado/Premium

= 1.0.9 (2025-09-11) =
* Fix: Corregida ruta del icono a /assets/img/ico.png
* Nuevo: Añadido indicador de mantenimiento en la barra de administración
* Nuevo: Dropdown con información del plan y características activas
* UI: Acceso rápido al dashboard desde la toolbar
* UI: Indicador visual del estado de protección

= 1.0.8 (2025-09-11) =
* Fix: Corregidas rutas de assets (logo e iconos) usando RPCARE_PLUGIN_URL
* Fix: Eliminadas notificaciones de otros plugins en la página de configuración
* UI: Interfaz más limpia sin interferencias de otros plugins
* Fix: Logo de Replanta ahora se muestra correctamente

= 1.0.7 (2025-09-11) =
* Fix: Añadidas verificaciones de existencia de archivos para evitar errores fatales
* Fix: Protegida la inicialización de componentes con try-catch
* Fix: Mejorada la robustez del plugin en entornos de producción
* Fix: Verificación de clases antes de instanciarlas

= 1.0.6 (2025-09-11) =
* Fix: Eliminada función add_admin_menu duplicada que causaba conflicto
* Fix: Corregida inicialización de la página de configuración
* Fix: Resuelto completamente el error fatal de callback inválido

= 1.0.5 (2025-09-11) =
* Fix: Corregido error fatal en página de configuración (método render inexistente)
* Nuevo: Añadido readme.txt para compatibilidad con WordPress
* Mejora: Mejor documentación del plugin

= 1.0.4 (2025-09-11) =
* Fix: Corregido error en el método de renderizado de la página de configuración
* Fix: Corregida URL del update checker GitHub (eliminado sufijo .git)
* Mejora: Configuración simplificada del sistema de actualizaciones

= 1.0.3 (2025-09-11) =
* Fix: Corregida URL del update checker GitHub
* Mejora: Mejorado sistema de detección de actualizaciones

= 1.0.2 (2025-09-11) =
* Fix: Corregido error de sintaxis PHP en task-security.php (faltaba tag <?php)
* Mejora: Plugin ahora se instala correctamente desde GitHub
* Mejora: Sistema de auto-actualización mejorado

= 1.0.1 (2025-09-11) =
* Versión inicial con sistema de auto-actualización
* Implementación completa de todas las funcionalidades

= 1.0.0 (2025-09-11) =
* Lanzamiento inicial del plugin

== Upgrade Notice ==

= 1.0.4 =
Actualización importante que corrige errores fatales en la página de configuración. Se recomienda actualizar inmediatamente.

= 1.0.3 =
Corrige problemas con el sistema de auto-actualización desde GitHub.

= 1.0.2 =
Corrige errores de sintaxis PHP que impedían la instalación correcta.

== Support ==

Para soporte técnico y consultas:
* Documentación: https://github.com/replantadev/care
* Reportar bugs: https://github.com/replantadev/care/issues

== Privacy Policy ==

Replanta Care respeta tu privacidad:
* No recopila datos personales sin autorización
* Los datos técnicos se procesan localmente
* Las integraciones externas son opcionales
* Cumple con GDPR y regulaciones de privacidad

== Technical Requirements ==

* WordPress 5.0 o superior
* PHP 7.4 o superior
* MySQL 5.6 o superior
* Extensión cURL habilitada
* Mínimo 64MB de memoria PHP (recomendado 128MB)

== License ==

Este plugin está licenciado bajo GPL v2 o posterior.
