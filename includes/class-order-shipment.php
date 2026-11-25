<?php
/**
 * Napojení objednávek WooCommerce na zásilky ICECOLD.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_Order_Shipment {

	public const META_NUMBER    = '_icecold_number';
	public const META_STATUS    = '_icecold_status';
	public const META_TRACKING  = '_icecold_tracking_url';
	public const META_MODE      = '_icecold_mode';
	public const META_DATE      = '_icecold_date';
	public const META_WINDOW    = '_icecold_window';
	public const META_CREATED   = '_icecold_created_at';
	public const META_ERROR     = '_icecold_last_error';
	public const META_EVENTS    = '_icecold_events';

	public static function init(): void {
		$trigger = ICECOLD_Settings::get( 'trigger_status' );

		if ( 'yes' === ICECOLD_Settings::get( 'auto_create' ) && '' !== $trigger ) {
			add_action( 'woocommerce_order_status_' . $trigger, array( __CLASS__, 'maybe_create' ), 20, 1 );
		}
	}

	/**
	 * Automatické založení zásilky po změně stavu.
	 */
	public static function maybe_create( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_NUMBER ) ) {
			return;
		}

		if ( ! self::uses_icecold( $order ) ) {
			return;
		}

		self::create( $order );
	}

	/**
	 * Má objednávka zvolenou dopravu ICECOLD?
	 */
	public static function uses_icecold( WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'icecold' === $method->get_method_id() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Nastavení zvolené dopravní metody (teplotní režim, okno).
	 *
	 * @return array{temperature: string, window: string, instance_id: int}
	 */
	public static function method_settings( WC_Order $order ): array {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 'icecold' !== $method->get_method_id() ) {
				continue;
			}

			$instance_id = (int) $method->get_instance_id();
			$temperature = 'chilled';
			$window      = '08:00-12:00';

			if ( $instance_id > 0 && class_exists( 'WC_Shipping_Zones' ) ) {
				$zone_method = WC_Shipping_Zones::get_shipping_method( $instance_id );
				if ( $zone_method instanceof WC_Shipping_Method ) {
					$temperature = (string) $zone_method->get_option( 'temperature', 'chilled' );
					$window      = (string) $zone_method->get_option( 'window', '08:00-12:00' );
				}
			}

			return array(
				'temperature' => in_array( $temperature, array( 'chilled', 'frozen' ), true ) ? $temperature : 'chilled',
				'window'      => $window,
				'instance_id' => $instance_id,
			);
		}

		return array(
			'temperature' => 'chilled',
			'window'      => '08:00-12:00',
			'instance_id' => 0,
		);
	}

	/**
	 * Nejbližší možné datum doručení (vynechá neděli, po 14:00 posouvá o den).
	 */
	public static function default_delivery_date(): string {
		$timestamp = current_time( 'timestamp' );

		if ( (int) wp_date( 'G', $timestamp ) >= 14 ) {
			$timestamp = strtotime( '+1 day', $timestamp );
		}

		while ( '7' === wp_date( 'N', $timestamp ) ) {
			$timestamp = strtotime( '+1 day', $timestamp );
		}

		$date = wp_date( 'Y-m-d', $timestamp );

		return (string) apply_filters( 'icecold_delivery_date', $date, null );
	}

	/**
	 * Sestaví payload podle dokumentace API.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build_payload( WC_Order $order ) {
		$settings = self::method_settings( $order );

		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		$street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
		if ( '' === $street ) {
			$street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
		}

		$city = $order->get_shipping_city() ?: $order->get_billing_city();
		$zip  = $order->get_shipping_postcode() ?: $order->get_billing_postcode();

		$missing = array();
		foreach ( array( 'name' => $name, 'street' => $street, 'city' => $city, 'zip' => $zip ) as $field => $value ) {
			if ( '' === trim( (string) $value ) ) {
				$missing[] = $field;
			}
		}

		if ( array() !== $missing ) {
			return new WP_Error(
				'icecold_missing_address',
				sprintf(
					/* translators: %s: seznam chybějících polí */
					__( 'Objednávka nemá doručovací adresu (%s).', 'icecold-shipping' ),
					implode( ', ', $missing )
				)
			);
		}

		$pieces = 0;
		$weight = 0.0;
		foreach ( $order->get_items() as $item ) {
			$qty     = max( 1, (int) $item->get_quantity() );
			$pieces += $qty;

			$product = $item->get_product();
			if ( $product instanceof WC_Product && '' !== $product->get_weight() ) {
				$weight += (float) $product->get_weight() * $qty;
			}
		}

		if ( $weight <= 0 ) {
			$weight = (float) $order->get_weight();
		}

		$phone = method_exists( $order, 'get_shipping_phone' ) ? (string) $order->get_shipping_phone() : '';
		$phone = $phone !== '' ? $phone : (string) $order->get_billing_phone();

		$payload = array(
			'reference' => (string) $order->get_order_number(),
			'mode'      => $settings['temperature'],
			'date'      => self::default_delivery_date(),
			'window'    => $settings['window'],
			'pieces'    => max( 1, min( 99, $pieces ) ),
			'weight'    => round( max( 0.1, $weight ), 2 ),
			'delivery'  => array(
				'name'   => $name,
				'street' => $street,
				'city'   => (string) $city,
				'zip'    => (string) $zip,
				'phone'  => (string) $phone,
			),
		);

		if ( $order->get_customer_note() ) {
			$payload['note'] = wp_strip_all_tags( $order->get_customer_note() );
		}

		return (array) apply_filters( 'icecold_shipment_payload', $payload, $order );
	}

	/**
	 * Založí zásilku (nebo vrátí existující číslo).
	 *
	 * @return string|WP_Error Číslo zásilky.
	 */
	public static function create( WC_Order $order, bool $force = false ) {
		$existing = (string) $order->get_meta( self::META_NUMBER );
		if ( '' !== $existing && ! $force ) {
			return $existing;
		}

		if ( ! ICECOLD_Settings::is_configured() ) {
			return new WP_Error( 'icecold_no_token', __( 'Chybí API token v nastavení ICECOLD.', 'icecold-shipping' ) );
		}

		$payload = self::build_payload( $order );
		if ( is_wp_error( $payload ) ) {
			$order->update_meta_data( self::META_ERROR, $payload->get_error_message() );
			$order->save();

			return $payload;
		}

		$reference = (string) $order->get_order_number();
		$result    = ICECOLD_API::create_shipment( $payload, $reference );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( self::META_ERROR, $result->get_error_message() );
			$order->add_order_note(
				sprintf(
					/* translators: %s: chybová zpráva */
					__( 'ICECOLD: zásilku se nepodařilo založit — %s', 'icecold-shipping' ),
					$result->get_error_message()
				)
			);
			$order->save();

			return $result;
		}

		$number = (string) ( $result['number'] ?? '' );
		if ( '' === $number ) {
			return new WP_Error( 'icecold_no_number', __( 'API nevrátilo číslo zásilky.', 'icecold-shipping' ) );
		}

		$order->update_meta_data( self::META_NUMBER, $number );
		$order->update_meta_data( self::META_STATUS, (string) ( $result['status'] ?? 'planned' ) );
		$order->update_meta_data( self::META_MODE, (string) ( $result['mode'] ?? $payload['mode'] ) );
		$order->update_meta_data( self::META_DATE, (string) ( $result['date'] ?? $payload['date'] ) );
		$order->update_meta_data( self::META_WINDOW, (string) ( $result['window'] ?? $payload['window'] ) );
		$order->update_meta_data( self::META_TRACKING, (string) ( $result['tracking_url'] ?? '' ) );
		$order->update_meta_data( self::META_CREATED, current_time( 'mysql' ) );
		$order->delete_meta_data( self::META_ERROR );
		$order->add_order_note(
			sprintf(
				/* translators: 1: číslo zásilky, 2: teplotní režim, 3: datum */
				__( 'ICECOLD: zásilka %1$s (%2$s, doručení %3$s).', 'icecold-shipping' ),
				$number,
				'frozen' === $payload['mode'] ? 'mražená −18 °C' : 'chlazená 1–5 °C',
				$payload['date']
			)
		);
		$order->save();

		ICECOLD_Settings::log( 'Zásilka ' . $number . ' založena pro objednávku ' . $reference );

		return $number;
	}

	/**
	 * Načte aktuální stav zásilky z API a uloží ho do objednávky.
	 *
	 * @return string|WP_Error Stav zásilky.
	 */
	public static function sync( WC_Order $order ) {
		$number = (string) $order->get_meta( self::META_NUMBER );
		if ( '' === $number ) {
			return new WP_Error( 'icecold_no_shipment', __( 'Objednávka zatím nemá zásilku ICECOLD.', 'icecold-shipping' ) );
		}

		$result = ICECOLD_API::get_shipment( $number );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$status = (string) ( $result['status'] ?? '' );
		if ( '' !== $status ) {
			$order->update_meta_data( self::META_STATUS, $status );
			$order->update_meta_data( self::META_TRACKING, (string) ( $result['tracking_url'] ?? $order->get_meta( self::META_TRACKING ) ) );
			$order->save();
		}

		return $status;
	}

	/**
	 * Uloží stav z webhooku.
	 */
	public static function apply_webhook_status( WC_Order $order, string $status, array $data = array() ): void {
		$previous = (string) $order->get_meta( self::META_STATUS );

		$order->update_meta_data( self::META_STATUS, $status );
		$order->save();

		if ( $previous === $status ) {
			return;
		}

		$labels = array(
			'planned'    => 'naplánováno',
			'loaded'     => 'naloženo',
			'in_transit' => 'na trase',
			'delivered'  => 'doručeno',
			'delayed'    => 'zpožděno',
			'returned'   => 'vrací se',
		);

		$order->add_order_note(
			sprintf(
				/* translators: 1: číslo zásilky, 2: stav */
				__( 'ICECOLD: zásilka %1$s — %2$s.', 'icecold-shipping' ),
				(string) $order->get_meta( self::META_NUMBER ),
				$labels[ $status ] ?? $status
			)
		);

		if ( 'delivered' === $status && 'yes' === ICECOLD_Settings::get( 'mark_completed' ) && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'ICECOLD: zásilka doručena.', 'icecold-shipping' ) );
		}

		if ( 'temperature.alert' === ( $data['event'] ?? '' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: teplota */
					__( 'ICECOLD: teplotní odchylka u zásilky (poslední měření %s °C).', 'icecold-shipping' ),
					(string) ( $data['temperature_c'] ?? '?' )
				)
			);
		}
	}
}
