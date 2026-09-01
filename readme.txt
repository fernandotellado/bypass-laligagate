=== Bypass LaLigaGate ===
Contributors: fernandot, ayudawp
Tags: cloudflare, dns, futbol, liga, proxy
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestiona el proxy de Cloudflare durante los bloqueos de IP por partidos de fútbol en España.

== Descripción ==

Durante los partidos de fútbol en España se aplican bloqueos judiciales masivos de IPs para combatir la piratería de retransmisiones. Estos bloqueos afectan a webs legítimas alojadas tras el proxy de Cloudflare, que comparten IPs con otros sitios que nada tienen que ver con el fútbol.

Este plugin monitoriza automáticamente los bloqueos activos consultando hayahora.futbol y, cuando detecta actividad, desactiva el proxy de Cloudflare en los registros DNS que elijas, pasando de Proxied (CDN) a DNS Only. Cuando terminan los bloqueos y pasa el periodo de espera configurado, reactiva el proxy automáticamente.

Puedes elegir qué cuenta como bloqueo:

- Cualquier bloqueo en España (por defecto): modo preventivo, no espera a que bloqueen tu dominio y actúa en cuanto hay bloqueos activos. Nunca se le escapa uno, pero desde que empezó la liga hay IPs bloqueadas casi todos los fines de semana, así que te quedas sin CDN ni WAF muchas horas aunque tu web no esté afectada.
- Solo si bloquean las IPs de esta web: resuelve las IPs públicas de los registros que gestionas y las compara con las listas de hayahora.futbol, actuando solo cuando alguna coincide.

Este plugin es un parche para seguir online, no la solución. El problema de fondo es legal y en la pantalla de ajustes tienes los enlaces a las iniciativas que están plantando cara a los bloqueos.

= Qué hace =

- Monitorización automática cada X minutos (configurable de 5 a 60)
- Dos modos de detección: cualquier bloqueo en España, o solo los que afectan a las IPs de tu web
- Periodo de espera configurable para evitar cambios rápidos de estado
- Si los datos de la fuente están parados o no se pueden leer, no se toca nada y se avisa en los ajustes
- Control manual: botones para forzar proxy OFF y restaurar proxy ON
- Notificaciones por email al administrador cuando el proxy se desactiva o reactiva automáticamente (desactivable)
- Resumen periódico por email, diario o semanal, con el tiempo que la web habría estado inaccesible
- Filtrado por operadores: solo actúa cuando hay bloqueos en varios ISPs, evitando falsos positivos
- Soporte para Global API Key y API Token de Cloudflare
- Endpoint para cron externo con token de seguridad
- Al desactivar el plugin se restaura el proxy automáticamente

== Instalación ==

1. Sube la carpeta `bypass-laligagate` a `wp-content/plugins/`
2. Activa el plugin desde Plugins en el escritorio de WordPress
3. Ve a Herramientas > Bypass LaLigaGate
4. Configura las credenciales de Cloudflare (Global API Key o API Token + ID de zona)
5. Pulsa "Probar conexión y cargar DNS"
6. Marca los registros DNS que quieras gestionar (normalmente dominio raíz y www)
7. Ajusta el intervalo de comprobación y el periodo de espera
8. Pulsa "Guardar cambios"

***Nota importante:***
Si tienes más instalaciones de WordPress en un mismo dominio (p.ej., tudominio.com/blog/ o blog.tudominio.com) no hace falta instalar el plugin en cada WordPress, solo tienes que instalarlo y configurarlo en la instalación del dominio principal (tudominio.com). En el caso de los subdominios deben estar añadidos en los registros DNS de Cloudflare, y así podrás también añadirlos a los ajustes del plugin para que se active o desactive el proxy cuando haya bloqueos.

= Obtener credenciales de Cloudflare =

**Global API Key (más sencillo):**
1. Entra en tu cuenta de Cloudflare
2. Ve a Mi perfil > API Tokens
3. Junto a "Global API Key" pulsa "Ver" y cópiala
4. También necesitas el email de tu cuenta

**API Token (más seguro):**
1. Ve a Mi perfil > API Tokens > Crear token
2. Usa la plantilla "Editar zona DNS"
3. Permisos necesarios: Zona > DNS > Editar y Zona > Zona > Leer
4. En Recursos de zona: Incluir > Zona específica > selecciona el dominio
5. Filtro de IP y TTL: déjalos sin configurar
6. Confirma y copia el token (solo se muestra una vez)

**ID de zona:**
Está en el dashboard de Cloudflare del dominio, panel derecho de la vista general (Overview), sección "API". Código alfanumérico de 32 caracteres.

== Servicios externos ==

= hayahora.futbol =
El plugin consulta periódicamente https://hayahora.futbol/estado/blocked-any.txt para comprobar si hay bloqueos activos. Cuando necesita saber qué operadores bloquean consulta también https://hayahora.futbol/estado/blocked-movistar.txt y los equivalentes de Vodafone, Orange, Masmovil y DIGI. Si esos ficheros fallan, recurre como mucho una vez por hora a https://hayahora.futbol/estado/data.json, que es el histórico completo y pesa varios megas. Se envía una petición GET con la URL del sitio en el User-Agent. No se transmiten datos personales ni de los visitantes.
URL: https://hayahora.futbol/

= Cloudflare DNS y Google Public DNS (resolución DoH) =
Solo en el modo "solo si bloquean las IPs de esta web", el plugin resuelve por DNS sobre HTTPS las IPs públicas de los registros que gestionas, consultando https://cloudflare-dns.com/dns-query y, si no responde, https://dns.google/resolve. Se envía únicamente el nombre del registro DNS. Se usa DoH en lugar del resolutor del servidor porque muchos alojamientos compartidos responden a tu propio dominio con la IP local de la máquina.
Privacidad de Cloudflare: https://www.cloudflare.com/privacypolicy/
Privacidad de Google: https://policies.google.com/privacy

= API de Cloudflare =
El plugin usa la API oficial de Cloudflare (https://api.cloudflare.com/client/v4/) para leer registros DNS y alternar el estado del proxy (Proxied/DNS Only). Las credenciales se almacenan en la base de datos de WordPress y se envían por HTTPS.
Términos: https://www.cloudflare.com/terms/
Privacidad: https://www.cloudflare.com/privacypolicy/

== Preguntas frecuentes ==

= ¿Es seguro usar este plugin? =
Sí. Solo modifica el estado del proxy de los registros DNS que selecciones (Proxied/DNS Only). No borra ni modifica el contenido de ningún registro DNS.

= ¿Qué pasa si falla la detección de bloqueos? =
No se toca nada: se mantiene el estado que hubiera y la pantalla de ajustes explica por qué. Vale tanto para el caso evidente (la consulta falla o devuelve un error) como para el que no lo es: que los ficheros respondan bien pero lleven horas sin actualizarse, porque un fichero vacío servido por un generador parado se leería como "no hay bloqueos" y restauraría el proxy en mitad de un partido. Equivocarse hacia el bypass cuesta unas horas sin CDN, equivocarse hacia el otro lado deja la web caída, así que ante la duda el plugin no cambia nada.

= ¿Qué pasa si desactivo el plugin durante un bypass activo? =
Al desactivar el plugin se restaura automáticamente el proxy (Proxied/CDN) en todos los registros seleccionados.

= ¿Puedo controlar el bypass manualmente? =
Sí. Hay botones para forzar el proxy OFF o restaurarlo ON. Al forzar manualmente, el cron automático no lo cambiará hasta que tú restaures el proxy.

= ¿Me avisa cuando cambia el estado? =
Sí, por defecto envía un email al administrador del sitio cuando el proxy se desactiva automáticamente por bloqueos y otro cuando se reactiva. Puedes desactivar estos avisos en la sección de opciones generales.

= ¿Funciona con cualquier proveedor de DNS? =
No, solo con Cloudflare. Los DNS del dominio deben estar gestionados por Cloudflare.

= ¿Qué modo de detección me conviene? =
Si te vale con que la web nunca se caiga y no te importa perder el CDN y el WAF durante las jornadas, deja el modo por defecto. Si prefieres conservar el proxy salvo cuando tu web esté realmente afectada, cambia a "solo si bloquean las IPs de esta web". El segundo es más preciso, pero depende de poder resolver tus IPs públicas: si no lo consigue, el plugin vuelve solo al modo global y te lo indica en la pantalla de ajustes.

= ¿Por qué mis registros AAAA aparecen como "sin verificar"? =
Porque hayahora.futbol solo publica direcciones IPv4 y no hay ninguna lista contra la que comparar una IPv6. A esos registros se les cambia el proxy igual que al resto, pero siguiendo lo que digan tus registros IPv4 o el estado general de los bloqueos.

= ¿El plugin arregla el problema de fondo? =
No. Es un parche para que tu web siga siendo accesible durante los bloqueos. El problema de fondo es una sentencia que permite bloquear IPs enteras de CDN, y ahí solo se avanza por la vía legal. En la pantalla de ajustes tienes los enlaces a RootedCon y HackBCN, a la iniciativa STOP #LaLigaGate de la Asociación Española de Startups y a OONI, para documentar el bloqueo desde tu conexión.

== Registro de cambios ==

= 1.5.0 =
- Añadido: ajuste "Qué se considera un bloqueo", con dos modos. "Cualquier bloqueo en España" es el comportamiento de siempre y sigue siendo el valor por defecto, así que al actualizar no cambia nada. "Solo si bloquean las IPs de esta web" resuelve las IPs públicas de tus registros y solo desactiva el proxy cuando alguna aparece en las listas de hayahora.futbol.
- Añadido: resolución de las IPs propias por DNS sobre HTTPS (Cloudflare y, si falla, Google), con gethostbynamel() como último recurso. Las IPs se resuelven solo mientras el proxy está activo y se guardan en caché seis horas, porque durante el bypass los registros apuntan al origen.
- Añadido: si no se consigue resolver ninguna IP propia, el plugin vuelve por su cuenta al modo global y lo indica en la fuente de la comprobación.
- Añadido: cuando los datos de hayahora.futbol llevan horas sin actualizarse, la comprobación se trata como un error y no como un "no hay bloqueos". Un fichero vacío servido por un generador parado habría restaurado el proxy en mitad de un partido. El margen son 3 horas y se puede cambiar con el filtro ayudawp_blg_max_data_age.
- Añadido: la pantalla de ajustes y el botón "Comprobar ahora" avisan cuando una comprobación no ha podido concluir nada, explicando el motivo y que se mantiene el estado anterior.
- Añadido: cerrojo de ejecución, para que WP-Cron y el cron externo no puedan disparar dos comprobaciones a la vez y mandar cambios opuestos del mismo registro a Cloudflare.
- Añadido: la pantalla de ajustes muestra el modo de detección, las IPs vigiladas, cuáles están bloqueadas y qué operadores las bloquean.
- Añadido: los registros AAAA seleccionados se marcan como "sin verificar", porque hayahora.futbol solo publica direcciones IPv4.
- Añadido: sección legal al final de la pantalla de ajustes con los recursos de RootedCon, HackBCN, la Asociación Española de Startups y OONI.
- Añadido: las respuestas de los endpoints se validan como IPv4 línea a línea, para que una página de error servida con un HTTP 200 no se interprete como una lista de miles de IPs bloqueadas.
- Añadido: nueva opción ayudawp_blg_own_ips y nuevos filtros ayudawp_blg_isp_txt_url, ayudawp_blg_max_data_age, ayudawp_blg_json_cooldown, ayudawp_blg_txt_max_bytes y ayudawp_blg_json_max_bytes. El "ISPs mínimos" se limita a 5, que son los operadores bajo la orden judicial.
- Mejorado: el recuento de operadores usa los cinco ficheros blocked-<isp>.txt en vez del JSON completo, que ya va por 2,7 MB. Los ajustes con "ISPs mínimos" mayor que 1 pasan de descargar 2,7 MB por comprobación a unos pocos KB.
- Mejorado: el desglose por operador solo se descarga cuando decide algo o cuando informa de algo útil. Sin bloqueos, la comprobación sigue siendo una sola petición mínima.
- Mejorado: el respaldo JSON deja de poder repetirse en cada ciclo. Ese fichero solo crece y decodificarlo cuesta unas once veces su tamaño en memoria, así que ahora se espera una hora entre usos, se limita el tamaño de la respuesta y no se decodifica si no cabe en la memoria disponible, en cuyo caso se mantiene el estado en vez de tumbar el cron con un error fatal.
- Mejorado: si los TXT fallan por estar obsoletos no se recurre al JSON, porque lo escribe el mismo proceso y ya se sabe la respuesta.
- Corregido: con la fuente TXT, el número de IPs bloqueadas se guardaba en el campo de "ISPs afectados", así que el resumen por email podía decir "ISPs afectados: 227". Ahora se guardan las dos cifras por separado y el email distingue una de otra.
- Cambiado: AyudaWP_BLG_Block_Checker::check_status() recibe ahora un array de argumentos (min_isps, mode, own_ips) en lugar de un entero.

Para versiones anteriores, consulta el archivo [changelog.txt](https://github.com/fernandotellado/bypass-laligagate/blob/main/changelog.txt).

== Aviso de actualización ==

= 1.5.0 =
Puedes elegir que el proxy solo se desactive cuando bloqueen las IPs de tu web, en vez de con cualquier bloqueo en España. El modo de siempre sigue siendo el de por defecto. Además, una fuente de datos parada ya no se lee como que no hay bloqueos.

== Soporte ==

- [Web](https://servicios.ayudawp.com)
- [Canal de YouTube](https://www.youtube.com/AyudaWordPressES)
- [Documentación y tutoriales](https://ayudawp.com)

== Acerca de ==

Plugin desarrollado para el servicio de mantenimiento WordPress de AyudaWP.
