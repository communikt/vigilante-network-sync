# Vigilante Network Sync

Capa de red para **WordPress multisite** que complementa el plugin de seguridad
**Vigilante** (de Fernando Tellado). Vigilante guarda su configuración por sitio y
no tiene panel de red; este plugin añade esa capa que le falta:

- **Replica** la configuración de Vigilante (`vigilante_options`) desde el sitio
  principal al resto de sitios de la red, bajo demanda y con **registro de
  resultado** por sitio.
- Permite **elegir el sitio principal** (por defecto el principal de la red, normalmente
  el ID 1, pero configurable por si los IDs cambian).
- Ofrece, **como opción**, un **login unificado por bloqueo**: el login solo se hace en el
  sitio principal y los subsitios responden **404** a cualquier intento de acceso
  (`wp-login.php` y `slug`) **sin revelar el slug secreto**, para que el **2FA se configure
  una sola vez** (la cookie de auth de red da acceso al resto).
- **No hace nada si Vigilante no está activo** y está diseñado para **no romper el
  acceso** (diseño *fail-open*).

> Este plugin **no modifica Vigilante**: solo lee y escribe su opción de configuración.

## Requisitos

- WordPress **multisite** (obligatorio).
- WordPress 6.2+ y PHP 7.4+.
- Plugin **Vigilante** activo en la red.

## Instalación

1. Copia la carpeta `vigilante-network-sync/` en `wp-content/plugins/`.
2. En **Network Admin → Plugins**, activa el plugin **en red** (Network Activate).
3. Ve a **Network Admin → Configuración → Vigilante Sync**.

## Uso

1. **Sitio principal:** elige el sitio del que se copiará la configuración (por
   defecto el principal de la red).
2. **Sincronización:** pulsa **«Sincronitza ara»**. Verás una tabla con el resultado
   por sitio (correcto / sin cambios / error).
3. **Login unificado (opcional):** activa **«Bloquejar el login als subsites»**. Solo es
   posible en redes de **subdirectorio** y si el sitio principal tiene configurado el
   *custom-login* de Vigilante. Los subsitios responderán 404 a cualquier intento de login
   (sin revelar el slug); el login real solo funciona en el principal.

### Campos que se preservan por sitio

Por defecto **no** se sobrescriben los campos específicos de cada sitio:

- Listas de IPs (`firewall.ip_whitelist/ip_blacklist`, `login_security.ip_whitelist`,
  `activity_log.excluded_ips`) — marca la casilla para copiarlas también.
- **Configuración de 2FA** (`login_security.two_factor`) — los secretos TOTP son por sitio;
  marca la casilla para copiarla solo si el método es **e-mail**.
- `security_headers.csp.report_uri` (URL absoluta por sitio) — siempre se preserva.

> En multisite los **IDs de usuario son globales**, así que las exclusiones por
> usuario y los roles sí se replican correctamente.

## Recomendaciones de configuración

- **Modo bloqueo (recomendado para 2FA único, red multi-idioma):** configura el
  custom-login en el sitio principal, mantén el slug copiado a los subsitios (sync) y
  activa el bloqueo de login. El login solo funciona en el principal; los subsitios
  responden 404 **sin revelar el slug**. El 2FA se configura una sola vez y la cookie de
  red cubre el resto. **Requiere multisite en subdirectorio** (cookie de auth compartida).
- **Modo independiente:** mismo slug en todos los sitios (vía sync) y bloqueo
  desactivado. Cada sitio oculta su propio login; el 2FA se configura por sitio (las
  tablas TOTP de Vigilante son por sitio; **no** marques copiar la config de 2FA con TOTP).
- **`.htaccess` / `wp-config.php`** son únicos y compartidos en la red: deja que el
  **sitio principal** sea quien los escriba (cabeceras, firewall, hardening) y el sync
  replica solo la configuración de PHP-runtime al resto.

## Resiliencia y recuperación

- **Diseño fail-open:** ni el sync ni el bloqueo pueden dejar al admin sin acceso. El
  sitio principal **nunca** se bloquea. El sync valida el esquema de Vigilante **antes de
  escribir** y aborta si no lo reconoce; el bloqueo solo actúa si se cumplen **todas** las
  precondiciones.
- **Redes de subdominio:** el bloqueo se **deshabilita y avisa** (`is_subdomain_install()`),
  porque la cookie de auth no se comparte y dejaría al admin fuera de los subsitios.
- **Vigilante de versión:** si Vigilante cambia de versión, aparece un aviso en Network
  Admin y se envía un email (una vez por versión) al destinatario configurado.
- **Salvaguarda post-update:** tras actualizar este plugin, si el esquema de Vigilante
  no valida, se desactiva **solo el bloqueo** automáticamente.
- **Kill-switch de emergencia:** define en `wp-config.php`:

  ```php
  define( 'VIGSYNC_DISABLE_LOGIN_GUARD', true );
  ```

  Esto desactiva el bloqueo sin tocar la base de datos (también se acepta el antiguo
  `VIGSYNC_DISABLE_REDIRECT`). Y como es un plugin normal, siempre puedes
  **desactivarlo o borrarlo** para recuperar el acceso.

## Actualizaciones automáticas (GitHub + Plugin Update Checker)

Este plugin se distribuye fuera de wordpress.org. Usa la librería **Plugin Update
Checker (PUC)** apuntando a **GitHub Releases** del repositorio público
`communikt/vigilante-network-sync`.

- La librería se incluye en `lib/plugin-update-checker/`. Si no está presente, el
  plugin funciona igual pero sin auto-update.
- Cada instalación detecta las nuevas versiones y muestra la actualización con la UI
  nativa de WordPress (incluido el *toggle* de auto-update por plugin).

### Política de actualización (recomendada)

Por defecto **solo notificación** (no auto-update silencioso), porque este plugin
afecta al login. Cada web puede optar al auto-update. Se recomienda un flujo **canary**:

1. Publicar la nueva *Release* en GitHub (subiendo `Version:` y, si procede,
   `Vigilante compat:` en la cabecera del plugin).
2. Actualizar **primero** en un sitio de pruebas (*canary*) y verificar que el login
   sigue funcionando.
3. Propagar al resto.

### Flujo de mantenimiento

Cuando salga una versión nueva de Vigilante: revisar que el esquema de
`vigilante_options` sigue siendo compatible, actualizar la cabecera
`Vigilante compat:`, subir `Version:` y publicar una *Release/tag* en GitHub. PUC se
encarga de distribuirla.

## Estructura

```
vigilante-network-sync/
  vigilante-network-sync.php           Bootstrap, constantes, hooks, updater
  uninstall.php                        Limpieza de la opción de red
  includes/
    class-vigsync-settings.php         Opción de red + defaults
    class-vigsync-detector.php         Detección/lectura de Vigilante, validación, versión
    class-vigsync-sync.php             Motor de sincronización + log
    class-vigsync-login-guard.php      Bloqueo de login en subsitios (fail-open)
    class-vigsync-network-admin.php    Página de red, formularios, avisos
    views/network-settings-page.php    Plantilla de la página
  assets/css/admin.css
  lib/plugin-update-checker/           Librería PUC (vendorizada)
  languages/                           .pot + traducciones ca/es/en (.po/.mo)
  README.md                            Esta documentación
  CHANGELOG.md                         Historial de versiones
```

## Historial de cambios

Consulta [CHANGELOG.md](CHANGELOG.md). Sigue *Keep a Changelog* + *SemVer*.

## Licencia

GPL v2 o posterior.
