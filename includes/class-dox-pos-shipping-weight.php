<?php
/**
 * El envío por peso: un método de envío de WooCommerce con tramos ("hasta 1 lb, 6; hasta 5 lb, 10")
 * y lo que cuesta pasar del último. Vive en las zonas de la tienda, así que cobra lo mismo en el
 * checkout de la web y en la caja.
 *
 * Se arma desde la caja (el engranaje > Costos de envío); en WooCommerce > Ajustes > Envío sale con
 * los mismos datos, con los tramos escritos uno por línea.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Shipping_Method' ) && ! class_exists( 'Dox_POS_Shipping_Weight' ) ) {

	/**
	 * Envío por peso, con tramos.
	 */
	class Dox_POS_Shipping_Weight extends WC_Shipping_Method {

		/**
		 * ¿Lleva impuestos?
		 *
		 * @var string
		 */
		public $tax_status = 'taxable';

		/**
		 * Una instancia del método dentro de una zona.
		 *
		 * @param int $instance_id La instancia dentro de una zona.
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'dox_pos_weight';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Shipping by weight (Dox POS)', 'dox-pos' );
			$this->method_description = __( 'Charges by the total weight of the order, in ranges. It is easier to set up from the register: the gear at the top, then Shipping costs.', 'dox-pos' );
			$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
			$this->init();
		}

		/**
		 * Los campos y lo guardado.
		 */
		public function init() {
			$unit                       = dox_pos_weight_unit();
			$this->instance_form_fields = array(
				'title'      => array(
					'title'   => __( 'Name', 'dox-pos' ),
					'type'    => 'text',
					'default' => __( 'Shipping', 'dox-pos' ),
				),
				'tax_status' => array(
					'title'   => __( 'Tax status', 'dox-pos' ),
					'type'    => 'select',
					'class'   => 'wc-enhanced-select',
					'default' => 'taxable',
					'options' => array(
						'taxable' => __( 'Taxable', 'dox-pos' ),
						'none'    => __( 'None', 'dox-pos' ),
					),
				),
				'tiers'      => array(
					'title'       => __( 'Weight ranges', 'dox-pos' ),
					'type'        => 'textarea',
					'css'         => 'min-height:110px',
					'default'     => '',
					/* translators: %s: weight unit (kg, lb) */
					'description' => sprintf( __( 'One range per line: the weight it goes up to (%s), a bar and what it costs. For example: 1 | 6', 'dox-pos' ), $unit ),
					'desc_tip'    => false,
				),
				'over_cost'  => array(
					'title'       => __( 'Heavier than the last range', 'dox-pos' ),
					'type'        => 'price',
					'default'     => '',
					'description' => __( 'What an order heavier than the last range pays. Empty: the last range applies.', 'dox-pos' ),
					'desc_tip'    => true,
				),
				'over_extra' => array(
					/* translators: %s: weight unit (kg, lb) */
					'title'       => sprintf( __( 'Plus, for each extra %s', 'dox-pos' ), $unit ),
					'type'        => 'price',
					'default'     => '',
					'description' => __( 'Added for every unit of weight above the last range. Empty: nothing extra.', 'dox-pos' ),
					'desc_tip'    => true,
				),
			);
			$this->title                = $this->get_option( 'title' );
			$this->tax_status           = $this->get_option( 'tax_status' );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Los tramos guardados, de menor a mayor: [ [ 'up_to' => 1.0, 'cost' => 6.0 ], ... ].
		 *
		 * @return array
		 */
		public function get_tiers() {
			return dox_pos_parse_weight_tiers( (string) $this->get_option( 'tiers' ) );
		}

		/**
		 * Lo que paga un peso. Null si el método no tiene nada configurado.
		 *
		 * @param float $weight El peso del pedido, en la unidad de la tienda.
		 * @return float|null
		 */
		public function cost_for( $weight ) {
			$tiers = $this->get_tiers();
			$over  = (string) $this->get_option( 'over_cost' );
			$extra = (string) $this->get_option( 'over_extra' );
			$over  = '' === trim( $over ) ? null : max( 0.0, (float) wc_format_decimal( $over ) );
			$extra = '' === trim( $extra ) ? 0.0 : max( 0.0, (float) wc_format_decimal( $extra ) );
			if ( ! $tiers && null === $over ) {
				return null;
			}
			$last = 0.0;
			foreach ( $tiers as $t ) {
				if ( $weight <= $t['up_to'] + 0.00001 ) {
					return $t['cost'];
				}
				$last = $t['up_to'];
			}
			// Más pesado que el último tramo: su precio propio, o el del último tramo; más lo de cada unidad de más.
			$base = null !== $over ? $over : (float) $tiers[ count( $tiers ) - 1 ]['cost'];
			return $base + ( $extra > 0 ? ceil( max( 0, $weight - $last ) - 0.00001 ) * $extra : 0.0 );
		}

		/**
		 * Lo que cobra a un paquete: pesa sus productos y busca el tramo.
		 *
		 * @param array $package El paquete (destino y productos).
		 */
		public function calculate_shipping( $package = array() ) {
			$cost = $this->cost_for( dox_pos_package_weight( $package ) );
			if ( null === $cost ) {
				return;
			}
			$this->add_rate(
				array(
					'id'      => $this->get_rate_id(),
					'label'   => $this->title,
					'cost'    => $cost,
					'package' => $package,
				)
			);
		}
	}
}
