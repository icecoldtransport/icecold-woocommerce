<?php
/**
 * Nastavení pluginu ICECOLD Shipping.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_Settings {

	public const OPTION = 'icecold_shipping_settings';

	public const PROD_BASE = 'https://it.icecoldtrans.cz/api/v1';
	public const SANDBOX_BASE = 'https://it.icecoldtrans.cz/sandbox/v1';

	/**
	 * Výchozí hodnoty.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'token'          => '',
			'portal_email'   => '',
			'portal_company' => '',
			'sandbox'        => 'yes',
			'auto_create'    => 'yes',
			'trigger_status' => 'processing',
			'mark_completed' => 'yes',
			'webhook_secret' => '',
			'debug'          => 'no',
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), array_map( 'strval', $saved ) );
	}

	public static function get( string $key ): string {
		$all = self::all();

		return $all[ $key ] ?? '';
	}

	public static function is_sandbox(): bool {
		return 'yes' === self::get( 'sandbox' );
	}

	public static function base_url(): string {
		$base = self::is_sandbox() ? self::SANDBOX_BASE : self::PROD_BASE;

		/**
		 * Umožňuje nasměrovat plugin na jiné API (testovací prostředí, staging).
		 *
		 * @param string $base Výchozí adresa API v1.
		 */
		return (string) apply_filters( 'icecold_api_base_url', $base );
	}

	public static function token(): string {
		return trim( self::get( 'token' ) );
	}

	public static function is_configured(): bool {
		return '' !== self::token();
	}

	public static function webhook_url(): string {
		return rest_url( 'icecold/v1/webhook' );
	}

	/**
	 * Zápis protokolu do WC logu.
	 */
	public static function log( string $message, string $level = 'info' ): void {
		if ( 'yes' !== self::get( 'debug' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => 'icecold-shipping' ) );
	}
}
