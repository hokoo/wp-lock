#!/usr/bin/env bash
# One isolated WordPress PHPUnit and coverage run. Retains logs in the printed /tmp directory.
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
docker_bin=${E4_DOCKER:-docker}
php_bin=${E4_PHP:-/usr/bin/php7.4}
polyfills_source=${E4_POLYFILLS_SOURCE:-$repo/vendor/yoast/phpunit-polyfills}
db_image=mariadb:10.11.10
db_name=wp_lock_e4_suite

command -v "$docker_bin" >/dev/null
test -x "$php_bin"
test -f "$repo/vendor/autoload.php"
test -f "$repo/wordpress-develop/src/wp-includes/version.php"
test -f "$repo/wordpress-develop/tests/phpunit/includes/bootstrap.php"
if [[ ! -f "$polyfills_source/phpunitpolyfills-autoload.php" ]]; then
	printf 'Compatible PHPUnit Polyfills fixture is missing: %s\n' "$polyfills_source" >&2
	exit 2
fi
"$php_bin" -r 'require $argv[1]; if (version_compare(\Yoast\PHPUnitPolyfills\Autoload::VERSION, "4.0.0", "<") || version_compare(\Yoast\PHPUnitPolyfills\Autoload::VERSION, "5.0.0", ">=")) { fwrite(STDERR, "PHPUnit Polyfills fixture must satisfy ^4.0.\n"); exit(2); }' "$polyfills_source/phpunitpolyfills-autoload.php"
"$php_bin" -r 'foreach (["mysqli", "pcntl", "posix", "SimpleXML"] as $name) { if (!extension_loaded($name)) { fwrite(STDERR, "Missing PHP extension: $name\n"); exit(1); } } if (!extension_loaded("xdebug") && !extension_loaded("pcov")) { fwrite(STDERR, "Coverage driver unavailable.\n"); exit(1); }'

run_dir=$(mktemp -d /tmp/wp-lock-e4-suite.XXXXXXXX)
chmod 755 "$run_dir"
mkdir -p "$run_dir/project" "$run_dir/logs" "$run_dir/socket" "$run_dir/bin"
chmod 777 "$run_dir/socket"
container="wp-lock-e4-suite-${run_dir##*.}"
active=0
cleanup() {
	if [[ "$active" == 1 ]]; then
		"$docker_bin" logs "$container" > "$run_dir/logs/db-container.log" 2>&1 || :
		"$docker_bin" stop "$container" > "$run_dir/logs/db-stop.txt" 2>&1 || :
	fi
	git -C "$repo" status --short > "$run_dir/logs/git-after.txt" || :
}
trap cleanup EXIT
printf 'E4 suite artifacts: %s\n' "$run_dir"
git -C "$repo" rev-parse HEAD > "$run_dir/logs/revision.txt"
git -C "$repo" status --short > "$run_dir/logs/git-before.txt"

cp -a "$repo/lib" "$repo/tests" "$repo/plugin.php" "$repo/composer.json" "$repo/phpunit.xml.dist" "$repo/vendor" "$run_dir/project/"
rm -rf "$run_dir/project/vendor/yoast/phpunit-polyfills"
cp -a "$polyfills_source" "$run_dir/project/vendor/yoast/phpunit-polyfills"
cp -a "$repo/wordpress-develop/src" "$run_dir/wp-src"
cp -a "$repo/wordpress-develop/tests/phpunit" "$run_dir/wp-tests-lib"
ln -s "$php_bin" "$run_dir/bin/php"

(
	cd "$run_dir/project"
	find lib tests -type f -print0 | sort -z | xargs -0 sha256sum
	sha256sum plugin.php composer.json phpunit.xml.dist vendor/autoload.php
) > "$run_dir/logs/source-sha256.txt"
(
	cd "$repo"
	find lib tests -type f -print0 | sort -z | xargs -0 sha256sum
	sha256sum plugin.php composer.json phpunit.xml.dist vendor/autoload.php
) > "$run_dir/logs/source-sha256-checkout.txt"
cmp "$run_dir/logs/source-sha256.txt" "$run_dir/logs/source-sha256-checkout.txt"
{
	printf 'polyfills-source=%s\n' "$polyfills_source"
	sha256sum "$polyfills_source/phpunitpolyfills-autoload.php"
	"$php_bin" -r '$installed = require $argv[1]; echo "checkout-composer-metadata=", $installed["versions"]["yoast/phpunit-polyfills"]["pretty_version"], PHP_EOL;' "$repo/vendor/composer/installed.php"
	"$php_bin" -r 'require $argv[1]; $version = \Yoast\PHPUnitPolyfills\Autoload::VERSION; echo "fixture-loaded-version=", $version, PHP_EOL; if (version_compare($version, "4.0.0", "<") || version_compare($version, "5.0.0", ">=")) exit(2);' "$run_dir/project/vendor/autoload.php"
} > "$run_dir/logs/dependency-preflight.txt"
"$php_bin" -v > "$run_dir/logs/php-version.txt"
"$php_bin" -m > "$run_dir/logs/php-modules.txt"
"$php_bin" -r 'include $argv[1]; echo $wp_version, PHP_EOL;' "$run_dir/wp-src/wp-includes/version.php" > "$run_dir/logs/wordpress-version.txt"

cat > "$run_dir/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '$run_dir/wp-src/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '$db_name' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost:$run_dir/socket/mysql.sock' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'wp-lock-disposable-tests-only' );
define( 'SECURE_AUTH_KEY', 'wp-lock-disposable-tests-only' );
define( 'LOGGED_IN_KEY', 'wp-lock-disposable-tests-only' );
define( 'NONCE_KEY', 'wp-lock-disposable-tests-only' );
define( 'AUTH_SALT', 'wp-lock-disposable-tests-only' );
define( 'SECURE_AUTH_SALT', 'wp-lock-disposable-tests-only' );
define( 'LOGGED_IN_SALT', 'wp-lock-disposable-tests-only' );
define( 'NONCE_SALT', 'wp-lock-disposable-tests-only' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP Lock disposable E4 suite' );
define( 'WP_PHP_BINARY', '$run_dir/bin/php' );
define( 'WPLANG', '' );
PHP
chmod 600 "$run_dir/wp-tests-config.php"
cat > "$run_dir/phpunit-bootstrap.php" <<PHP
<?php
define( 'WP_TESTS_CONFIG_FILE_PATH', '$run_dir/wp-tests-config.php' );
require '$run_dir/project/tests/bootstrap.php';
PHP

"$docker_bin" image inspect "$db_image" --format '{{json .RepoDigests}}' > "$run_dir/logs/db-image-digest.txt"
grep -Fq 'sha256:' "$run_dir/logs/db-image-digest.txt"
"$docker_bin" run --pull=never --rm -d --name "$container" --network none \
	--mount "type=bind,src=$run_dir/socket,dst=/suite" --tmpfs /var/lib/mysql \
	-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_DATABASE="$db_name" \
	"$db_image" --socket=/suite/mysql.sock --skip-networking > "$run_dir/logs/db-container-id.txt"
active=1

ready=0
for attempt in $(seq 1 90); do
	if "$docker_bin" exec "$container" mariadb --socket=/suite/mysql.sock --batch --skip-column-names "$db_name" -e 'SELECT DATABASE()' 2>/dev/null | grep -Fxq "$db_name"; then
		ready=1
		break
	fi
	sleep 1
done
if [[ "$ready" != 1 ]]; then
	printf 'Disposable database did not become ready.\n' >&2
	exit 2
fi
"$docker_bin" inspect "$container" --format '{{.HostConfig.NetworkMode}} {{json .HostConfig.Tmpfs}} {{json .NetworkSettings.Ports}}' > "$run_dir/logs/db-isolation.txt"
grep -q '^none ' "$run_dir/logs/db-isolation.txt"
grep -Fq '/var/lib/mysql' "$run_dir/logs/db-isolation.txt"
"$docker_bin" exec "$container" mariadb --socket=/suite/mysql.sock --batch --skip-column-names "$db_name" \
	-e 'SELECT DATABASE(), VERSION(), @@default_storage_engine, @@tx_isolation, @@autocommit' > "$run_dir/logs/db-runtime.txt"
grep -q '^wp_lock_e4_suite' "$run_dir/logs/db-runtime.txt"
grep -Fq 'InnoDB' "$run_dir/logs/db-runtime.txt"

export PATH="$run_dir/bin:$PATH"
export WP_TESTS_DIR="$run_dir/wp-tests-lib"
cd "$run_dir/project"
if composer test -- --bootstrap "$run_dir/phpunit-bootstrap.php" --log-junit "$run_dir/logs/composer-test.junit.xml" \
	> "$run_dir/logs/composer-test.stdout" 2> "$run_dir/logs/composer-test.stderr"; then
	printf '0\n' > "$run_dir/logs/composer-test.exit"
else
	status=$?
	printf '%s\n' "$status" > "$run_dir/logs/composer-test.exit"
	exit "$status"
fi
mkdir -p build/logs
if composer test:coverage -- --bootstrap "$run_dir/phpunit-bootstrap.php" --log-junit "$run_dir/logs/composer-coverage.junit.xml" \
	> "$run_dir/logs/composer-coverage.stdout" 2> "$run_dir/logs/composer-coverage.stderr"; then
	printf '0\n' > "$run_dir/logs/composer-coverage.exit"
else
	status=$?
	printf '%s\n' "$status" > "$run_dir/logs/composer-coverage.exit"
	exit "$status"
fi

if "$php_bin" -r '
foreach (array_slice($argv, 1, 2) as $file) {
	$report = simplexml_load_file($file);
	if (!$report || count($report->xpath("//testcase")) === 0 || count($report->xpath("//testcase/skipped")) !== 0) {
		fwrite(STDERR, "Missing tests or skipped tests in $file\n"); exit(1);
	}
}
$clover = simplexml_load_file($argv[3]);
if (!$clover) { exit(1); }
$metrics = $clover->project->metrics;
$total = (int) $metrics["statements"];
$covered = (int) $metrics["coveredstatements"];
if (0 === $total) { fwrite(STDERR, "No executable lines in Clover report.\n"); exit(1); }
$percentage = 100 * $covered / $total;
printf("Line coverage: %.2f%% (%d/%d)\n", $percentage, $covered, $total);
exit($percentage >= 90 ? 0 : 1);
' "$run_dir/logs/composer-test.junit.xml" "$run_dir/logs/composer-coverage.junit.xml" build/logs/clover.xml \
	> "$run_dir/logs/coverage-gate.stdout" 2> "$run_dir/logs/coverage-gate.stderr"; then
	printf '0\n' > "$run_dir/logs/coverage-gate.exit"
else
	status=$?
	printf '%s\n' "$status" > "$run_dir/logs/coverage-gate.exit"
	exit "$status"
fi
printf 'E4 suite and coverage passed; artifacts: %s\n' "$run_dir"
