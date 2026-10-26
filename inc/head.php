<?php
/**
 * <head> output in the order of TECH-38: consent default (POL-40), theme pre-paint (UX-28),
 * icon tags (UX-54), then the GA4 loader (content/analytics.json).
 *
 * @package UpdateOtter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** POL-40 exact text. LiteSpeed must exclude it from minify, defer and combine (data-no-optimize). */
function updateotter_consent_default(): void {
	echo "<script data-no-optimize=\"1\" data-no-defer=\"1\" data-cfasync=\"false\">\n";
	echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
	echo "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',region:['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IS','IE','IT','LV','LI','LT','LU','MT','NL','NO','PL','PT','RO','SK','SI','ES','SE','GB','CH'],wait_for_update:500});\n";
	// UO-68: made for children, so ad storage stays denied everywhere (no personalised ads, no ad cookies).
	echo "gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'granted'});\n";
	echo "gtag('set','ads_data_redaction',true);\n";
	echo "</script>\n";
}

/** Sets data-theme and the js class before paint; `theme` is the only localStorage key on the site (UX-28). */
function updateotter_theme_script(): void {
	// The "js" class lets CSS reserve room for JS-only controls before they appear (UO-55, no layout shift).
	echo '<script data-no-optimize="1" data-no-defer="1">document.documentElement.classList.add("js");try{var t=localStorage.getItem("theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}</script>' . "\n";
}

add_action(
	'wp_head',
	static function (): void {
		updateotter_consent_default();
		updateotter_theme_script();
		echo '<meta name="color-scheme" content="light dark">' . "\n";
	},
	0
);

add_action(
	'wp_head',
	static function (): void {
		$h = esc_url( home_url( '/' ) );
		?>
<link rel="icon" href="<?php echo $h; ?>favicon.ico" sizes="48x48">
<link rel="icon" href="<?php echo $h; ?>favicon.svg" type="image/svg+xml">
<link rel="icon" href="<?php echo $h; ?>favicon-96x96.png" type="image/png" sizes="96x96">
<link rel="icon" href="<?php echo $h; ?>favicon-192x192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="<?php echo $h; ?>apple-touch-icon.png">
<link rel="manifest" href="<?php echo $h; ?>site.webmanifest">
<meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0E131D" media="(prefers-color-scheme: dark)">
		<?php
	},
	2
);

/** GA4 measurement ID: content/analytics.json first, then the old settings option. */
function updateotter_ga4_id(): string {
	$file = get_template_directory() . '/content/analytics.json';
	$json = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$id   = trim( (string) ( is_array( $json ) ? ( $json['ga4_id'] ?? '' ) : '' ) );
	$id   = '' !== $id ? $id : (string) get_option( 'updateotter_ga4_id', '' );
	return 1 === preg_match( '/^G-[A-Z0-9]{4,12}$/', $id ) ? $id : '';
}

/**
 * GA4 (Ali, 2026-10-26). Consent mode defaults above decide storage: ads denied everywhere,
 * analytics denied in the EEA/UK/CH (cookieless pings, no banner yet) and granted elsewhere.
 * Child-directed site: Google signals and ad personalisation are always off. gtag.js is added
 * after the load event so it never competes with the page's own resources (LCP/TBT).
 */
add_action(
	'wp_head',
	static function (): void {
		$id = updateotter_ga4_id();
		if ( '' === $id || is_user_logged_in() || is_preview() || updateotter_locked() ) {
			return;
		}
		?>
<script data-no-optimize="1" data-no-defer="1">(function(){var id=<?php echo wp_json_encode( $id ); ?>;gtag('js',new Date());gtag('config',id,{allow_google_signals:false,allow_ad_personalization_signals:false});function load(){var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+id;document.head.appendChild(s);}if(document.readyState==='complete')load();else window.addEventListener('load',load);})();</script>
		<?php
	},
	20
);

/**
 * Search engine ownership tags from content/site-verification.json (P1-46; no DNS or hPanel
 * change): {"google": "<content value>", "bing": "<content value>"}. Home page only.
 */
add_action(
	'wp_head',
	static function (): void {
		if ( ! is_front_page() ) {
			return;
		}
		$file = get_template_directory() . '/content/site-verification.json';
		$tags = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $tags ) ) {
			return;
		}
		foreach ( array( 'google' => 'google-site-verification', 'bing' => 'msvalidate.01' ) as $key => $name ) {
			if ( ! empty( $tags[ $key ] ) && is_string( $tags[ $key ] ) ) {
				echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $tags[ $key ] ) . '">' . "\n";
			}
		}
	},
	1
);
