# Instalación en una red multisite — Vigilante + Vigilante Network Sync

Guía operativa para montar desde cero **Vigilante** y **Vigilante Network Sync** en una red
multisite nueva, en el orden correcto y con las verificaciones que evitan quedarse fuera.

> **Requisito imprescindible:** multisite **en subdirectorio**
> (`example.com/es/`, `example.com/en/`). En redes de **subdominio** la cookie de
> autenticación no se comparte entre sitios, así que el bloqueo de login dejaría al
> administrador fuera de los `wp-admin` de los subsitios: el plugin lo detecta con
> `is_subdomain_install()`, lo **deshabilita por diseño** y avisa en Network Admin.

El orden importa: **primero Vigilante en el sitio principal, después el sync, y el bloqueo
de login al final**. Cada bloque termina con una comprobación.

---

## A. Vigilante en el sitio principal

1. **Instala Vigilante y actívalo en red** (Network Admin → Plugins → *Network Activate*).
   Activarlo en red hace que se cargue en todos los sitios, pero **su configuración sigue
   siendo por sitio**: Vigilante guarda `vigilante_options` con `get_option`/`update_option`
   y no tiene panel de red. Esa es justamente la carencia que cubre este plugin.

2. **Configura todo en el sitio principal**, no en los subsitios: módulos, firewall,
   cabeceras de seguridad, hardening. Motivo: `.htaccess` y `wp-config.php` son ficheros
   **únicos y compartidos** por toda la red.

   > **Con Vigilante 2.9.8 o superior esto ya no depende de tu disciplina:** el propio
   > Vigilante solo permite escribir esos dos ficheros desde el sitio principal y a un
   > administrador de red (`Vigilante_Settings::can_write_shared_files()`). En los subsitios
   > esas secciones aparecen en **solo lectura**, mostrando los valores del principal. Antes de
   > 2.9.8 mandaba «el último que guardaba», y bastaba con que un admin de un subsitio pulsara
   > Guardar sin tocar nada para que una constante desapareciera de `wp-config.php`.
   >
   > Desde 2.9.3 las whitelists de IP y User-Agent del firewall también reescriben reglas en
   > `.htaccess` al guardar. Una razón más para tocar el firewall solo desde el principal.

3. **Activa el custom-login en el principal:** *Vigilante → Login Security → URL de acceso
   personalizada*. Elige el slug y **apúntalo antes de guardar**. La URL resultante es
   `home_url( slug )`, es decir, **relativa a cada sitio**, por lo que el mismo slug es válido
   en toda la red.

4. **Verifica el acceso en una ventana de incógnito ANTES de cerrar tu sesión actual:**
   - `https://principal/tu-slug/` → sale el formulario de login. ✅
   - `https://principal/wp-login.php` → 404. ✅

   Este es el paso que evita el susto. No sigas hasta que la primera URL funcione.

5. **Configura el 2FA de tu usuario en el sitio principal.** Los secretos TOTP de Vigilante
   viven en **tablas por blog** (`{$wpdb->prefix}…totp`), así que no se pueden sincronizar
   copiando opciones. Si siempre entras por el principal, lo enrolas **una sola vez** y la
   cookie de autenticación de red te da acceso al resto.

---

## B. Vigilante Network Sync

6. **Instálalo y actívalo en red.** Por ZIP (Network Admin → Plugins → Añadir nuevo → Subir
   plugin) o copiando la carpeta a `wp-content/plugins/`. Es un plugin **de red**
   (`Network: true`): tiene que activarse con *Network Activate*.

7. Ve a **Network Admin → Configuración → Vigilante Sync**.

8. **Sitio principal (fuente):** por defecto el principal de la red (`get_main_site_id()`),
   configurable por si los IDs cambian. Si el ID guardado dejara de existir, hay *fallback*.

9. **Casillas de sincronización:**
   - ✅ **Sincronizar el slug de custom-login** — déjala marcada (es el valor por defecto).
   - ❌ **Copiar las listas de IPs y User-Agents** — desmarcada. Son específicas de cada sitio.
   - ❌ **Copiar la configuración de 2FA** — desmarcada. Copiar el «método activado» sin el
     secreto TOTP (que vive en una tabla por blog) dejaría al usuario **sin poder validar**.
     Solo tiene sentido marcarla si el método es **e-mail**.

10. **Pulsa «Sincronitza ara»** y revisa la tabla de resultados por sitio: *correcto* /
    *sin cambios* / *error*. Si el esquema de Vigilante no se reconociera, el sync **aborta
    antes de escribir nada** y te lo dice.

11. **Activa «Bloquejar el login als subsites».** Solo es activable si la red es de
    subdirectorio y el principal tiene custom-login configurado. A partir de aquí los
    subsitios responden **404** a `wp-login.php` y al slug, **sin revelar el slug secreto**.

12. **Verifica en incógnito:**
    - `https://subsitio/wp-login.php` → 404. ✅
    - `https://subsitio/tu-slug/` → 404. ✅
    - `https://principal/tu-slug/` → login OK. ✅
    - Tras entrar por el principal, navega a `https://subsitio/wp-admin/` → entra con la
      cookie de red, sin volver a autenticarte. ✅

---

## C. Operativa del día a día

- **El sync es manual.** Cada vez que cambies algo de Vigilante en el sitio principal, vuelve
  a Network Admin → Vigilante Sync y pulsa **«Sincronitza ara»**. No hay propagación
  automática (es deliberado: escribir en todos los sitios debe ser una acción consciente).

- **Campos que NO se propagan** (se preservan por sitio):
  - Listas de IPs y User-Agents: `firewall.ip_whitelist` / `ip_blacklist` / `ua_whitelist` /
    `ua_blacklist`, `login_security.ip_whitelist`, `activity_log.excluded_ips`.
  - `login_security.two_factor` (secretos TOTP por blog).
  - `email.additional_recipients` (quién recibe los avisos de cada sitio).
  - `security_headers.csp.report_uri` (URL absoluta por sitio) — **siempre** se preserva.

- **Ajustes que ya no se copian** (con Vigilante 2.9.8+): todo lo que solo se escribe en
  `wp-config.php` y `.htaccess` — la sección `security_headers` entera, la parte de
  `wp_hardening` que escribe constantes y las protecciones de ficheros de `firewall`. Los
  gobierna el sitio principal para toda la red, así que copiarlos a un subsitio no haría nada.

  > En multisite los **IDs de usuario son globales**, así que las exclusiones por usuario y
  > los roles sí se replican correctamente.

- **Actualizaciones de Vigilante:** cuando cambie de versión aparecerá un aviso en Network
  Admin y se enviará un email (una vez por versión) al destinatario configurado. Es
  **informativo**: nunca desactiva el bloqueo ni el sync. Se silencia publicando una versión
  de este plugin con la cabecera `Vigilante compat:` actualizada, tras revisar que el esquema
  de `vigilante_options` sigue siendo compatible.

  > ⚠️ **Antes de actualizar Vigilante en una red, guarda una copia del `.htaccess` de la
  > raíz.** Desde la 2.9.9, Vigilante reescribe su bloque del `.htaccess` una vez por versión,
  > pero en multisite esa reescritura **no llega a aplicarse**: corre en cualquier petición,
  > incluidas las anónimas, y en el sitio principal la comprobación de permisos falla para un
  > visitante sin sesión, con lo que se marca como hecha sin haber escrito nada. En la 2.10.0
  > esto importa más, porque de esa misma rama cuelga la captura de la copia del `.htaccess`
  > en la que se apoya la recuperación de los ajustes de Cabeceras — y es **de una sola
  > oportunidad**. Con una copia manual del fichero siempre puedes releer los valores que
  > tenías. Es un asunto de Vigilante, no de este plugin, y está reportado a su autor.

- **Actualizaciones de este plugin:** llegan por GitHub Releases vía Plugin Update Checker.
  Política recomendada: **solo notificación** (no auto-update silencioso, porque afecta al
  login) y flujo **canary** — actualizar primero en una web de pruebas, verificar que el
  login sigue entrando por el slug del principal, y después propagar.

---

## Checklist rápido

```
[ ] Red en subdirectorio (no subdominio)
[ ] Vigilante activado en red
[ ] Config de Vigilante hecha SOLO en el sitio principal
[ ] Custom-login activo en el principal + slug apuntado
[ ] Acceso verificado en incógnito ANTES de cerrar sesión
[ ] 2FA enrolado en el sitio principal
[ ] Network Sync activado en red
[ ] Sitio principal (fuente) correcto en la config de red
[ ] «Sincronitza ara» ejecutado, tabla de resultados sin errores
[ ] Bloqueo de login activado
[ ] 404 verificado en los subsitios + acceso por cookie de red
```

---

## Si algo va mal

Consulta **[RECUPERACION-EMERGENCIA.md](RECUPERACION-EMERGENCIA.md)**. En resumen, de menos a
más invasivo:

1. Kill-switch en `wp-config.php`: `define( 'VIGSYNC_DISABLE_LOGIN_GUARD', true );`
2. WP-CLI por SSH: `wp plugin deactivate vigilante-network-sync --network`
3. FTP: renombrar la carpeta del plugin a `vigilante-network-sync.OFF`

Y recuerda las invariantes: el **sitio principal nunca se bloquea**, el bloqueo es
**fail-open** (si falla cualquier precondición, no actúa) y desactivar el plugin devuelve
siempre el login estándar.
