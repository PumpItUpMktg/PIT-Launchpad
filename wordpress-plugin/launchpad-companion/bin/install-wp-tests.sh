#!/usr/bin/env bash
# Installs the WordPress test library + a test database so the WP_UnitTestCase
# suite can run (phpunit). Standard wp-cli scaffold layout.
#
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Then: WP_TESTS_DIR=/tmp/wordpress-tests-lib ./vendor/bin/phpunit
# Or simply use `wp-env` and `wp-env run tests-wordpress ...`.
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}

download() { curl -s "$1" > "$2"; }

if [ "$WP_VERSION" = "latest" ]; then
    WP_TAG=$(curl -s https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
else
    WP_TAG=$WP_VERSION
fi

# Test library
if [ ! -d "$WP_TESTS_DIR" ]; then
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
    svn co --quiet "https://develop.svn.wordpress.org/tags/${WP_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"
    download "https://develop.svn.wordpress.org/tags/${WP_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/; s/yourusernamehere/$DB_USER/; s/yourpasswordhere/$DB_PASS/; s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
fi

# Test database
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true

echo "WP test library installed at $WP_TESTS_DIR (WP ${WP_TAG})."
echo "Run: WP_TESTS_DIR=$WP_TESTS_DIR ./vendor/bin/phpunit"
