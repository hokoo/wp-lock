#!/usr/bin/env bash
# Serial legacy E1 diagnostic on disposable, network-disabled database containers.
set -uo pipefail

repo_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$repo_dir" || exit 2
out=$(mktemp -d /tmp/wp-lock-d1.XXXXXXXX) || exit 2
printf 'D1 artifacts: %s\n' "$out"
mkdir -p "$out/build" "$out/source" "$out/logs"
docker_bin=${D1_DOCKER:-docker}
diagnostic=${D1_DIAGNOSTIC_SCRIPT:-tests/diagnostics/e1-baseline.php}
[[ "$diagnostic" == tests/diagnostics/e1-baseline.php || "$diagnostic" == tests/diagnostics/e3-foundations.php || "$diagnostic" == tests/diagnostics/e3-ownership.php ]] || { printf 'Unsupported diagnostic path\n' >&2; exit 2; }
d1_docker() { "$docker_bin" "$@"; }
active_name=
active_label=
cleanup() {
	if [[ -n "$active_name" ]]; then
		d1_docker logs "$active_name" > "$out/logs/$active_label-container.log" 2>&1 || :
		d1_docker stop "$active_name" > "$out/logs/$active_label-stop.log" 2>&1 || :
	fi
	git status --short > "$out/git-after.txt"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
die() { printf 'D1 setup/harness stop: %s; artifacts: %s\n' "$1" "$out" >&2; exit 2; }

git rev-parse HEAD > "$out/revision.txt"
git status --short > "$out/git-before.txt"
d1_docker info > "$out/logs/docker-info.txt" 2>&1 || die 'Docker daemon unavailable'
cat > "$out/build/Dockerfile" <<'EOF'
ARG BASE
FROM ${BASE}
RUN docker-php-ext-install mysqli pcntl
EOF
for spec in '8.5.11 php:8.5.11-cli-bookworm' '7.4.33 php:7.4.33-cli'; do
	read -r version base <<< "$spec"
	d1_docker pull "$base" > "$out/logs/php-$version-pull.log" 2>&1 || die "PHP pull $base"
	d1_docker image inspect "$base" --format '{{json .RepoDigests}}' > "$out/logs/php-$version-digest.txt" || die "PHP inspect $base"
	grep -Fq 'sha256:' "$out/logs/php-$version-digest.txt" || die "PHP digest $base"
	d1_docker build --network none --build-arg "BASE=$base" -f "$out/build/Dockerfile" -t "wp-lock-d1-php:$version" "$out/build" > "$out/logs/php-$version-build.log" 2>&1 || die "PHP build $version"
	d1_docker image inspect "wp-lock-d1-php:$version" --format '{{.Id}}' > "$out/logs/php-$version-built-id.txt" || die "PHP built image inspect $version"
	d1_docker run --rm --network none -e "D1_EXPECTED_PHP=$version" "wp-lock-d1-php:$version" php -r 'exit(PHP_VERSION === getenv("D1_EXPECTED_PHP") && extension_loaded("mysqli") && extension_loaded("pcntl") && extension_loaded("posix") ? 0 : 2);' || die "PHP extension/version preflight $version"
done

for version in 7.1.2 6.2.13; do
	curl -fL "https://wordpress.org/wordpress-$version.tar.gz" -o "$out/source/wordpress-$version.tar.gz" || die "WordPress download $version"
	sha256sum "$out/source/wordpress-$version.tar.gz" >> "$out/source-sha256.txt"
	mkdir -p "$out/source/$version"
	tar -xzf "$out/source/wordpress-$version.tar.gz" -C "$out/source/$version" || die "WordPress extract $version"
	test -f "$out/source/$version/wordpress/wp-includes/class-wpdb.php" || die "WordPress source $version"
done

rows=(
	'mysql267|mysql:26.7.0|mysql|8.5.11|7.1.2'
	'mysql97|mysql:9.7.2|mysql|8.5.11|7.1.2'
	'maria130|mariadb:13.0.2|mariadb|8.5.11|7.1.2'
	'maria123|mariadb:12.3.3|mariadb|8.5.11|7.1.2'
	'mysql97-floor|mysql:9.7.2|mysql|7.4.33|6.2.13'
	'maria123-floor|mariadb:12.3.3|mariadb|7.4.33|6.2.13'
)
for row in "${rows[@]}"; do
	IFS='|' read -r label image client php_version wp_version <<< "$row"
	printf '%s\n' "$row" >> "$out/rows.txt"
	wp_source="$out/source/$wp_version/wordpress"
	d1_docker pull "$image" > "$out/logs/$label-pull.log" 2>&1 || die "database pull $label"
	d1_docker image inspect "$image" --format '{{json .RepoDigests}}' > "$out/logs/$label-digest.txt" || die "database inspect $label"
	grep -Fq 'sha256:' "$out/logs/$label-digest.txt" || die "database digest $label"
	socket_dir="$out/$label-socket"
	mkdir -p "$socket_dir"
	chmod 777 "$socket_dir"
	active_label=$label
	active_name="wp-lock-d1-${out##*.}-$label"
	if [[ "$client" == mysql ]]; then
		d1_docker run --rm -d --name "$active_name" --network none --mount "type=bind,src=$socket_dir,dst=/e1" --tmpfs /var/lib/mysql \
			-e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wp_lock_e1 "$image" --socket=/e1/mysql.sock --skip-networking --mysqlx=0 > "$out/logs/$label-start.log" 2>&1 || die "database start $label"
	else
		d1_docker run --rm -d --name "$active_name" --network none --mount "type=bind,src=$socket_dir,dst=/e1" --tmpfs /var/lib/mysql \
			-e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=yes -e MARIADB_DATABASE=wp_lock_e1 "$image" --socket=/e1/mysql.sock --skip-networking > "$out/logs/$label-start.log" 2>&1 || die "database start $label"
	fi
	ready=1
	for ((attempt=0; attempt<90; attempt++)); do
		if d1_docker logs "$active_name" 2>&1 | grep -Fq 'init process done. Ready for start up.' &&
			d1_docker exec "$active_name" "$client" --socket=/e1/mysql.sock --batch --skip-column-names wp_lock_e1 -e 'SELECT DATABASE()' 2>/dev/null | grep -Fxq wp_lock_e1; then ready=0; break; fi
		sleep 1
	done
	[[ "$ready" -eq 0 ]] || die "database readiness $label"
	if d1_docker run --rm --network none --mount "type=bind,src=$repo_dir,dst=/repo,readonly" \
		--mount "type=bind,src=$wp_source,dst=/wp,readonly" --mount "type=bind,src=$socket_dir,dst=/e1" \
		--workdir /repo -e WP_LOCK_E1_DISPOSABLE=wp_lock_e1 -e WP_LOCK_E1_SOCKET=/e1/mysql.sock -e WP_LOCK_E1_WP=/wp \
		"wp-lock-d1-php:$php_version" php "$diagnostic" > "$out/logs/$label.jsonl" 2> "$out/logs/$label.stderr"; then
		status=0
	else
		status=$?
	fi
	printf '%s\n' "$status" > "$out/logs/$label.exit"
	d1_docker logs "$active_name" > "$out/logs/$label-container.log" 2>&1
	d1_docker stop "$active_name" > "$out/logs/$label-stop.log" 2>&1 || die "database stop $label"
	active_name=
	[[ "$status" -le 1 ]] || die "diagnostic bootstrap $label (exit $status)"
	grep -Fq '"case":"summary"' "$out/logs/$label.jsonl" || die "diagnostic missing summary $label"
	if [[ "${D1_REQUIRE_PASS:-0}" == 1 ]]; then
		[[ "$status" -eq 0 ]] && grep -Fq '"pass":true' "$out/logs/$label.jsonl" || die "diagnostic failure $label"
	fi
	grep -Fq '"engine":"InnoDB"' "$out/logs/$label.jsonl" || die "non-InnoDB $label"
	grep -Fq '"isolation":"REPEATABLE READ"' "$out/logs/$label.jsonl" || die "missing RR $label"
	grep -Fq '"isolation":"READ COMMITTED"' "$out/logs/$label.jsonl" || die "missing RC $label"
	grep -Fq "\"php\":\"$php_version\"" "$out/logs/$label.jsonl" || die "PHP version $label"
	grep -Fq "\"wordpress\":\"$wp_version\"" "$out/logs/$label.jsonl" || die "WordPress version $label"
	if [[ "$client" == mariadb ]]; then
		grep -Fq 'MariaDB' "$out/logs/$label.jsonl" || die "server family $label"
	fi
	printf '%s exit=%s\n' "$label" "$status"
done
printf 'D1 serial diagnostic finished; inspect legacy RED/GREEN and inconclusive outcomes in %s\n' "$out"
