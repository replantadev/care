---
title: Instalacion y activacion
layout: default
---

# Instalacion y activacion

## Requisitos

- WordPress 6.0 o superior
- PHP 7.4+ (recomendado 8.1+)
- Acceso a WP Admin con rol Administrador
- License key Replanta Care (se obtiene al contratar un plan)

## Instalacion

### Via panel WordPress (recomendado)

Replanta Care se distribuye como archivo ZIP desde el repositorio privado.

1. Descarga el ZIP de la version mas reciente desde el enlace facilitado por Replanta
2. Ve a **Plugins > Anadir nuevo > Subir plugin**
3. Selecciona el ZIP y haz clic en **Instalar ahora**
4. Activa el plugin

### Via Git (para entornos con acceso SSH)

```bash
cd wp-content/plugins/
git clone https://github.com/replantadev/care.git replanta-care
cd replanta-care
composer install --no-dev
```

## Activacion del license key

Una vez instalado, el plugin mostrara una pantalla de activacion:

1. Ve a **Replanta Care > Configuracion**
2. Introduce el license key proporcionado por Replanta
3. Haz clic en **Activar licencia**

El plugin verifica el key contra el Replanta Hub. Si el sitio no tiene conexion con el Hub, el key se puede introducir manualmente como constante en `wp-config.php`:

```php
define('RPCARE_LICENSE_KEY', 'tu-license-key-aqui');
```

## Conexion con Replanta Hub

El Hub es el panel central de Replanta para gestion de clientes. Care se conecta al Hub para:

- Sincronizar el estado del sitio cada 6 horas
- Recibir comandos de mantenimiento remotos
- Enviar reportes y alertas

La URL del Hub y el token de autenticacion se configuran en **Replanta Care > Configuracion > Integraciones**.

## Primer ciclo de mantenimiento

Tras activar, Care ejecuta una revision inicial del sitio en los primeros 5 minutos. Puedes forzarla manualmente desde **Replanta Care > Dashboard > Ejecutar ahora**.

---

[Siguiente: Configuracion y planes](configuration.md)
