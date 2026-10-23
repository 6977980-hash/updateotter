#!/bin/sh
# Local WordPress + SQLite copy of the site for dry runs and page checks (no MySQL needed).
# Usage: sh tools/local-wp.sh [DIR]   (default DIR: /tmp/uo-wp)
# Then:  wp --allow-root --path=DIR rbx bundled --game=sae --dry-run
#        wp --allow-root --path=DIR rbx pages-sync
#        php -S localhost:8080 -t DIR   (site at http://localhost:8080)
set -eu
REPO=$(cd "$(dirname "$0")/.." && pwd)
DIR=${1:-/tmp/uo-wp}
WP="php ${DIR}.wp-cli.phar --allow-root --path=$DIR"

[ -f "${DIR}.wp-cli.phar" ] || curl -sSLo "${DIR}.wp-cli.phar" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
mkdir -p "$DIR"
if [ ! -f "$DIR/wp-config.php" ]; then
	$WP core download --quiet
	curl -sSLo /tmp/uo-sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -qo /tmp/uo-sqlite.zip -d "$DIR/wp-content/plugins/"
	sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$DIR/wp-content/plugins/sqlite-database-integration#;s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$DIR/wp-content/db.php"
	$WP config create --dbname=x --dbuser=x --dbpass=x --skip-check --quiet
	$WP core install --url=http://localhost:8080 --title=UpdateOtter --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
	# Asset URLs resolve through the symlinked theme; this keeps them loading under php -S.
	ln -s . "$DIR/$(basename "$DIR")" 2>/dev/null || true
fi
ln -sfn "$REPO" "$DIR/wp-content/themes/updateotter"
$WP theme activate updateotter
$WP rewrite structure '/%postname%/' --hard >/dev/null
$WP option update rbx_prelaunch_lock 0 >/dev/null
$WP rbx setup-pages
echo "Ready: $DIR (wp-cli: $WP)"
