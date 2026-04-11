# ¿Hay ahora fútbol? – Bypass #LaLigaGate

Plugin para WordPress que gestiona el proxy de Cloudflare automáticamente durante los bloqueos de IP por partidos de fútbol en España.

## ¿Por qué?

Cada vez que hay fútbol en España se aplican bloqueos judiciales masivos de IPs para combatir la piratería de retransmisiones. Estas órdenes de bloqueo afectan a IPs de Cloudflare que comparten miles de webs legítimas, dejándolas inaccesibles para los usuarios de operadoras españolas.

Si tu web está detrás del proxy de Cloudflare (la nubecita naranja), puede quedar bloqueada aunque no tenga nada que ver con el fútbol.

## ¿Qué hace el plugin?

El plugin consulta [hayahora.futbol](https://hayahora.futbol/) cada X minutos (configurable). Si detecta bloqueos activos desactiva el proxy de Cloudflare en los registros DNS que hayas elegido, pasando de **Proxied (CDN)** a **DNS Only**. Cuando terminan los bloqueos y pasa un periodo de espera (también configurable), reactiva el proxy automáticamente. También tiene botones de activación y desactivación manual del proxy.

La desactivación automática del proxy funciona en **modo preventivo**: no espera a que bloqueen tu dominio concreto, actúa en cuanto detecta que hay bloqueos activos en general, más seguro y menos consultas externas y al cron.

## Qué hace

- Monitorización automática cada 5-60 minutos (configurable)
- Desactiva el proxy al detectar bloqueos, lo reactiva cuando pasan
- Periodo de espera configurable para evitar cambios rápidos de estado
- Botones manuales para forzar proxy OFF o restaurar ON
- Email al administrador cuando el proxy se desactiva o reactiva automáticamente
- Soporte para Global API Key y API Token de Cloudflare
- Endpoint para cron externo con token de seguridad (opcional)
- Al desactivar el plugin se restaura el proxy automáticamente

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
│   └── class-email-notifier.php     # Emails al admin en cambios de estado
├── assets/
│   ├── css/admin.css                # Estilos de la página de ajustes
│   └── js/admin.js                  # JavaScript para AJAX y UI
├── uninstall.php                    # Limpieza al borrar el plugin
└── readme.txt                       # Readme para WordPress.org
```

Los datos del plugin se almacenan en tres opciones independientes de `wp_options` para evitar que se sobreescriban entre sí:

- `ayudawp_blg_settings` — credenciales, intervalos, registros seleccionados
- `ayudawp_blg_dns_cache` — caché de registros DNS de Cloudflare
- `ayudawp_blg_bypass_state` — estado del bypass, última comprobación, override manual

## Instalación

1. Descarga el zip desde la [página de releases](https://github.com/fernandotellado/bypass-laligagate/releases) o clona el repositorio
2. Sube la carpeta `bypass-laligagate` a `wp-content/plugins/`
3. Activa el plugin en el escritorio de WordPress
4. Ve a **Herramientas > Bypass LaLigaGate**

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

## Cómo funciona

```
hayahora.futbol dice que hay bloqueos
        ↓
Plugin desactiva proxy (DNS Only) en los registros seleccionados
        ↓
Email al admin: "Proxy desactivado por bloqueos de La Liga"
        ↓
    [pasan los bloqueos]
        ↓
    [pasa el periodo de espera]
        ↓
Plugin reactiva proxy (CDN)
        ↓
Email al admin: "Proxy reactivado"
```

Si fuerzas el proxy OFF manualmente, el cron automático **no lo cambiará** hasta que pulses "Restaurar proxy ON". Así puedes intervenir sin que el automatismo te lo deshaga.

## Requisitos

- WordPress 5.6 o superior
- PHP 7.4 o superior
- Dominio con DNS gestionados por Cloudflare
- Credenciales de la API de Cloudflare (Global API Key o API Token)

## Servicios externos

- **[hayahora.futbol](https://hayahora.futbol/)** — Datos de bloqueos activos. Se envía una petición GET con la URL del sitio en el User-Agent. No se transmiten datos personales.
- **[API de Cloudflare](https://developers.cloudflare.com/api/)** — Lectura de registros DNS y cambio del estado del proxy. Las credenciales se envían por HTTPS.

## Registro de cambios

### 1.0.2
- Corregido: ahora se ignoran los registros de más de 6 horas en hayahora.futbol JSON, evitando así que el bypass permanezca activo indefinidamente
- Añadido: umbral filtrable mediante `ayudawp_blg_stale_threshold` (por defedto 6)

### 1.0.1
- Corregido: la detección de bloqueos ahora analiza correctamente la estructura del JSON de hayahora.futbol con arrays indexados numéricamente que contengan objetos de IPs con cambios de estado

### 1.0.0
- Versión inicial

## Licencia

GPLv2 o posterior. Puedes usar, modificar y distribuir el plugin libremente bajo los términos de la GPL.

## Autor

Desarrollado por [Fernando Tellado](https://ayudawp.com) para los clientes de [Mantenimiento WordPress de AyudaWP](https://mantenimiento.ayudawp.com) a partir de [este otro desarrollo](https://github.com/dcarrero/cf-football-bypass) de David Carrero.
