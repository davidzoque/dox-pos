<?php
/**
 * El editor de fotos de la caja: el de WordPress con ImageMagick, con un paso previo de muestreo.
 *
 * Reducir una foto de golpe con filtro es lo caro de toda la subida. Un teléfono moderno saca
 * fotos de 24 o 48 megapíxeles, y ahí la reducción con filtro pasa de segundos a minutos: la
 * foto descifrada ocupa 8 bytes por píxel (372 MB una de 48 MP), y en cuanto no cabe en el techo
 * de memoria, ImageMagick trabaja contra disco.
 *
 * La salida es reducir en dos pasos: primero un tijeretazo por muestreo, que solo coge píxeles y
 * no calcula nada, hasta el doble del tamaño final; y después el afinado con filtro de siempre,
 * ya sobre una copia pequeña. El resultado se ve igual (el afinado sigue siendo el de WordPress)
 * y en una foto de 48 MP la conversión baja de 146 segundos a unos 4.
 *
 * Este editor solo se usa en la caja: se registra alrededor de la conversión y se quita después,
 * así que el resto de WordPress sigue con el suyo.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Image_Editor_Imagick' ) ) {
	require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
	require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
}

if ( class_exists( 'WP_Image_Editor_Imagick' ) && ! class_exists( 'Dox_POS_Image_Editor' ) ) {

	/**
	 * El editor de WordPress con el muestreo previo delante de cada reducción.
	 */
	class Dox_POS_Image_Editor extends WP_Image_Editor_Imagick {

		/**
		 * Reduce la foto. Igual que el de WordPress, con el tijeretazo previo.
		 *
		 * @param int|null $max_w Ancho máximo.
		 * @param int|null $max_h Alto máximo.
		 * @param bool     $crop  Si recorta.
		 * @return true|WP_Error
		 */
		public function resize( $max_w, $max_h, $crop = false ) {
			$this->dox_pos_presample( $max_w, $max_h );
			return parent::resize( $max_w, $max_h, $crop );
		}

		/**
		 * Lo mismo para los tamaños que WordPress genera después (miniatura, mediana...).
		 *
		 * @param array $sizes Los tamaños.
		 * @return array
		 */
		public function multi_resize( $sizes ) {
			$mayor = 0;
			foreach ( (array) $sizes as $s ) {
				$mayor = max( $mayor, (int) ( $s['width'] ?? 0 ), (int) ( $s['height'] ?? 0 ) );
			}
			if ( $mayor > 0 ) {
				$this->dox_pos_presample( $mayor, $mayor );
			}
			return parent::multi_resize( $sizes );
		}

		/**
		 * El tijeretazo: deja la foto en el doble del tamaño al que va a acabar, cogiendo píxeles
		 * sin calcular medias. No se hace si la foto ya es pequeña (el margen de 1,5 evita
		 * muestrear para nada) ni si no hay nada que reducir.
		 *
		 * @param int|null $max_w Ancho de destino.
		 * @param int|null $max_h Alto de destino.
		 */
		protected function dox_pos_presample( $max_w, $max_h ) {
			$destino = max( (int) $max_w, (int) $max_h );
			$size    = $this->get_size();
			if ( $destino < 1 || ! $this->image instanceof Imagick || ! is_array( $size ) ) {
				return;
			}
			$w    = (int) ( $size['width'] ?? 0 );
			$h    = (int) ( $size['height'] ?? 0 );
			$lado = max( $w, $h );
			if ( $lado < 1 ) {
				return;
			}
			$doble = $destino * 2;
			if ( $lado <= (int) round( $doble * 1.5 ) ) {
				return; // Ya es lo bastante pequeña: el afinado solo no cuesta nada.
			}
			$escala = $doble / $lado;
			try {
				$this->image->sampleImage( max( 1, (int) round( $w * $escala ) ), max( 1, (int) round( $h * $escala ) ) );
				$this->update_size();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Si el muestreo falla se sigue con la foto entera: lenta, pero sale.
			}
		}
	}
}
