#!/usr/bin/env bash
# Standard WordPress test-suite installer — same one scaffolded by `wp scaffold plugin-tests`.
# Installs WP core + the PHPUnit test library into /tmp by default.
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-127.0.0.1}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

if [ "$WP_VERSION" = "latest" ]; then
	ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
else
	ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
fi

curl -sL "$ARCHIVE_URL" | tar --strip-components=1 -xz -C "$WP_CORE_DIR"

svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn co --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/"     "$WP_TESTS_DIR/data"

curl -sL "https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php" -o "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourusernamehere/$DB_USER/"        "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s/yourpasswordhere/$DB_PASS/"        "$WP_TESTS_DIR/wp-tests-config.php"
sed -i.bak "s|localhost|$DB_HOST|"               "$WP_TESTS_DIR/wp-tests-config.php"
rm "$WP_TESTS_DIR"/wp-tests-config.php.bak

mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp || true

echo "WP test library installed at $WP_TESTS_DIR"
