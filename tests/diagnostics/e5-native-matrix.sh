#!/usr/bin/env bash
# Full WordPress/Composer suite on disposable exact-version RR and RC rows.
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$repo"
mode=${1:-all}
docker_bin=${E5_DOCKER:-docker}
composer_bin=${E5_COMPOSER:-$(command -v composer)}
out=${E5_NATIVE_OUT:-$(mktemp -d /tmp/wp-lock-e5-native.XXXXXXXX)}
docker_suffix=$(printf '%s' "$out" | sha256sum)
docker_suffix=${docker_suffix%% *}
docker_suffix=${docker_suffix:0:12}
mkdir -p "$out" "$out/logs" "$out/build" "$out/source" "$out/evidence"
printf 'E5 native artifacts: %s\n' "$out"
git rev-parse HEAD > "$out/revision.txt"
git status --short > "$out/git-before.txt"

rows=(
	'mysql267|mysql:26.7.0|mysql|8.5.11|7.1.2|candidate'
	'mysql97|mysql:9.7.2|mysql|8.5.11|7.1.2|lts'
	'maria130|mariadb:13.0.2|mariadb|8.5.11|7.1.2|candidate'
	'maria123|mariadb:12.3.3|mariadb|8.5.11|7.1.2|lts'
	'mysql97-floor|mysql:9.7.2|mysql|7.4.33|6.2.13|lts'
	'maria123-floor|mariadb:12.3.3|mariadb|7.4.33|6.2.13|lts'
)
selected=()
for row in "${rows[@]}"; do
	IFS='|' read -r label image client php_version wp_version track <<< "$row"
	if [[ "$mode" == all || "$mode" == "$track" || "$mode" == "$label" ]]; then selected+=("$row"); fi
done
[[ ${#selected[@]} -gt 0 ]] || { printf 'Unknown E5 native matrix selection: %s\n' "$mode" >&2; exit 2; }
printf '%s\n' "${selected[@]}" > "$out/rows.txt"

command -v "$docker_bin" >/dev/null
command -v curl >/dev/null
command -v svn >/dev/null
command -v timeout >/dev/null
test -f "$composer_bin"
active=
active_test=
cleanup() {
	main_status=$?
	trap - EXIT
	cleanup_failed=0
	if [[ -n "$active_test" ]]; then
		if timeout --signal=TERM --kill-after=5s 30 "$docker_bin" rm -f "$active_test" > "$out/logs/active-test-stop.log" 2>&1; then :
		else cleanup_code=$?; cleanup_failed=1; printf 'active-test-stop exit=%s\n' "$cleanup_code" >> "$out/logs/cleanup-failures.txt"; fi
	fi
	if [[ -n "$active" ]]; then
		if timeout --signal=TERM --kill-after=5s 20 "$docker_bin" logs "$active" > "$out/logs/active-container.log" 2>&1; then :
		else cleanup_code=$?; cleanup_failed=1; printf 'active-container-log exit=%s\n' "$cleanup_code" >> "$out/logs/cleanup-failures.txt"; fi
		if timeout --signal=TERM --kill-after=5s 60 "$docker_bin" stop "$active" > "$out/logs/active-stop.log" 2>&1; then :
		else cleanup_code=$?; cleanup_failed=1; printf 'active-stop exit=%s\n' "$cleanup_code" >> "$out/logs/cleanup-failures.txt"; fi
	fi
	printf 'main_exit=%s cleanup_failed=%s\n' "$main_status" "$cleanup_failed" > "$out/logs/cleanup-status.txt"
	git status --short > "$out/git-after.txt" || :
	mkdir -p "$out/evidence"
	for file in revision.txt rows.txt git-before.txt git-after.txt; do
		if [[ -f "$out/$file" ]]; then cp "$out/$file" "$out/evidence/$file"; fi
	done
	if [[ -d "$out/logs" ]]; then cp -a "$out/logs" "$out/evidence/logs"; fi
	for row in "${selected[@]}"; do
		IFS='|' read -r label image client php_version wp_version track <<< "$row"
		if [[ -d "$out/$label/logs" ]]; then mkdir -p "$out/evidence/$label"; cp -a "$out/$label/logs" "$out/evidence/$label/logs"; fi
		for suffix in rr rc; do
			if [[ -d "$out/$label/$suffix" ]]; then
				mkdir -p "$out/evidence/$label/$suffix"
				for file in runtime.json junit.xml composer-test.stdout composer-test.stderr composer-test.exit junit-gate.txt db-isolation.txt; do
					if [[ -f "$out/$label/$suffix/$file" ]]; then cp "$out/$label/$suffix/$file" "$out/evidence/$label/$suffix/$file"; fi
				done
			fi
		done
		for file in source-sha256.txt wp-tests-sha256.txt; do
			if [[ -f "$out/source/$wp_version/$file" ]]; then
				mkdir -p "$out/evidence/source/$wp_version"
				cp "$out/source/$wp_version/$file" "$out/evidence/source/$wp_version/$file"
			fi
		done
	done
	if [[ "$main_status" -eq 0 && "$cleanup_failed" -ne 0 ]]; then exit 1; fi
	exit "$main_status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
"$docker_bin" version --format '{{.Server.Version}}' > "$out/logs/docker-server-version.txt" 2>&1

cp "$composer_bin" "$out/build/composer"
sha256sum "$out/build/composer" > "$out/logs/composer-tool-sha256.txt"
cat > "$out/build/Dockerfile" <<'EOF'
ARG BASE
FROM ${BASE}
# Bullseye's post-EOL security index references removed packages; use signed main for this fixture.
RUN if grep -q '^VERSION_CODENAME=bullseye$' /etc/os-release; then \
        printf 'deb http://deb.debian.org/debian bullseye main\n' > /etc/apt/sources.list && \
        rm -f /etc/apt/sources.list.d/*.sources; \
    fi && \
    apt-get update && apt-get install -y --no-install-recommends unzip git && \
    docker-php-ext-install mysqli pcntl && \
    rm -rf /var/lib/apt/lists/*
COPY composer /usr/local/bin/composer
EOF

declare -A built=() downloaded=()
for row in "${selected[@]}"; do
	IFS='|' read -r label image client php_version wp_version track <<< "$row"
	if [[ ! -v built[$php_version] ]]; then
		if [[ "$php_version" == 8.5.11 ]]; then base=php:8.5.11-cli-bookworm; else base=php:7.4.33-cli; fi
		timeout --signal=TERM --kill-after=30s 600 "$docker_bin" pull "$base" > "$out/logs/php-$php_version-pull.log" 2>&1
		"$docker_bin" image inspect "$base" --format '{{json .RepoDigests}}' > "$out/logs/php-$php_version-digest.txt"
		grep -Fq 'sha256:' "$out/logs/php-$php_version-digest.txt"
		tag="wp-lock-e5-native-$docker_suffix:$php_version"
		timeout --signal=TERM --kill-after=30s 1200 "$docker_bin" build --build-arg "BASE=$base" -t "$tag" "$out/build" > "$out/logs/php-$php_version-build.log" 2>&1
		"$docker_bin" image inspect "$tag" --format '{{.Id}}' > "$out/logs/php-$php_version-built-id.txt"
		"$docker_bin" run --rm --network none "$tag" php -r '
foreach (["mysqli", "pcntl", "posix", "dom", "SimpleXML", "xmlwriter", "mbstring"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing required extension: $extension\n"); exit(2); }
}
foreach (["pcntl_fork", "pcntl_exec", "pcntl_waitpid", "posix_kill"] as $function) {
    if (!function_exists($function)) { fwrite(STDERR, "Missing required function: $function\n"); exit(2); }
}
' > "$out/logs/php-$php_version-preflight.txt"
		"$docker_bin" run --rm --network none "$tag" php -v > "$out/logs/php-$php_version-runtime.txt"
		"$docker_bin" run --rm --network none "$tag" php /usr/local/bin/composer --version --no-ansi > "$out/logs/composer-tool-$php_version.txt"
		grep -Fq "PHP $php_version" "$out/logs/php-$php_version-runtime.txt"
		project="$out/project-$php_version"
		mkdir -p "$project"
		git ls-files -z -- lib tests plugin.php composer.json phpunit.xml.dist > "$out/build/project-files.z"
		tar --null -T "$out/build/project-files.z" -cf - | tar -xf - -C "$project"
		(
			xargs -0 sha256sum < "$out/build/project-files.z"
		) > "$out/logs/checkout-$php_version-sha256.txt"
		(
			cd "$project"
			xargs -0 sha256sum < "$out/build/project-files.z"
		) > "$out/logs/copied-$php_version-sha256.txt"
		cmp "$out/logs/checkout-$php_version-sha256.txt" "$out/logs/copied-$php_version-sha256.txt"
		active_test="wp-lock-e5-install-$docker_suffix-$php_version"
		timeout --signal=TERM --kill-after=30s 900 "$docker_bin" run --rm --name "$active_test" --mount "type=bind,src=$project,dst=/project" --workdir /project \
			-e COMPOSER_ALLOW_SUPERUSER=1 "$tag" php /usr/local/bin/composer install --no-interaction --prefer-dist --no-progress \
			> "$out/logs/composer-$php_version.stdout" 2> "$out/logs/composer-$php_version.stderr"
		active_test=
		"$docker_bin" run --rm --network none --mount "type=bind,src=$project,dst=/project,readonly" --workdir /project \
			"$tag" php -r 'require "vendor/autoload.php"; $v = \Yoast\PHPUnitPolyfills\Autoload::VERSION; if (version_compare($v, "4.0.0", "<") || version_compare($v, "5.0.0", ">=")) exit(2); echo "polyfills=$v\n";' \
			> "$out/logs/dependency-$php_version.txt"
		(
			cd "$project"
			xargs -0 sha256sum < "$out/build/project-files.z"
			sha256sum composer.lock vendor/autoload.php
		) > "$out/logs/source-$php_version-sha256.txt"
		built[$php_version]=$tag
	fi
	if [[ ! -v downloaded[$wp_version] ]]; then
		mkdir -p "$out/source/$wp_version"
		curl --fail --location --retry 3 --connect-timeout 20 --max-time 300 \
			"https://wordpress.org/wordpress-$wp_version.tar.gz" -o "$out/source/$wp_version/wordpress.tar.gz"
		sha256sum "$out/source/$wp_version/wordpress.tar.gz" > "$out/source/$wp_version/source-sha256.txt"
		tar -xzf "$out/source/$wp_version/wordpress.tar.gz" -C "$out/source/$wp_version"
		timeout --signal=TERM --kill-after=15s 600 svn export --non-interactive -q \
			"https://develop.svn.wordpress.org/tags/$wp_version/tests/phpunit" "$out/source/$wp_version/wp-tests" \
			> "$out/logs/wp-tests-$wp_version.stdout" 2> "$out/logs/wp-tests-$wp_version.stderr"
		test -f "$out/source/$wp_version/wordpress/wp-includes/version.php"
		test -f "$out/source/$wp_version/wp-tests/includes/bootstrap.php"
		find "$out/source/$wp_version/wp-tests" -type f -print0 | sort -z | xargs -0 sha256sum > "$out/source/$wp_version/wp-tests-sha256.txt"
		downloaded[$wp_version]=1
	fi

	rowdir="$out/$label"
	mkdir -p "$rowdir/socket" "$rowdir/logs"
	chmod 777 "$rowdir/socket"
	timeout --signal=TERM --kill-after=30s 600 "$docker_bin" pull "$image" > "$rowdir/logs/db-pull.txt" 2>&1
	"$docker_bin" image inspect "$image" --format '{{json .RepoDigests}}' > "$rowdir/logs/db-digest.txt"
	grep -Fq 'sha256:' "$rowdir/logs/db-digest.txt"
	active="wp-lock-e5-native-$docker_suffix-$label"
	if [[ "$client" == mysql ]]; then
		"$docker_bin" run --rm -d --name "$active" --network none --mount "type=bind,src=$rowdir/socket,dst=/suite" --tmpfs /var/lib/mysql \
			-e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wp_lock_e5_native \
			"$image" --socket=/suite/mysql.sock --skip-networking --mysqlx=0 > "$rowdir/logs/db-container-id.txt"
	else
		"$docker_bin" run --rm -d --name "$active" --network none --mount "type=bind,src=$rowdir/socket,dst=/suite" --tmpfs /var/lib/mysql \
			-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_DATABASE=wp_lock_e5_native \
			"$image" --socket=/suite/mysql.sock --skip-networking > "$rowdir/logs/db-container-id.txt"
	fi
	ready=0
	for attempt in $(seq 1 90); do
		if "$docker_bin" exec "$active" "$client" --socket=/suite/mysql.sock --batch --skip-column-names wp_lock_e5_native -e 'SELECT DATABASE()' 2>/dev/null | grep -Fxq wp_lock_e5_native; then ready=1; break; fi
		sleep 1
	done
	[[ "$ready" == 1 ]] || { printf 'Database readiness failed: %s\n' "$label" >&2; exit 2; }
	"$docker_bin" inspect "$active" --format '{{.HostConfig.NetworkMode}} {{json .HostConfig.Tmpfs}} {{json .NetworkSettings.Ports}}' > "$rowdir/logs/db-isolation.txt"
	grep -q '^none ' "$rowdir/logs/db-isolation.txt"
	grep -Fq '/var/lib/mysql' "$rowdir/logs/db-isolation.txt"
	"$docker_bin" exec "$active" "$client" --socket=/suite/mysql.sock --batch --skip-column-names wp_lock_e5_native \
		-e 'SELECT DATABASE(), VERSION(), @@default_storage_engine, @@read_only' > "$rowdir/logs/db-runtime.txt"
	grep -Fq 'InnoDB' "$rowdir/logs/db-runtime.txt"
	grep -Fq "${image#*:}" "$rowdir/logs/db-runtime.txt"

	for isolation in 'REPEATABLE READ' 'READ COMMITTED'; do
		if [[ "$isolation" == 'REPEATABLE READ' ]]; then suffix=rr; else suffix=rc; fi
		case_dir="$rowdir/$suffix"
		mkdir -p "$case_dir/uploads"
		chmod 777 "$case_dir" "$case_dir/uploads"
		"$docker_bin" exec "$active" "$client" --socket=/suite/mysql.sock wp_lock_e5_native \
			-e "SET GLOBAL TRANSACTION ISOLATION LEVEL $isolation"
		cat > "$case_dir/wp-tests-config.php" <<'PHP'
<?php
define( 'ABSPATH', '/wp/' );
define( 'UPLOADS', '../suite/uploads' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', 'wp_lock_e5_native' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost:/suite/socket/mysql.sock' );
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
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP Lock disposable E5 native suite' );
define( 'WP_PHP_BINARY', '/usr/local/bin/php' );
define( 'WPLANG', '' );
PHP
		cat > "$case_dir/phpunit-bootstrap.php" <<'PHP'
<?php
define( 'WP_TESTS_CONFIG_FILE_PATH', '/suite/wp-tests-config.php' );
require '/project/tests/bootstrap.php';
PHP
		"$docker_bin" exec "$active" "$client" --socket=/suite/mysql.sock --batch --skip-column-names wp_lock_e5_native \
			-e "SHOW GLOBAL VARIABLES LIKE 'transaction_isolation'; SHOW GLOBAL VARIABLES LIKE 'tx_isolation'" > "$case_dir/db-isolation.txt"
		active_test="wp-lock-e5-suite-$docker_suffix-$label-$suffix"
		if timeout --signal=TERM --kill-after=30s 900 "$docker_bin" run --rm --name "$active_test" --network none \
			--mount "type=bind,src=$out/project-$php_version,dst=/project" \
			--mount "type=bind,src=$out/source/$wp_version/wordpress,dst=/wp,readonly" \
			--mount "type=bind,src=$out/source/$wp_version/wp-tests,dst=/wp-tests,readonly" \
			--mount "type=bind,src=$case_dir,dst=/suite" \
			--mount "type=bind,src=$rowdir/socket,dst=/suite/socket" \
			--workdir /project -e COMPOSER_ALLOW_SUPERUSER=1 -e WP_TESTS_DIR=/wp-tests \
			-e "WP_LOCK_EXPECTED_PHP_VERSION=$php_version" -e "WP_LOCK_EXPECTED_WORDPRESS_VERSION=$wp_version" \
			-e "WP_LOCK_EXPECTED_ISOLATION=$isolation" -e WP_LOCK_RUNTIME_RECORD=/suite/runtime.json \
			"${built[$php_version]}" php /usr/local/bin/composer test -- --bootstrap /suite/phpunit-bootstrap.php --log-junit /suite/junit.xml \
			> "$case_dir/composer-test.stdout" 2> "$case_dir/composer-test.stderr"; then
			printf '0\n' > "$case_dir/composer-test.exit"
		else
			status=$?
			printf '%s\n' "$status" > "$case_dir/composer-test.exit"
			exit "$status"
		fi
		active_test=
		test -s "$case_dir/runtime.json"
		"$docker_bin" run --rm --network none --mount "type=bind,src=$case_dir,dst=/suite,readonly" "${built[$php_version]}" php -r '
$report = simplexml_load_file("/suite/junit.xml");
if (!$report || count($report->xpath("//testcase")) === 0 || count($report->xpath("//testcase/skipped")) !== 0) {
    fwrite(STDERR, "Missing or skipped native tests.\n"); exit(1);
}
printf("tests=%d skipped=0\n", count($report->xpath("//testcase")));
' > "$case_dir/junit-gate.txt"
		printf '%s %s passed\n' "$label" "$isolation"
	done
	"$docker_bin" logs "$active" > "$rowdir/logs/db-container.log" 2>&1
	timeout --signal=TERM --kill-after=15s 60 "$docker_bin" stop "$active" > "$rowdir/logs/db-stop.txt" 2>&1
	active=
done
printf 'E5 native matrix passed: %s; artifacts: %s\n' "$mode" "$out"
