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
# A inserção das constantes usa PHP de verdade (`php -r`), não WP-CLI. As
# chamadas de WP-CLI continuam passando por `-d disable_functions=`.
if [ "${args[0]}" = '-r' ]; then
  exec php "$@"
fi
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
  *'plugin is-installed wp-super-cache'*)
    [ -f "$state/installed" ]
    ;;
  *'plugin is-active wp-super-cache'*)
    [ -f "$state/active" ]
    ;;
  *'plugin install '*'--activate --force'*)
    mkdir -p "$state/root/wp-content/plugins/wp-super-cache" "$state/root/wp-content/cache"
    : > "$state/root/wp-content/plugins/wp-super-cache/wp-cache.php"
    # O hook real do WPSC só grava os drop-ins quando AS DUAS constantes já
    # estão persistidas. Exigir ambas evita que uma ordem incorreta no
    # instalador passe silenciosamente.
    if grep -Fq "define( 'WP_CACHE', true );" "$state/root/wp-config.php" \
      && grep -Fq "define( 'WPCACHEHOME', '$state/root/wp-content/plugins/wp-super-cache/' );" "$state/root/wp-config.php"; then
      : > "$state/root/wp-content/advanced-cache.php"
      : > "$state/root/wp-content/wp-cache-config.php"
    fi
    : > "$state/installed"
    : > "$state/active"
    ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'config path'*) printf '%s\n' "$state/root/wp-config.php" ;;
  # `wp config get` lê a configuração PHP efetiva, não texto: é o que o
  # instalador precisa usar para provar persistência sem falso positivo.
  *'config get WP_CACHE --type=constant'*)
    grep -Fq "define( 'WP_CACHE', true );" "$state/root/wp-config.php" || exit 1
    printf '1\n'
    ;;
  *'config get WPCACHEHOME --type=constant'*)
    line="$(grep -F "define( 'WPCACHEHOME', '" "$state/root/wp-config.php" | tail -n 1)"
    [ -n "$line" ] || exit 1
    printf '%s\n' "$line" | sed -n "s/.*define( 'WPCACHEHOME', '\(.*\)' );.*/\1/p"
    ;;
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
cp "$WPSC_TEST_ARCHIVE" "$output"
SH
  chmod 700 "$TMP_DIR/curl"
}

make_fake_cli
state="$TMP_DIR/state"
mkdir -p "$state/root/wp-content"
# Reproduz o wp-config.php REAL da Locaweb: sem o comentário-âncora
# "stop editing" e com o define de ABSPATH aninhado num if. Nesse formato
# `wp config set` falha com "Unable to locate placement anchor", que foi a causa
# da quinta tentativa de deploy.
cat > "$state/root/wp-config.php" <<'CONFIG'
<?php
define( 'DB_NAME', 'exemplo' );
$table_prefix = 'wpis_';
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
CONFIG
: > "$TMP_DIR/configure.php"
source_archive="$TMP_DIR/wp-super-cache.3.1.3.zip"
curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 \
  'https://downloads.wordpress.org/plugin/wp-super-cache.3.1.3.zip' \
  --output "$source_archive"
archive_checksum="$(sha256sum "$source_archive" | cut -d ' ' -f 1)"
[ "$archive_checksum" = 'e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' ] || fail 'fixture oficial diverge do SHA-256 fixo'
export WPSC_TEST_LOG="$TMP_DIR/commands.log"
export WPSC_TEST_STATE="$state"
export WPSC_TEST_ARCHIVE="$source_archive"
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
grep -F -- 'plugin is-active wp-super-cache' "$TMP_DIR/commands.log" >/dev/null || fail 'instalador não confirmou ativação pelo comando suportado'
plugin_install_line="$(grep -nF 'plugin install ' "$TMP_DIR/commands.log" | cut -d: -f1)"

# `wp config set` exige um âncora que o wp-config da Locaweb não tem. O
# instalador precisa gravar as constantes por conta própria.
if grep -F -- 'config set WP_CACHE' "$TMP_DIR/commands.log" >/dev/null; then
  fail 'instalador ainda depende de wp config set, que falha sem âncora de posicionamento'
fi
if grep -F -- 'config set WPCACHEHOME' "$TMP_DIR/commands.log" >/dev/null; then
  fail 'instalador ainda depende de wp config set para WPCACHEHOME'
fi

# As constantes precisam ficar ANTES do require_once que carrega o WordPress:
# depois dele o WP_CACHE não tem efeito no drop-in.
config_body="$state/root/wp-config.php"
wp_cache_at="$(grep -nF "define( 'WP_CACHE', true );" "$config_body" | head -n 1 | cut -d: -f1)"
wpcachehome_at="$(grep -nF "define( 'WPCACHEHOME'," "$config_body" | head -n 1 | cut -d: -f1)"
require_at="$(grep -nF "require_once ABSPATH . 'wp-settings.php';" "$config_body" | head -n 1 | cut -d: -f1)"
[ -n "$wp_cache_at" ] || fail 'WP_CACHE não foi inserido no wp-config'
[ -n "$wpcachehome_at" ] || fail 'WPCACHEHOME não foi inserido no wp-config'
[ "$wp_cache_at" -lt "$require_at" ] || fail 'WP_CACHE precisa preceder o require_once do wp-settings'
[ "$wpcachehome_at" -lt "$require_at" ] || fail 'WPCACHEHOME precisa preceder o require_once do wp-settings'

# O arquivo tem de continuar sintaticamente válido e preservar o conteúdo original.
php -l "$config_body" >/dev/null || fail 'wp-config ficou com PHP inválido após a inserção'
grep -Fq "define( 'DB_NAME', 'exemplo' );" "$config_body" || fail 'inserção destruiu conteúdo preexistente do wp-config'
grep -Fq "require_once ABSPATH . 'wp-settings.php';" "$config_body" || fail 'inserção removeu o require_once do wp-settings'
[ "$(grep -cF "define( 'WP_CACHE', true );" "$config_body")" -eq 1 ] || fail 'WP_CACHE foi inserido em duplicidade'

# Backup do wp-config precisa existir para permitir rollback.
backup_count="$(find "$state/root" -maxdepth 1 -name 'wp-config.php.uonix-wpsc-*' | wc -l | tr -d ' ')"
[ "$backup_count" -ge 1 ] || fail 'instalador não criou backup do wp-config antes de alterar'

# A persistência precisa ser lida pela configuração PHP efetiva, não por grep de
# texto: um define comentado ou um valor divergente passariam no teste textual.
grep -F -- 'config get WP_CACHE --type=constant' "$TMP_DIR/commands.log" >/dev/null || fail 'instalador não confirmou WP_CACHE pela configuração efetiva'
grep -F -- 'config get WPCACHEHOME --type=constant' "$TMP_DIR/commands.log" >/dev/null || fail 'instalador não confirmou WPCACHEHOME pela configuração efetiva'
wp_cache_get_line="$(grep -nF 'config get WP_CACHE --type=constant' "$TMP_DIR/commands.log" | head -n 1 | cut -d: -f1)"
wpcachehome_get_line="$(grep -nF 'config get WPCACHEHOME --type=constant' "$TMP_DIR/commands.log" | head -n 1 | cut -d: -f1)"
[ "$wp_cache_get_line" -lt "$plugin_install_line" ] || fail 'WP_CACHE precisa ser verificado ANTES da ativação, não só depois'
[ "$wpcachehome_get_line" -lt "$plugin_install_line" ] || fail 'WPCACHEHOME precisa ser verificado ANTES da ativação, não só depois'

if grep -F -- 'plugin status wp-super-cache --field=status' "$TMP_DIR/commands.log" >/dev/null; then
  fail 'instalador ainda usa --field incompatível com o WP-CLI da Locaweb'
fi

# `wp config path` fora da instalação operada precisa ser recusado: escrever as
# constantes noutro wp-config afetaria outro site e a prova de persistência
# passaria a valer para o arquivo errado.
foreign_state="$TMP_DIR/foreign-state"
mkdir -p "$foreign_state/root/wp-content" "$foreign_state/other"
printf "<?php\n" > "$foreign_state/root/wp-config.php"
printf "<?php\n" > "$foreign_state/other/wp-config.php"
cat > "$TMP_DIR/wp-cli-foreign.php" <<SH
#!/usr/bin/env bash
set -euo pipefail
case "\$*" in
  *'plugin is-installed wp-super-cache'*) exit 1 ;;
  *'config path'*) printf '%s\n' "$foreign_state/other/wp-config.php" ;;
  *'config set '*) printf "TOUCHED\n" >> "$foreign_state/other/wp-config.php" ;;
  *'config get '*) printf '1\n' ;;
  *'plugin install '*) : ;;
  *'plugin is-active wp-super-cache'*) : ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'eval-file '*) printf 'WPSC_SIMPLE_CONFIGURATION=PASS\n' ;;
  *) printf 'unexpected foreign CLI command: %s\n' "\$*" >&2; exit 91 ;;
esac
SH
chmod 700 "$TMP_DIR/wp-cli-foreign.php"
if bash "$SCRIPT" \
  --wp-root="$foreign_state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli-foreign.php" \
  --config-script="$TMP_DIR/configure.php" \
  --archive="$source_archive" \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  >/dev/null 2>&1; then
  fail 'wp-config fora da instalação operada foi aceito'
fi
# A guarda precisa agir ANTES de qualquer escrita: o arquivo estrangeiro não
# pode ter recebido nenhuma constante.
if grep -Fq 'TOUCHED' "$foreign_state/other/wp-config.php" \
  || grep -Fq "define( 'WP_CACHE'" "$foreign_state/other/wp-config.php" \
  || grep -Fq "define( 'WPCACHEHOME'" "$foreign_state/other/wp-config.php" \
  || find "$foreign_state/other" -maxdepth 1 -name 'wp-config.php.uonix-wpsc-*' | grep -q .; then
  fail 'instalador escreveu em wp-config fora da instalação operada'
fi

# Symlink no próprio wp-config também é recusado: redireciona a escrita.
symlink_state="$TMP_DIR/symlink-state"
mkdir -p "$symlink_state/root/wp-content" "$symlink_state/elsewhere"
printf "<?php\n" > "$symlink_state/elsewhere/wp-config.php"
ln -s "$symlink_state/elsewhere/wp-config.php" "$symlink_state/root/wp-config.php"
cat > "$TMP_DIR/wp-cli-symlink.php" <<SH
#!/usr/bin/env bash
set -euo pipefail
case "\$*" in
  *'plugin is-installed wp-super-cache'*) exit 1 ;;
  *'config path'*) printf '%s\n' "$symlink_state/root/wp-config.php" ;;
  *'config set '*) printf "TOUCHED\n" >> "$symlink_state/root/wp-config.php" ;;
  *'config get '*) printf '1\n' ;;
  *'plugin install '*) : ;;
  *'plugin is-active wp-super-cache'*) : ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'eval-file '*) printf 'WPSC_SIMPLE_CONFIGURATION=PASS\n' ;;
  *) printf 'unexpected symlink CLI command: %s\n' "\$*" >&2; exit 91 ;;
esac
SH
chmod 700 "$TMP_DIR/wp-cli-symlink.php"
if bash "$SCRIPT" \
  --wp-root="$symlink_state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli-symlink.php" \
  --config-script="$TMP_DIR/configure.php" \
  --archive="$source_archive" \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  >/dev/null 2>&1; then
  fail 'wp-config symlinkado foi aceito'
fi
if grep -Fq 'TOUCHED' "$symlink_state/elsewhere/wp-config.php" \
  || grep -Fq "define( 'WP_CACHE'" "$symlink_state/elsewhere/wp-config.php" \
  || grep -Fq "define( 'WPCACHEHOME'" "$symlink_state/elsewhere/wp-config.php"; then
  fail 'instalador escreveu através de wp-config symlinkado'
fi

# WPCACHEHOME divergente precisa reprovar: o WPSC carregaria phase1 de outro
# diretório e o cache ficaria quebrado com aparência de instalação correta.
divergent_state="$TMP_DIR/divergent-state"
mkdir -p "$divergent_state/root/wp-content"
cat > "$divergent_state/root/wp-config.php" <<'CONFIG'
<?php
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
CONFIG
cat > "$TMP_DIR/wp-cli-divergent.php" <<SH
#!/usr/bin/env bash
set -euo pipefail
case "\$*" in
  *'plugin is-installed wp-super-cache'*) exit 1 ;;
  *'config path'*) printf '%s\n' "$divergent_state/root/wp-config.php" ;;
  *'config set '*) : ;;
  *'config get WP_CACHE --type=constant'*) printf '1\n' ;;
  *'config get WPCACHEHOME --type=constant'*) printf '/wrong/wp-super-cache/\n' ;;
  *'plugin install '*)
    # Cria todos os artefatos esperados: assim a ÚNICA razão possível de falha
    # é a comparação de WPCACHEHOME, e não uma checagem posterior.
    mkdir -p "$divergent_state/root/wp-content/plugins/wp-super-cache" "$divergent_state/root/wp-content/cache"
    : > "$divergent_state/root/wp-content/plugins/wp-super-cache/wp-cache.php"
    : > "$divergent_state/root/wp-content/advanced-cache.php"
    : > "$divergent_state/root/wp-content/wp-cache-config.php"
    ;;
  *'plugin is-active wp-super-cache'*) : ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'eval-file '*) printf 'WPSC_SIMPLE_CONFIGURATION=PASS\n' ;;
  *) printf 'unexpected divergent CLI command: %s\n' "\$*" >&2; exit 91 ;;
esac
SH
chmod 700 "$TMP_DIR/wp-cli-divergent.php"
divergent_output="$TMP_DIR/divergent.out"
if bash "$SCRIPT" \
  --wp-root="$divergent_state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli-divergent.php" \
  --config-script="$TMP_DIR/configure.php" \
  --archive="$source_archive" \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  >"$divergent_output" 2>&1; then
  fail 'WPCACHEHOME divergente foi aceito'
fi
grep -Fq 'wpcachehome_divergente' "$divergent_output" || fail 'WPCACHEHOME divergente reprovou por outro motivo'

# Um wp-config já sintaticamente inválido não pode ser substituído: sem o lint,
# o instalador publicaria um arquivo quebrado e derrubaria o site inteiro.
broken_state="$TMP_DIR/broken-state"
mkdir -p "$broken_state/root/wp-content"
cat > "$broken_state/root/wp-config.php" <<'CONFIG'
<?php
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
function uonix_broken( {
CONFIG
broken_before="$(shasum -a 256 < "$broken_state/root/wp-config.php" | cut -d' ' -f1)"
cat > "$TMP_DIR/wp-cli-broken.php" <<SH
#!/usr/bin/env bash
set -euo pipefail
case "\$*" in
  *'plugin is-installed wp-super-cache'*) exit 1 ;;
  *'config path'*) printf '%s\n' "$broken_state/root/wp-config.php" ;;
  *'config get '*) printf '1\n' ;;
  *'plugin install '*) : ;;
  *'plugin is-active wp-super-cache'*) : ;;
  *'plugin get wp-super-cache --field=version'*) printf '3.1.3\n' ;;
  *'eval-file '*) printf 'WPSC_SIMPLE_CONFIGURATION=PASS\n' ;;
  *) printf 'unexpected broken CLI command: %s\n' "\$*" >&2; exit 91 ;;
esac
SH
chmod 700 "$TMP_DIR/wp-cli-broken.php"
if bash "$SCRIPT" \
  --wp-root="$broken_state/root" \
  --php-bin="$TMP_DIR/php-bin" \
  --wp-bin="$TMP_DIR/wp-cli-broken.php" \
  --config-script="$TMP_DIR/configure.php" \
  --archive="$source_archive" \
  --source-sha256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270' \
  >/dev/null 2>&1; then
  fail 'wp-config com PHP inválido foi aceito para substituição'
fi
broken_after="$(shasum -a 256 < "$broken_state/root/wp-config.php" | cut -d' ' -f1)"
[ "$broken_before" = "$broken_after" ] || fail 'instalador substituiu wp-config sem validar sintaxe PHP'
[ ! -e "$broken_state/root/wp-config.php.uonix-wpsc-pending" ] || fail 'arquivo temporário de inserção não foi removido'

# Falha fechada: plugin já presente não pode ser atualizado silenciosamente.
: > "$state/installed"
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
