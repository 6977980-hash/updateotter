<?php
/**
 * Manual ad slots (UX-38..40, TECH-43, POL-30), ads.txt (POL-28) and the AdSense site tag.
 *
 * Config lives in git: content/ads.json (Ali's rule: no wp-admin work). The Appearance → UpdateOtter
 * screen is only a fallback for keys the file leaves empty.
 * - publisher_id set: home page gets <meta name="google-adsense-account"> (site check during the
 *   application) and /ads.txt serves the Google line.
 * - approved true + slot IDs: the ad script loads and the slots render. Nothing renders before
 *   approval (L9: no empty boxes).
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const UPDATEOTTER_AD_SLOTS = array( 'below-tool', 'mid-content', 'end', 'sidebar' );

/**
 * Effective ad config: content/ads.json first, then the old options.
 *
 * @return array{client: string, approved: bool, slots: array<string, string>}
 */
function updateotter_ads_config(): array {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$file = get_template_directory() . '/content/ads.json';
	$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$json = is_array( $json ) ? $json : array();

	$pub    = trim( (string) ( $json['publisher_id'] ?? '' ) );
	$client = '' !== $pub ? 'ca-' . preg_replace( '/^ca-/', '', $pub ) : (string) get_option( 'updateotter_adsense_client', '' );
	if ( 1 !== preg_match( '/^ca-pub-\d{10,20}$/', $client ) ) {
		$client = '';
	}

	$slots = (array) get_option( 'updateotter_ad_slot_ids', array() );
	foreach ( (array) ( $json['slots'] ?? array() ) as $k => $v ) {
		if ( in_array( $k, UPDATEOTTER_AD_SLOTS, true ) && '' !== (string) $v ) {
			$slots[ $k ] = (string) $v;
		}
	}

	$cfg = array(
		'client'   => $client,
		'approved' => is_bool( $json['approved'] ?? null ) ? $json['approved'] : (bool) get_option( 'updateotter_ads_approved', 0 ),
		'slots'    => array_filter( array_map( 'strval', $slots ), static fn( $v ) => 1 === preg_match( '/^\d{6,12}$/', $v ) ),
	);
	return $cfg;
}

function updateotter_ads_on(): bool {
	$cfg = updateotter_ads_config();
	return ! updateotter_locked()
		&& $cfg['approved']
		&& '' !== $cfg['client']
		&& ! is_404()
		&& ! is_search()
		&& ! updateotter_is_site_page();
}

/**
 * @param string $slot One of UPDATEOTTER_AD_SLOTS.
 */
function updateotter_ad_slot( string $slot ): void {
	static $count = 0;
	if ( ! in_array( $slot, UPDATEOTTER_AD_SLOTS, true ) || ! updateotter_ads_on() || $count >= 3 ) {
		return;
	}
	$id = updateotter_ads_config()['slots'][ $slot ] ?? '';
	if ( '' === $id ) {
		return;
	}
	++$count;
	printf(
		'<div class="ad-slot ad-slot--%1$s"><span class="ad-slot__label">%2$s</span><ins class="adsbygoogle" style="display:block" data-ad-client="%3$s" data-ad-slot="%4$s" data-ad-format="auto" data-full-width-responsive="true" data-tag-for-age-treatment="1"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>',
		esc_attr( $slot ),
		esc_html__( 'Advertisement', 'updateotter' ),
		esc_attr( updateotter_ads_config()['client'] ),
		esc_attr( $id )
	);
}
add_action( 'updateotter_ad_slot', 'updateotter_ad_slot' );

add_action(
	'wp_head',
	static function (): void {
		if ( updateotter_ads_on() ) {
			printf( '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=%s" crossorigin="anonymous"></script>' . "\n", esc_attr( updateotter_ads_config()['client'] ) );
		}
	},
	30
);

/* [uo_ad slot="below-tool"] places a slot inside page content (calculator, hub). */
add_shortcode(
	'uo_ad',
	static function ( $atts ): string {
		$slot = (string) ( shortcode_atts( array( 'slot' => '' ), (array) $atts )['slot'] );
		ob_start();
		updateotter_ad_slot( $slot );
		return (string) ob_get_clean();
	}
);

/* AdSense site ownership tag, home page only (same place as the Search Console tag). */
add_action(
	'wp_head',
	static function (): void {
		$client = updateotter_ads_config()['client'];
		if ( '' !== $client && is_front_page() && ! updateotter_locked() ) {
			echo '<meta name="google-adsense-account" content="' . esc_attr( $client ) . '">' . "\n";
		}
	},
	1
);

/* /ads.txt (POL-28): text/plain, 200, from content/ads.json. 404 until a publisher ID is set. */
add_action(
	'parse_request',
	static function (): void {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) . '/ads.txt' !== $path ) {
			return;
		}
		$client = updateotter_ads_config()['client'];
		if ( '' === $client ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Content-Type-Options: nosniff' );
		echo 'google.com, ' . esc_html( substr( $client, 3 ) ) . ", DIRECT, f08c47fec0942fa0\n";
		exit;
	},
	0
);

/* Settings page: GA4 ID, AdSense client, slot IDs, approved flag. */
add_action(
	'admin_menu',
	static function (): void {
		add_theme_page( __( 'UpdateOtter settings', 'updateotter' ), __( 'UpdateOtter', 'updateotter' ), 'manage_options', 'updateotter', 'updateotter_settings_page' );
	}
);

add_action(
	'admin_init',
	static function (): void {
		register_setting(
			'updateotter',
			'updateotter_ga4_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => preg_match( '/^G-[A-Z0-9]{4,12}$/', strtoupper( trim( (string) $v ) ) ) ? strtoupper( trim( (string) $v ) ) : '',
			)
		);
		register_setting(
			'updateotter',
			'updateotter_adsense_client',
			array(
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ) => preg_match( '/^ca-pub-\d{10,20}$/', trim( (string) $v ) ) ? trim( (string) $v ) : '',
			)
		);
		register_setting(
			'updateotter',
			'updateotter_ad_slot_ids',
			array(
				'type'              => 'array',
				'sanitize_callback' => static function ( $v ): array {
					$out = array();
					foreach ( UPDATEOTTER_AD_SLOTS as $s ) {
						$x = preg_replace( '/\D/', '', (string) ( ( (array) $v )[ $s ] ?? '' ) );
						if ( '' !== $x ) {
							$out[ $s ] = $x;
						}
					}
					return $out;
				},
			)
		);
		register_setting(
			'updateotter',
			'updateotter_ads_approved',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ) => $v ? 1 : 0,
			)
		);
	}
);

function updateotter_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$ids = (array) get_option( 'updateotter_ad_slot_ids', array() );
	echo '<div class="wrap"><h1>' . esc_html__( 'UpdateOtter settings', 'updateotter' ) . '</h1><form method="post" action="options.php">';
	settings_fields( 'updateotter' );
	echo '<table class="form-table"><tr><th><label for="uo-ga4">' . esc_html__( 'GA4 measurement ID', 'updateotter' ) . '</label></th><td><input id="uo-ga4" name="updateotter_ga4_id" value="' . esc_attr( (string) get_option( 'updateotter_ga4_id', '' ) ) . '" placeholder="G-XXXXXXX"><p class="description">' . esc_html__( 'Loads only after the Google CMP reports consent (or that GDPR does not apply). Leave empty until Phase 1c.', 'updateotter' ) . '</p></td></tr>';
	echo '<tr><th><label for="uo-client">' . esc_html__( 'AdSense client ID', 'updateotter' ) . '</label></th><td><input id="uo-client" name="updateotter_adsense_client" value="' . esc_attr( (string) get_option( 'updateotter_adsense_client', '' ) ) . '" placeholder="ca-pub-0000000000000000"></td></tr>';
	foreach ( UPDATEOTTER_AD_SLOTS as $s ) {
		echo '<tr><th><label for="uo-slot-' . esc_attr( $s ) . '">' . esc_html( sprintf( /* translators: %s: slot name */ __( 'Slot ID: %s', 'updateotter' ), $s ) ) . '</label></th><td><input id="uo-slot-' . esc_attr( $s ) . '" name="updateotter_ad_slot_ids[' . esc_attr( $s ) . ']" value="' . esc_attr( (string) ( $ids[ $s ] ?? '' ) ) . '" inputmode="numeric"></td></tr>';
	}
	echo '<tr><th>' . esc_html__( 'AdSense approved', 'updateotter' ) . '</th><td><label><input type="checkbox" name="updateotter_ads_approved" value="1"' . checked( (bool) get_option( 'updateotter_ads_approved', 0 ), true, false ) . '> ' . esc_html__( 'Show ad slots (only after AdSense approval, Phase 1d)', 'updateotter' ) . '</label></td></tr></table>';
	submit_button();
	echo '</form></div>';
}
