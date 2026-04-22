<?php
/**
 * Admin settings page.
 *
 * @package Bypass_LaLigaGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AyudaWP_BLG_Admin_Page {

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu() {
		add_management_page( 'Bypass LaLigaGate', 'Bypass LaLigaGate', 'manage_options', 'bypass-laligagate', array( $this, 'render_page' ) );
	}

	public function register_settings() {
		register_setting( 'ayudawp_blg_group', AYUDAWP_BLG_OPT_CONFIG, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_config' ),
		) );
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_bypass-laligagate' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ayudawp-blg-admin', AYUDAWP_BLG_URL . 'assets/css/admin.css', array(), AYUDAWP_BLG_VERSION );
		wp_enqueue_script( 'ayudawp-blg-admin', AYUDAWP_BLG_URL . 'assets/js/admin.js', array(), AYUDAWP_BLG_VERSION, true );
		wp_localize_script( 'ayudawp-blg-admin', 'ayudawpBlg', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ayudawp_blg_nonce' ),
		) );
	}

	public function sanitize_config( $input ) {
		$existing = ayudawp_blg_get_config();
		$s = array();
		$auth = isset( $input['auth_type'] ) ? sanitize_text_field( $input['auth_type'] ) : $existing['auth_type'];
		$s['auth_type']        = in_array( $auth, array( 'global', 'token' ), true ) ? $auth : 'global';
		$s['cf_email']         = isset( $input['cf_email'] ) ? sanitize_email( $input['cf_email'] ) : $existing['cf_email'];
		$s['cf_api_key']       = isset( $input['cf_api_key'] ) ? sanitize_text_field( $input['cf_api_key'] ) : $existing['cf_api_key'];
		$s['cf_zone_id']       = isset( $input['cf_zone_id'] ) ? sanitize_text_field( $input['cf_zone_id'] ) : $existing['cf_zone_id'];
		$s['check_interval']   = isset( $input['check_interval'] ) ? max( 5, min( 60, intval( $input['check_interval'] ) ) ) : $existing['check_interval'];
		$s['cooldown']         = isset( $input['cooldown'] ) ? max( 5, min( 600, intval( $input['cooldown'] ) ) ) : $existing['cooldown'];
		$s['selected_records'] = ( isset( $input['selected_records'] ) && is_array( $input['selected_records'] ) )
			? array_map( 'sanitize_text_field', $input['selected_records'] ) : array();
		$s['cron_secret']      = isset( $input['cron_secret'] ) ? sanitize_text_field( $input['cron_secret'] ) : $existing['cron_secret'];
		if ( empty( $s['cron_secret'] ) ) {
			$s['cron_secret'] = wp_generate_password( 32, false, false );
		}
		$s['min_isps']            = isset( $input['min_isps'] ) ? max( 1, intval( $input['min_isps'] ) ) : $existing['min_isps'];
		$s['email_notifications'] = ! empty( $input['email_notifications'] ) ? 1 : 0;
		$s['delete_data']         = ! empty( $input['delete_data'] ) ? 1 : 0;
		return $s;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$cfg   = ayudawp_blg_get_config();
		$state = ayudawp_blg_get_state();
		$dns   = ayudawp_blg_get_dns_cache();
		$diag  = ayudawp_blg_get_cron_diagnostics();

		$is_blocked  = 'SI' === $state['last_status'];
		$is_manual   = ! empty( $state['manual_override'] );
		$is_bypass   = ! empty( $state['bypass_active'] ) || $is_manual;
		$has_checked = ! empty( $state['last_check'] );

		$cron_url = home_url( '/?bypass_blg_cron=1&token=' . $cfg['cron_secret'] );
		?>
		<div class="wrap ayudawp-blg-wrap">
			<h1>Bypass LaLigaGate <small>v<?php echo esc_html( AYUDAWP_BLG_VERSION ); ?></small></h1>
			<p class="description">Gestiona el proxy de Cloudflare automáticamente durante los bloqueos de IP por partidos de fútbol en España.</p>

			<?php if ( $diag['unhealthy'] && $has_checked ) : ?>
				<div class="notice notice-warning ayudawp-blg-cron-warning">
					<p><strong>La comprobación automática se está retrasando.</strong>
					<?php if ( ! $diag['scheduled'] ) : ?>
						No hay ninguna comprobación programada. Visita de nuevo esta página o guarda los ajustes para reprogramarla.
					<?php elseif ( $diag['stale'] ) : ?>
						La última comprobación fue hace <?php echo esc_html( human_time_diff( $diag['last_check_ts'], time() ) ); ?> (intervalo configurado: <?php echo intval( $cfg['check_interval'] ); ?> min).
					<?php else : ?>
						La próxima comprobación debería haberse ejecutado ya.
					<?php endif; ?>
					WP-Cron solo se dispara con visitas al sitio. Si tu web tiene poco tráfico usa el <strong>cron externo</strong> que aparece más abajo para ejecutar la comprobación desde el servidor.</p>
				</div>
			<?php endif; ?>

			<div class="ayudawp-blg-card ayudawp-blg-card--status">
				<h2>Estado actual</h2>
				<table class="ayudawp-blg-status-table">
					<tr>
						<th>Bloqueos activos</th>
						<td id="blg-status-blocked"><span class="blg-badge <?php echo $is_blocked ? 'blg-badge-danger' : 'blg-badge-ok'; ?>"><?php echo $is_blocked ? 'SI' : 'NO'; ?></span></td>
						<td class="blg-status-detail">
							<?php if ( $is_blocked ) : ?>
								Hay partidos con bloqueos activos.
							<?php elseif ( $has_checked ) : ?>
								No se detectan bloqueos en este momento.
							<?php else : ?>
								Aún no se ha realizado ninguna comprobación.
							<?php endif; ?>
							<a href="https://hayahora.futbol/" target="_blank" rel="noopener">Ver hayahora.futbol</a>
						</td>
					</tr>
					<tr>
						<th>Bypass activo (proxy OFF)</th>
						<td id="blg-status-bypass"><span class="blg-badge <?php echo $is_bypass ? 'blg-badge-warning' : 'blg-badge-ok'; ?>"><?php echo $is_bypass ? 'SI' : 'NO'; ?></span></td>
						<td class="blg-status-detail">
							<?php if ( $is_manual ) : ?>
								Forzado manualmente. Pulsa "Restaurar proxy ON" para devolver el control al cron.
							<?php elseif ( $is_bypass ) : ?>
								Activado automáticamente por detección de bloqueos.
							<?php else : ?>
								Proxy activo (CDN). Funcionamiento normal.
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Última comprobación</th>
						<td id="blg-status-lastcheck" colspan="2">
							<?php echo esc_html( $has_checked ? $state['last_check'] : 'Pendiente' ); ?>
							<?php if ( $has_checked && $diag['stale'] ) : ?>
								<span class="blg-badge blg-badge-warning blg-cron-badge" title="La comprobación debería haberse ejecutado antes.">retrasada</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>Próxima comprobación</th>
						<td id="blg-status-nextcheck" colspan="2">
							<?php if ( $diag['scheduled'] ) : ?>
								<?php echo esc_html( $diag['next_human'] ); ?>
								<span class="blg-status-note">(cada <?php echo intval( $cfg['check_interval'] ); ?> min por WP-Cron)</span>
							<?php else : ?>
								<em>No programada</em>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<div class="ayudawp-blg-manual-actions">
					<button type="button" class="button" id="blg-btn-check">Comprobar ahora</button>
					<button type="button" class="button" id="blg-btn-proxy-off">Forzar proxy OFF</button>
					<button type="button" class="button" id="blg-btn-proxy-on">Restaurar proxy ON</button>
				</div>
				<div id="blg-action-status" class="ayudawp-blg-action-msg"></div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'ayudawp_blg_group' ); ?>

				<div class="ayudawp-blg-card">
					<h2>Credenciales de Cloudflare</h2>
					<table class="form-table">
						<tr>
							<th scope="row">Tipo de autenticación</th>
							<td>
								<select name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[auth_type]" id="blg-field-auth-type">
									<option value="global" <?php selected( $cfg['auth_type'], 'global' ); ?>>Global API Key</option>
									<option value="token" <?php selected( $cfg['auth_type'], 'token' ); ?>>API Token</option>
								</select>
								<div class="blg-help-box" id="blg-help-auth-global"><p>Ya existe en tu cuenta, solo hay que copiarla. Tiene acceso completo.</p></div>
								<div class="blg-help-box" id="blg-help-auth-token" style="display:none;"><p>Más seguro: permite limitar permisos a lo necesario.</p></div>
							</td>
						</tr>
						<tr id="blg-row-email">
							<th scope="row">Email de Cloudflare</th>
							<td><input type="email" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[cf_email]" id="blg-field-email" value="<?php echo esc_attr( $cfg['cf_email'] ); ?>" class="regular-text" /><p class="description">El email con el que te registraste en Cloudflare.</p></td>
						</tr>
						<tr>
							<th scope="row" id="blg-label-apikey">Global API Key</th>
							<td>
								<input type="password" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[cf_api_key]" id="blg-field-apikey" value="<?php echo esc_attr( $cfg['cf_api_key'] ); ?>" class="regular-text" autocomplete="off" />
								<div class="blg-help-box" id="blg-help-apikey-global">
									<p><strong>Dónde encontrarla:</strong></p>
									<ol><li>Entra en <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Mi perfil &rarr; API Tokens</a></li><li>Junto a "Global API Key", pulsa <strong>Ver</strong></li><li>Confirma tu contraseña y copia la clave</li></ol>
								</div>
								<div class="blg-help-box" id="blg-help-apikey-token" style="display:none;">
									<p><strong>Cómo crear el token:</strong></p>
									<ol><li>Ve a <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Mi perfil &rarr; API Tokens</a> &rarr; <strong>Crear token</strong></li><li>Usa la plantilla <strong>"Editar zona DNS"</strong></li><li>En Permisos: <code>Zona &gt; DNS &gt; Editar</code> + añade <code>Zona &gt; Zona &gt; Leer</code></li><li>En Recursos de zona: <strong>Incluir &gt; Zona específica</strong> &gt; selecciona el dominio</li><li>Filtro de IP y TTL: déjalos sin configurar</li><li>Pulsa "Ir al resumen", confirma, y copia el token (solo se muestra una vez)</li></ol>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row">ID de zona</th>
							<td>
								<input type="text" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[cf_zone_id]" id="blg-field-zoneid" value="<?php echo esc_attr( $cfg['cf_zone_id'] ); ?>" class="regular-text" />
								<div class="blg-help-box"><p>Dashboard de Cloudflare del dominio &rarr; panel derecho de Overview &rarr; sección "API". Código de 32 caracteres.</p></div>
							</td>
						</tr>
						<tr>
							<th scope="row">Conexión</th>
							<td>
								<button type="button" class="button button-primary" id="blg-btn-test">Probar conexión y cargar DNS</button>
								<div id="blg-test-status" class="ayudawp-blg-action-msg"></div>
							</td>
						</tr>
					</table>
				</div>

				<div class="ayudawp-blg-card">
					<h2>Registros DNS a gestionar</h2>
					<p class="blg-important-note">Marca los registros cuyo proxy quieres que se desactive durante los bloqueos (normalmente el dominio raíz y www) y pulsa "Guardar cambios" más abajo.</p>
					<div id="blg-dns-records"><?php $this->render_dns_table( $dns, $cfg['selected_records'] ); ?></div>
				</div>

				<div class="ayudawp-blg-card">
					<h2>Automatización</h2>
					<table class="form-table">
						<tr>
							<th scope="row">Intervalo de comprobación</th>
							<td><input type="number" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[check_interval]" value="<?php echo intval( $cfg['check_interval'] ); ?>" min="5" max="60" class="small-text" /> minutos<p class="description">Cada cuánto se consulta hayahora.futbol (5-60 min).</p></td>
						</tr>
						<tr>
							<th scope="row">Periodo de espera tras desactivar</th>
							<td><input type="number" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[cooldown]" value="<?php echo intval( $cfg['cooldown'] ); ?>" min="5" max="600" class="small-text" /> minutos<p class="description">Espera antes de reactivar el proxy tras terminar los bloqueos (5-600 min).</p></td>
						</tr>
						<tr<?php echo $diag['unhealthy'] && $has_checked ? ' class="blg-row-highlight"' : ''; ?>>
							<th scope="row">Cron externo (opcional)<?php if ( $diag['unhealthy'] && $has_checked ) : ?><br /><span class="blg-badge blg-badge-warning">recomendado</span><?php endif; ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[cron_secret]" value="<?php echo esc_attr( $cfg['cron_secret'] ); ?>" class="regular-text" autocomplete="off" />
								<div class="blg-help-box">
									<p>Si quieres usar un cron real del servidor en vez de WP-Cron, configura esta URL:</p>
									<code class="blg-code-block"><?php echo esc_html( $cron_url ); ?></code>
									<p>Ejemplo para crontab (cada <?php echo intval( $cfg['check_interval'] ); ?> min):</p>
									<code class="blg-code-block">*/<?php echo intval( $cfg['check_interval'] ); ?> * * * * curl -s "<?php echo esc_html( $cron_url ); ?>" > /dev/null 2>&1</code>
									<p>Para regenerar el token, borra el campo y guarda.</p>
								</div>
							</td>
						</tr>
					</table>
				</div>
				<div class="ayudawp-blg-card">
					<h2>Opciones generales</h2>
					<table class="form-table">
						<tr>
							<th scope="row">ISPs mínimos para activar bypass</th>
							<td>
								<input type="number" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[min_isps]" value="<?php echo intval( $cfg['min_isps'] ); ?>" min="1" class="small-text" />
								<p class="description">Número mínimo de proveedores (ISPs) distintos con bloqueos activos para considerar que hay un bloqueo real de La Liga. Valor recomendado: 2. Con 1 se activa ante cualquier bloqueo, incluso si es un problema de red de un solo operador.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Avisos por email</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[email_notifications]" value="1" <?php checked( $cfg['email_notifications'], 1 ); ?> /> Enviar un email al administrador cuando el proxy se active o desactive automáticamente</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Borrado de datos</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[delete_data]" value="1" <?php checked( $cfg['delete_data'], 1 ); ?> /> Eliminar todos los datos del plugin de la base de datos al borrar el plugin</label>
								<p class="description">Si desmarcas esta opción, la configuración se conservará para una futura reinstalación.</p>
							</td>
						</tr>
					</table>
				</div>
				<?php submit_button( 'Guardar cambios' ); ?>
			</form>
			<p class="ayudawp-blg-footer">Bypass LaLigaGate v<?php echo esc_html( AYUDAWP_BLG_VERSION ); ?> &mdash; <a href="https://mantenimiento.ayudawp.com" target="_blank" rel="noopener">Mantenimiento WordPress</a></p>
		</div>
		<?php
	}

	public function render_dns_table( $records, $selected_ids ) {
		if ( empty( $records ) ) {
			echo '<p class="description">No hay registros DNS en caché. Rellena las credenciales y pulsa "Probar conexión y cargar DNS".</p>';
			return;
		}
		$selected_ids = is_array( $selected_ids ) ? $selected_ids : array();
		?>
		<div class="ayudawp-blg-dns-scroll">
		<table class="widefat striped ayudawp-blg-dns-table">
			<thead><tr><th class="check-column"><input type="checkbox" id="blg-select-all" /></th><th>Nombre</th><th>Tipo</th><th>Contenido</th><th>Proxy</th></tr></thead>
			<tbody>
			<?php foreach ( $records as $r ) :
				$rid = $r['id'] ?? '';
				$chk = in_array( $rid, $selected_ids, true ) ? 'checked' : '';
				$prx = ! empty( $r['proxied'] ) ? 'ON' : 'OFF';
				$cls = 'ON' === $prx ? 'blg-proxy-on' : 'blg-proxy-off';
			?>
			<tr><td><input type="checkbox" name="<?php echo esc_attr( AYUDAWP_BLG_OPT_CONFIG ); ?>[selected_records][]" value="<?php echo esc_attr( $rid ); ?>" <?php echo esc_attr( $chk ); ?> /></td><td><?php echo esc_html( $r['name'] ?? '' ); ?></td><td><code><?php echo esc_html( $r['type'] ?? '' ); ?></code></td><td><code><?php echo esc_html( $r['content'] ?? '' ); ?></code></td><td><span class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $prx ); ?></span></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}
}
