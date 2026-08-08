---
title: Configuracion y planes
layout: default
---

# Configuracion y planes

## Seleccion de plan

El plan se configura en **Replanta Care > Configuracion > Plan**. Cada plan desbloquea un conjunto de tareas automaticas.

### Plan Semilla

Ideal para sitios corporativos sencillos, blogs y landings.

Incluye:
- Actualizaciones automaticas de WordPress core, plugins y temas
- Health check semanal (conectividad, BD, PHP)
- Reporte mensual por email al cliente

### Plan Raiz

Para proyectos con trafico activo que requieren supervision continua.

Todo lo de Semilla, mas:
- Escaneo de seguridad (vulnerabilidades de plugins, cambios de archivos criticos)
- Optimizacion de base de datos y cache (WPO)
- Deteccion y registro de errores 404
- Reporte semanal

### Plan Ecosistema

Para tiendas WooCommerce, portales y proyectos criticos.

Todo lo de Raiz, mas:
- Monitorizacion de Core Web Vitals (CWV)
- Deteccion de anomalias de trafico y rendimiento
- Gestion de entorno de staging
- Backup avanzado con rollback automatico
- Limpieza de medios huerfanos
- Revision SEO basica
- Integracion Cloudflare

## Configuracion general

### Notificaciones

| Opcion | Descripcion |
|---|---|
| Email de notificaciones | Destinatario de alertas y reportes |
| Nivel de alertas | Solo criticas / Todas |
| Formato de reporte | Resumido / Detallado |

### Actualizaciones automaticas

```
Modo de actualizacion:
  [ ] Manual - solo notifica, no actualiza
  [x] Automatico con backup previo (recomendado)
  [ ] Solo plugins de seguridad
```

Cuando el modo automatico esta activo, Care:
1. Crea un backup del sitio
2. Aplica la actualizacion
3. Verifica que el sitio responde (health check post-update)
4. Si falla, ejecuta rollback automatico al backup

### Programacion de tareas

Por defecto las tareas se ejecutan en horario de baja carga (madrugada). Puedes personalizar la hora en **Configuracion > Programacion**.

## Entorno de staging para actualizaciones

El propietario del sitio debe proporcionar y mantener una URL de staging exclusiva, por ejemplo `https://dev2.example.com`, y declararla en **Replanta Care > Configuracion > URL de staging**. La URL por si sola no crea ni sincroniza el entorno: debe apuntar a una instalacion WordPress funcional, aislada y clonada desde produccion.

Antes de habilitar el pipeline, el entorno de staging debe cumplir lo siguiente:

- HTTPS y DNS validos, y acceso disponible para el operador responsable.
- Copia reciente de los archivos y de la base de datos de produccion.
- Replanta Care instalado y emparejado con Plugin Center como entorno `staging`; produccion se empareja por separado como `production`.
- Indexacion bloqueada, correos salientes neutralizados y pasarelas de pago en modo prueba o desactivadas.
- Webhooks, pedidos, integraciones ERP/CRM y cualquier otra accion externa neutralizados.
- Cache separada de produccion; Redis debe funcionar correctamente o quedar desactivado durante la validacion.

Care no solicita ni almacena credenciales MySQL del cliente para este flujo. Si el hosting no ofrece clonacion automatica, el cliente o el operador crea/refresca el clon con las herramientas del hosting. Para una clonacion manual pueden necesitarse credenciales de base de datos y acceso a archivos, pero se gestionan fuera de Care y no deben introducirse en el campo URL.

El emparejamiento del staging usa credenciales tecnicas propias del pipeline y no debe consumir una segunda plaza comercial del plan. En licencias de una plaza no se debe volver a ejecutar el onboarding normal con la misma clave sobre la URL de staging; Plugin Center debe registrarla como instancia subordinada del mismo grupo.

Una vez que las dos instalaciones estan emparejadas, Plugin Center puede dirigir el lote firmado al staging, recoger las pruebas y solicitar aprobacion antes de aplicar exactamente el mismo lote en produccion.

## Constantes wp-config.php

| Constante | Descripcion | Default |
|---|---|---|
| `RPCARE_LICENSE_KEY` | License key si no se introduce en admin | — |
| `RPCARE_GITHUB_REPO_URL` | URL del repo de actualizaciones | `github.com/replantadev/care` |
| `RPCARE_GITHUB_BRANCH` | Branch para auto-update | `main` |
| `RPCARE_UPDATE_URL` | URL del update-info.json (Hub) | — |
| `RPCARE_HUB_URL` | URL del Replanta Hub | — |
| `RPCARE_HUB_TOKEN` | Token de autenticacion Hub | — |

---

[Siguiente: Tareas de mantenimiento](tasks.md)
