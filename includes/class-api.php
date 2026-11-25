<?php
/**
 * Klient ICECOLD API v1.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_API {

	/**
	 * Obecný požadavek na API.
	 *
	 * @param string               $method HTTP metoda.
	 * @param string               $path   Cesta bez base URL, např. /shipments.
	 * @param array<string, mixed>|null $body Tělo požadavku.
	 * @param array<string, mixed> $args   Doplňky (headers, raw).
	 * @return array<string, mixed>|string|WP_Error
	 */
	public static function request( string $method, string $path, ?array $body = null, array $args = array() ) {
		$token = isset( $args['token'] ) ? trim( (string) $args['token'] ) : ( empty( $args['no_auth'] ) ? ICECOLD_Settings::token() : '' );
		if ( '' === $token && empty( $args['no_auth'] ) ) {
			return new WP_Error( 'icecold_no_token', __( 'Chybí přihlášení k ICECOLD. Přihlaste se portálovým účtem v nastavení ICECOLD.', 'icecold-shipping' ) );
		}

		$headers = array(
			'Accept'     => $args['accept'] ?? 'application/json',
			'User-Agent' => 'ICECOLD-WooCommerce/' . ICECOLD_SHIPPING_VERSION . '; ' . home_url(),
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if ( ! empty( $args['idempotency_key'] ) ) {
			$headers['Idempotency-Key'] = (string) $args['idempotency_key'];
		}

		$request = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$headers['Content-Type']  = 'application/json';
			$request['headers']       = $headers;
			$request['body']          = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$request['data_format']   = 'body';
		}

		$base     = isset( $args['base_url'] ) ? (string) $args['base_url'] : ICECOLD_Settings::base_url();
		$url      = $base . $path;
		$response = wp_remote_request( $url, $request );
		$attempt  = 0;

		// 429: respektujeme Retry-After, jedno opakování.
		while ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) && $attempt < 1 ) {
			$header = wp_remote_retrieve_header( $response, 'retry-after' );
			$wait   = is_scalar( $header ) ? (int) $header : 1;
			sleep( max( 1, min( 5, $wait ) ) );
			++$attempt;
			$response = wp_remote_request( $url, $request );
		}

		if ( is_wp_error( $response ) ) {
			ICECOLD_Settings::log( 'API ' . $method . ' ' . $path . ' selhalo: ' . $response->get_error_message(), 'error' );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			if ( ! empty( $args['raw'] ) ) {
				return $raw;
			}

			$data = json_decode( $raw, true );

			return is_array( $data ) ? $data : array();
		}

		$data    = json_decode( $raw, true );
		$type    = is_array( $data ) && isset( $data['error']['type'] ) ? (string) $data['error']['type'] : 'http_' . $code;
		$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
		$errors  = is_array( $data ) && isset( $data['error']['errors'] ) ? $data['error']['errors'] : array();

		ICECOLD_Settings::log( sprintf( 'API %s %s → %d %s %s', $method, $path, $code, $type, wp_json_encode( $errors ) ), 'error' );

		return new WP_Error(
			'icecold_' . $type,
			'' !== $message ? $message : sprintf(
				/* translators: %d: HTTP status */
				__( 'ICECOLD API vrátilo chybu HTTP %d.', 'icecold-shipping' ),
				$code
			),
			array(
				'status' => $code,
				'type'   => $type,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Založí zásilku.
	 *
	 * @param array<string, mixed> $payload Data zásilky.
	 * @param string               $key     Idempotency-Key (číslo objednávky).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_shipment( array $payload, string $key ) {
		return self::request( 'POST', '/shipments', $payload, array( 'idempotency_key' => $key ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_shipment( string $number ) {
		return self::request( 'GET', '/shipments/' . rawurlencode( $number ) );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_temperatures( string $number ) {
		return self::request( 'GET', '/shipments/' . rawurlencode( $number ) . '/temperatures' );
	}

	/**
	 * Tiskový štítek 100 × 150 mm (HTML stránka s tiskem 1:1).
	 *
	 * @return string|WP_Error
	 */
	public static function get_label( string $number ) {
		return self::request(
			'GET',
			'/shipments/' . rawurlencode( $number ) . '/label',
			null,
			array(
				'raw'    => true,
				'accept' => 'text/html',
			)
		);
	}

	/**
	 * Přihlášení stejným účtem, jaký se používá do klientského portálu.
	 * Heslo se do WordPressu neukládá, pouze vrácený token.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function login( string $email, string $password ) {
		return self::request(
			'POST',
			'/auth/login',
			array(
				'email'    => $email,
				'password' => $password,
			),
			array(
				'no_auth'  => true,
				/* Portálové přihlášení je vždy produkční; ve stagingu stačí vypnout sandbox. */
				'base_url' => ICECOLD_Settings::is_sandbox() ? ICECOLD_Settings::PROD_BASE : ICECOLD_Settings::base_url(),
			)
		);
	}

	/**
	 * Zruší token na serveru při odpojení e-shopu.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function logout() {
		if ( '' === ICECOLD_Settings::token() ) {
			return array( 'ok' => true );
		}

		return self::request( 'POST', '/auth/logout' );
	}

	/**
	 * Registrace webhooku pro tento e-shop.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function register_webhook() {
		$events = array(
			'shipment.loaded',
			'shipment.in_transit',
			'shipment.delivered',
			'shipment.delayed',
			'shipment.returned',
			'temperature.alert',
		);

		$result = self::request(
			'POST',
			'/webhooks',
			array(
				'url'    => ICECOLD_Settings::webhook_url(),
				'events' => $events,
			)
		);

		if ( ! is_wp_error( $result ) && ! empty( $result['secret'] ) ) {
			$settings                    = ICECOLD_Settings::all();
			$settings['webhook_secret']  = (string) $result['secret'];
			update_option( ICECOLD_Settings::OPTION, $settings );
		}

		return $result;
	}
}
