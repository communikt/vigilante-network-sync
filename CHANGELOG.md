# Changelog — Vigilante Network Sync

Todos los cambios relevantes de este plugin se documentan aquí.

El formato se basa en [Keep a Changelog](https://keepachangelog.com/es/1.1.0/)
y el proyecto sigue [Versionado Semántico](https://semver.org/lang/es/).

> **Recordatorio de release:** en cada cambio, actualiza este archivo, sube la
> cabecera `Version:` (y `Vigilante compat:` si procede) en
> `vigilante-network-sync.php`, y publica una Release/tag en GitHub para que el
> Plugin Update Checker la distribuya.

## [Sin publicar]

_Sin cambios pendientes._

## [2.0.4] - 2026-08-23

Mantenimiento para **Vigilante 2.9.9**. Revisados los cuatro puntos de acoplamiento y el
bloqueo de login temprano que estrena Vigilante: **no hay incompatibilidad y el comportamiento
del sincronizador no cambia**. Lo que sí se hace es dejar de describir un esquema que ya no
existe y aplicar a las listas de IPs el mismo criterio de validez que Vigilante.

### Cambiado
- **`NETWORK_UNIFORM_FIELDS` se limpia de campos muertos.** Vigilante 2.9.9 retira del esquema
  once ajustes que ningún código leía, dos de ellos en nuestra lista de excepciones:
  `firewall.country_blocking` y `file_integrity.suspicious_patterns`. Como esa lista solo se
  consulta contra lo que devuelve `Vigilante_Settings::get_user_data_keys()`, y de ahí también
  han desaparecido, mantenerlas era código muerto que además describía un esquema irreal.
  Ningún efecto funcional.
- **Las listas de IPs copiadas se depuran con el validador de Vigilante.** Desde 2.9.9,
  Vigilante rechaza al guardar lo que nunca podrá coincidir (`999.999.999.999/99`, una palabra
  suelta) en lugar de almacenarlo en silencio en una lista de seguridad. Como este plugin
  escribe `vigilante_options` directamente, sin pasar por ese formulario, ahora usa
  `Vigilante_IP_Utils::split_list()` — el método **de Vigilante**, para que el criterio no pueda
  divergir — sobre `firewall.ip_whitelist` / `ip_blacklist`, `login_security.ip_whitelist` y
  `activity_log.excluded_ips`. Solo actúa cuando la casilla de copiar las listas está activa: si
  no lo está, las listas del destino se preservan y no se tocan. Lo descartado **se nombra en el
  log del sync**, etiquetado con su sección y clave, para que no desaparezca en silencio: una
  lista de IPs es un mecanismo de acceso. Las listas de User-Agent no se tocan nunca, y con
  Vigilante anterior a 2.9.9 el validador no existe y el payload se copia como siempre.

### Compatibilidad
- **Revisado y validado contra Vigilante 2.9.9.** `Vigilante_Settings::get_shared_file_settings()`
  es **idéntica** a la de 2.9.8, y las claves que exige `validate_schema()` (`modules`,
  `login_security`, `firewall` y `login_security.custom_login_url`) siguen todas en su sitio. Los
  once ajustes retirados se borran también de las configuraciones ya guardadas, mediante una
  migración que corre en el `admin_init` **de cada sitio**: un subsitio que nadie visite no se
  limpiaría nunca por sí solo, pero el sync propaga la configuración ya limpia del principal y lo
  deja al día sin esperar a esa visita.
- **El nuevo bloqueo temprano de `wp-admin` no colisiona con `Vigsync_Login_Guard`.** Vigilante
  2.9.9 responde 404 a un GET anónimo sin cookie de sesión ya en `plugins_loaded` prioridad 1,
  antes de cargar el tema y el resto de plugins, y solo si el sitio tiene `custom_login_url`. En
  un subsitio con el slug sincronizado (lo normal: la casilla viene activada), `/subsitio/wp-admin/`
  da el mismo 404 que antes, pero más barato; sin slug, la petición sigue el camino de siempre
  hasta `login_init`, donde responde nuestro guard. El SSO por cookie de red no se ve afectado:
  Vigilante solo comprueba la **presencia** de la cookie, que en una red en subdirectorio tiene
  path `/`.
- El fix de 2.9.9 por el que una IP en la lista blanca del firewall dejaba de exponer el
  formulario de login (y de entregar el slug secreto en la cabecera `Location`) **refuerza** el
  modelo de bloqueo de esta v2.x sin pedir ningún cambio: las listas de IPs se preservan por
  sitio, así que cada subsitio conserva la suya.

## [2.0.3] - 2026-08-20

Vigilante **2.9.8** trae soporte real de multisite (sin panel de red, pero con las decisiones
que afectan a toda la red centralizadas en el sitio principal). Esta versión **alinea el
sincronizador con ese modelo**: deja de copiar lo que Vigilante ya gestiona en red y afina qué
se preserva de cada sitio.

### Cambiado
- **El sync ya no copia los ajustes de fichero compartido.** Desde Vigilante 2.9.8,
  `wp-config.php` y el `.htaccess` de la raíz solo los escribe el sitio principal
  (`Vigilante_Settings::can_write_shared_files()`), y los subsitios ven esas secciones como
  solo lectura mostrando los valores del principal. Copiarlas allí no tenía ningún efecto en
  runtime y dejaba en el subsitio una copia local que contradecía el fichero real. Ahora se
  preservan del destino: toda la sección `security_headers`, la parte de `wp_hardening` que
  escribe constantes (`disallow_file_edit`, `force_ssl_admin`, `wp_debug`…) y la mitad
  `.htaccess` de `firewall` (`protect_wp_config`, `limit_http_methods`,
  `disable_directory_browsing`…). La lista se lee **de Vigilante**
  (`Vigilante_Settings::get_shared_file_settings()`), no está copiada aquí, así que no puede
  quedar desfasada. Con Vigilante anterior a 2.9.8 el método no existe y el comportamiento es
  el de siempre.
- **Campos preservados por sitio ampliados.** Se añaden `firewall.ua_whitelist` y
  `ua_blacklist` (gobernados por la misma casilla que las listas de IPs, que pasa a llamarse
  «listas de IPs y User-Agents») y `email.additional_recipients`, que se preserva siempre:
  quién recibe los avisos de un sitio es decisión de ese sitio, a menudo del cliente.
- **Red de seguridad para versiones futuras de Vigilante.** Cualquier campo que Vigilante
  declare en `Vigilante_Settings::get_user_data_keys()` y que este plugin no conozca se
  **preserva por defecto**, en lugar de copiarse sin criterio. Las excepciones conscientes
  están en `Vigsync_Sync::NETWORK_UNIFORM_FIELDS`: `firewall.trusted_proxy_header` y
  `country_blocking`, `user_security.insecure_usernames` y las exclusiones de `file_integrity`.
  Esos sí se propagan porque en una red **en subdirectorio** son uniformes por definición — el
  proxy es el mismo, la política es la misma y el escaneo de integridad recorre el **mismo
  sistema de ficheros**. La lista de Vigilante responde a otra pregunta que la nuestra («qué no
  debe borrar un botón de restaurar» frente a «qué es propio de cada sitio de una red»), por eso
  se adopta como red de seguridad y no al pie de la letra.
- Aviso en la pantalla de red, visible solo con Vigilante 2.9.8+, explicando qué ha dejado de
  copiarse y por qué.

### Compatibilidad
- **Revisado y validado contra Vigilante 2.9.8** (incluye 2.9.6 y 2.9.7). El esquema de
  `vigilante_options` **sí cambia** esta vez, pero de forma compatible: desaparecen
  `firewall.block_http_1_0`, `firewall.protect_htaccess`, `user_security.warn_existing_insecure`
  y el par `login_security.disable_xmlrpc` / `disable_xmlrpc_pingback` (XML-RPC pasa a
  `wp_hardening.xmlrpc_mode` en 2.9.7), y aparece `security_headers.upgrade_insecure_requests`.
  Como el sync copia secciones enteras, la migración se propaga sola.
- `Vigsync_Detector::validate_schema()` sigue siendo válida: `modules`, `firewall`,
  `login_security` y `login_security.custom_login_url` siguen existiendo.
- **El bloqueo de login sigue siendo necesario y no tiene sustituto:** `class-login-security.php`
  de Vigilante 2.9.8 no contiene una sola referencia a multisite. Vigilante mantiene
  `block_wp_login_access()` en `login_init` con prioridad 1, la misma lista de acciones
  permitidas y el mismo texto de 404, así que `Vigsync_Login_Guard` sigue alineado.
- Vigilante sigue sin enganchar nada a `updated_option` de `vigilante_options`, de modo que
  escribir la opción desde un subsitio no dispara reescrituras de ficheros.
- Los cambios de defaults de 2.9.8 (HTTPS, política de contraseñas, expiración, sesiones) solo
  afectan a instalaciones nuevas; el sync copia el valor del principal en cualquier caso.

### Notas
- `login_security.custom_login_url` se sigue copiando por defecto (casilla propia) y, cuando no
  se sincroniza, se deja **siempre declarado** en el destino (cadena vacía si allí no había
  valor) en vez de eliminar la clave: es el campo del que depende el acceso y conviene que el
  valor efectivo esté escrito y no dependa de un *merge* de defaults.
- Tests ampliados a **37 asserts** (antes 19), con un *stub* de Vigilante 2.9.8 declarado dentro
  de un condicional para poder ejercitar también el camino de compatibilidad con versiones
  anteriores — PHP registra las clases de primer nivel antes de ejecutar el fichero, así que una
  declaración normal habría hecho invisible ese camino.
- Traducciones ca/es/en regeneradas y completas (78/78).

## [2.0.2] - 2026-08-14

Versión de mantenimiento: **validación de compatibilidad con Vigilante 2.9.5** (2.9.3, 2.9.4 y
2.9.5). Sin cambios funcionales ni de esquema en este plugin.

### Cambiado
- Cabecera `Vigilante compat: 2.9.2` → **`2.9.5`**, que silencia el aviso informativo del
  «vigilante de versión» (aviso en Network Admin + email). Ese aviso nunca desactivó nada.

### Compatibilidad
- **Revisado y validado contra Vigilante 2.9.5.** No requiere cambios de código. Verificado:
  - **`includes/class-settings.php` es byte a byte idéntico al de 2.9.2** → el esquema de
    `vigilante_options` no ha cambiado: `Vigsync_Detector::validate_schema` sigue encontrando
    `modules`, `login_security`, `firewall` y `login_security.custom_login_url`, y no hay claves
    nuevas que añadir a la lista de campos preservados por sitio.
  - **Motor de sync:** Vigilante no engancha nada a `update_option`/`updated_option` de
    `vigilante_options` (solo el registro de actividad escucha `updated_option` de forma
    genérica), así que escribir la opción desde un subsitio no dispara reescrituras de
    `.htaccess`. La reescritura de reglas de 2.9.3 (whitelists de IP/User-Agent aplicadas
    también a nivel `.htaccess`) se dispara **solo al guardar desde el admin de un sitio**.
    Se mantiene la recomendación de configurar el hardening en el sitio principal, ya que
    `.htaccess` es único y compartido en toda la red.
  - **`vigilante_login_rules_version`** sigue existiendo y con el mismo uso, por lo que el
    `delete_option()` que hace el sync en cada destino sigue siendo válido.
  - **Bloqueo de login (`Vigsync_Login_Guard`):** Vigilante sigue enganchando
    `block_wp_login_access()` en `login_init` con prioridad 1 (nuestro guard va en prioridad 0)
    y su lista de acciones permitidas (`postpass`, `logout`, `rp`, `resetpass`, `confirmaction`,
    `lostpassword`, `retrievepassword`) no ha cambiado. El texto del 404 sigue siendo el mismo
    (`The page you are looking for does not exist.` / `404 Not Found`).
- **Cambios de 2.9.3/2.9.4 que refuerzan el modelo de bloqueo** (a favor, no en contra):
  - 2.9.3 cierra dos fugas del slug secreto que nuestro guard ya evitaba en los subsitios:
    `/login` (que el core convertía en un 302 a la URL oculta) y el `POST` anónimo a
    `/wp-admin`. Ahora ambos devuelven 404 también en el sitio principal.
  - 2.9.4 corrige el ocultamiento de `wp-admin`, que se comparaba con `strpos()` sobre toda la
    `REQUEST_URI`: ya no convierte en 404 las URLs de front-end que solo *contienen*
    «wp-admin» (`/wp-admin-tips/`, `/?redirect_to=/wp-admin/`). Usa `is_admin()` y la ruta de
    `admin_url()`, que en multisite de subdirectorio es correcta por sitio.
  - 2.9.4 deja de renderizar la plantilla 404 del tema desde `init` (regresión de 2.9.3 con
    coste de un render completo por petición bloqueada). No afecta a nuestro guard, que corre
    en `login_init` y usa `wp_die()`.

## [2.0.1] - 2026-07-04

Versión de mantenimiento: **validación de compatibilidad con Vigilante 2.9.2**. Sin cambios
funcionales ni de esquema en este plugin.

### Cambiado
- Cabecera `Vigilante compat: 2.8.0` → **`2.9.2`**. Esto silencia el aviso del «vigilante de
  versión» (aviso en Network Admin + email) que aparecía al detectar que Vigilante era más
  nuevo que la versión validada. El aviso era solo informativo: **nunca desactivó el bloqueo
  de login ni la sincronización**.

### Compatibilidad
- **Revisado y validado contra Vigilante 2.9.2.** No requiere cambios de código. Se verificaron
  los cuatro puntos de acoplamiento con Vigilante:
  - **Validación de esquema** (`Vigsync_Detector::validate_schema`): las claves exigidas
    (`modules`, `login_security`, `firewall`, `login_security.custom_login_url`) siguen
    presentes → el sync no se aborta.
  - **Motor de sync** (copia completa + preservación por sitio): las secciones nuevas de 2.9.x
    se replican automáticamente y Vigilante rellena los valores por defecto que falten con
    `array_merge_deep`.
  - **Bloqueo de login** (`Vigsync_Login_Guard`): es autocontenido (replica las exclusiones y
    el 404 de Vigilante, solo depende de `VIGILANTE_VERSION` y del slug de custom-login, y
    engancha `login_init` del core). El texto del 404 sigue siendo idéntico al de Vigilante
    2.9.2 (`The page you are looking for does not exist.` / `404 Not Found`), por lo que los
    subsitios siguen siendo indistinguibles.
  - **Campos preservados por sitio** (listas de IPs, `login_security.two_factor`,
    `security_headers.csp.report_uri`, `login_security.custom_login_url`): todos siguen en las
    mismas rutas.
- **No se añaden nuevas opciones de sincronización.** Los cambios de Vigilante 2.8.0 → 2.9.2 se
  limitan al escáner de integridad de ficheros (menos falsos positivos con temas legítimos,
  CRLF/BOM, verificación tras actualizar, SHA-256, exclusión de `.css` por defecto): son
  cambios de comportamiento del escáner, no de esquema ni de login. Los campos nuevos del
  esquema (p. ej. `firewall.trusted_proxy_header` y las exclusiones de `file_integrity`) son
  **uniformes en toda la red** en un multisite en subdirectorio (mismo dominio/servidor/proxy),
  así que copiarlos del sitio principal es lo correcto y no necesitan preservación por sitio.

## [2.0.0] - 2026-06-28

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

[Sin publicar]: https://github.com/communikt/vigilante-network-sync/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/communikt/vigilante-network-sync/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/communikt/vigilante-network-sync/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/communikt/vigilante-network-sync/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/communikt/vigilante-network-sync/releases/tag/v1.0.0
