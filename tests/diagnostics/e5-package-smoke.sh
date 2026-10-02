#!/usr/bin/env bash
# Install a frozen tracked commit as a Composer dependency, then smoke its DB backend.
set -euo pipefail

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$repo"
[[ $# -eq 1 && $1 =~ ^[0-9a-f]{40}$ ]] || { printf 'Pass the full frozen implementation commit SHA.\n' >&2; exit 2; }
revision=$(git rev-parse --verify "$1^{commit}")
[[ "$revision" == "$1" && "$revision" == "$(git rev-parse HEAD)" ]] || { printf 'Checkout is not the frozen commit.\n' >&2; exit 2; }
git diff --quiet "$revision" -- tests/diagnostics/e5-package-smoke.sh tests/diagnostics/e5-package-smoke.php plugin.php composer.json || {
	printf 'Package smoke inputs differ from the frozen commit.\n' >&2; exit 2;
}

docker_bin=${E5_DOCKER:-docker}
composer_bin=${E5_COMPOSER:-$(command -v composer)}
command -v "$docker_bin" >/dev/null
command -v curl >/dev/null
command -v rg >/dev/null
command -v sha256sum >/dev/null
command -v timeout >/dev/null
test -f "$composer_bin"
out=$(mktemp -d /tmp/wp-lock-e5-package.XXXXXXXX)
mkdir -p "$out" "$out/logs" "$out/build" "$out/package" "$out/consumer" "$out/source" "$out/socket"
chmod 777 "$out/socket"
printf 'E5 package artifacts: %s\n' "$out"
printf '%s\n' "$revision" > "$out/revision.txt"
git status --short > "$out/git-before.txt"
suffix=$(printf '%s' "$out" | sha256sum)
suffix=${suffix%% *}
suffix=${suffix:0:12}
active_db=
active_php=
docker_short() { timeout --signal=TERM --kill-after=5s 60 "$docker_bin" "$@"; }
cleanup() {
	main_status=$?
	trap - EXIT
	cleanup_failed=0
	if [[ -n "$active_php" ]]; then
		if timeout --signal=TERM --kill-after=5s 30 "$docker_bin" rm -f "$active_php" > "$out/logs/php-stop.log" 2>&1 ||
			rg -Fq 'No such container' "$out/logs/php-stop.log"; then :
		else cleanup_failed=1; printf 'PHP container removal failed.\n' >> "$out/logs/cleanup-failures.txt"; fi
	fi
	if [[ -n "$active_db" ]]; then
		if timeout --signal=TERM --kill-after=5s 20 "$docker_bin" logs "$active_db" > "$out/logs/db-container.log" 2>&1; then :
		else cleanup_failed=1; printf 'DB log capture failed.\n' >> "$out/logs/cleanup-failures.txt"; fi
		if timeout --signal=TERM --kill-after=5s 60 "$docker_bin" stop "$active_db" > "$out/logs/db-stop.log" 2>&1; then :
		else cleanup_failed=1; printf 'DB stop failed.\n' >> "$out/logs/cleanup-failures.txt"; fi
	fi
	git status --short > "$out/git-after.txt" || :
	printf 'main_exit=%s cleanup_failed=%s\n' "$main_status" "$cleanup_failed" > "$out/logs/cleanup-status.txt"
	if [[ "$main_status" -eq 0 && "$cleanup_failed" -ne 0 ]]; then exit 1; fi
	exit "$main_status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

git archive --format=tar "$revision" > "$out/package.tar"
sha256sum "$out/package.tar" > "$out/package-tar.sha256"
tar -tf "$out/package.tar" > "$out/archive-files.txt"
if rg -n '(^|/)(vendor|wordpress|wordpress-develop|\.idea|\.vscode|\.aws|\.env)(/|$)|(^|/)(auth\.json|composer\.lock)$|\.(pem|key|sql)$' "$out/archive-files.txt"; then
	printf 'Frozen archive contains excluded local or dependency content.\n' >&2
	exit 2
fi
tar -xf "$out/package.tar" -C "$out/package"
test -f "$out/package/composer.json"
test -f "$out/package/tests/diagnostics/e5-package-smoke.php"
rg -Fq 'Version: 3.0.0' "$out/package/plugin.php"
(
	cd "$out/package"
	find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$out/package-files.sha256"
cp "$out/package/tests/diagnostics/e5-package-smoke.php" "$out/build/smoke.php"
cp "$composer_bin" "$out/build/composer"
sha256sum "$out/build/composer" > "$out/logs/composer-tool.sha256"
cat > "$out/build/Dockerfile" <<'DOCKERFILE'
FROM php:8.5.11-cli-bookworm
RUN docker-php-ext-install mysqli pcntl
COPY composer /usr/local/bin/composer
DOCKERFILE

docker_short version --format '{{.Server.Version}}' > "$out/logs/docker-server-version.txt"
timeout --signal=TERM --kill-after=15s 600 "$docker_bin" pull php:8.5.11-cli-bookworm > "$out/logs/php-pull.log" 2>&1
docker_short image inspect php:8.5.11-cli-bookworm --format '{{json .RepoDigests}}' > "$out/logs/php-digest.txt"
rg -Fq 'sha256:' "$out/logs/php-digest.txt"
php_image="wp-lock-e5-package-$suffix:8.5.11"
timeout --signal=TERM --kill-after=15s 900 "$docker_bin" build -t "$php_image" "$out/build" > "$out/logs/php-build.log" 2>&1
docker_short image inspect "$php_image" --format '{{.Id}}' > "$out/logs/php-image-id.txt"
active_php="wp-lock-e5-package-$suffix-preflight"
docker_short run --rm --name "$active_php" --network none "$php_image" php -r '
foreach (["mysqli", "pcntl", "posix"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Missing $extension\n"); exit(2); }
}
foreach (["proc_open", "pcntl_waitpid", "posix_kill"] as $function) {
    if (!function_exists($function)) { fwrite(STDERR, "Missing $function\n"); exit(2); }
}
if (PHP_VERSION !== "8.5.11") exit(2);
' > "$out/logs/php-preflight.txt"
active_php=
active_php="wp-lock-e5-package-$suffix-php-version"
docker_short run --rm --name "$active_php" --network none "$php_image" php -v > "$out/logs/php-runtime.txt"
active_php=
active_php="wp-lock-e5-package-$suffix-composer-version"
docker_short run --rm --name "$active_php" --network none "$php_image" php /usr/local/bin/composer --version --no-ansi > "$out/logs/composer-runtime.txt"
active_php=

cat > "$out/consumer/composer.json" <<'COMPOSER'
{
  "name": "wp-lock/disposable-consumer",
  "repositories": [{"type": "path", "url": "/package", "options": {"symlink": false}}],
  "require": {"hokoo/wp-lock": "*"},
  "minimum-stability": "dev",
  "prefer-stable": true
}
COMPOSER
active_php="wp-lock-e5-package-$suffix-install"
timeout --signal=TERM --kill-after=15s 300 "$docker_bin" run --rm --name "$active_php" --network none \
	--mount "type=bind,src=$out/package,dst=/package,readonly" \
	--mount "type=bind,src=$out/consumer,dst=/consumer" --workdir /consumer \
	-e COMPOSER_ALLOW_SUPERUSER=1 "$php_image" php /usr/local/bin/composer install \
	--no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins \
	> "$out/logs/composer-install.stdout" 2> "$out/logs/composer-install.stderr"
active_php=
test -d "$out/consumer/vendor/hokoo/wp-lock"
test ! -L "$out/consumer/vendor/hokoo/wp-lock"
test -f "$out/consumer/vendor/autoload.php"
test -f "$out/consumer/composer.lock"
(
	cd "$out/consumer/vendor/hokoo/wp-lock"
	find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "$out/installed-files.sha256"
cmp "$out/package-files.sha256" "$out/installed-files.sha256"
cp "$out/consumer/composer.lock" "$out/composer.lock"

timeout --signal=TERM --kill-after=15s 300 curl --fail --location --retry 3 --connect-timeout 20 --max-time 280 \
	'https://wordpress.org/wordpress-7.1.2.tar.gz' -o "$out/source/wordpress-7.1.2.tar.gz"
sha256sum "$out/source/wordpress-7.1.2.tar.gz" > "$out/source/wordpress-sha256.txt"
tar -xzf "$out/source/wordpress-7.1.2.tar.gz" -C "$out/source"
test -f "$out/source/wordpress/wp-includes/class-wpdb.php"

timeout --signal=TERM --kill-after=15s 600 "$docker_bin" pull mysql:9.7.2 > "$out/logs/db-pull.log" 2>&1
docker_short image inspect mysql:9.7.2 --format '{{json .RepoDigests}}' > "$out/logs/db-digest.txt"
rg -Fq 'sha256:' "$out/logs/db-digest.txt"
active_db="wp-lock-e5-package-$suffix-db"
docker_short run --rm -d --name "$active_db" --network none \
	--mount "type=bind,src=$out/socket,dst=/dbsocket" --tmpfs /var/lib/mysql \
	-e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wp_lock_e5_package \
	mysql:9.7.2 --socket=/dbsocket/mysql.sock --skip-networking --mysqlx=0 > "$out/logs/db-container-id.txt"
ready=0
for attempt in $(seq 1 90); do
	if timeout --signal=TERM --kill-after=3s 10 "$docker_bin" exec "$active_db" mysql --socket=/dbsocket/mysql.sock \
		--batch --skip-column-names wp_lock_e5_package -e 'SELECT DATABASE()' 2>/dev/null | rg -Fxq wp_lock_e5_package; then ready=1; break; fi
	sleep 1
done
[[ "$ready" -eq 1 ]] || { printf 'Disposable database did not become ready.\n' >&2; exit 2; }
docker_short inspect "$active_db" --format '{{.HostConfig.NetworkMode}} {{json .HostConfig.Tmpfs}}' > "$out/logs/db-isolation.txt"
rg -q '^none ' "$out/logs/db-isolation.txt"
rg -Fq '/var/lib/mysql' "$out/logs/db-isolation.txt"
docker_short exec "$active_db" mysql --socket=/dbsocket/mysql.sock --batch --skip-column-names wp_lock_e5_package \
	-e 'SELECT DATABASE(), VERSION(), @@default_storage_engine, @@read_only' > "$out/logs/db-runtime.txt"
rg -Fq 'InnoDB' "$out/logs/db-runtime.txt"
rg -Fq '9.7.2' "$out/logs/db-runtime.txt"

for isolation in 'REPEATABLE READ' 'READ COMMITTED'; do
	if [[ "$isolation" == 'REPEATABLE READ' ]]; then label=rr; else label=rc; fi
	mkdir -p "$out/$label"
	docker_short exec "$active_db" mysql --socket=/dbsocket/mysql.sock wp_lock_e5_package \
		-e "SET GLOBAL TRANSACTION ISOLATION LEVEL $isolation"
	active_php="wp-lock-e5-package-$suffix-$label"
	if timeout --signal=TERM --kill-after=15s 240 "$docker_bin" run --rm --name "$active_php" --network none \
		--mount "type=bind,src=$out/consumer,dst=/consumer,readonly" \
		--mount "type=bind,src=$out/source/wordpress,dst=/wp,readonly" \
		--mount "type=bind,src=$out/build,dst=/suite,readonly" \
		--mount "type=bind,src=$out/socket,dst=/dbsocket" \
		-e WP_LOCK_PACKAGE_DISPOSABLE=wp_lock_e5_package -e WP_LOCK_PACKAGE_SOCKET=/dbsocket/mysql.sock \
		-e "WP_LOCK_PACKAGE_ISOLATION=$isolation" "$php_image" php /suite/smoke.php parent \
		> "$out/$label/smoke.jsonl" 2> "$out/$label/smoke.stderr"; then
		printf '0\n' > "$out/$label/smoke.exit"
	else
		status=$?
		printf '%s\n' "$status" > "$out/$label/smoke.exit"
		exit "$status"
	fi
	active_php=
	rg -Fq '"case":"shared_read"' "$out/$label/smoke.jsonl"
	rg -Fq '"case":"exclusive_write"' "$out/$label/smoke.jsonl"
	rg -Fq '"case":"summary","isolation":"' "$out/$label/smoke.jsonl"
	rg -Fq '"pass":true,"owners":0' "$out/$label/smoke.jsonl"
	printf '%s package smoke passed\n' "$isolation"
done
printf 'E5 package smoke passed on %s; artifacts: %s\n' "$revision" "$out"
