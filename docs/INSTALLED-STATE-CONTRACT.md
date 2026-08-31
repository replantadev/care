# Contrato de estado instalado — Pipeline Smart Updates

## Objetivo

`installed_state_hash` es el único hash que autoriza o invalida el paso de un
lote aprobado desde staging hacia producción. Care lo calcula y Plugin Center
lo congela en el manifiesto y en la aprobación.

Contrato actual: `installed-state-v1`.

## Datos incluidos

- versión de WordPress;
- versión efectiva de PHP;
- ruta, versión y estado activo/inactivo de todos los plugins de aplicación;
- slug, versión y estado activo/inactivo de todos los temas instalados.

Las claves se ordenan recursivamente y se codifican como JSON sin escapar
Unicode ni barras. El resultado es SHA-256 hexadecimal.

## Exclusiones deliberadas

- timestamps y fechas de comprobación;
- transients y avisos de actualizaciones disponibles;
- URLs o disponibilidad de paquetes;
- traducciones;
- Replanta Care (`replanta-care/replanta-care.php`), que pertenece al plano de
  control y se valida por versión/capacidades en gates independientes.

Por tanto, caducar o refrescar `update_plugins` no crea drift. Instalar,
eliminar, activar, desactivar o cambiar la versión de código de aplicación sí.

## Fail closed

Plugin Center no puede crear un lote nuevo en modo `staging_required` si Care
no publica un `installed_state_hash` válido de 64 caracteres. Los inventarios
legacy se pueden mostrar, pero no pueden autorizar producción.

El mismo campo debe estar presente tanto en `/updates/inventory` como en el
snapshot enviado por `report_inventory`. El receptor compara únicamente este
contrato para lotes nuevos; nunca reconstruye el hash a partir de metadatos
volátiles.
