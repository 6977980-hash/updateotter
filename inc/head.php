<?php
/**
 * <head> output in the order of TECH-38: consent default (POL-40), theme pre-paint (UX-28),
 * icon tags (UX-54), then the consent-gated GA4 loader.
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
	echo "gtag('consent','default',{ad_storage:'granted',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'granted'});\n";
	echo "gtag('set','ads_data_redaction',true);\n";
	echo "</script>\n";
}

/** Sets data-theme before paint; `theme` is the only localStorage key on the site (UX-28). */
function updateotter_theme_script(): void {
	echo '<script data-no-optimize="1" data-no-defer="1">try{var t=localStorage.getItem("theme");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}</script>' . "\n";
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

/**
 * GA4 loads only after the CMP answers (POL-40, REV C-36): gdprApplies false, or purposes 1 and 8
 * consented. No CMP answer within 3 s means nothing loads. Off until a measurement ID is saved.
 */
add_action(
	'wp_head',
	static function (): void {
		$id = (string) get_option( 'updateotter_ga4_id', '' );
		if ( ! preg_match( '/^G-[A-Z0-9]{4,12}$/', $id ) || is_user_logged_in() ) {
			return;
		}
		?>
<script data-no-optimize="1">(function(){var id=<?php echo wp_json_encode( $id ); ?>,done=false,t0=Date.now();function load(){if(done)return;done=true;var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+id;document.head.appendChild(s);gtag('js',new Date());gtag('config',id);}function ok(d){if(!d)return false;if(d.gdprApplies===false)return true;var p=d.purpose&&d.purpose.consents;return !!(p&&p[1]&&p[8]);}function wait(){if(typeof window.__tcfapi==='function'){window.__tcfapi('addEventListener',2,function(d,s){if(s&&(d.eventStatus==='tcloaded'||d.eventStatus==='useractioncomplete')&&ok(d))load();});return;}if(Date.now()-t0<3000)setTimeout(wait,100);}wait();})();</script>
		<?php
	},
	20
);
