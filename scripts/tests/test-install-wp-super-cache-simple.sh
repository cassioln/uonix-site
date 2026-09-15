#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="$ROOT_DIR/scripts/install-wp-super-cache-simple.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

make_fake_cli() {
  cat > "$TMP_DIR/php-bin" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
args=("$@")
[ "${args[0]}" = '-d' ]
[ "${args[1]}" = 'disable_functions=' ]
exec "${args[2]}" "${args[@]:3}"
SH
  chmod 700 "$TMP_DIR/php-bin"

  cat > "$TMP_DIR/wp-cli.php" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
log="${WPSC_TEST_LOG:?}"
state="${WPSC_TEST_STATE:?}"
printf '%s\n' "$*" >> "$log"
command="$*"
case "$command" in
  *'plugin status wp-super-cache --field=status'*)
    [ -f "$state/active" ] && printf 'active\n'
    ;;
  *'plugin install '*'--activate --force'*)
    mkdir -p "$state/root/wp-content/plugins/wp-super-cache" "$state/root/wp-content/cache"
    : > "$state/root/wp-content/plugins/wp-super-cache/wp-cache.php"
    : > "$state/root/wp-content/advanced-cache.php"
    : > "$state/root/wp-content/wp-cache-config.php"
    : > "$state/active"
    ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'eval-file '*) printf 'WPSC_SIMPLE_CONFIGURATION=PASS\n' ;;
  *) printf 'unexpected fake CLI command: %s\n' "$command" >&2; exit 91 ;;
esac
SH
  chmod 700 "$TMP_DIR/wp-cli.php"

  cat > "$TMP_DIR/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    http://*|https://*) url="$1"; shift ;;
    *) shift ;;
  esac
done
[ "$url" = 'https://downloads.wordpress.org/plugin/wp-super-cache.3.1.3.zip' ]
[ -n "$output" ]
cp /tmp/wp-super-cache.3.1.3.zip "$output"
SH
  chmod 700 "$TMP_DIR/curl"
}

make_fake_cli
state="$TMP_DIR/state"
mkdir -p "$state/root/wp-content"
: > "$TMP_DIR/configure.php"
export WPSC_TEST_LOG="$TMP_DIR/commands.log"
export WPSC_TEST_STATE="$state"
export PATH="$TMP_DIR:$PATH"

bash "$SCRIPT" \
  --wp-root="$state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli.php" \
  --config-script="$TMP_DIR/configure.php" \
  --source-url='https://downloads.wordpress.org/plugin/wp-super-cache.3.1.3.zip' \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  > "$TMP_DIR/output"

grep -qx 'WPSC_SIMPLE_INSTALL=PASS version=3.1.3 mode=PHP' "$TMP_DIR/output" || fail 'instalação não confirmou perfil Simple'
grep -F -- '--activate --force' "$TMP_DIR/commands.log" >/dev/null || fail 'plugin não foi ativado'
grep -F -- 'eval-file' "$TMP_DIR/commands.log" >/dev/null || fail 'configurador não foi executado'

# Falha fechada: plugin já presente não pode ser atualizado silenciosamente.
: > "$state/active"
if bash "$SCRIPT" \
  --wp-root="$state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli.php" \
  --config-script="$TMP_DIR/configure.php" \
  --source-url='https://downloads.wordpress.org/plugin/wp-super-cache.3.1.3.zip' \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  >/dev/null 2>&1; then
  fail 'plugin preexistente foi aceito'
fi

printf 'PASS: instalador WPSC valida fonte, recusa estado preexistente e aplica Simple.\n'
