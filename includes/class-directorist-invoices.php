<?php
/**
 * Directorist invoice integration and PDF delivery.
 *
 * @package Directorist_Invoice
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds invoices from Directorist and Booking orders.
 */
final class Directorist_Invoices {
	private const PAGE_SIZE = 20;

	/**
	 * Register WordPress hooks.
	 */
	public static function init(): void {
		add_filter( 'directorist_dashboard_tabs', array( __CLASS__, 'dashboard_tab' ) );
		add_filter( 'directorist_dashboard_tabs', array( __CLASS__, 'profile_tax_id_field' ), 99 );
		add_action( 'directorist_user_profile_updated', array( __CLASS__, 'save_profile_tax_id' ), 10, 2 );
		add_filter( 'atbdp_extension_settings_submenu', array( __CLASS__, 'settings_tab' ) );
		add_filter( 'atbdp_listing_type_settings_field_list', array( __CLASS__, 'settings_fields' ) );
		add_action( 'init', array( __CLASS__, 'repair_saved_address' ) );
		add_action( 'admin_post_directorist_invoice_download', array( __CLASS__, 'download' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_order_assets' ), 20 );
		add_action( 'directorist_payment_receipt_before_order_details', array( __CLASS__, 'receipt_link' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'legacy_order_action' ), 10, 2 );
	}

	/**
	 * Check whether the current user can manage Directorist orders.
	 *
	 * @return bool
	 */
	private static function can_manage(): bool {
		return current_user_can( 'manage_atbdp_options' ) || current_user_can( 'manage_options' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Directorist registers this capability for administrators.
	}

	/**
	 * Check whether an integration table exists during this request.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		static $exists = array();
		global $wpdb;
		if ( ! array_key_exists( $table, $exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Integration table existence is cached for this request.
			$exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}
		return $exists[ $table ];
	}

	/**
	 * Add the customer invoices dashboard tab.
	 *
	 * @param array $tabs Existing dashboard tabs.
	 * @return array
	 */
	public static function dashboard_tab( array $tabs ): array {
		if ( ! is_user_logged_in() ) {
			return $tabs;
		}
		self::enqueue_order_assets();

		$tabs['dashboard_invoices'] = array(
			'title'   => __( 'Invoices', 'directorist-invoice' ),
			'icon'    => 'fas fa-file-alt',
			'content' => self::render_list(),
		);

		return $tabs;
	}

	/**
	 * Insert a Tax ID input into Directorist's rendered profile form.
	 *
	 * Directorist filters dashboard tabs after rendering the profile template, so
	 * this keeps its original form and AJAX submission in place.
	 *
	 * @param array $tabs Existing dashboard tabs.
	 * @return array
	 */
	public static function profile_tax_id_field( array $tabs ): array {
		if ( ! is_user_logged_in() || empty( $tabs['dashboard_profile']['content'] ) || ! is_string( $tabs['dashboard_profile']['content'] ) ) {
			return $tabs;
		}

		$content = $tabs['dashboard_profile']['content'];
		$marker  = '<div class="directorist-user-password">';
		if ( false === strpos( $content, $marker ) || false !== strpos( $content, 'name="user[directorist_invoice_tax_id]"' ) ) {
			return $tabs;
		}

		$tax_id = get_user_meta( get_current_user_id(), 'directorist_invoice_tax_id', true );
		$tax_id = is_scalar( $tax_id ) ? (string) $tax_id : '';
		$field  = '<div class="directorist-user-tax-id"><div class="directorist-form-group">';
		$field .= '<label for="directorist_invoice_tax_id">' . esc_html__( 'Tax ID', 'directorist-invoice' ) . '</label>';
		$field .= '<input class="directorist-form-element" type="text" id="directorist_invoice_tax_id" name="user[directorist_invoice_tax_id]" maxlength="100" value="' . esc_attr( $tax_id ) . '">';
		$field .= '</div></div>';

		$tabs['dashboard_profile']['content'] = str_replace( $marker, $field . $marker, $content );
		return $tabs;
	}

	/**
	 * Store Tax ID after Directorist accepts the profile update.
	 *
	 * @param int   $user_id Updated user ID.
	 * @param array $data    Submitted profile data, already unslashed by Directorist.
	 */
	public static function save_profile_tax_id( int $user_id, array $data ): void {
		if ( get_current_user_id() !== $user_id || ! current_user_can( 'edit_user', $user_id ) || ! array_key_exists( 'directorist_invoice_tax_id', $data ) || ! is_scalar( $data['directorist_invoice_tax_id'] ) ) {
			return;
		}

		$tax_id = sanitize_text_field( (string) $data['directorist_invoice_tax_id'] );
		update_user_meta( $user_id, 'directorist_invoice_tax_id', mb_substr( $tax_id, 0, 100 ) );
	}

	/**
	 * Add invoice settings under Directorist extensions.
	 *
	 * @param array $submenu Existing extension settings.
	 * @return array
	 */
	public static function settings_tab( array $submenu ): array {
		$submenu['invoice_submenu'] = array(
			'label'    => __( 'Invoice', 'directorist-invoice' ),
			'icon'     => '<i class="fas fa-file-alt" aria-hidden="true"></i>',
			'sections' => array(
				'invoice_business' => array(
					'title'       => __( 'Business details', 'directorist-invoice' ),
					'description' => __( 'These details appear on every PDF invoice.', 'directorist-invoice' ),
					'fields'      => array( 'invoice_business_name', 'invoice_business_logo', 'invoice_business_address', 'invoice_business_email', 'invoice_business_tax_id' ),
				),
				'invoice_customer' => array(
					'title'       => __( 'Customer details', 'directorist-invoice' ),
					'description' => __( 'Choose which customer profile details appear on PDF invoices. The address is always shown when available.', 'directorist-invoice' ),
					'fields'      => array( 'invoice_show_customer_email', 'invoice_show_customer_phone', 'invoice_show_customer_tax_id' ),
				),
			),
		);
		return $submenu;
	}

	/**
	 * Define invoice business settings fields.
	 *
	 * @param array $fields Existing settings fields.
	 * @return array
	 */
	public static function settings_fields( array $fields ): array {
		$previous                               = get_option( 'directorist_invoice_business', array() );
		$previous                               = is_array( $previous ) ? $previous : array();
		$site_logo_id                           = (int) get_theme_mod( 'custom_logo' );
		$site_logo                              = $site_logo_id ? wp_get_attachment_url( $site_logo_id ) : '';
		$fields['invoice_business_name']        = array(
			'type'  => 'text',
			'label' => __( 'Business name', 'directorist-invoice' ),
			'value' => $previous['name'] ?? get_bloginfo( 'name' ),
		);
		$fields['invoice_business_logo']        = array(
			'type'        => 'wp-media-picker',
			'label'       => __( 'Business logo', 'directorist-invoice' ),
			'description' => __( 'Shown in the PDF header. Choose a PNG, JPEG, GIF, or WebP image from the Media Library.', 'directorist-invoice' ),
			'value'       => $previous['logo'] ?? ( $site_logo ? $site_logo : '' ),
		);
		$fields['invoice_business_address']     = array(
			'type'  => 'textarea',
			'label' => __( 'Business address', 'directorist-invoice' ),
			'value' => $previous['address'] ?? '',
		);
		$fields['invoice_business_email']       = array(
			'type'  => 'text',
			'label' => __( 'Contact email', 'directorist-invoice' ),
			'value' => $previous['email'] ?? get_option( 'admin_email' ),
		);
		$fields['invoice_business_tax_id']      = array(
			'type'  => 'text',
			'label' => __( 'Tax ID', 'directorist-invoice' ),
			'value' => $previous['tax_id'] ?? '',
		);
		$fields['invoice_show_customer_email']  = array(
			'type'  => 'toggle',
			'label' => __( 'Show customer email', 'directorist-invoice' ),
			'value' => true,
		);
		$fields['invoice_show_customer_phone']  = array(
			'type'  => 'toggle',
			'label' => __( 'Show customer phone', 'directorist-invoice' ),
			'value' => true,
		);
		$fields['invoice_show_customer_tax_id'] = array(
			'type'  => 'toggle',
			'label' => __( 'Show customer Tax ID', 'directorist-invoice' ),
			'value' => true,
		);
		return $fields;
	}

	/**
	 * Repair addresses stored with a legacy encoded payload.
	 */
	public static function repair_saved_address(): void {
		$options = get_option( 'atbdp_option', array() );
		if ( ! is_array( $options ) || ! isset( $options['invoice_business_address'] ) || ! is_string( $options['invoice_business_address'] ) ) {
			return;
		}

		// Earlier versions saved Directorist's encoded form payload instead of its decoded textarea value.
		$stored  = $options['invoice_business_address'];
		$decoded = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a legacy settings payload.
		if ( false === $decoded || base64_encode( $decoded ) !== $stored ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Verifies the legacy payload format.
			return;
		}

		$values = json_decode( $decoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $values ) || 1 !== count( $values ) || ! isset( $values[0] ) || ! is_string( $values[0] ) ) {
			return;
		}

		$options['invoice_business_address'] = sanitize_textarea_field( $values[0] );
		update_option( 'atbdp_option', $options );
	}

	/**
	 * Register the authenticated order invoice links endpoint.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'directorist-invoice/v1',
			'/orders/(?P<id>\d+)/links',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function ( $request ) {
					$id      = absint( $request['id'] );
					$invoice = self::get_invoice( 'order', $id );
					if ( ! $invoice || ! self::may_download( $invoice ) ) {
						return new WP_Error( 'directorist_invoice_not_found', __( 'Invoice not found.', 'directorist-invoice' ), array( 'status' => 404 ) );
					}
					return rest_ensure_response(
						array(
							'view_url'     => self::view_url( 'order', $id ),
							'download_url' => self::download_url( 'order', $id ),
						)
					);
				},
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * Load action menu assets on the Directorist orders screen.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 */
	public static function admin_order_assets( string $hook_suffix ): void {
		if ( ! self::can_manage() ) {
			return;
		}
		if ( 'at_biz_dir_page_directorist-orders' === $hook_suffix ) {
			self::enqueue_order_assets( true );
		} elseif ( 'edit.php' === $hook_suffix ) {
			$screen = get_current_screen();
			if ( $screen && 'atbdp_orders' === $screen->post_type ) {
				self::enqueue_icon_styles();
			}
		}
	}

	/**
	 * Load the icon button styles and Directorist's Font Awesome font.
	 */
	private static function enqueue_icon_styles(): void {
		wp_enqueue_style(
			'directorist-invoice-order-view',
			plugins_url( 'assets/order-view.css', DIRECTORIST_INVOICE_FILE ),
			array( 'directorist-font-awesome' ),
			DIRECTORIST_INVOICE_VERSION
		);
	}

	/**
	 * Load the invoice actions script and styles once.
	 *
	 * @param bool $admin Whether assets are for the admin orders screen.
	 */
	private static function enqueue_order_assets( bool $admin = false ): void {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;
		wp_enqueue_script(
			'directorist-invoice-order-view',
			plugins_url( 'assets/order-view.js', DIRECTORIST_INVOICE_FILE ),
			$admin ? array( 'directorist/admin-order' ) : array(),
			DIRECTORIST_INVOICE_VERSION,
			true
		);
		self::enqueue_icon_styles();
		wp_localize_script(
			'directorist-invoice-order-view',
			'directoristInvoiceOrderView',
			array(
				'endpoint'       => esc_url_raw( rest_url( 'directorist-invoice/v1/orders/' ) ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'viewLabel'      => __( 'View invoice', 'directorist-invoice' ),
				'downloadLabel'  => __( 'Download PDF', 'directorist-invoice' ),
				'actionsLabel'   => __( 'Actions', 'directorist' ),
				'viewOrderLabel' => __( 'View Order', 'directorist' ),
			)
		);
	}

	/**
	 * Print invoice links on a Directorist order receipt.
	 *
	 * @param object $order Directorist order object.
	 */
	public static function receipt_link( $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}
		$id      = (int) $order->get_id();
		$invoice = self::get_invoice( 'order', $id );
		if ( ! $invoice || ! self::may_download( $invoice ) ) {
			return;
		}
		self::enqueue_icon_styles();
		echo '<p class="directorist-invoice-action-pair">';
		echo self::invoice_action_link( 'order', $id, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes URL and label attributes.
		echo self::invoice_action_link( 'order', $id, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes URL and label attributes.
		echo '</p>';
	}

	/**
	 * Add links to legacy order post actions.
	 *
	 * @param array   $actions Existing post actions.
	 * @param WP_Post $post    Order post.
	 * @return array
	 */
	public static function legacy_order_action( array $actions, $post ): array {
		if ( self::can_manage() && $post instanceof WP_Post && 'atbdp_orders' === $post->post_type && in_array( get_post_meta( $post->ID, '_payment_status', true ), array( 'created', 'pending', 'prepaid', 'completed', 'refunded' ), true ) ) {
			$actions['directorist_invoice_view']     = self::invoice_action_link( 'legacy', $post->ID, true );
			$actions['directorist_invoice_download'] = self::invoice_action_link( 'legacy', $post->ID, false );
		}
		return $actions;
	}

	/**
	 * Build an accessible invoice icon link.
	 *
	 * @param string $source Order source.
	 * @param int    $id     Order ID.
	 * @param bool   $view   Whether the link opens the PDF in the browser.
	 * @return string
	 */
	private static function invoice_action_link( string $source, int $id, bool $view ): string {
		$label = $view ? __( 'View invoice', 'directorist-invoice' ) : __( 'Download PDF', 'directorist-invoice' );
		$url   = $view ? self::view_url( $source, $id ) : self::download_url( $source, $id );
		return sprintf(
			'<a class="directorist-invoice-icon-link" href="%1$s" title="%2$s" aria-label="%2$s"%3$s><i class="fas %4$s" aria-hidden="true"></i></a>',
			esc_url( $url ),
			esc_attr( $label ),
			$view ? ' target="_blank" rel="noopener"' : '',
			$view ? 'fa-file-alt' : 'fa-download'
		);
	}

	/**
	 * Build the PDF download URL.
	 *
	 * @param string $source Order source.
	 * @param int    $id     Order ID.
	 * @return string
	 */
	private static function download_url( string $source, int $id ): string {
		return self::invoice_url( $source, $id, false );
	}

	/**
	 * Build the inline PDF URL.
	 *
	 * @param string $source Order source.
	 * @param int    $id     Order ID.
	 * @return string
	 */
	private static function view_url( string $source, int $id ): string {
		return self::invoice_url( $source, $id, true );
	}

	/**
	 * Build a nonce protected invoice URL.
	 *
	 * @param string $source Order source.
	 * @param int    $id     Order ID.
	 * @param bool   $inline Whether to display the PDF in the browser.
	 * @return string
	 */
	private static function invoice_url( string $source, int $id, bool $inline ): string {
		return add_query_arg(
			array(
				'action'   => 'directorist_invoice_download',
				'source'   => $source,
				'id'       => $id,
				'view'     => $inline ? '1' : '0',
				'_wpnonce' => wp_create_nonce( 'directorist_invoice_' . $source . '_' . $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Authorize and stream an invoice PDF.
	 */
	public static function download(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$source = isset( $_GET['source'] ) && is_scalar( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$id     = isset( $_GET['id'] ) && is_scalar( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		if ( ! in_array( $source, array( 'order', 'legacy', 'booking_wc' ), true ) || ! $id ) {
			wp_die( esc_html__( 'Invalid invoice request.', 'directorist-invoice' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( 'directorist_invoice_' . $source . '_' . $id );

		$invoice = self::get_invoice( $source, $id );
		if ( ! $invoice || ! self::may_download( $invoice ) ) {
			wp_die( esc_html__( 'Invoice not found.', 'directorist-invoice' ), '', array( 'response' => 404 ) );
		}

		$autoload = DIRECTORIST_INVOICE_PATH . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			wp_die( esc_html__( 'The PDF library is missing. Reinstall the invoice extension.', 'directorist-invoice' ), '', array( 'response' => 500 ) );
		}
		require_once $autoload;

		try {
			$options = new \Dompdf\Options();
			$options->set( 'isRemoteEnabled', false );
			$options->set( 'isPhpEnabled', false );
			$options->set( 'isJavascriptEnabled', false );
			$options->set( 'imageByteSizeLimit', 16 * MB_IN_BYTES );
			$options->set( 'defaultFont', 'DejaVu Sans' );
			$options->set( 'chroot', DIRECTORIST_INVOICE_PATH );
			$options->set( 'fontCache', sys_get_temp_dir() );
			$pdf = new \Dompdf\Dompdf( $options );
			$pdf->setPaper( 'A4' );
			$pdf->loadHtml( self::invoice_html( $invoice ), 'UTF-8' );
			$pdf->render();
			$bytes = $pdf->output();
		} catch ( \Throwable $error ) {
			do_action( 'directorist_invoice_pdf_error', $error );
			wp_die( esc_html__( 'Unable to generate the invoice PDF.', 'directorist-invoice' ), '', array( 'response' => 500 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		$inline = isset( $_GET['view'] ) && is_scalar( $_GET['view'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['view'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="directorist-invoice-' . sanitize_file_name( $invoice['number'] ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		exit;
	}

	/**
	 * Check whether the current user may access an invoice.
	 *
	 * @param array $invoice Normalized invoice data.
	 * @return bool
	 */
	private static function may_download( array $invoice ): bool {
		return self::can_manage() || ( 0 < (int) $invoice['user_id'] && get_current_user_id() === (int) $invoice['user_id'] );
	}

	/**
	 * Load an invoice from its order source.
	 *
	 * @param string $source Order source.
	 * @param int    $id     Order ID.
	 * @return array|null
	 */
	private static function get_invoice( string $source, int $id ): ?array {
		if ( 'order' === $source ) {
			return self::directorist_order( $id );
		}
		if ( 'legacy' === $source ) {
			return self::legacy_order( $id );
		}
		if ( 'booking_wc' === $source ) {
			return self::woocommerce_booking( $id );
		}
		return null;
	}

	/**
	 * Build an invoice from a current Directorist order.
	 *
	 * @param int $id Order ID.
	 * @return array|null
	 */
	private static function directorist_order( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'directorist_orders';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read current order state from Directorist's custom table.
		$order = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
		if ( ! $order || ! in_array( $order->status, array( 'pending', 'unpaid', 'prepaid', 'paid', 'refunded' ), true ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Payment status must remain current.
		$payment  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . 'directorist_payments',
				$id
			)
		);
		$title    = self::order_title( $order );
		$subtotal = (float) $order->sub_total;
		$discount = function_exists( 'directorist_compute_fixed_or_percent_amount' )
			? max( 0, min( max( 0, $subtotal ), (float) directorist_compute_fixed_or_percent_amount( $order->coupon_discount_type, (float) $order->coupon_discount, $subtotal ) ) ) : 0;
		$tax_base = max( 0, $subtotal - $discount );
		$tax      = function_exists( 'directorist_compute_fixed_or_percent_amount' )
			? max( 0, (float) directorist_compute_fixed_or_percent_amount( $order->tax_type, (float) $order->tax_rate, $tax_base ) ) : 0;
		$total    = function_exists( 'directorist_order_total_amount' )
			? directorist_order_total_amount( $order ) : round( $tax_base + $tax, 2 );

		return self::normalize(
			array(
				'source'      => 'order',
				'id'          => $id,
				'number'      => 'DIR-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
				'user_id'     => (int) $order->user_id,
				'date'        => $payment && 'paid' === $payment->status ? $payment->created_at : $order->created_at,
				'status'      => $order->status,
				'title'       => $title,
				'currency'    => $order->currency ? $order->currency : 'USD',
				'subtotal'    => $subtotal,
				'discount'    => $discount,
				'tax'         => $tax,
				'total'       => $total,
				'method'      => $payment ? $payment->method : '',
				'transaction' => $payment ? $payment->transaction_id : '',
			)
		);
	}

	/**
	 * Build a purchase title for a Directorist order.
	 *
	 * @param object $order Directorist order record.
	 * @return string
	 */
	private static function order_title( $order ): string {
		global $wpdb;
		if ( ! empty( $order->legacy_id ) && get_post_meta( (int) $order->legacy_id, '_booking_id', true ) ) {
			$listing = $order->listing_id ? get_the_title( (int) $order->listing_id ) : '';
			/* translators: %s: booked listing title. */
			return $listing ? sprintf( __( 'Booking: %s', 'directorist-invoice' ), $listing ) : __( 'Booking', 'directorist-invoice' );
		}
		if ( 'pricing_plan' === $order->ref_type && absint( $order->ref ) ) {
			$plan_table = $wpdb->prefix . 'directorist_plans';
			if ( self::table_exists( $plan_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read a plan title from Directorist's custom table.
				$title = $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM %i WHERE id = %d', $plan_table, absint( $order->ref ) ) );
				if ( $title ) {
					/* translators: %s: pricing plan title. */
					return sprintf( __( 'Pricing plan: %s', 'directorist-invoice' ), $title );
				}
			}
			$legacy_title = 'atbdp_pricing_plans' === get_post_type( absint( $order->ref ) ) ? get_the_title( absint( $order->ref ) ) : '';
			/* translators: %s: pricing plan title. */
			return $legacy_title ? sprintf( __( 'Pricing plan: %s', 'directorist-invoice' ), $legacy_title ) : __( 'Pricing plan', 'directorist-invoice' );
		}
		if ( 'featured_listing' === $order->ref_type ) {
			$listing = $order->listing_id ? get_the_title( (int) $order->listing_id ) : '';
			/* translators: %s: featured listing title. */
			return $listing ? sprintf( __( 'Featured listing: %s', 'directorist-invoice' ), $listing ) : __( 'Featured listing', 'directorist-invoice' );
		}
		if ( $order->listing_id ) {
			$listing = get_the_title( (int) $order->listing_id );
			if ( $listing ) {
				return $listing;
			}
		}
		return $order->ref_type ? ucwords( str_replace( '_', ' ', $order->ref_type ) ) : __( 'Directorist purchase', 'directorist-invoice' );
	}

	/**
	 * Build an invoice from a legacy Directorist order post.
	 *
	 * @param int $id Order post ID.
	 * @return array|null
	 */
	private static function legacy_order( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || 'atbdp_orders' !== $post->post_type || ! in_array( get_post_meta( $id, '_payment_status', true ), array( 'created', 'pending', 'prepaid', 'completed', 'refunded' ), true ) ) {
			return null;
		}

		global $wpdb;
		$current_table = $wpdb->prefix . 'directorist_orders';
		if ( self::table_exists( $current_table ) &&
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Detect a migrated Directorist order using its custom table.
			$wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE legacy_id = %d AND status IN ('pending','unpaid','prepaid','paid','refunded') LIMIT 1", $current_table, $id ) ) ) {
			return null; // A migrated order has its own current invoice.
		}

		$booking_id = absint( get_post_meta( $id, '_booking_id', true ) );
		$listing_id = absint( get_post_meta( $id, '_listing_id', true ) );
		$title      = $booking_id ? __( 'Booking', 'directorist-invoice' ) : __( 'Directorist purchase', 'directorist-invoice' );
		if ( $listing_id && get_the_title( $listing_id ) ) {
			$title .= ': ' . get_the_title( $listing_id );
		}
		$amount = (float) get_post_meta( $id, '_amount', true );

		return self::normalize(
			array(
				'source'      => 'legacy',
				'id'          => $id,
				'number'      => 'DIR-L-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
				'user_id'     => (int) $post->post_author,
				'date'        => $post->post_date,
				'status'      => get_post_meta( $id, '_payment_status', true ),
				'title'       => $title,
				'currency'    => function_exists( 'atbdp_get_payment_currency' ) ? atbdp_get_payment_currency() : 'USD',
				'subtotal'    => $amount,
				'discount'    => 0,
				'tax'         => 0,
				'total'       => $amount,
				'method'      => get_post_meta( $id, '_payment_gateway', true ),
				'transaction' => get_post_meta( $id, '_transaction_id', true ),
			)
		);
	}

	/**
	 * Build an invoice from a WooCommerce booking order.
	 *
	 * @param int $id WooCommerce order ID.
	 * @return array|null
	 */
	private static function woocommerce_booking( int $id ): ?array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		global $wpdb;
		$booking_table = $wpdb->prefix . 'directorist_booking';
		if ( ! self::table_exists( $booking_table ) ) {
			return null;
		}
		$order = wc_get_order( $id );
		if ( ! $order || ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing', 'completed', 'refunded' ), true ) ) {
			return null;
		}
		$listing_id = (int) $order->get_meta( '_listing_id' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm this WooCommerce order belongs to a Directorist booking.
		$booking = $listing_id ? $wpdb->get_row(
			$wpdb->prepare(
				'SELECT listing_id FROM %i WHERE order_id = %d AND listing_id = %d LIMIT 1',
				$booking_table,
				$id,
				$listing_id
			)
		) : null;
		if ( ! $booking ) {
			return null;
		}
		$listing_title = $booking->listing_id ? get_the_title( (int) $booking->listing_id ) : '';
		/* translators: %s: booked listing title. */
		$title = $listing_title ? sprintf( __( 'Booking: %s', 'directorist-invoice' ), $listing_title ) : __( 'Booking', 'directorist-invoice' );
		return self::normalize(
			array(
				'source'          => 'booking_wc',
				'id'              => $id,
				'number'          => 'DIR-B-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT ),
				'user_id'         => (int) $order->get_customer_id(),
				'date'            => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d H:i:s' ) : ( $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' ) ),
				'status'          => $order->get_status(),
				'title'           => $title,
				'currency'        => $order->get_currency(),
				'subtotal'        => (float) $order->get_subtotal(),
				'discount'        => (float) $order->get_discount_total(),
				'tax'             => (float) $order->get_total_tax(),
				'total'           => (float) $order->get_total(),
				'method'          => $order->get_payment_method_title(),
				'transaction'     => $order->get_transaction_id(),
				'billing_name'    => $order->get_formatted_billing_full_name(),
				'billing_email'   => $order->get_billing_email(),
				'billing_phone'   => $order->get_billing_phone(),
				'billing_address' => implode(
					"\n",
					array_filter(
						array(
							$order->get_billing_address_1(),
							$order->get_billing_address_2(),
							trim( $order->get_billing_city() . ' ' . $order->get_billing_postcode() ),
							$order->get_billing_country(),
						)
					)
				),
			)
		);
	}

	/**
	 * Fill in billing details and calculate the adjustment.
	 *
	 * @param array $data Order invoice fields.
	 * @return array
	 */
	private static function normalize( array $data ): array {
		$user                   = $data['user_id'] ? get_userdata( $data['user_id'] ) : false;
		$data['billing_name']   = isset( $data['billing_name'] ) && $data['billing_name'] ? $data['billing_name'] : ( $user ? $user->display_name : __( 'Customer', 'directorist-invoice' ) );
		$profile_address        = $user ? get_user_meta( $user->ID, 'address', true ) : '';
		$profile_phone          = $user ? get_user_meta( $user->ID, 'atbdp_phone', true ) : '';
		$profile_tax_id         = $user ? get_user_meta( $user->ID, 'directorist_invoice_tax_id', true ) : '';
		$profile_address        = is_scalar( $profile_address ) ? mb_substr( (string) $profile_address, 0, 1000 ) : '';
		$profile_phone          = is_scalar( $profile_phone ) ? mb_substr( (string) $profile_phone, 0, 100 ) : '';
		$profile_tax_id         = is_scalar( $profile_tax_id ) ? mb_substr( (string) $profile_tax_id, 0, 100 ) : '';
		$data['billing_email']  = $user && $user->user_email ? $user->user_email : ( $data['billing_email'] ?? '' );
		$data['billing_phone']  = $profile_phone ? $profile_phone : ( $data['billing_phone'] ?? '' );
		$data['billing_tax_id'] = $profile_tax_id;
		if ( $profile_address ) {
			$data['billing_address'] = $profile_address;
		}
		if ( empty( $data['billing_address'] ) && $user ) {
			$data['billing_address'] = implode(
				"\n",
				array_filter(
					array(
						get_user_meta( $user->ID, 'billing_address_1', true ),
						get_user_meta( $user->ID, 'billing_address_2', true ),
						trim( get_user_meta( $user->ID, 'billing_city', true ) . ' ' . get_user_meta( $user->ID, 'billing_postcode', true ) ),
						get_user_meta( $user->ID, 'billing_country', true ),
					)
				)
			);
		}
		$data['billing_address'] = $data['billing_address'] ?? '';
		$data['adjustment']      = round( (float) $data['total'] - ( (float) $data['subtotal'] - (float) $data['discount'] + (float) $data['tax'] ), 2 );
		return $data;
	}

	/**
	 * Render the current customer's invoice table.
	 *
	 * @return string
	 */
	private static function render_list(): string {
		static $render_cache = array();
		global $wpdb;
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$page_param = 'invoice_page';
		$page       = isset( $_GET[ $page_param ] ) && is_scalar( $_GET[ $page_param ] ) ? max( 1, absint( wp_unslash( $_GET[ $page_param ] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$offset     = ( $page - 1 ) * self::PAGE_SIZE;
		$cache_key  = $user_id . ':' . $page;
		if ( isset( $render_cache[ $cache_key ] ) ) {
			return $render_cache[ $cache_key ];
		}
		$rows     = array();
		$migrated = array();
		$table    = $wpdb->prefix . 'directorist_orders';
		if ( self::table_exists( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read only the signed-in customer's live Directorist orders.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, legacy_id, created_at AS date, 'order' AS source FROM %i WHERE status IN ('pending','unpaid','prepaid','paid','refunded') AND user_id = %d",
					$table,
					$user_id
				)
			);
			foreach ( $rows as $row ) {
				if ( $row->legacy_id ) {
					$migrated[ (int) $row->legacy_id ] = true;
				}
			}
		}

		$args = array(
			'post_type'      => 'atbdp_orders',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'author'         => $user_id,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Legacy order status is stored only in post meta.
				array(
					'key'     => '_payment_status',
					'value'   => array( 'created', 'pending', 'prepaid', 'completed', 'refunded' ),
					'compare' => 'IN',
				),
			),
		);
		foreach ( get_posts( $args ) as $id ) {
			if ( ! isset( $migrated[ (int) $id ] ) ) {
				$rows[] = (object) array(
					'id'     => $id,
					'source' => 'legacy',
					'date'   => get_post_field( 'post_date', $id ),
				);
			}
		}

		$booking_table = $wpdb->prefix . 'directorist_booking';
		if ( function_exists( 'wc_get_order' ) && self::table_exists( $booking_table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read only the signed-in customer's live bookings.
			$bookings            = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT order_id, listing_id FROM %i WHERE bookings_author = %d AND order_id IS NOT NULL AND order_id <> 0', $booking_table, $user_id ) );
			$seen_booking_orders = array();
			foreach ( $bookings as $booking ) {
				$id = (int) $booking->order_id;
				if ( isset( $seen_booking_orders[ $id ] ) ) {
					continue;
				}
				$order = wc_get_order( $id );
				if ( $order && in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing', 'completed', 'refunded' ), true ) &&
					(int) $order->get_meta( '_listing_id' ) === (int) $booking->listing_id &&
					(int) $order->get_customer_id() === $user_id ) {
					$created                    = $order->get_date_created();
					$rows[]                     = (object) array(
						'id'     => (int) $id,
						'source' => 'booking_wc',
						'date'   => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
					);
					$seen_booking_orders[ $id ] = true;
				}
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b->date, $a->date );
			}
		);
		$total = count( $rows );
		if ( $total ) {
			$page   = min( $page, (int) ceil( $total / self::PAGE_SIZE ) );
			$offset = ( $page - 1 ) * self::PAGE_SIZE;
		}
		$invoices = array();
		foreach ( array_slice( $rows, $offset, self::PAGE_SIZE ) as $row ) {
			$invoice = self::get_invoice( $row->source, (int) $row->id );
			if ( $invoice && self::may_download( $invoice ) ) {
				$invoices[] = $invoice;
			}
		}

		ob_start();
		$heading = __( 'Your invoices', 'directorist-invoice' );
		echo '<div class="directorist-invoices" style="padding:20px 0"><h2>' . esc_html( $heading ) . '</h2>';
		if ( ! $total ) {
			echo '<p>' . esc_html__( 'No orders with invoices yet.', 'directorist-invoice' ) . '</p></div>';
			$render_cache[ $cache_key ] = ob_get_clean();
			return $render_cache[ $cache_key ];
		}
		echo '<div style="overflow-x:auto"><table class="widefat striped" style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #e4e7ec"><thead><tr style="background:#f3f7f8">';
		foreach ( array( __( 'Invoice', 'directorist-invoice' ), __( 'Date', 'directorist-invoice' ), __( 'Purchase', 'directorist-invoice' ), __( 'Total', 'directorist-invoice' ), __( 'Status', 'directorist-invoice' ), __( 'Actions', 'directorist-invoice' ) ) as $label ) {
			echo '<th style="padding:12px;text-align:left">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $invoices as $invoice ) {
			echo '<tr>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec">' . esc_html( $invoice['number'] ) . '</td>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec">' . esc_html( self::format_date( $invoice['date'] ) ) . '</td>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec">' . esc_html( $invoice['title'] ) . '</td>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec">' . esc_html( self::money( $invoice['total'], $invoice['currency'] ) ) . '</td>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec">' . esc_html( self::status_label( $invoice['status'] ) ) . '</td>';
			echo '<td style="padding:12px;border-top:1px solid #e4e7ec;white-space:nowrap"><span class="directorist-invoice-action-pair">';
			echo self::invoice_action_link( $invoice['source'], $invoice['id'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes URL and label attributes.
			echo self::invoice_action_link( $invoice['source'], $invoice['id'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes URL and label attributes.
			echo '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		if ( $total > self::PAGE_SIZE ) {
			$base = remove_query_arg( $page_param );
			$hash = '#dashboard_invoices';
			echo '<p style="margin-top:16px">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( add_query_arg( $page_param, $page - 1, $base ) . $hash ) . '">' . esc_html__( 'Previous', 'directorist-invoice' ) . '</a> &nbsp; ';
			}
				/* translators: 1: current page number, 2: total number of pages. */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'directorist-invoice' ), $page, (int) ceil( $total / self::PAGE_SIZE ) ) );
			if ( $page * self::PAGE_SIZE < $total ) {
				echo ' &nbsp; <a href="' . esc_url( add_query_arg( $page_param, $page + 1, $base ) . $hash ) . '">' . esc_html__( 'Next', 'directorist-invoice' ) . '</a>';
			}
			echo '</p>';
		}
		echo '</div>';
		$render_cache[ $cache_key ] = ob_get_clean();
		return $render_cache[ $cache_key ];
	}

	/**
	 * Format an invoice date for display.
	 *
	 * @param string $date Stored date.
	 * @return string
	 */
	public static function format_date( string $date ): string {
		try {
			return ( new DateTimeImmutable( $date, wp_timezone() ) )->format( get_option( 'date_format' ) );
		} catch ( Exception $exception ) {
			return $date;
		}
	}

	/**
	 * Format a currency amount.
	 *
	 * @param float  $amount   Monetary amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, string $currency ): string {
		return strtoupper( $currency ) . ' ' . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Get a readable order status label.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		if ( 'created' === $status ) {
			return __( 'Unpaid', 'directorist-invoice' );
		}
		if ( 'on-hold' === $status ) {
			return __( 'On hold', 'directorist-invoice' );
		}
		return ucwords( str_replace( '-', ' ', $status ) );
	}

	/**
	 * Render the invoice HTML template.
	 *
	 * @param array $invoice Normalized invoice data used by the template.
	 * @return string
	 */
	private static function invoice_html( array $invoice ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The included template consumes $invoice.
		$business        = self::business_details();
		$customer_fields = array(
			'email'  => self::show_customer_field( 'email' ),
			'phone'  => self::show_customer_field( 'phone' ),
			'tax_id' => self::show_customer_field( 'tax_id' ),
		);
		ob_start();
		include DIRECTORIST_INVOICE_PATH . 'templates/invoice.php';
		return ob_get_clean();
	}

	/**
	 * Check whether a customer detail is enabled for PDF invoices.
	 *
	 * @param string $field Customer field suffix.
	 * @return bool
	 */
	private static function show_customer_field( string $field ): bool {
		$value = get_directorist_option( 'invoice_show_customer_' . $field, true );
		return is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Load business details shown on invoices.
	 *
	 * @return array
	 */
	private static function business_details(): array {
		$saved = get_option( 'directorist_invoice_business', array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( ! array_key_exists( 'logo', $saved ) ) {
			$site_logo_id  = (int) get_theme_mod( 'custom_logo' );
			$saved['logo'] = $site_logo_id ? (string) wp_get_attachment_url( $site_logo_id ) : '';
		}
		$business = array();
		foreach ( array( 'name', 'logo', 'address', 'email', 'tax_id' ) as $key ) {
			$value            = get_directorist_option( 'invoice_business_' . $key, null );
			$business[ $key ] = null === $value ? ( $saved[ $key ] ?? '' ) : $value;
		}
		if ( ! $business['name'] ) {
			$business['name'] = get_bloginfo( 'name' );
		}
		if ( ! $business['email'] ) {
			$business['email'] = get_option( 'admin_email' );
		}
		$business = wp_parse_args(
			apply_filters( 'directorist_invoice_business', $business ),
			array(
				'name'    => '',
				'logo'    => '',
				'address' => '',
				'email'   => '',
				'tax_id'  => '',
			)
		);
		foreach ( array( 'name', 'address', 'email', 'tax_id' ) as $key ) {
			$business[ $key ] = is_scalar( $business[ $key ] ) ? (string) $business[ $key ] : '';
		}
		$business['logo_data_uri'] = self::logo_data_uri( $business['logo'] );
		return $business;
	}

	/**
	 * Read a validated local logo image for PDF embedding.
	 *
	 * @param string|int $logo Media attachment ID or URL.
	 * @return string
	 */
	private static function logo_data_uri( $logo ): string {
		if ( ! is_scalar( $logo ) || ! $logo ) {
			return '';
		}
		$logo          = (string) $logo;
		$attachment_id = ctype_digit( $logo ) ? (int) $logo : attachment_url_to_postid( $logo );
		$path          = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $path && ! $attachment_id ) {
			$uploads     = wp_get_upload_dir();
			$logo_url    = wp_parse_url( $logo );
			$upload_url  = wp_parse_url( $uploads['baseurl'] ?? '' );
			$upload_path = isset( $upload_url['path'] ) ? trailingslashit( $upload_url['path'] ) : '';
			if ( $upload_path && isset( $logo_url['path'], $logo_url['host'], $upload_url['host'] ) &&
				strtolower( $logo_url['host'] ) === strtolower( $upload_url['host'] ) &&
				0 === strpos( $logo_url['path'], $upload_path ) ) {
				$relative  = rawurldecode( substr( $logo_url['path'], strlen( $upload_path ) ) );
				$base      = realpath( $uploads['basedir'] ?? '' );
				$candidate = $base ? realpath( $base . '/' . $relative ) : false;
				if ( $candidate && 0 === strpos( $candidate, $base . DIRECTORY_SEPARATOR ) ) {
					$path = $candidate;
				}
			}
		}
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > 2 * MB_IN_BYTES ) {
			return '';
		}
		$image = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid media is ignored.
		$mime  = $image['mime'] ?? '';
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) {
			return '';
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads an already validated local media file.
		return false === $bytes ? '' : 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Embeds the local image in the PDF.
	}
}
