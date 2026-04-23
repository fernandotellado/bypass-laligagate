# ¿Hay ahora fútbol? – Bypass #LaLigaGate

Plugin para WordPress que gestiona el proxy de Cloudflare automáticamente durante los bloqueos de IP por partidos de fútbol en España.

## ¿Por qué?

Cada vez que hay fútbol en España se aplican bloqueos judiciales masivos de IPs para combatir la piratería de retransmisiones. Estas órdenes de bloqueo afectan a IPs de Cloudflare que comparten miles de webs legítimas, dejándolas inaccesibles para los usuarios de operadoras españolas.

Si tu web está detrás del proxy de Cloudflare (la nubecita naranja), puede quedar bloqueada aunque no tenga nada que ver con el fútbol.

## ¿Qué hace el plugin?

El plugin consulta [hayahora.futbol](https://hayahora.futbol/) cada X minutos (configurable). Si detecta bloqueos activos desactiva el proxy de Cloudflare en los registros DNS que hayas elegido, pasando de **Proxied (CDN)** a **DNS Only**. Cuando terminan los bloqueos y pasa un periodo de espera (también configurable), reactiva el proxy automáticamente. También tiene botones de activación y desactivación manual del proxy.

La desactivación automática del proxy funciona en **modo preventivo**: no espera a que bloqueen tu dominio concreto, actúa en cuanto detecta bloqueos activos en varios proveedores (ISPs). El número mínimo de ISPs es configurable para evitar falsos positivos por problemas de red de un solo operador.

## Qué hace

- Monitorización automática cada 5-60 minutos (configurable)
- Desactiva el proxy al detectar bloqueos, lo reactiva cuando pasan
- Filtrado inteligente por ISPs: solo actúa cuando hay bloqueos en varios proveedores, evitando falsos positivos por problemas de red de un solo operador (configurable)
- Periodo de espera configurable para evitar cambios rápidos de estado
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
│   └── class-email-notifier.php     # Emails al admin en cambios de estado
├── assets/
│   ├── css/admin.css                # Estilos de la página de ajustes
│   └── js/admin.js                  # JavaScript para AJAX y UI
├── uninstall.php                    # Limpieza al borrar el plugin (condicional)
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

- **ISPs mínimos para activar bypass**: número mínimo de proveedores distintos con bloqueos activos para considerar que hay un bloqueo real. Con el valor por defecto (2) se evitan falsos positivos por problemas de red de un solo operador (por ejemplo, MásMóvil cortando IPs de AWS/Akamai sin que sea un bloqueo de La Liga)
- **Avisos por email**: activa o desactiva los emails al administrador cuando el proxy cambia de estado automáticamente (activo por defecto)
- **Borrado de datos**: si está marcado, al borrar el plugin se eliminan todas las opciones de la base de datos. Si lo desmarcas, la configuración se conserva para una futura reinstalación (activo por defecto)

## Cómo funciona

```
hayahora.futbol dice que hay bloqueos
        ↓
¿Bloqueos en X o más ISPs distintos? (configurable, por defecto 2)
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

- **[hayahora.futbol](https://hayahora.futbol/)** — Datos de bloqueos activos. Se envía una petición GET con la URL del sitio en el User-Agent. No se transmiten datos personales.
- **[API de Cloudflare](https://developers.cloudflare.com/api/)** — Lectura de registros DNS y cambio del estado del proxy. Las credenciales se envían por HTTPS.

## Registro de cambios

### 1.2.1
- Corregido: la tabla de registros DNS de los ajustes no reflejaba el nuevo estado del proxy tras un cambio automático del cron, quedando "atascada" en el estado anterior porque la caché se guardaba antes de aplicar los cambios. Ahora se actualiza tras los cambios y el botón "Comprobar ahora" también refresca la tabla en pantalla.

### 1.2.0
- Corregido: el periodo de espera tras los bloqueos se mide ahora desde que terminan los bloqueos, no desde que empezó el bypass. Antes, si los bloqueos duraban más que el periodo de espera, el proxy se reactivaba sin ningún margen real.
- Añadido: watchdog que reprograma la comprobación automática si por cualquier motivo desaparece del cron de WordPress.
- Añadido: fila "Próxima comprobación" en la tarjeta de estado y aviso condicional cuando WP-Cron lleva retraso, con recomendación del cron externo del servidor. El aviso solo aparece si hay retraso real, manteniendo la interfaz limpia en funcionamiento normal.
- Mejorado: el ejemplo de crontab usa el intervalo configurado en lugar de un valor fijo.

### 1.1.2
- Mejorado: cambio de ISPs por defecto de 2 a 1 para que sea el usuario quien decida su umbral de riesgo, siendo por defecto el mínimo posible.

### 1.1.1
- Mejorado: los mensajes de estado del botón "Comprobar ahora" ahora usan colores según el contexto (rojo si hay bloqueos, ámbar en periodo de espera o forzado manual, verde si todo está bien)
- Mejorado: el mensaje de forzar proxy OFF ahora se muestra en ámbar en vez de verde
- Añadido: estilo de mensaje de aviso (warning) en la interfaz de administración

### 1.1.0
- Añadido: sección de opciones generales en la pantalla de ajustes
- Añadido: filtrado por número mínimo de ISPs con bloqueos para evitar falsos positivos por problemas de red de un solo operador (configurable, por defecto 2)
- Añadido: opción para activar o desactivar los avisos por email (activa por defecto)
- Añadido: opción para conservar los datos del plugin al borrarlo, para futuras reinstalaciones (borrado activo por defecto)
- Corregido: sincronización de versión entre el plugin y el readme

### 1.0.2
- Corregido: ahora se ignoran los registros de más de 6 horas en hayahora.futbol JSON, evitando así que el bypass permanezca activo indefinidamente
- Añadido: umbral filtrable mediante `ayudawp_blg_stale_threshold` (por defecto 6 horas)

### 1.0.1
- Corregido: la detección de bloqueos ahora analiza correctamente la estructura del JSON de hayahora.futbol con arrays indexados numéricamente que contengan objetos de IPs con cambios de estado

### 1.0.0
- Versión inicial

## Licencia

GPLv2 o posterior. Puedes usar, modificar y distribuir el plugin libremente bajo los términos de la GPL.

## Autor

Desarrollado por [Fernando Tellado](https://ayudawp.com) para los clientes de [Mantenimiento WordPress de AyudaWP](https://mantenimiento.ayudawp.com) a partir de [este otro desarrollo](https://github.com/dcarrero/cf-football-bypass) de David Carrero.
