<?php
/**
 * Příjem webhooků ICECOLD.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_Webhook {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'icecold/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Ověří podpis, najde objednávku a uloží stav.
	 */
	public static function handle( WP_REST_Request $request ) {
		$secret = trim( ICECOLD_Settings::get( 'webhook_secret' ) );
		if ( '' === $secret ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => 'webhook_secret_missing',
					'message' => 'V nastavení ICECOLD chybí tajemství webhooku.',
				),
				503
			);
		}

		$raw       = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x-icecold-signature' );
		$timestamp = (string) $request->get_header( 'x-icecold-timestamp' );

		if ( '' === $raw || '' === $signature || '' === $timestamp ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'missing_signature' ), 401 );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $raw, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			ICECOLD_Settings::log( 'Webhook: neplatný podpis.', 'error' );

			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_signature' ), 401 );
		}

		$sent_at = is_numeric( $timestamp ) ? (int) $timestamp : (int) strtotime( $timestamp );
		if ( $sent_at > 0 && abs( time() - $sent_at ) > 300 ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'stale_timestamp' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_json' ), 400 );
		}

		$event_id = (string) ( $payload['id'] ?? '' );
		if ( '' !== $event_id ) {
			$key = 'icecold_evt_' . md5( $event_id );
			if ( get_transient( $key ) ) {
				return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
			}
			set_transient( $key, 1, DAY_IN_SECONDS );
		}

		$event = (string) ( $payload['event'] ?? '' );
		$data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		$order = self::find_order( (string) ( $data['number'] ?? '' ), (string) ( $data['reference'] ?? '' ) );
		if ( ! $order instanceof WC_Order ) {
			ICECOLD_Settings::log( 'Webhook: objednávka nenalezena pro ' . ( $data['reference'] ?? '?' ), 'warning' );

			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'order_not_found' ), 200 );
		}

		$status = (string) ( $data['status'] ?? '' );
		if ( '' === $status ) {
			$status = str_replace( array( 'shipment.', 'temperature.' ), '', $event );
		}

		$data['event']           = $event;
		$data['temperature_c']   = $data['temperature_c'] ?? null;

		ICECOLD_Order_Shipment::apply_webhook_status( $order, $status, $data );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function find_order( string $number, string $reference ): ?WC_Order {
		if ( '' !== $number ) {
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'type'       => 'shop_order',
					'meta_key'   => ICECOLD_Order_Shipment::META_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $number, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);

			if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
				return $orders[0];
			}
		}

		if ( '' !== $reference ) {
			$by_id = wc_get_order( $reference );
			if ( $by_id instanceof WC_Order && $number === (string) $by_id->get_meta( ICECOLD_Order_Shipment::META_NUMBER ) ) {
				return $by_id;
			}
		}

		return null;
	}
}
