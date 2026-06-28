# Recuperación de emergencia — Vigilante Network Sync

Guía operativa para recuperar el acceso si el **bloqueo de login** (modo login unificado)
diera problemas en una red multisite. Pensada para tenerla a mano antes de tocar producción.

> **Antes de entrar en pánico:** el **sitio principal NUNCA se bloquea** y, si mantienes una
> sesión de administrador abierta en otra ventana, el bloqueo no afecta a `wp-admin` (solo a
> la pantalla de login). En la práctica casi nunca necesitarás nada de esto.

El orden recomendado va de menos a más invasivo: **1) kill-switch → 2) WP-CLI → 3) FTP**.

---

## 1. Kill-switch en `wp-config.php` (no necesita SSH)

Edita `wp-config.php` (por FTP o el gestor de archivos del hosting) y añade, antes de
`/* That's all, stop editing! */`:

```php
define( 'VIGSYNC_DISABLE_LOGIN_GUARD', true );
```

Desactiva **solo** el bloqueo de login, sin tocar la base de datos ni el resto del plugin.
(También se acepta el nombre antiguo `VIGSYNC_DISABLE_REDIRECT`.) Para volver a activarlo,
borra la línea.

---

## 2. WP-CLI (necesita acceso SSH)

WP-CLI (`wp ...`) se ejecuta por SSH en el servidor, sin pasar por el login web. Por eso
funciona aunque el login esté bloqueado. En multisite, los plugins activados en red
requieren el flag `--network`.

```bash
# Ver el estado de los plugins de la red
wp plugin list --network

# Desactivar SOLO este plugin (quita el bloqueo; conserva la config en la BD)
wp plugin deactivate vigilante-network-sync --network

# Reactivarlo cuando esté resuelto
wp plugin activate vigilante-network-sync --network

# Si el problema viniera del propio Vigilante (reabre wp-login.php estándar)
wp plugin deactivate vigilante --network
```

Desactivar **solo el bloqueo** sin desactivar el plugin (modifica la opción de red):

```bash
# Apagar el bloqueo
wp eval 'update_site_option( "vigsync_settings", array_merge( (array) get_site_option( "vigsync_settings", array() ), array( "login_block_enabled" => false ) ) );'

# Ver el valor actual de la opción de red del plugin
wp eval 'var_export( get_site_option( "vigsync_settings" ) );'

# Comprobar si la red es de subdirectorio (false) o de subdominio (true)
wp eval 'var_export( is_subdomain_install() );'
```

> Nota: `update_site_option`/`get_site_option` son opciones **de red** (no dependen del blog
> actual), así que no hace falta `--url=`.

---

## 3. FTP / gestor de archivos (último recurso, sin SSH ni acceso web)

Renombra la carpeta del plugin para que WordPress lo desactive automáticamente:

```
wp-content/plugins/vigilante-network-sync  →  vigilante-network-sync.OFF
```

Al volver a dejarle el nombre original, el plugin reaparece con su configuración intacta
(las opciones siguen en la base de datos).

---

## Recordatorio de invariantes de seguridad del plugin

- El **sitio principal (source) nunca se bloquea**: siempre hay una puerta de login abierta.
- El bloqueo es **fail-open**: si Vigilante no está activo, o la red es de **subdominios**,
  o falla cualquier precondición, el bloqueo no actúa.
- En redes de **subdominio** el bloqueo está **deshabilitado por diseño** (la cookie de auth
  no se comparte y dejaría al admin fuera de los subsitios).
