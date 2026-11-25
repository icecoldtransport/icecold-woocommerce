<?php
/**
 * Dopravní metoda ICECOLD.
 *
 * @package ICECOLD_Shipping
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICECOLD_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'icecold';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'ICECOLD – chlazená / mražená doprava', 'icecold-shipping' );
		$this->method_description = __( 'Cena a časové okno se nastavují tady, API token v nastavení ICECOLD. Objednávka se zásilkou se zakládá podle nastavení pluginu.', 'icecold-shipping' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = (string) $this->get_option( 'title', __( 'ICECOLD – chlazená 1–5 °C', 'icecold-shipping' ) );
		$this->enabled = (string) $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title'       => array(
				'title'       => __( 'Název v košíku', 'icecold-shipping' ),
				'type'        => 'text',
				'default'     => __( 'ICECOLD – chlazená 1–5 °C', 'icecold-shipping' ),
				'desc_tip'    => true,
				'description' => __( 'Text, který uvidí zákazník při volbě dopravy.', 'icecold-shipping' ),
			),
			'temperature' => array(
				'title'   => __( 'Teplotní režim', 'icecold-shipping' ),
				'type'    => 'select',
				'default' => 'chilled',
				'options' => array(
					'chilled' => __( 'Chlazená 1–5 °C', 'icecold-shipping' ),
					'frozen'  => __( 'Mražená −18 °C', 'icecold-shipping' ),
				),
			),
			'window'      => array(
				'title'       => __( 'Časové okno', 'icecold-shipping' ),
				'type'        => 'text',
				'default'     => '08:00-12:00',
				'description' => __( 'Např. 08:00-12:00 nebo 12:00-16:00.', 'icecold-shipping' ),
			),
			'cost'        => array(
				'title'             => __( 'Cena dopravy (Kč)', 'icecold-shipping' ),
				'type'              => 'number',
				'default'           => '149',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			),
			'free_over'   => array(
				'title'       => __( 'Zdarma od (Kč)', 'icecold-shipping' ),
				'type'        => 'number',
				'default'     => '2500',
				'description' => __( '0 vypne dopravu zdarma.', 'icecold-shipping' ),
			),
		);
	}

	public function calculate_shipping( $package = array() ): void {
		if ( ! ICECOLD_Settings::is_configured() ) {
			return;
		}

		$total = (float) ( WC()->cart ? WC()->cart->get_subtotal() : 0 );
		$free  = (float) $this->get_option( 'free_over', '0' );
		$cost  = ( $free > 0 && $total >= $free ) ? 0 : (float) $this->get_option( 'cost', '0' );

		$this->add_rate(
			array(
				'id'    => $this->get_rate_id(),
				'label' => $this->title,
				'cost'  => $cost,
				'meta_data' => array(
					'icecold_temperature' => (string) $this->get_option( 'temperature', 'chilled' ),
					'icecold_window'      => (string) $this->get_option( 'window', '08:00-12:00' ),
				),
			)
		);
	}
}
