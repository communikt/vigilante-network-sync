=== Vigilante Network Sync ===
Contributors: communikt
Tags: multisite, network, security, vigilante, login
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
Network: true
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capa de red para multisite que replica la configuración de Vigilante desde el sitio principal al resto y unifica el login (opcional).

== Description ==

**Vigilante Network Sync** complementa el plugin de seguridad **Vigilante** (de Fernando
Tellado) en instalaciones **WordPress multisite**. Vigilante guarda su configuración por
sitio y no tiene panel de red; este plugin añade esa capa que le falta.

* **Sincroniza** la configuración de Vigilante (`vigilante_options`) desde el sitio
  principal al resto de sitios de la red, bajo demanda y con **registro por sitio**.
* Permite **elegir el sitio principal** (por defecto el de la red, normalmente el ID 1,
  pero configurable por si los IDs cambian).
* **Redirect de login unificado** (opcional): los logins de los subsitios se redirigen al
  login personalizado del sitio principal, para que el **2FA se configure una sola vez**.
* **No hace nada si Vigilante no está activo** y está diseñado para **no romper el acceso**
  (diseño *fail-open*).

Este plugin **no modifica Vigilante**: solo lee y escribe su opción de configuración.

**Requisitos:** WordPress multisite, Vigilante activo en la red.

= Distribución y actualizaciones =

Se distribuye fuera de WordPress.org mediante **GitHub Releases** + la librería *Plugin
Update Checker*, por lo que las instalaciones reciben las actualizaciones con la interfaz
nativa de WordPress (incluido el *toggle* de auto-update por plugin).

== Installation ==

1. Sube la carpeta `vigilante-network-sync/` a `wp-content/plugins/`.
2. En **Network Admin → Plugins**, activa el plugin **en red** (Network Activate).
3. Ve a **Network Admin → Configuración → Vigilante Sync**.
4. Elige el sitio principal y pulsa **«Sincronitza ara»**.

== Frequently Asked Questions ==

= ¿Modifica el plugin Vigilante o sus archivos? =

No. Solo lee y escribe la opción `vigilante_options` de cada sitio. No toca el código de
Vigilante.

= ¿Funciona sin Vigilante? =

No hace nada útil sin Vigilante activo, y nunca escribe configuración si no detecta el
plugin (comprueba `VIGILANTE_VERSION`).

= ¿Sincroniza el 2FA? =

No. Los secretos de 2FA de Vigilante viven en tablas por sitio y no se pueden (ni se deben)
sincronizar copiando opciones. Por eso se recomienda el **modo redirect**: que todos
inicien sesión por el sitio principal y configuren el 2FA una sola vez; la cookie de red
cubre el resto.

= ¿Qué pasa con .htaccess y wp-config.php? =

Son únicos y compartidos en la red. Configura cabeceras, firewall y hardening en el **sitio
principal** (que es quien escribe esos archivos); el sync replica el resto de la
configuración de PHP-runtime.

= ¿Y si me quedo fuera por el redirect de login? =

Define en `wp-config.php`:

`define( 'VIGSYNC_DISABLE_REDIRECT', true );`

Esto desactiva el redirect sin tocar la base de datos. Además, al ser un plugin normal,
siempre puedes desactivarlo o borrarlo para recuperar el acceso.

= ¿Subdirectorio o subdominio? =

Pensado y probado para **multisite en subdirectorio** (mismo dominio). En subdominio, el
redirect entre sitios necesitaría añadir los hosts permitidos (`allowed_redirect_hosts`).

= ¿Sobrescribe las listas de IPs de cada sitio? =

No por defecto. Las listas de IPs y la CSP `report-uri` se preservan por sitio; hay una
casilla para copiar también las listas de IPs.

== Changelog ==

= 1.0.0 - 2026-06-23 =
* Primera versión.
* Sincronización de `vigilante_options` del sitio principal al resto de la red, con
  registro de resultado por sitio.
* Selección de sitio principal configurable (con *fallback* si el ID ya no existe).
* Redirect de login unificado opcional (solo activable con custom-login en el principal),
  con diseño *fail-open*.
* Página de configuración en Network Admin (capability `manage_network_options`, nonces,
  saneado/escapado).
* Preservación de campos por sitio (listas de IPs y `csp.report_uri`).
* Resiliencia: validación de esquema antes de escribir, vigilante de versión con aviso y
  email, kill-switch `VIGSYNC_DISABLE_REDIRECT`, y salvaguarda tras actualizar.
* Auto-actualización vía GitHub Releases (Plugin Update Checker).
* Traducciones: catalán, español e inglés.

Historial completo en `CHANGELOG.md`.

== Upgrade Notice ==

= 1.0.0 =
Primera versión. Requiere WordPress multisite y el plugin Vigilante activo.
