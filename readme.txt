=== Vigilante Network Sync ===
Contributors: communikt
Tags: multisite, network, security, vigilante, login
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.4
Network: true
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Capa de red para multisite que replica la configuración de Vigilante desde el sitio principal al resto y unifica el login bloqueándolo en los subsitios (opcional).

== Description ==

**Vigilante Network Sync** complementa el plugin de seguridad **Vigilante** (de Fernando
Tellado) en instalaciones **WordPress multisite**. Vigilante guarda su configuración por
sitio y no tiene panel de red; este plugin añade esa capa que le falta.

* **Sincroniza** la configuración de Vigilante (`vigilante_options`) desde el sitio
  principal al resto de sitios de la red, bajo demanda y con **registro por sitio**.
* Permite **elegir el sitio principal** (por defecto el de la red, normalmente el ID 1,
  pero configurable por si los IDs cambian).
* **Login unificado por bloqueo** (opcional): el login solo se hace en el sitio principal;
  los subsitios responden **404** a cualquier intento de acceso (`wp-login.php` y `slug`)
  **sin revelar el slug secreto**. Así el **2FA se configura una sola vez** y la cookie de
  auth de red da acceso al resto.
* **No hace nada si Vigilante no está activo** y está diseñado para **no romper el acceso**
  (diseño *fail-open*; el sitio principal nunca se bloquea).

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

No por defecto. Los secretos de 2FA viven en tablas por sitio (TOTP cifrado con `AUTH_KEY`)
y no se pueden sincronizar copiando opciones: copiar `method=totp` sin el secreto dejaría al
usuario sin poder validar. Por eso `login_security.two_factor` se **preserva por sitio**.
Hay una casilla para copiar también la config de 2FA, recomendable **solo si el método es
e-mail**. La solución para 2FA único es el **modo bloqueo**: todos inician sesión por el
sitio principal y configuran el 2FA una sola vez; la cookie de red cubre el resto.

= ¿Qué pasa con .htaccess y wp-config.php? =

Son únicos y compartidos en la red. Desde **Vigilante 2.9.8** eso ya lo impone el propio
Vigilante: solo el sitio principal (y un administrador de red) puede escribirlos, y los
subsitios ven esas secciones en modo solo lectura con los valores del principal. En
consecuencia, este plugin **ya no copia** esos ajustes a los subsitios: no tendrían efecto.
El sync replica el resto, la configuración de PHP-runtime, que sí es por sitio.

= ¿Y si me quedo fuera por el bloqueo de login? =

El sitio principal **nunca** se bloquea, así que siempre tienes una puerta abierta. Además,
puedes definir en `wp-config.php`:

`define( 'VIGSYNC_DISABLE_LOGIN_GUARD', true );`

Esto desactiva el bloqueo sin tocar la base de datos (también se acepta el antiguo
`VIGSYNC_DISABLE_REDIRECT`). Y al ser un plugin normal, siempre puedes desactivarlo o
borrarlo para recuperar el acceso.

= ¿Subdirectorio o subdominio? =

Pensado y probado para **multisite en subdirectorio** (mismo dominio), donde la cookie de
auth de red se comparte. En **subdominio** la cookie no se comparte: bloquear el login de
los subsitios te dejaría fuera de sus wp-admin, por eso el plugin **deshabilita el bloqueo
y avisa** en redes de subdominio. La sincronización de configuración sí funciona igual.

= ¿Sobrescribe las listas de IPs de cada sitio? =

No por defecto. Se preservan por sitio las listas de IPs y de User-Agents, la CSP
`report-uri`, la configuración de 2FA y los destinatarios adicionales de avisos; hay una
casilla para copiar también las listas. Al copiarlas, las entradas de IP que nunca podrían
coincidir se descartan con el mismo validador que usa Vigilante 2.9.9 al guardar y se nombran
en el log del sync. Además, cualquier campo que Vigilante declare como dato del usuario del
sitio y que este plugin no conozca se preserva por defecto.

== Changelog ==

= 2.0.4 - 2026-08-23 =
* **Revisado y validado contra Vigilante 2.9.9**, sin cambios de comportamiento. La lista de
  ajustes de fichero compartido es idéntica a la de 2.9.8 y el esquema que exige el
  sincronizador sigue intacto.
* Vigilante 2.9.9 retira once ajustes que ningún código leía; dos estaban en la lista de
  excepciones de este plugin (`firewall.country_blocking` y `file_integrity.suspicious_patterns`)
  y se eliminan por ser ya código muerto.
* Al copiar las listas de IPs, las entradas que nunca podrían coincidir se descartan con el
  validador de Vigilante 2.9.9 (`Vigilante_IP_Utils::split_list()`) y se nombran en el log del
  sync, en lugar de propagarse a los subsitios. Solo actúa si la casilla de copiar las listas
  está marcada; las listas de User-Agent no se tocan.
* El nuevo bloqueo temprano de `wp-admin` de Vigilante 2.9.9 no colisiona con el bloqueo de
  login de este plugin, y el SSO por cookie de red sigue intacto.

= 2.0.3 - 2026-08-20 =
* **Alineado con el soporte multisite de Vigilante 2.9.8.** El sync deja de copiar a los
  subsitios los ajustes que solo se escriben en `wp-config.php` y en el `.htaccess`
  (cabeceras de seguridad, hardening de WP y protecciones de ficheros): esos ficheros son
  únicos en la red y solo los escribe el sitio principal, así que copiarlos no hacía nada y
  dejaba en el subsitio una copia local que contradecía el fichero real.
* La lista de esos ajustes se lee de Vigilante (`get_shared_file_settings()`), no está
  duplicada aquí, así que no puede quedar desfasada. Con Vigilante anterior a 2.9.8 el
  comportamiento es el de siempre.
* **Más campos preservados por sitio:** listas de User-Agents del firewall (junto a las de
  IPs) y destinatarios adicionales de avisos. Cualquier campo nuevo que Vigilante declare
  como dato del usuario se preserva por defecto, salvo los que en una red son uniformes por
  definición (cabecera de proxy, bloqueo por país, nombres de usuario inseguros y
  exclusiones del escaneo de integridad), que se siguen propagando.
* Validado contra Vigilante 2.9.8: el esquema cambia (XML-RPC pasa a `wp_hardening`), pero
  la migración se propaga sola. El bloqueo de login sigue siendo necesario: Vigilante 2.9.8
  no incluye ningún login unificado para multisite.

= 2.0.2 - 2026-08-14 =
* Mantenimiento: **validado contra Vigilante 2.9.5** (sin cambios de código). Cabecera
  `Vigilante compat` actualizada a 2.9.5, lo que silencia el aviso informativo del vigilante
  de versión.
* `class-settings.php` de Vigilante es idéntico al de 2.9.2: el esquema de `vigilante_options`
  no cambia y no hacen falta nuevas opciones de sync ni campos preservados.
* Los cambios de 2.9.3/2.9.4 refuerzan el modelo de bloqueo: cierran la fuga del slug por
  `/login` y por el POST anónimo a `/wp-admin`, y corrigen los falsos 404 en URLs de front-end
  que solo contenían «wp-admin».

= 2.0.1 - 2026-07-04 =
* Mantenimiento: **validado contra Vigilante 2.9.2** (sin cambios de código). Cabecera
  `Vigilante compat` actualizada a 2.9.2, lo que silencia el aviso informativo del vigilante
  de versión.
* Los cambios de Vigilante 2.9.x se limitan al escáner de integridad de ficheros; no afectan
  ni al esquema de opciones ni al bloqueo de login. No hacen falta nuevas opciones de sync.

= 2.0.0 - 2026-06-28 =
* **Breaking:** el redirect de login se sustituye por un **bloqueo** en los subsitios. El
  redirect revelaba el slug secreto (`subsite/wp-admin` → `principal/slug/`); ahora los
  subsitios responden 404 sin revelar nada. La opción se migra automáticamente.
* `Vigsync_Login_Redirect` → `Vigsync_Login_Guard`; `login_redirect_enabled` →
  `login_block_enabled`.
* El sync ya no copia la config de 2FA por defecto (`login_security.two_factor` se preserva
  por sitio); nueva casilla `sync_two_factor` (solo recomendable con método e-mail).
* Nuevo kill-switch `VIGSYNC_DISABLE_LOGIN_GUARD` (se mantiene el antiguo).
* Aviso y deshabilitación del bloqueo en redes de subdominio.
* Tests de lógica pura (`tests/`, excluidos del ZIP).

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

= 2.0.4 =
Mantenimiento para Vigilante 2.9.9: sin cambios de comportamiento. Limpia dos campos que
Vigilante ha retirado del esquema y depura las entradas de IP imposibles al copiar las listas.

= 2.0.3 =
Alinea el sincronizador con el soporte multisite de Vigilante 2.9.8: deja de copiar los
ajustes de wp-config.php y .htaccess (que ahora solo escribe el sitio principal) y preserva
más campos propios de cada sitio. Recomendada si actualizas a Vigilante 2.9.8.

= 2.0.2 =
Actualización de mantenimiento: valida la compatibilidad con Vigilante 2.9.5 y silencia el
aviso de versión. Sin cambios funcionales.

= 2.0.1 =
Actualización de mantenimiento: valida la compatibilidad con Vigilante 2.9.2 y silencia el
aviso de versión. Sin cambios funcionales.

= 2.0.0 =
Cambio importante: el redirect de login (que revelaba el slug) se sustituye por un bloqueo
404 en los subsitios. La opción se migra sola. Tras actualizar, revisa Network Admin →
Vigilante Sync. Solo para multisite en subdirectorio.

= 1.0.0 =
Primera versión. Requiere WordPress multisite y el plugin Vigilante activo.
