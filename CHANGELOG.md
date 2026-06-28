# Changelog — Vigilante Network Sync

Todos los cambios relevantes de este plugin se documentan aquí.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

> **Recordatorio de release:** en cada cambio, actualiza este archivo, sube la
> cabecera `Version:` (y `Vigilante compat:` si procede) en
> `vigilante-network-sync.php`, y publica una Release/tag en GitHub para que el
> Plugin Update Checker la distribuya.

## [Sin publicar]

### Por hacer
- Prueba end-to-end en un multisite real (sync + bloqueo de login con sesión cerrada).

## [2.0.0] - 2026-06-28

> **Cambio incompatible (breaking):** el «redirect de login unificado» se sustituye por un
> «bloqueo de login» en los subsitios. La opción se migra automáticamente al actualizar.

### Cambiado
- **Login unificado por bloqueo, no por redirect.** El modo redirect revelaba el slug
  secreto del custom-login: al ir a `subsite/wp-admin` redirigía a `principal/slug/`,
  exponiendo la URL que debía ser oculta. Ahora los subsitios responden un **404 idéntico
  al de Vigilante** ante cualquier intento de login (tanto `wp-login.php` como el `slug`),
  sin revelar nada. El login solo funciona en el sitio principal; la cookie de auth de red
  (subdirectorio) da acceso al resto. El sitio principal **nunca** se bloquea.
  - Clase `Vigsync_Login_Redirect` → `Vigsync_Login_Guard`
    (`includes/class-vigsync-login-guard.php`); engancha `login_init` (prio 0) y reutiliza
    las exclusiones de Vigilante (POST, logout, reset, app passwords, modal interim-login…).
  - Opción `login_redirect_enabled` → `login_block_enabled` (**migración automática** vía
    `Vigsync_Settings::maybe_upgrade()`, `vigsync_db_version = 2`).
- **El sync ya no copia la configuración de 2FA por defecto.** `login_security.two_factor`
  pasa a ser un campo preservado por sitio (como las listas de IPs), porque los secretos
  TOTP viven en tablas por-blog cifradas con `AUTH_KEY`: copiar `method=totp` sin el secreto
  dejaba al usuario sin poder validar. Nueva casilla **«Copiar también la configuración de
  2FA»** (`sync_two_factor`, solo recomendable con método e-mail).

### Añadido
- **Kill-switch `VIGSYNC_DISABLE_LOGIN_GUARD`** (se mantiene el antiguo
  `VIGSYNC_DISABLE_REDIRECT` por compatibilidad).
- **Aviso de red de subdominios:** en `is_subdomain_install()` el bloqueo se deshabilita y
  se avisa, porque la cookie de auth no se comparte y dejaría al admin fuera de los wp-admin
  de los subsitios.
- **Tests de lógica pura** (`tests/test-logic.php`, excluido del ZIP): `build_payload`
  (preservación de 2FA/IPs/CSP/custom-login) y `should_block_request`.

### Compatibilidad
- Validado contra **Vigilante 2.8.0**. Requiere WordPress multisite **en subdirectorio**
  6.2+ y PHP 7.4+. El modo bloqueo requiere cookie de auth de red compartida.

## [1.0.1] - 2026-06-23

### Añadido
- Licencia `LICENSE` (GPLv2 completa).
- `.gitattributes` para excluir archivos de desarrollo del ZIP de distribución.
- Workflow de GitHub Actions que empaqueta el ZIP y publica la Release en cada tag `v*`.

### Notas
- Versión de mantenimiento/herramientas: sin cambios funcionales en el plugin respecto a
  la 1.0.0. Sirve además para validar el flujo automático de publicación de releases.

## [1.0.0] - 2026-06-23

Primera versión. Plugin de red para multisite que complementa Vigilante.

### Añadido
- **Sincronización de configuración:** replica `vigilante_options` desde el sitio
  principal al resto de sitios de la red, bajo demanda (botón manual) y con **registro
  de resultado por sitio** (correcto / sin cambios / error).
- **Selección de sitio principal** configurable (por defecto `get_main_site_id()`, con
  *fallback* si el ID guardado ya no existe).
- **Redirect de login unificado** (opcional): redirige los logins de los subsitios al
  custom-login del sitio principal. Solo activable si el principal tiene custom-login;
  el slug se lee en vivo (no se escribe a mano). Diseño *fail-open*.
- **Página de configuración en Network Admin** (Configuración → Vigilante Sync) con
  capability `manage_network_options`, nonces y escapado/saneado de datos.
- **Preservación de campos por sitio** en el sync (listas de IPs y `csp.report_uri`),
  con opción para copiar también las listas de IPs.
- **Resiliencia:**
  - Validación del esquema de `vigilante_options` antes de escribir (aborta si no lo
    reconoce).
  - Vigilante de versión: aviso en Network Admin y email (una vez por versión) cuando
    Vigilante cambia, comparando con la cabecera `Vigilante compat:`.
  - Auto-desactivación solo del redirect si faltan precondiciones.
  - Kill-switch `VIGSYNC_DISABLE_REDIRECT` (constante en `wp-config.php`).
  - Salvaguarda `upgrader_process_complete`: revalida el esquema tras actualizar y
    desactiva el redirect si es necesario.
- **Auto-actualización** vía GitHub Releases con Plugin Update Checker (v5.6).
- **Internacionalización:** `.pot` y traducciones a catalán (`ca`), español (`es_ES`)
  e inglés (`en_US`); carga de dominio con `load_plugin_textdomain()`.
- Documentación de usuario (`README.md`) y desinstalación limpia (`uninstall.php`).

### Compatibilidad
- Validado contra **Vigilante 2.8.0**.
- Requiere WordPress multisite 6.2+ y PHP 7.4+.

[Sin publicar]: https://github.com/communikt/vigilante-network-sync/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/communikt/vigilante-network-sync/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/communikt/vigilante-network-sync/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/communikt/vigilante-network-sync/releases/tag/v1.0.0
