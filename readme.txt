=== Bypass LaLigaGate ===
Contributors: fernandot, ayudawp
Tags: cloudflare, dns, futbol, bypass, proxy
Requires at least: 5.6
Tested up to: 6.9.4
Requires PHP: 7.4
Stable tag: 1.0.1
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
- Notificaciones por email al administrador cuando el proxy se desactiva o reactiva automáticamente
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
Sí. Envía un email al administrador del sitio cuando el proxy se desactiva automáticamente por bloqueos y otro cuando se reactiva.

= ¿Funciona con cualquier proveedor de DNS? =
No, solo con Cloudflare. Los DNS del dominio deben estar gestionados por Cloudflare.

== Registro de cambios ==

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
