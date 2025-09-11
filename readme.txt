=== Replanta Care ===
Contributors: replanta
Tags: maintenance, security, performance, updates, monitoring
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Plugin de mantenimiento WordPress automático para clientes de Replanta.

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
