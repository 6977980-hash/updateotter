<?php
/**
 * Manual ad slots (UX-38..40, TECH-43, POL-30). Nothing renders until the owner saves an AdSense
 * client ID and slot IDs AND ticks "approved" (L9: no empty boxes before approval).
 * Settings live under Appearance → UpdateOtter.
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const UPDATEOTTER_AD_SLOTS = array( 'below-tool', 'mid-content', 'end', 'sidebar' );

function updateotter_ads_on(): bool {
	return ! updateotter_locked()
		&& (bool) get_option( 'updateotter_ads_approved', 0 )
		&& 1 === preg_match( '/^ca-pub-\d{10,20}$/', (string) get_option( 'updateotter_adsense_client', '' ) )
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
	$ids = (array) get_option( 'updateotter_ad_slot_ids', array() );
	$id  = (string) ( $ids[ $slot ] ?? '' );
	if ( ! preg_match( '/^\d{6,12}$/', $id ) ) {
		return;
	}
	++$count;
	printf(
		'<div class="ad-slot ad-slot--%1$s"><span class="ad-slot__label">%2$s</span><ins class="adsbygoogle" style="display:block" data-ad-client="%3$s" data-ad-slot="%4$s" data-ad-format="auto" data-full-width-responsive="true" data-tag-for-age-treatment="1"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>',
		esc_attr( $slot ),
		esc_html__( 'Advertisement', 'updateotter' ),
		esc_attr( (string) get_option( 'updateotter_adsense_client' ) ),
		esc_attr( $id )
	);
}
add_action( 'updateotter_ad_slot', 'updateotter_ad_slot' );

add_action(
	'wp_head',
	static function (): void {
		if ( updateotter_ads_on() ) {
			printf( '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=%s" crossorigin="anonymous"></script>' . "\n", esc_attr( (string) get_option( 'updateotter_adsense_client' ) ) );
		}
	},
	30
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
