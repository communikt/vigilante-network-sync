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

> Para montar una red **desde cero** (Vigilante incluido) en el orden correcto y con las
> verificaciones que evitan quedarse fuera, sigue la guía paso a paso
> [`docs/INSTALACION-MULTISITE.md`](docs/INSTALACION-MULTISITE.md) del repositorio.

## Uso

1. **Sitio principal:** elige el sitio del que se copiará la configuración (por
   defecto el principal de la red).
2. **Sincronización:** pulsa **«Sincronitza ara»**. Verás una tabla con el resultado
   por sitio (correcto / sin cambios / error).
3. **Login unificado (opcional):** activa **«Bloquejar el login als subsites»**. Solo es
   posible en redes de **subdirectorio** y si el sitio principal tiene configurado el
   *custom-login* de Vigilante. Los subsitios responderán 404 a cualquier intento de login
   (sin revelar el slug); el login real solo funciona en el principal.

### Qué NO se copia a los subsitios

**1. Ajustes de fichero compartido** (con Vigilante 2.9.8 o superior). `wp-config.php` y el
`.htaccess` de la raíz son ficheros únicos de toda la red y desde 2.9.8 **solo los escribe el
sitio principal**; los subsitios ven esas secciones en modo solo lectura, mostrando los valores
del principal. Copiarlas no tendría efecto, así que el sync las preserva del destino:

- toda la sección `security_headers`,
- la parte de `wp_hardening` que escribe constantes (`disallow_file_edit`, `force_ssl_admin`,
  `wp_debug`…),
- la mitad `.htaccess` de `firewall` (`protect_wp_config`, `limit_http_methods`,
  `disable_directory_browsing`…).

La lista se lee de Vigilante (`Vigilante_Settings::get_shared_file_settings()`), no está
duplicada aquí. Con versiones anteriores a 2.9.8 el comportamiento es el de siempre.

**2. Campos propios de cada sitio:**

- Listas de IPs y de User-Agents (`firewall.ip_whitelist/ip_blacklist/ua_whitelist/ua_blacklist`,
  `login_security.ip_whitelist`, `activity_log.excluded_ips`) — marca la casilla para copiarlas.
  Al copiarlas, las entradas de **IP** que nunca podrían coincidir (`999.999.999.999/99`, una
  palabra suelta…) se descartan con el mismo validador que usa Vigilante 2.9.9 al guardar, y
  se nombran en el log del sync. Las de User-Agent no se tocan.
- **Configuración de 2FA** (`login_security.two_factor`) — marca la casilla para copiarla.
  **Con Vigilante anterior a 2.11.10:** hazlo solo si el método es **e-mail**, porque los
  secretos TOTP viven en tablas por sitio y copiar el "método activado" sin el secreto dejaría
  al usuario sin poder validar. **Desde Vigilante 2.11.10 es al revés y conviene copiarla:**
  la política de segundo factor pasó a ser la **unión** de lo que pide cada sitio de la red,
  así que un `two_factor.enabled` residual en cualquier subsitio impone segundo factor a
  cuentas de toda la instalación; y la matrícula TOTP se busca en la tabla de todos los sitios
  que la cuenta puede alcanzar, de modo que copiar la configuración ya no deja a nadie fuera.
  La página de ajustes detecta esta situación, avisa y **lista los sitios divergentes**.
  Compara lo que cada sitio **pide de verdad**, con el mismo criterio que Vigilante: un sitio
  con el 2FA activado pero sin ningún rol marcado no pide nada a nadie y cuenta como apagado.
- `email.additional_recipients` — quién recibe los avisos de un sitio es decisión de ese sitio.
- `security_headers.csp.report_uri` (URL absoluta por sitio) — siempre se preserva.
- Cualquier campo que Vigilante declare en `get_user_data_keys()` y que este plugin no conozca:
  se preserva por defecto, para que una versión futura de Vigilante no nos pille copiando
  campos nuevos sin criterio.

**Excepciones que sí se propagan** aunque Vigilante las trate como datos del usuario, porque en
una red **en subdirectorio** son uniformes por definición: `firewall.trusted_proxy_header` y
`firewall.trusted_proxies`, `user_security.insecure_usernames` y las exclusiones de
`file_integrity` (el escaneo recorre el mismo sistema de ficheros en todos los sitios).

> Las dos claves de proxy viajan **juntas** desde la v2.0.8, y no es un detalle. Desde Vigilante
> 2.11.9 la cabecera reenviada (`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP`) solo se acepta
> si la conexión viene de un par que Vigilante pueda reconocer como proxy, y con
> `trusted_proxies` vacía eso se reduce a las direcciones de la propia red privada (o los rangos
> de Cloudflare). Copiar solo la cabecera dejaba a los subsitios de una red detrás de un proxy
> público viendo a **todos** los visitantes con la dirección del proxy.

> En multisite los **IDs de usuario son globales**, así que las exclusiones por
> usuario y los roles sí se replican correctamente.

## Recomendaciones de configuración

- **Modo bloqueo (recomendado para 2FA único, red multi-idioma):** configura el
  custom-login en el sitio principal, mantén el slug copiado a los subsitios (sync) y
  activa el bloqueo de login. El login solo funciona en el principal; los subsitios
  responden 404 **sin revelar el slug**. El 2FA se configura una sola vez y la cookie de
  red cubre el resto. **Requiere multisite en subdirectorio** (cookie de auth compartida).
- **Modo independiente:** mismo slug en todos los sitios (vía sync) y bloqueo
  desactivado. Cada sitio oculta su propio login. Con Vigilante anterior a 2.11.10 el 2FA se
  configura por sitio (las tablas TOTP son por sitio; **no** marques copiar la config de 2FA con
  TOTP). Desde la 2.11.10 el 2FA ya no es por sitio ni aunque el login lo sea: la política es la
  unión de toda la red, así que lo coherente es **marcar la casilla** y mantenerlo uniforme.
- **`.htaccess` / `wp-config.php`** son únicos y compartidos en la red: configúralos desde el
  **sitio principal**. Con Vigilante 2.9.8 o superior esto ya no es solo una recomendación —
  el propio Vigilante rechaza esas escrituras desde cualquier otro sitio— y el sync replica
  al resto únicamente la configuración de PHP-runtime.

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

- **Guía completa de recuperación** (kill-switch → WP-CLI → FTP):
  [`docs/RECUPERACION-EMERGENCIA.md`](docs/RECUPERACION-EMERGENCIA.md) del repositorio.

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
