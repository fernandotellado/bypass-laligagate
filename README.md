# ¿Hay ahora fútbol? – Bypass #LaLigaGate

Plugin para WordPress que gestiona el proxy de Cloudflare automáticamente durante los bloqueos de IP por partidos de fútbol en España.

## ¿Por qué?

Cada vez que hay fútbol en España se aplican bloqueos judiciales masivos de IPs para combatir la piratería de retransmisiones. Estas órdenes de bloqueo afectan a IPs de Cloudflare que comparten miles de webs legítimas, dejándolas inaccesibles para los usuarios de operadoras españolas.

Si tu web está detrás del proxy de Cloudflare (la nubecita naranja), puede quedar bloqueada aunque no tenga nada que ver con el fútbol.

## ¿Qué hace el plugin?

El plugin consulta [hayahora.futbol](https://hayahora.futbol/) cada X minutos (configurable). Si detecta bloqueos activos desactiva el proxy de Cloudflare en los registros DNS que hayas elegido, pasando de **Proxied (CDN)** a **DNS Only**. Cuando terminan los bloqueos y pasa un periodo de espera (también configurable), reactiva el proxy automáticamente. También tiene botones de activación y desactivación manual del proxy.

Desde la versión 1.5.0 puedes elegir qué cuenta como bloqueo:

- **Cualquier bloqueo en España** (por defecto): modo preventivo, no espera a que bloqueen tu dominio y actúa en cuanto hay bloqueos activos. Nunca se le escapa uno, pero desde que empezó la liga hay IPs bloqueadas casi todos los fines de semana, así que te quedas sin CDN ni WAF muchas horas aunque tu web no esté afectada.
- **Solo si bloquean las IPs de esta web**: resuelve las IPs públicas de los registros que gestionas y las compara con las listas de hayahora.futbol, actuando solo cuando alguna coincide. Es lo mismo que hace el comprobador de su web, pero automático.

En ambos casos el ajuste de **ISPs mínimos** filtra por cuántos operadores distintos están bloqueando, para descartar el fallo puntual de red de uno solo.

## Qué hace

- Monitorización automática cada 5-60 minutos (configurable)
- Desactiva el proxy al detectar bloqueos, lo reactiva cuando pasan
- Dos modos de detección: cualquier bloqueo en España, o solo los que afectan a las IPs de tu web
- Filtrado por operadores: solo actúa cuando hay bloqueos en varios ISPs, evitando falsos positivos por problemas de red de uno solo (configurable)
- Periodo de espera configurable para evitar cambios rápidos de estado
- Si los datos de la fuente están parados o no se pueden leer, no se toca nada y se avisa en los ajustes
- Botones manuales para forzar proxy OFF o restaurar ON
- Email al administrador cuando el proxy se desactiva o reactiva automáticamente (desactivable)
- Soporte para Global API Key y API Token de Cloudflare
- Endpoint para cron externo con token de seguridad (opcional)
- Al desactivar el plugin se restaura el proxy automáticamente
- Opción para conservar los datos del plugin al borrarlo (borrado activo por defecto)

## Estructura

```
bypass-laligagate/
├── bypass-laligagate.php            # Bootstrap, helpers, activación/desactivación
├── includes/
│   ├── class-admin-page.php         # Página de ajustes (Herramientas > Bypass LaLigaGate)
│   ├── class-ajax-handler.php       # Acciones AJAX (test, check, proxy off/on)
│   ├── class-block-checker.php      # Consulta hayahora.futbol
│   ├── class-cloudflare-api.php     # API de Cloudflare (auth, DNS, proxy)
│   ├── class-cron-manager.php       # WP-Cron y lógica automática
│   ├── class-email-notifier.php     # Emails al admin en cambios de estado
│   └── class-own-ip-resolver.php    # Resuelve por DoH las IPs públicas de la web
├── assets/
│   ├── css/admin.css                # Estilos de la página de ajustes
│   └── js/admin.js                  # JavaScript para AJAX y UI
├── uninstall.php                    # Limpieza al borrar el plugin (condicional)
├── changelog.txt                    # Histórico completo de versiones
└── readme.txt                       # Readme con formato de WordPress
```

Los datos del plugin se almacenan en opciones independientes de `wp_options` para evitar que se sobreescriban entre sí:

- `ayudawp_blg_settings` — credenciales, intervalos, registros seleccionados, modo de detección
- `ayudawp_blg_dns_cache` — caché de registros DNS de Cloudflare
- `ayudawp_blg_bypass_state` — estado del bypass, última comprobación, override manual
- `ayudawp_blg_block_log` — historial de episodios de bloqueo para el resumen por email
- `ayudawp_blg_own_ips` — IPs públicas de la web, resueltas con el proxy activo

## Instalación

1. Descarga el zip desde la [página de releases](https://github.com/fernandotellado/bypass-laligagate/releases) o clona el repositorio
2. Sube la carpeta `bypass-laligagate` a `wp-content/plugins/`
3. Activa el plugin en el escritorio de WordPress
4. Ve a **Herramientas > Bypass LaLigaGate**

### Nota importante
Si tienes más instalaciones de WordPress en un mismo dominio (p.ej., tudominio.com/blog/ o blog.tudominio.com) no hace falta instalar el plugin en cada WordPress, solo tienes que instalarlo y configurarlo en la instalación del dominio principal (tudominio.com). En el caso de los subdominios deben estar añadidos en los registros DNS de Cloudflare, y así podrás también añadirlos a los ajustes del plugin para que se active o desactive el proxy cuando haya bloqueos.

## Configuración

### Credenciales de Cloudflare

Necesitas una de estas dos opciones:

**Global API Key** (más sencillo, ya existe en tu cuenta):
1. Entra en [Mi perfil > API Tokens](https://dash.cloudflare.com/profile/api-tokens) de Cloudflare
2. Junto a "Global API Key", pulsa **Ver** y cópiala
3. En el plugin selecciona "Global API Key", pega la clave y pon tu email de Cloudflare

**API Token** (más seguro, permisos limitados):
1. Ve a [Mi perfil > API Tokens](https://dash.cloudflare.com/profile/api-tokens) > **Crear token**
2. Usa la plantilla "Editar zona DNS"
3. Permisos: `Zona > DNS > Editar` y `Zona > Zona > Leer`
4. Recursos de zona: Incluir > Zona específica > selecciona tu dominio
5. Confirma y copia el token (solo se muestra una vez)

### ID de zona

Está en el dashboard de Cloudflare de tu dominio, panel derecho de la vista general (Overview), sección "API". Es un código alfanumérico de 32 caracteres.

### Registros DNS

1. Rellena las credenciales y pulsa **Probar conexión y cargar DNS**
2. Marca los registros cuyo proxy quieras gestionar (normalmente el dominio raíz y www)
3. Pulsa **Guardar cambios**

### Automatización

- **Intervalo de comprobación**: cada cuántos minutos consulta hayahora.futbol (por defecto 15)
- **Periodo de espera**: minutos que espera antes de reactivar el proxy después de que los bloqueos terminen (por defecto 60)

### Opciones generales

- **Qué se considera un bloqueo**: `Cualquier bloqueo en España` (por defecto) o `Solo si bloquean las IPs de esta web`. El segundo modo necesita poder resolver las IPs públicas de tus registros; si no lo consigue vuelve solo al primero antes que arriesgarse a dejar la web caída
- **ISPs mínimos para activar bypass**: número mínimo de operadores distintos con bloqueos para considerar que hay un bloqueo real. Con 2 o más se descartan los fallos puntuales de red de un solo operador (por ejemplo, MásMóvil cortando IPs de AWS/Akamai sin que sea un bloqueo de La Liga). Hay cinco operadores bajo la orden judicial, así que el máximo es 5
- **Avisos por email**: activa o desactiva los emails al administrador cuando el proxy cambia de estado automáticamente (activo por defecto)
- **Borrado de datos**: si está marcado, al borrar el plugin se eliminan todas las opciones de la base de datos. Si lo desmarcas, la configuración se conserva para una futura reinstalación (activo por defecto)

## Cómo funciona

```
Se consulta blocked-any.txt en hayahora.futbol
        ↓
¿Los datos son recientes?  ──── NO ──→ no se toca nada y se avisa en los ajustes
        ↓ SÍ
¿Hay IPs bloqueadas?
        ↓ SÍ
Modo "solo mi IP": ¿alguna es de esta web?   ──── NO ──→ no se hace nada
Modo "cualquier bloqueo": cuentan todas
        ↓ SÍ
¿Bloqueos en X o más operadores distintos? (configurable)
   (se consultan los 5 TXT por ISP)
        ↓ SÍ
Plugin desactiva proxy (DNS Only) en los registros seleccionados
        ↓
Email al admin: "Proxy desactivado por bloqueos de La Liga" (si está activado)
        ↓
    [pasan los bloqueos]
        ↓
    [pasa el periodo de espera]
        ↓
Plugin reactiva proxy (CDN)
        ↓
Email al admin: "Proxy reactivado" (si está activado)
```

Si fuerzas el proxy OFF manualmente, el cron automático **no lo cambiará** hasta que pulses "Restaurar proxy ON". Así puedes intervenir sin que el automatismo te lo deshaga.

## Requisitos

- WordPress 5.6 o superior
- PHP 7.4 o superior
- Dominio con DNS gestionados por Cloudflare
- Credenciales de la API de Cloudflare (Global API Key o API Token)

## Servicios externos

- **[hayahora.futbol](https://hayahora.futbol/)** — Datos de bloqueos activos. Se consulta `blocked-any.txt` en cada comprobación y, cuando hace falta el desglose, los cinco `blocked-<isp>.txt`. Sin bloqueos ese fichero está vacío; en el momento de más actividad medido hasta ahora (22 de agosto de 2026, con 886 direcciones bloqueadas a la vez) rondaría los 13 KB. El JSON completo (`data.json`) es solo un respaldo para cuando fallan los TXT, y se usa como máximo una vez por hora: es el histórico entero, va por 2,7 MB y decodificarlo cuesta unas once veces eso en memoria. Se envía una petición GET con la URL del sitio en el User-Agent. No se transmiten datos personales.
- **[API de Cloudflare](https://developers.cloudflare.com/api/)** — Lectura de registros DNS y cambio del estado del proxy. Las credenciales se envían por HTTPS.
- **[Cloudflare DNS](https://developers.cloudflare.com/1.1.1.1/encryption/dns-over-https/) y [Google Public DNS](https://developers.google.com/speed/public-dns/docs/doh)** — Solo en el modo "solo si bloquean las IPs de esta web", para resolver por DNS sobre HTTPS las IPs públicas de los registros que gestionas. Se envía el nombre del registro, nada más. Se usa DoH y no el resolutor del servidor porque muchos alojamientos compartidos responden a tu propio dominio con la IP local de la máquina, lo que dejaría el modo inservible sin avisar.

## Hoja de ruta

Lo que hay pendiente, por orden de prioridad. No hay fechas: esto se hace en ratos libres y depende bastante de lo que vaya publicando hayahora.futbol y de lo que se rompa cada jornada.

### Primero

- **Avisar de que el bypass expone la IP de origen.** Mientras el proxy está en DNS Only tu dominio resuelve a la IP real del servidor, y esa resolución queda registrada para siempre en los servicios de DNS pasivo. A partir de la primera activación, cualquiera puede saltarse Cloudflare atacando esa IP directamente aunque el proxy vuelva a estar activo. No es un fallo del plugin, es lo que significa quitar el proxy, pero hoy no se dice en ninguna parte y conviene saberlo para poder mitigarlo con un cortafuegos en el origen.
- **Dejar de reimprimir las credenciales de Cloudflare en el formulario.** La Global API Key da acceso completo a la cuenta, no solo al DNS, y ahora mismo viaja en el `value` del campo cada vez que se abre la pantalla de ajustes. Debería mostrarse enmascarada y guardarse solo cuando llegue un valor nuevo.
- **Internacionalización.** El plugin declara `Text Domain` pero tiene todos los textos en duro. Hay que envolverlos en las funciones de traducción y generar el `.pot`. Es requisito para publicarlo en el repositorio oficial.

### Después

- **"¿Esto me afecta a mí?", un diagnóstico en la pantalla de ajustes.** El comprobador de hayahora.futbol distingue entre "bloqueada ahora" y "está en la lista de bloqueo aunque ahora no lo esté", y esa segunda respuesta es justo la que hace falta para elegir modo de detección con criterio: si las IPs de tu web han aparecido alguna vez, el modo "solo mi IP" te sirve; si no han aparecido nunca, no hará nada jamás. Sería una consulta a petición, con un botón, nunca desde el cron.
- **Historial visible en el escritorio.** Los episodios de bloqueo ya se guardan, pero solo salen en el resumen por email. Falta una tabla con las últimas jornadas, cuánto duró cada bloqueo y qué operadores participaron.
- **Aviso mientras el bypass está activo.** Un aviso en el escritorio recordando que la web está sin CDN ni WAF, con el tiempo que lleva así, para que no se quede olvidado.
- **Comandos de WP-CLI.** `wp blg status`, `wp blg check` y `wp blg proxy on|off` para gestionarlo sin entrar al escritorio y poder meterlo en scripts de mantenimiento.

### Más adelante

- **Integración con Salud del sitio.** Credenciales válidas, cron al día, IPs resueltas, antigüedad de los datos de la fuente y si el modo configurado se puede aplicar de verdad.
- **Avisos por webhook.** Además del email, poder enviar los cambios de estado a Slack, Telegram o cualquier endpoint, que es donde mira la gente que gestiona varias webs.
- **Gestión en multisitio.** Ahora cada sitio de la red se configura por separado. Tendría sentido poder definir las credenciales y los registros una vez desde la administración de red.
- **IPv6.** En cuanto hayahora.futbol publique listas de IPv6, los registros AAAA podrán verificarse igual que los A. Hasta entonces se marcan como "sin verificar" en la tabla de DNS.

### Descartado, con su motivo

- **`blocked.dns.hayahora.futbol` como tercera fuente.** La web publica ese dominio, cuyos registros A son las IPs bloqueadas en cualquier operador, y parecía la fuente más barata posible: una consulta DNS en vez de una petición HTTP. No sirve aquí. El pico medido son 886 direcciones simultáneas, o sea unos 14 KB de respuesta DNS, muy por encima de lo que cabe en un datagrama UDP, así que cualquier resolutor por el camino puede devolverla truncada. Y una lista truncada produce justo el error que no nos podemos permitir: un "tu IP no está bloqueada" cuando sí lo está. Para lo que la propia web propone, enrutado condicional en un router, va perfecto, porque ahí equivocarse es barato.
- **Adelantarse al calendario de partidos.** Sería lo ideal (desactivar el proxy antes del pitido inicial), pero hayahora.futbol no publica calendario y montar uno propio con horarios de partidos significa depender de otra fuente que se rompe cada temporada. Mientras el intervalo de comprobación sea de 5 minutos, la ventana de exposición es asumible.
- **Soporte para otros CDN.** El plugin va sobre la API de Cloudflare y el conmutador Proxied/DNS Only, que no tiene equivalente directo en otros proveedores. Si aparece demanda real se estudiará, pero no es un cambio pequeño.

¿Echas algo en falta o tienes una idea mejor? Abre un [issue](https://github.com/fernandotellado/bypass-laligagate/issues).

## Esto es un parche, no la solución

El plugin mantiene tu web en pie mientras dure el bloqueo, pero no arregla el fondo del asunto. Una sentencia permite bloquear IPs enteras de CDN durante los partidos y por el camino caen miles de webs que no tienen nada que ver con el fútbol. Mientras lo sigamos tapando con apaños técnicos, bloquear sale gratis.

Si te ha afectado, deja constancia y súmate a lo que ya está en marcha:

- [RootedCon y HackBCN](https://rootedcon.com/blog/rootedcon-y-hackbcn-se-unen-para-ofrecer-apoyo-tecnico-y-judicial-a-las-victimas-de-laligagate/) ofrecen apoyo técnico y judicial a los afectados, con un [modelo de denuncia](https://rootedcon.com/blog/laligagate-plantilla-de-denuncia-a-la-aepd/) listo para presentar.
- [STOP #LaLigaGate](https://asociacionstartups.es/laligagate/), de la Asociación Española de Startups, recopila casos de negocios digitales afectados para defenderlos en bloque.
- [OONI Probe](https://ooni.org/install/) mide el bloqueo desde tu conexión y lo deja registrado en una [base de datos pública e independiente](https://explorer.ooni.org/search?test_name=web_connectivity&failure=true&only=anomalies&probe_cc=ES).

Cuantos más casos documentados haya, más difícil es sostener que esto no afecta a nadie.

## Registro de cambios

### 1.5.0
- Añadido: ajuste **Qué se considera un bloqueo**, con dos modos. `Cualquier bloqueo en España` es el comportamiento de siempre y sigue siendo el valor por defecto, así que al actualizar no cambia nada. `Solo si bloquean las IPs de esta web` resuelve las IPs públicas de tus registros y solo desactiva el proxy cuando alguna aparece en las listas de hayahora.futbol, que es lo que evita quedarse sin CDN ni WAF cada fin de semana desde que empezó la liga.
- Añadido: resolución de las IPs propias por DNS sobre HTTPS (Cloudflare y, si falla, Google), con `gethostbynamel()` como último recurso. Se usa DoH porque muchos alojamientos compartidos responden a tu propio dominio con la IP local de la máquina. Las IPs se resuelven solo mientras el proxy está activo y se guardan en caché seis horas, porque durante el bypass los registros apuntan al origen y volver a resolverlos haría que el proxy se restaurase un ciclo después de cada bypass, en bucle.
- Añadido: si el modo "solo mi IP" no consigue resolver ninguna dirección, el plugin vuelve por su cuenta al modo global y lo indica en la fuente (`txt+sin-ips`), antes que dar por buena una web accesible que no lo está.
- Añadido: cuando los datos de hayahora.futbol llevan horas sin actualizarse, la comprobación se trata como un error y no como un "no hay bloqueos". Un `blocked-any.txt` vacío servido por un generador parado habría restaurado el proxy en mitad de un partido, que es justo el fallo que este plugin existe para evitar. El margen son 3 horas, filtrable con `ayudawp_blg_max_data_age`, y la ausencia de la cabecera `Last-Modified` no cuenta como obsolescencia: no poder juzgar la antigüedad no es evidencia de nada.
- Añadido: la pantalla de ajustes y el botón "Comprobar ahora" avisan cuando una comprobación no ha podido concluir nada, explicando el motivo y que se mantiene el estado anterior. Antes las dos situaciones se veían igual desde fuera ("última comprobación: hace 20 minutos") y significan cosas opuestas.
- Añadido: cerrojo de ejecución, para que WP-Cron y el cron externo no puedan disparar dos comprobaciones a la vez y mandar cambios opuestos del mismo registro a Cloudflare. Caduca a los 5 minutos, así que un ciclo interrumpido no deja el plugin bloqueado.
- Añadido: la pantalla de ajustes muestra el modo de detección, las IPs vigiladas, cuáles están bloqueadas y qué operadores las bloquean.
- Añadido: los registros AAAA seleccionados se marcan como "sin verificar" en la tabla de DNS, porque hayahora.futbol solo publica direcciones IPv4 y no hay lista contra la que compararlos.
- Añadido: sección legal al final de la pantalla de ajustes con los recursos de RootedCon, HackBCN, la Asociación Española de Startups y OONI.
- Añadido: las respuestas de los endpoints se validan como IPv4 línea a línea, para que una página de error servida con un HTTP 200 no se interprete como una lista de miles de IPs bloqueadas.
- Añadido: nueva opción `ayudawp_blg_own_ips` y nuevos filtros `ayudawp_blg_isp_txt_url`, `ayudawp_blg_max_data_age`, `ayudawp_blg_json_cooldown`, `ayudawp_blg_txt_max_bytes` y `ayudawp_blg_json_max_bytes`. El "ISPs mínimos" se limita a 5, que son los operadores bajo la orden judicial.
- Mejorado: el recuento de operadores usa los cinco ficheros `blocked-<isp>.txt` en vez del JSON completo, que ya va por 2,7 MB. Los ajustes con "ISPs mínimos" mayor que 1 pasan de descargar 2,7 MB por comprobación a unos pocos KB.
- Mejorado: el desglose por operador solo se descarga cuando decide algo (mínimo de ISPs mayor que 1) o cuando informa de algo útil (modo "solo mi IP"). En el caso habitual, sin bloqueos, la comprobación es una sola petición mínima.
- Mejorado: el respaldo JSON deja de poder repetirse en cada ciclo. Ese fichero es el histórico completo, solo crece, y decodificarlo cuesta unas once veces su tamaño en memoria (medido: 2,69 MB de texto se convierten en 28,7 MB de arrays), así que un fallo prolongado de los TXT significaba descargar y procesar eso cada pocos minutos. Ahora se espera una hora entre usos, se limita el tamaño de la respuesta y no se decodifica si no cabe en la memoria disponible, en cuyo caso se mantiene el estado en vez de tumbar el cron con un error fatal.
- Mejorado: si los TXT fallan por estar obsoletos no se recurre al JSON, porque lo escribe el mismo proceso y ya se sabe la respuesta.
- Corregido: con la fuente TXT, el número de IPs bloqueadas se guardaba en el campo de "ISPs afectados", así que el resumen por email podía decir "ISPs afectados: 227". Ahora se guardan las dos cifras por separado y el email distingue una de otra. Los episodios anteriores a esta versión se muestran como IPs, que es lo que el número era en realidad.
- Cambiado: `AyudaWP_BLG_Block_Checker::check_status()` recibe ahora un array de argumentos (`min_isps`, `mode`, `own_ips`) en lugar de un entero.

El histórico completo de versiones está en [`changelog.txt`](changelog.txt).

## Licencia

GPLv2 o posterior. Puedes usar, modificar y distribuir el plugin libremente bajo los términos de la GPL.

## Autor

Desarrollado por [Fernando Tellado](https://ayudawp.com) para los clientes de [Mantenimiento WordPress de AyudaWP](https://mantenimiento.ayudawp.com) a partir de [este otro desarrollo](https://github.com/dcarrero/cf-football-bypass) de David Carrero.
