<?php
/**
 * Administrace pluginu: nastavení, metabox objednávky, štítky.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'admin_post_icecold_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_icecold_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_post_icecold_label', array( __CLASS__, 'handle_label' ) );
		add_action( 'admin_post_icecold_register_webhook', array( __CLASS__, 'handle_register_webhook' ) );
		add_action( 'admin_post_icecold_portal_login', array( __CLASS__, 'handle_portal_login' ) );
		add_action( 'admin_post_icecold_portal_logout', array( __CLASS__, 'handle_portal_logout' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );

		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'ICECOLD', 'icecold-shipping' ),
			__( 'ICECOLD', 'icecold-shipping' ),
			'manage_woocommerce',
			'icecold-shipping',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'icecold_shipping',
			ICECOLD_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => ICECOLD_Settings::defaults(),
			)
		);
	}

	/**
	 * Převede hodnotu zaškrtávacího pole na `yes`/`no`.
	 *
	 * @param mixed $value Hodnota z formuláře nebo z programového nastavení.
	 */
	private static function yes_no( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true ) ? 'yes' : 'no';
	}

	/**
	 * @param mixed $input Nezpracovaná data z formuláře.
	 * @return array<string, string>
	 */
	public static function sanitize( $input ): array {
		$current = ICECOLD_Settings::all();
		$input   = is_array( $input ) ? $input : array();

		$clean = array(
			'token'          => array_key_exists( 'token', $input ) ? sanitize_text_field( (string) $input['token'] ) : $current['token'],
			'portal_email'   => isset( $input['portal_email'] ) ? sanitize_email( (string) $input['portal_email'] ) : $current['portal_email'],
			'portal_company' => isset( $input['portal_company'] ) ? sanitize_text_field( (string) $input['portal_company'] ) : $current['portal_company'],
			'sandbox'        => array_key_exists( 'sandbox', $input ) ? self::yes_no( $input['sandbox'] ) : 'no',
			'auto_create'    => array_key_exists( 'auto_create', $input ) ? self::yes_no( $input['auto_create'] ) : 'no',
			'trigger_status' => isset( $input['trigger_status'] ) ? sanitize_key( (string) $input['trigger_status'] ) : $current['trigger_status'],
			'mark_completed' => array_key_exists( 'mark_completed', $input ) ? self::yes_no( $input['mark_completed'] ) : 'no',
			'webhook_secret' => isset( $input['webhook_secret'] ) ? sanitize_text_field( (string) $input['webhook_secret'] ) : $current['webhook_secret'],
			'debug'          => array_key_exists( 'debug', $input ) ? self::yes_no( $input['debug'] ) : 'no',
		);

		return $clean;
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s         = ICECOLD_Settings::all();
		$connected = '' !== $s['token'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ICECOLD — napojení dopravy', 'icecold-shipping' ); ?></h1>

			<?php if ( $connected ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php esc_html_e( 'E-shop je přihlášený k ICECOLD.', 'icecold-shipping' ); ?></strong>
						<?php if ( '' !== $s['portal_company'] ) : ?>
							<?php echo esc_html( $s['portal_company'] ); ?>
						<?php endif; ?>
						<?php if ( '' !== $s['portal_email'] ) : ?>
							· <?php echo esc_html( $s['portal_email'] ); ?>
						<?php endif; ?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0">
					<input type="hidden" name="action" value="icecold_portal_logout">
					<?php wp_nonce_field( 'icecold_portal_logout' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Odpojit e-shop', 'icecold-shipping' ); ?></button>
				</form>
			<?php else : ?>
				<h2><?php esc_html_e( 'Přihlášení portálovým účtem', 'icecold-shipping' ); ?></h2>
				<p><?php esc_html_e( 'Přihlaste se stejným e-mailem a heslem, jakým se přihlašujete do klientského portálu ICECOLD. Heslo se neukládá — po přihlášení zůstane jen token, který lze kdykoli zrušit.', 'icecold-shipping' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="icecold_portal_login">
					<?php wp_nonce_field( 'icecold_portal_login' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="icecold-portal-email"><?php esc_html_e( 'E-mail do portálu', 'icecold-shipping' ); ?></label></th>
							<td><input type="email" class="regular-text" id="icecold-portal-email" name="portal_email" required autocomplete="username"></td>
						</tr>
						<tr>
							<th scope="row"><label for="icecold-portal-password"><?php esc_html_e( 'Heslo do portálu', 'icecold-shipping' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" id="icecold-portal-password" name="portal_password" required autocomplete="current-password">
								<p class="description"><?php esc_html_e( 'Heslo se po přihlášení neukládá. Přihlášení portálem je produkční; pro sandbox použijte ruční API token.', 'icecold-shipping' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Přihlásit se do portálu', 'icecold-shipping' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<p>
				<?php esc_html_e( 'Přihlášením vznikne token vázaný na vaši firmu. Zásilky se pak zakládají automaticky a jsou vidět v portálu i na dispečinku.', 'icecold-shipping' ); ?>
				<a href="https://it.icecoldtrans.cz/api" target="_blank" rel="noopener"><?php esc_html_e( 'API reference', 'icecold-shipping' ); ?></a>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'icecold_shipping' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="icecold-token"><?php esc_html_e( 'API token (pokročilé)', 'icecold-shipping' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="icecold-token" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[token]" value="<?php echo esc_attr( $s['token'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Po přihlášení portálem se vyplní automaticky. Ručně ho použijte jen pro sandbox nebo jiné napojení.', 'icecold-shipping' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Prostředí', 'icecold-shipping' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[sandbox]" value="1" <?php checked( 'yes', $s['sandbox'] ); ?>> <?php esc_html_e( 'Sandbox (testovací zásilky)', 'icecold-shipping' ); ?></label>
							<p class="description"><?php echo esc_html( ICECOLD_Settings::base_url() ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Zakládat zásilky', 'icecold-shipping' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[auto_create]" value="1" <?php checked( 'yes', $s['auto_create'] ); ?>> <?php esc_html_e( 'Automaticky při změně stavu objednávky', 'icecold-shipping' ); ?></label>
							<p>
								<select name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[trigger_status]">
									<?php
									$statuses = array(
										'processing' => __( 'Zpracovává se', 'icecold-shipping' ),
										'completed'  => __( 'Dokončeno', 'icecold-shipping' ),
										'on-hold'    => __( 'Pozastaveno', 'icecold-shipping' ),
									);
									foreach ( $statuses as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( $s['trigger_status'], $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Po doručení', 'icecold-shipping' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[mark_completed]" value="1" <?php checked( 'yes', $s['mark_completed'] ); ?>> <?php esc_html_e( 'Nastavit objednávku na „Dokončeno“', 'icecold-shipping' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="icecold-secret"><?php esc_html_e( 'Tajemství webhooku', 'icecold-shipping' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="icecold-secret" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[webhook_secret]" value="<?php echo esc_attr( $s['webhook_secret'] ); ?>" autocomplete="off">
							<p class="description">
								<?php esc_html_e( 'URL pro webhooky:', 'icecold-shipping' ); ?>
								<code><?php echo esc_html( ICECOLD_Settings::webhook_url() ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Protokol', 'icecold-shipping' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( ICECOLD_Settings::OPTION ); ?>[debug]" value="1" <?php checked( 'yes', $s['debug'] ); ?>> <?php esc_html_e( 'Zapisovat požadavky do WooCommerce logu', 'icecold-shipping' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="icecold_register_webhook">
				<?php wp_nonce_field( 'icecold_register_webhook' ); ?>
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Zaregistrovat webhook u ICECOLD', 'icecold-shipping' ); ?></button>
					<span class="description"><?php esc_html_e( 'Uloží URL a události, tajemství se doplní automaticky.', 'icecold-shipping' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}

	public static function metabox(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'icecold-shipment',
				__( 'ICECOLD zásilka', 'icecold-shipping' ),
				array( __CLASS__, 'render_metabox' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * @param WP_Post|WC_Order $post Nebo objednávka u HPOS.
	 */
	public static function render_metabox( $post ): void {
		$order = $post instanceof WC_Order ? $post : wc_get_order( $post->ID );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$number   = (string) $order->get_meta( ICECOLD_Order_Shipment::META_NUMBER );
		$status   = (string) $order->get_meta( ICECOLD_Order_Shipment::META_STATUS );
		$tracking = (string) $order->get_meta( ICECOLD_Order_Shipment::META_TRACKING );
		$error    = (string) $order->get_meta( ICECOLD_Order_Shipment::META_ERROR );
		$mode     = (string) $order->get_meta( ICECOLD_Order_Shipment::META_MODE );
		$date     = (string) $order->get_meta( ICECOLD_Order_Shipment::META_DATE );
		$window   = (string) $order->get_meta( ICECOLD_Order_Shipment::META_WINDOW );

		if ( '' === $number ) {
			echo '<p>' . esc_html__( 'Zásilka zatím není založená.', 'icecold-shipping' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html( $number ) . '</strong><br>';
			echo esc_html( self::status_label( $status ) ) . '<br>';
			if ( '' !== $mode ) {
				echo esc_html( ( 'frozen' === $mode ? 'mražená −18 °C' : 'chlazená 1–5 °C' ) );
				echo esc_html( $date ? ' · ' . $date : '' );
				echo esc_html( $window ? ' · ' . $window : '' );
				echo '<br>';
			}
			if ( '' !== $tracking ) {
				printf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( $tracking ),
					esc_html__( 'Sledovat zásilku', 'icecold-shipping' )
				);
			}
			echo '</p>';
		}

		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}

		if ( '' === $number ) {
			self::button( 'icecold_create', $order->get_id(), __( 'Založit zásilku', 'icecold-shipping' ), 'button button-primary' );
		} else {
			self::button( 'icecold_sync', $order->get_id(), __( 'Obnovit stav', 'icecold-shipping' ) );
			self::button( 'icecold_label', $order->get_id(), __( 'Otevřít štítek k tisku', 'icecold-shipping' ), 'button', true );
		}
	}

	private static function button( string $action, int $order_id, string $label, string $class = 'button', bool $new_tab = false ): void {
		printf(
			'<form method="post" action="%s" style="margin:6px 0"%s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$new_tab ? ' target="_blank" rel="noopener"' : ''
		);
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( $action ) );
		printf( '<input type="hidden" name="order_id" value="%d">', (int) $order_id );
		wp_nonce_field( $action . '_' . $order_id );
		printf(
			'<button type="submit" class="%s">%s</button></form>',
			esc_attr( $class ),
			esc_html( $label )
		);
	}

	public static function handle_create(): void {
		$order = self::guard( 'icecold_create' );
		$order_id = $order->get_id();

		$result = ICECOLD_Order_Shipment::create( $order, true );

		wp_safe_redirect( self::back_url( $order_id, is_wp_error( $result ) ? 'error' : 'created' ) );
		exit;
	}

	public static function handle_sync(): void {
		$order    = self::guard( 'icecold_sync' );
		$order_id = $order->get_id();

		$result = ICECOLD_Order_Shipment::sync( $order );

		wp_safe_redirect( self::back_url( $order_id, is_wp_error( $result ) ? 'error' : 'synced' ) );
		exit;
	}

	public static function handle_label(): void {
		$order = self::guard( 'icecold_label' );

		$number = (string) $order->get_meta( ICECOLD_Order_Shipment::META_NUMBER );
		if ( '' === $number ) {
			wp_die( esc_html__( 'Objednávka nemá zásilku ICECOLD.', 'icecold-shipping' ) );
		}

		$html = ICECOLD_API::get_label( $number );
		if ( is_wp_error( $html ) ) {
			wp_die( esc_html( $html->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="stitek-' . sanitize_file_name( $number ) . '.html"' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tisková stránka z API.
		exit;
	}

	public static function handle_register_webhook(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'icecold-shipping' ) );
		}
		check_admin_referer( 'icecold_register_webhook' );

		$result = ICECOLD_API::register_webhook();

		wp_safe_redirect( admin_url( 'admin.php?page=icecold-shipping&icecold_notice=' . ( is_wp_error( $result ) ? 'webhook_error' : 'webhook_ok' ) ) );
		exit;
	}

	public static function handle_portal_login(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'icecold-shipping' ) );
		}
		check_admin_referer( 'icecold_portal_login' );

		$email    = isset( $_POST['portal_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['portal_email'] ) ) : '';
		$password = isset( $_POST['portal_password'] ) ? (string) wp_unslash( $_POST['portal_password'] ) : '';

		if ( ! is_email( $email ) || '' === $password ) {
			self::portal_redirect( 'portal_error', __( 'Zadejte platný e-mail a heslo do portálu.', 'icecold-shipping' ) );
		}

		$result = ICECOLD_API::login( $email, $password );
		if ( is_wp_error( $result ) ) {
			self::portal_redirect( 'portal_error', $result->get_error_message() );
		}

		$token = isset( $result['token'] ) ? (string) $result['token'] : '';
		if ( '' === $token ) {
			self::portal_redirect( 'portal_error', __( 'Portál nevrátil přihlašovací token.', 'icecold-shipping' ) );
		}

		$settings                   = ICECOLD_Settings::all();
		$settings['token']          = $token;
		$settings['sandbox']        = 'no';
		$settings['portal_email']   = $email;
		$settings['portal_company'] = isset( $result['company']['name'] ) ? sanitize_text_field( (string) $result['company']['name'] ) : '';
		update_option( ICECOLD_Settings::OPTION, $settings );

		self::portal_redirect( 'portal_ok' );
	}

	public static function handle_portal_logout(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'icecold-shipping' ) );
		}
		check_admin_referer( 'icecold_portal_logout' );

		ICECOLD_API::logout();

		$settings                   = ICECOLD_Settings::all();
		$settings['token']          = '';
		$settings['portal_email']   = '';
		$settings['portal_company'] = '';
		update_option( ICECOLD_Settings::OPTION, $settings );

		self::portal_redirect( 'portal_disconnected' );
	}

	private static function portal_redirect( string $notice, string $error = '' ): never {
		if ( '' !== $error ) {
			set_transient( 'icecold_portal_error_' . get_current_user_id(), $error, 120 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=icecold-shipping&icecold_notice=' . rawurlencode( $notice ) ) );
		exit;
	}

	private static function guard( string $action ): WC_Order {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění.', 'icecold-shipping' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		check_admin_referer( $action . '_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Objednávka neexistuje.', 'icecold-shipping' ) );
		}

		return $order;
	}

	private static function back_url( int $order_id, string $notice ): string {
		$order = wc_get_order( $order_id );
		$base  = $order instanceof WC_Order && $order->get_edit_order_url()
			? $order->get_edit_order_url()
			: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		return add_query_arg( 'icecold_notice', $notice, $base );
	}

	public static function notices(): void {
		$notice = isset( $_GET['icecold_notice'] ) ? sanitize_key( (string) $_GET['icecold_notice'] ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'created'       => array( 'success', __( 'Zásilka ICECOLD byla založena.', 'icecold-shipping' ) ),
			'synced'        => array( 'success', __( 'Stav zásilky byl obnoven.', 'icecold-shipping' ) ),
			'error'         => array( 'error', __( 'Operace se nepovedla, detail je v poznámce objednávky.', 'icecold-shipping' ) ),
			'webhook_ok'    => array( 'success', __( 'Webhook byl zaregistrován.', 'icecold-shipping' ) ),
			'webhook_error' => array( 'error', __( 'Webhook se nepodařilo zaregistrovat.', 'icecold-shipping' ) ),
			'portal_ok'     => array( 'success', __( 'Přihlášení k portálu proběhlo. E-shop je připojený.', 'icecold-shipping' ) ),
			'portal_disconnected' => array( 'success', __( 'E-shop byl odpojen od ICECOLD.', 'icecold-shipping' ) ),
			'portal_error'  => array( 'error', __( 'Přihlášení k portálu se nepovedlo.', 'icecold-shipping' ) ),
		);

		if ( 'portal_error' === $notice ) {
			$detail = get_transient( 'icecold_portal_error_' . get_current_user_id() );
			if ( is_string( $detail ) && '' !== $detail ) {
				delete_transient( 'icecold_portal_error_' . get_current_user_id() );
				$messages['portal_error'][1] .= ' ' . $detail;
			}
		}

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	/**
	 * @param array<string, string> $columns Sloupce seznamu objednávek.
	 * @return array<string, string>
	 */
	public static function column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['icecold'] = __( 'ICECOLD', 'icecold-shipping' );
			}
		}
		if ( ! isset( $new['icecold'] ) ) {
			$new['icecold'] = __( 'ICECOLD', 'icecold-shipping' );
		}

		return $new;
	}

	/**
	 * @param string   $column  Klíč sloupce.
	 * @param int|WC_Order $order_id Nebo objednávka u HPOS.
	 */
	public static function column_content( $column, $order_id ): void {
		if ( 'icecold' !== $column ) {
			return;
		}

		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order ) {
			echo '—';
			return;
		}

		$number = (string) $order->get_meta( ICECOLD_Order_Shipment::META_NUMBER );
		if ( '' === $number ) {
			echo '—';
			return;
		}

		printf(
			'<a href="%s">%s</a><br><small>%s</small>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $number ),
			esc_html( self::status_label( (string) $order->get_meta( ICECOLD_Order_Shipment::META_STATUS ) ) )
		);
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'planned'    => __( 'naplánováno', 'icecold-shipping' ),
			'loaded'     => __( 'naloženo', 'icecold-shipping' ),
			'in_transit' => __( 'na trase', 'icecold-shipping' ),
			'delivered'  => __( 'doručeno', 'icecold-shipping' ),
			'delayed'    => __( 'zpožděno', 'icecold-shipping' ),
			'returned'   => __( 'vrací se', 'icecold-shipping' ),
		);

		return $labels[ $status ] ?? ( '' !== $status ? $status : __( 'bez stavu', 'icecold-shipping' ) );
	}
}
