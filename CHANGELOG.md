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
- Prueba end-to-end en un multisite real (sync + redirect con sesión cerrada).

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

[Sin publicar]: https://github.com/communikt/vigilante-network-sync/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/communikt/vigilante-network-sync/releases/tag/v1.0.0
