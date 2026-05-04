=== Bypass LaLigaGate ===
Contributors: fernandot, ayudawp
Tags: cloudflare, dns, futbol, liga, proxy
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestiona el proxy de Cloudflare durante los bloqueos de IP por partidos de fútbol en España.

== Descripción ==

Durante los partidos de fútbol en España se aplican bloqueos judiciales masivos de IPs para combatir la piratería de retransmisiones. Estos bloqueos afectan a webs legítimas alojadas tras el proxy de Cloudflare, que comparten IPs con otros sitios que nada tienen que ver con el fútbol.

Este plugin monitoriza automáticamente los bloqueos activos consultando hayahora.futbol y, cuando detecta actividad, desactiva el proxy de Cloudflare en los registros DNS que elijas, pasando de Proxied (CDN) a DNS Only. Cuando terminan los bloqueos y pasa el periodo de espera configurado, reactiva el proxy automáticamente.

Funciona en modo preventivo: no espera a que tu dominio concreto sea bloqueado, actúa en cuanto se detectan bloqueos activos en general.

= Qué hace =

- Monitorización automática cada X minutos (configurable de 5 a 60)
- Modo preventivo: actúa ante cualquier bloqueo activo
- Periodo de espera configurable para evitar cambios rápidos de estado
- Control manual: botones para forzar proxy OFF y restaurar proxy ON
- Notificaciones por email al administrador cuando el proxy se desactiva o reactiva automáticamente (desactivable)
- Filtrado inteligente por ISPs: solo actúa cuando hay bloqueos en varios proveedores, evitando falsos positivos
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
El plugin consulta periódicamente https://hayahora.futbol/estado/data.json para comprobar si hay bloqueos activos. Se envía una petición GET con la URL del sitio en el User-Agent. No se transmiten datos personales ni de los visitantes.
URL: https://hayahora.futbol/

= API de Cloudflare =
El plugin usa la API oficial de Cloudflare (https://api.cloudflare.com/client/v4/) para leer registros DNS y alternar el estado del proxy (Proxied/DNS Only). Las credenciales se almacenan en la base de datos de WordPress y se envían por HTTPS.
Términos: https://www.cloudflare.com/terms/
Privacidad: https://www.cloudflare.com/privacypolicy/

== Preguntas frecuentes ==

= ¿Es seguro usar este plugin? =
Sí. Solo modifica el estado del proxy de los registros DNS que selecciones (Proxied/DNS Only). No borra ni modifica el contenido de ningún registro DNS.

= ¿Qué pasa si falla la detección de bloqueos? =
Si la consulta a hayahora.futbol falla, el plugin asume que no hay bloqueos y mantiene el estado actual sin hacer cambios, por seguridad.

= ¿Qué pasa si desactivo el plugin durante un bypass activo? =
Al desactivar el plugin se restaura automáticamente el proxy (Proxied/CDN) en todos los registros seleccionados.

= ¿Puedo controlar el bypass manualmente? =
Sí. Hay botones para forzar el proxy OFF o restaurarlo ON. Al forzar manualmente, el cron automático no lo cambiará hasta que tú restaures el proxy.

= ¿Me avisa cuando cambia el estado? =
Sí, por defecto envía un email al administrador del sitio cuando el proxy se desactiva automáticamente por bloqueos y otro cuando se reactiva. Puedes desactivar estos avisos en la sección de opciones generales.

= ¿Funciona con cualquier proveedor de DNS? =
No, solo con Cloudflare. Los DNS del dominio deben estar gestionados por Cloudflare.

== Registro de cambios ==

= 1.4.0 =
- Mejorado: la comprobación de bloqueos usa por defecto el endpoint ligero `blocked-any.txt` (~150 bytes) en lugar del JSON completo (~8 MB), reduciendo unas 50.000 veces el tráfico por chequeo. Si el TXT falla por cualquier motivo, vuelve automáticamente al JSON.
- Añadido: cuando el ajuste "Mínimo de ISPs" es mayor que 1 se sigue usando el JSON, ya que el TXT no expone el detalle por proveedor.
- Añadido: indicación de la fuente consultada (txt / json / json+txt-fallback) en la fila "Última comprobación" de la pantalla de ajustes, para auditar que el cambio funciona como se espera.
- Añadido: filtros `ayudawp_blg_txt_url`, `ayudawp_blg_json_url` y `ayudawp_blg_source` para forzar endpoints o estrategia desde código.

= 1.3.0 =
- Añadido: resumen periódico por email (diario o semanal, opcional, desactivado por defecto) con el tiempo total que la web habría estado inaccesible si el plugin no hubiese desactivado el proxy durante los bloqueos. Configurable con hora de envío (por defecto 10:00) en la zona horaria del sitio, igual que las entradas programadas.
- Añadido: historial interno de episodios de bloqueo (últimos 500) que alimenta el resumen. Se registra a partir de las transiciones del estado NO/SI de hayahora.futbol.

= 1.2.1 =
- Corregido: la tabla de registros DNS de los ajustes no reflejaba el nuevo estado del proxy tras un cambio automático del cron. Quedaba "atascada" en el estado anterior porque la caché se guardaba antes de aplicar los cambios. Ahora se actualiza tras los cambios y el botón "Comprobar ahora" también refresca la tabla en pantalla.

= 1.2.0 =
- Corregido: el periodo de espera tras los bloqueos ahora se mide desde que terminan los bloqueos, no desde que empezó el bypass. Antes, si los bloqueos duraban más que el propio periodo de espera, el proxy se reactivaba sin ningún margen.
- Añadido: watchdog que reprograma la comprobación automática si por cualquier motivo desaparece del cron de WordPress, sin tener que desactivar y reactivar el plugin.
- Añadido: fila "Próxima comprobación" en la tarjeta de estado para saber cuándo está previsto el siguiente ciclo automático.
- Añadido: aviso en la página de ajustes cuando se detecta que WP-Cron lleva retraso (más de 2× el intervalo configurado), con recomendación de usar el cron externo del servidor. Solo se muestra si hay retraso real, así la interfaz normal no cambia.
- Mejorado: el ejemplo de crontab usa el intervalo configurado en lugar de un valor fijo.

= 1.1.2 =
- Mejorado: cambio de ISPs por defecto de 2 a 1 para que sea el usuario quien decida su umbral de riesgo, siendo por defecto el mínimo posible.

= 1.1.1 =
- Mejorado: los mensajes de estado del botón "Comprobar ahora" ahora usan colores según el contexto (rojo si hay bloqueos, ámbar en periodo de espera o forzado manual, verde si todo está bien)
- Mejorado: el mensaje de forzar proxy OFF ahora se muestra en ámbar en vez de verde
- Añadido: estilo de mensaje de aviso (warning) en la interfaz de administración

= 1.1.0 =
- Añadido: sección de opciones generales en la pantalla de ajustes
- Añadido: filtrado por número mínimo de ISPs con bloqueos para evitar falsos positivos por problemas de red de un solo operador (configurable, por defecto 2)
- Añadido: opción para activar o desactivar los avisos por email (activa por defecto)
- Añadido: opción para conservar los datos del plugin al borrarlo, para futuras reinstalaciones (borrado activo por defecto)
- Corregido: sincronización de versión entre el plugin y el readme

= 1.0.2 =
- Fixed: orphaned block entries older than 6 hours in hayahora.futbol JSON are now ignored, preventing the bypass from staying active indefinitely
- Added: filterable staleness threshold via ayudawp_blg_stale_threshold (default 6 hours)

= 1.0.1 =
- Fixed: block detection now correctly parses hayahora.futbol JSON structure with numerically-indexed arrays containing IP objects with stateChanges

= 1.0.0 =
- Versión inicial
- Monitorización automática de hayahora.futbol en modo preventivo
- Gestión de registros DNS de Cloudflare (A, AAAA, CNAME)
- Soporte para Global API Key y API Token
- Control manual con botones de forzar proxy OFF / restaurar proxy ON
- Forzado manual respetado por el cron (no sobreescribe)
- Notificaciones por email al desactivar/reactivar el proxy automáticamente
- Periodo de espera configurable tras desactivar el proxy
- Endpoint para cron externo con token de seguridad
- Restauración automática del proxy al desactivar el plugin
- Aviso descartable tras activar con enlace a los ajustes
- Enlace "Ajustes" en la lista de plugins

== Soporte ==

- [Web](https://servicios.ayudawp.com)
- [Canal de YouTube](https://www.youtube.com/AyudaWordPressES)
- [Documentación y tutoriales](https://ayudawp.com)

== Acerca de ==

Plugin desarrollado para el servicio de mantenimiento WordPress de AyudaWP.
