#!/usr/bin/env python3
"""Executa os heredocs reais de rollback/release com fixtures locais."""

from __future__ import annotations

import os
import pathlib
import re
import subprocess
import tempfile
import hashlib
import stat

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/deploy-production.yml"
RUN_ID = "runtime-42"
TARGET_URL = "https://prod.example.invalid"


def fail(message: str) -> None:
    raise AssertionError(message)


def named_step_run(document: str, step_name: str) -> str:
    lines = document.splitlines()
    name_index = next(
        index for index, line in enumerate(lines)
        if line.strip() == f"- name: {step_name}"
    )
    for index in range(name_index + 1, len(lines)):
        if lines[index].lstrip().startswith("- name:"):
            break
        match = re.match(r"^(\s*)run:\s*\|\s*$", lines[index])
        if not match:
            continue
        run_indent = len(match.group(1))
        body: list[str] = []
        for body_line in lines[index + 1 :]:
            indentation = len(body_line) - len(body_line.lstrip())
            if body_line.strip() and indentation <= run_indent:
                break
            body.append(body_line[run_indent + 2 :] if body_line.strip() else "")
        return "\n".join(body) + "\n"
    fail(f"step sem run literal: {step_name}")
    return ""


def remote_blocks(run_body: str) -> list[str]:
    blocks = re.findall(r"<<'REMOTE'\n(.*?)\nREMOTE(?:\n|$)", run_body, re.S)
    if not blocks:
        fail("nenhum heredoc literal REMOTE encontrado")
    return [block + "\n" for block in blocks]


def publish_allowlist_prelude(run_body: str) -> str:
    start_token = "expected_modules=()\n"
    end_token = "# O marcador remoto é criado antes do primeiro rsync."
    start = run_body.find(start_token)
    end = run_body.find(end_token, start)
    if start < 0 or end < 0:
        fail("não foi possível extrair a pré-validação da allowlist de produção")
    return (
        "set -euo pipefail\n"
        + run_body[start:end]
        + "printf 'ALLOWLIST_MODULE=%s\\n' \"${expected_modules[@]}\"\n"
    )


def write_executable(path: pathlib.Path, content: str) -> None:
    path.write_text(content, encoding="utf-8")
    path.chmod(0o755)


class RollbackFixture:
    def __init__(
        self,
        root: pathlib.Path,
        *,
        db_owner: str | None,
        code_owner: str | None,
        rollback_status: int = 0,
        active_migration_owner: str | None = None,
        corrupt_backup_manifest: bool = False,
        corrupt_restored_code: bool = False,
    ) -> None:
        self.root = root
        self.document_root = root / "document-root"
        self.backup_dir = root / "backup"
        self.bin_dir = root / "bin"
        self.php_log = root / "php.log"
        self.mysql_log = root / "mysql.log"
        self.operation_lock = self.document_root / ".uonix-operation.lock"
        self.migration_lock = self.document_root / ".uonix-vts-migration.lock"
        self.root.mkdir(parents=True)
        self.document_root.mkdir()
        self.bin_dir.mkdir()
        self.operation_lock.mkdir()
        (self.operation_lock / "owner").write_text(RUN_ID + "\n", encoding="utf-8")
        (self.operation_lock / "owner").chmod(0o600)
        if db_owner is not None:
            db_marker = self.operation_lock / "db-mutation-started"
            db_marker.write_text(db_owner + "\n", encoding="utf-8")
            db_marker.chmod(0o600)
        if code_owner is not None:
            code_marker = self.operation_lock / "code-mutation-started"
            code_marker.write_text(code_owner + "\n", encoding="utf-8")
            code_marker.chmod(0o600)
        if active_migration_owner is not None:
            self.migration_lock.mkdir()
            migration_owner = self.migration_lock / "owner"
            migration_owner.write_text(active_migration_owner + "\n", encoding="utf-8")
            migration_owner.chmod(0o600)

        current_theme = self.document_root / "wp-content/themes/kadence-child"
        current_module = self.document_root / "wp-content/mu-plugins/uonix-new"
        current_theme.mkdir(parents=True)
        current_module.mkdir(parents=True)
        (current_theme / "new.txt").write_text("new\n", encoding="utf-8")
        (current_module / "new.txt").write_text("new\n", encoding="utf-8")
        core = self.document_root / "wp-content/mu-plugins/uonix-core.php"
        core.write_text("new core\n", encoding="utf-8")

        old_theme = self.backup_dir / "managed/themes/kadence-child"
        old_module = self.backup_dir / "managed/mu-plugins/uonix-old"
        old_theme.mkdir(parents=True)
        old_module.mkdir(parents=True)
        (old_theme / "old.txt").write_text("old\n", encoding="utf-8")
        (old_module / "old.txt").write_text("old\n", encoding="utf-8")
        (self.backup_dir / "managed/mu-plugins/uonix-core.php").write_text("old core\n", encoding="utf-8")
        manifest_entries = []
        for relative in sorted(
            (
                "themes/kadence-child/old.txt",
                "mu-plugins/uonix-core.php",
                "mu-plugins/uonix-old/old.txt",
            )
        ):
            payload = (self.backup_dir / "managed" / relative).read_bytes()
            digest = hashlib.sha256(payload).hexdigest()
            if corrupt_backup_manifest and relative == "themes/kadence-child/old.txt":
                digest = "0" * 64
            manifest_entries.append(f"{digest}  {relative}\n")
        backup_manifest = self.backup_dir / "manifest.backup.sha256"
        backup_manifest.write_text(
            "".join(manifest_entries), encoding="utf-8"
        )
        backup_manifest.chmod(0o600)

        fake_php = self.bin_dir / "php"
        write_executable(
            fake_php,
            """#!/usr/bin/env bash
set -u
printf '<%s>' "$@" >> "$MOCK_PHP_LOG"
printf '\n' >> "$MOCK_PHP_LOG"
joined=" $* "
case "$joined" in
  *' uonix ficha-tecnica migrate --rollback '*) exit "${MOCK_ROLLBACK_STATUS:-0}" ;;
  *' option get home '*|*' option get siteurl '*) printf '%s\n' "$MOCK_TARGET_URL"; exit 0 ;;
  *' eval '*UONIX_ENV*) printf 'production\n'; exit 0 ;;
esac
exit 0
""",
        )
        write_executable(self.bin_dir / "sleep", "#!/usr/bin/env bash\nexit 0\n")
        write_executable(
            self.bin_dir / "cp",
            """#!/usr/bin/env bash
/bin/cp "$@" || exit $?
if [ "${MOCK_CORRUPT_RESTORED_CODE:-0}" = 1 ] && [ ! -e "$MOCK_CORRUPT_ONCE" ]; then
  destination="${@: -1}"
  if [ -f "$destination/kadence-child/old.txt" ]; then
    printf 'corrompido depois da cópia\n' >> "$destination/kadence-child/old.txt"
    : > "$MOCK_CORRUPT_ONCE"
  fi
fi
""",
        )
        write_executable(
            self.bin_dir / "mysql",
            "#!/usr/bin/env bash\nprintf 'INVOKED\\n' >> \"$MOCK_MYSQL_LOG\"\nexit 99\n",
        )
        self.wp_bin = self.bin_dir / "wp-cli.phar"
        self.wp_bin.write_text("fixture\n", encoding="utf-8")
        self.env = os.environ.copy()
        self.env.update(
            {
                "PATH": f"{self.bin_dir}:{self.env.get('PATH', '')}",
                "MOCK_PHP_LOG": str(self.php_log),
                "MOCK_MYSQL_LOG": str(self.mysql_log),
                "MOCK_ROLLBACK_STATUS": str(rollback_status),
                "MOCK_TARGET_URL": TARGET_URL,
                "MOCK_CORRUPT_RESTORED_CODE": "1" if corrupt_restored_code else "0",
                "MOCK_CORRUPT_ONCE": str(root / "corrupt-once"),
            }
        )

    def run(self, script: pathlib.Path) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                "bash",
                str(script),
                str(self.document_root),
                str(self.backup_dir),
                str(self.bin_dir / "php"),
                str(self.wp_bin),
                TARGET_URL,
                RUN_ID,
            ],
            env=self.env,
            text=True,
            capture_output=True,
            check=False,
        )

    def code_is_new(self) -> bool:
        return (self.document_root / "wp-content/themes/kadence-child/new.txt").is_file()

    def code_is_old(self) -> bool:
        return (self.document_root / "wp-content/themes/kadence-child/old.txt").is_file()


def assert_no_mysql(fixture: RollbackFixture, case: str) -> None:
    if fixture.mysql_log.exists() and fixture.mysql_log.read_text(encoding="utf-8"):
        fail(f"{case}: heredoc invocou mysql; dump integral não pode ser automático")


def test_acquire_lock(script: pathlib.Path, temp: pathlib.Path) -> None:
    lock = temp / "acquire-valid/.uonix-operation.lock"
    lock.parent.mkdir(parents=True)
    result = subprocess.run(
        ["bash", str(script), str(lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    owner = lock / "owner"
    if result.returncode != 0 or not owner.is_file():
        fail(f"aquisição real não criou lock/owner: {result.stderr[-300:]}")
    if owner.read_text(encoding="utf-8") != RUN_ID + "\n":
        fail("aquisição real não gravou owner exato")
    if stat.S_IMODE(owner.stat().st_mode) != 0o600:
        fail("owner criado pela aquisição não possui permissão 0600")

    occupied = temp / "acquire-occupied/.uonix-operation.lock"
    occupied.mkdir(parents=True)
    foreign_owner = occupied / "owner"
    foreign_owner.write_text("other-run\n", encoding="utf-8")
    result = subprocess.run(
        ["bash", str(script), str(occupied), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or foreign_owner.read_text(encoding="utf-8") != "other-run\n":
        fail("aquisição sobrescreveu lock/owner de outra execução")


def manifest_for(base: pathlib.Path, relative_files: tuple[str, ...]) -> str:
    entries: list[str] = []
    for relative in sorted(relative_files):
        digest = hashlib.sha256((base / relative).read_bytes()).hexdigest()
        entries.append(f"{digest}  {relative}\n")
    return "".join(entries)


def test_backup_manifest(script: pathlib.Path, temp: pathlib.Path) -> None:
    root = temp / "backup-valid"
    document_root = root / "document-root"
    backup_dir = root / "backup"
    theme = document_root / "wp-content/themes/kadence-child"
    module = document_root / "wp-content/mu-plugins/uonix-module"
    theme.mkdir(parents=True)
    module.mkdir(parents=True)
    (theme / "theme.txt").write_text("theme\n", encoding="utf-8")
    core = document_root / "wp-content/mu-plugins/uonix-core.php"
    core.write_text("core\n", encoding="utf-8")
    (module / "module.txt").write_text("module\n", encoding="utf-8")
    result = subprocess.run(
        ["bash", str(script), str(document_root), str(backup_dir)],
        text=True,
        capture_output=True,
        check=False,
    )
    manifest = backup_dir / "manifest.backup.sha256"
    if result.returncode != 0 or not manifest.is_file():
        fail(f"backup real não criou manifesto: {result.stderr[-300:]}")
    expected = manifest_for(
        backup_dir / "managed",
        (
            "themes/kadence-child/theme.txt",
            "mu-plugins/uonix-core.php",
            "mu-plugins/uonix-module/module.txt",
        ),
    )
    if manifest.read_text(encoding="utf-8") != expected:
        fail("manifesto do backup não representa conjunto exato e hashes copiados")
    if stat.S_IMODE(manifest.stat().st_mode) != 0o600:
        fail("manifesto do backup não possui permissão 0600")

    preexisting_root = temp / "backup-preexisting-manifest-symlink"
    preexisting_document = preexisting_root / "document-root"
    preexisting_backup = preexisting_root / "backup"
    preexisting_theme = preexisting_document / "wp-content/themes/kadence-child"
    preexisting_mu = preexisting_document / "wp-content/mu-plugins"
    preexisting_theme.mkdir(parents=True)
    preexisting_mu.mkdir(parents=True)
    (preexisting_theme / "theme.txt").write_text("theme\n", encoding="utf-8")
    (preexisting_mu / "uonix-core.php").write_text("core\n", encoding="utf-8")
    preexisting_backup.mkdir()
    outside_manifest = preexisting_root / "outside-manifest"
    outside_manifest.write_text("do-not-overwrite\n", encoding="utf-8")
    outside_manifest.chmod(0o600)
    (preexisting_backup / "manifest.backup.sha256").symlink_to(outside_manifest)
    result = subprocess.run(
        ["bash", str(script), str(preexisting_document), str(preexisting_backup)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0:
        fail("backup reutilizou diretório existente com manifesto symlink")
    if outside_manifest.read_text(encoding="utf-8") != "do-not-overwrite\n":
        fail("backup seguiu manifesto preexistente e sobrescreveu arquivo externo")
    if not (preexisting_backup / "manifest.backup.sha256").is_symlink():
        fail("backup substituiu manifesto symlink preexistente em vez de falhar fechado")

    symlink_root = temp / "backup-symlink"
    symlink_document = symlink_root / "document-root"
    symlink_backup = symlink_root / "backup"
    mu_plugins = symlink_document / "wp-content/mu-plugins"
    mu_plugins.mkdir(parents=True)
    outside = symlink_root / "outside-theme"
    outside.mkdir()
    (outside / "secret.txt").write_text("outside\n", encoding="utf-8")
    themes = symlink_document / "wp-content/themes"
    themes.mkdir(parents=True)
    (themes / "kadence-child").symlink_to(outside, target_is_directory=True)
    (mu_plugins / "uonix-core.php").write_text("core\n", encoding="utf-8")
    result = subprocess.run(
        ["bash", str(script), str(symlink_document), str(symlink_backup)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0:
        fail("backup aceitou raiz gerenciada como symlink")
    copied_root = symlink_backup / "managed/themes/kadence-child"
    if copied_root.exists() or copied_root.is_symlink():
        fail("backup copiou raiz gerenciada symlink antes de falhar")
    if outside.joinpath("secret.txt").read_text(encoding="utf-8") != "outside\n":
        fail("backup alterou conteúdo externo alcançado pelo symlink")

    file_link_root = temp / "backup-file-symlink"
    file_link_document = file_link_root / "document-root"
    file_link_backup = file_link_root / "backup"
    file_link_theme = file_link_document / "wp-content/themes/kadence-child"
    file_link_mu = file_link_document / "wp-content/mu-plugins"
    file_link_theme.mkdir(parents=True)
    file_link_mu.mkdir(parents=True)
    (file_link_theme / "theme.txt").write_text("theme\n", encoding="utf-8")
    outside_core = file_link_root / "outside-core.php"
    outside_core.write_text("outside core\n", encoding="utf-8")
    (file_link_mu / "uonix-core.php").symlink_to(outside_core)
    result = subprocess.run(
        ["bash", str(script), str(file_link_document), str(file_link_backup)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0:
        fail("backup aceitou arquivo gerenciado raiz como symlink")
    copied_core = file_link_backup / "managed/mu-plugins/uonix-core.php"
    if copied_core.exists() or copied_core.is_symlink():
        fail("backup copiou arquivo gerenciado symlink antes de falhar")
    if outside_core.read_text(encoding="utf-8") != "outside core\n":
        fail("backup alterou arquivo externo alcançado pelo symlink raiz")

    nested_link_root = temp / "backup-nested-symlink"
    nested_link_document = nested_link_root / "document-root"
    nested_link_backup = nested_link_root / "backup"
    nested_theme = nested_link_document / "wp-content/themes/kadence-child"
    nested_mu = nested_link_document / "wp-content/mu-plugins"
    nested_theme.mkdir(parents=True)
    nested_mu.mkdir(parents=True)
    (nested_theme / "theme.txt").write_text("theme\n", encoding="utf-8")
    (nested_mu / "uonix-core.php").write_text("core\n", encoding="utf-8")
    outside_nested = nested_link_root / "outside-nested.txt"
    outside_nested.write_text("outside nested\n", encoding="utf-8")
    (nested_theme / "nested-link.txt").symlink_to(outside_nested)
    result = subprocess.run(
        ["bash", str(script), str(nested_link_document), str(nested_link_backup)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0:
        fail("backup aceitou symlink dentro de diretório gerenciado real")
    copied_nested_theme = nested_link_backup / "managed/themes/kadence-child"
    if copied_nested_theme.exists() or copied_nested_theme.is_symlink():
        fail("backup copiou raiz com symlink interno antes de falhar")
    if outside_nested.read_text(encoding="utf-8") != "outside nested\n":
        fail("backup alterou alvo externo de symlink interno")


def test_publish_marker(script: pathlib.Path, temp: pathlib.Path) -> None:
    valid_lock = temp / "publish-valid/.uonix-operation.lock"
    valid_lock.mkdir(parents=True)
    valid_owner = valid_lock / "owner"
    valid_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    valid_owner.chmod(0o600)
    result = subprocess.run(
        ["bash", str(script), str(valid_lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    marker = valid_lock / "code-mutation-started"
    if result.returncode != 0:
        fail(f"marcador de código válido falhou: {result.stderr[-300:]}")
    if marker.read_text(encoding="utf-8") != RUN_ID + "\n":
        fail("marcador de código não identifica exatamente a execução")
    if stat.S_IMODE(marker.stat().st_mode) != 0o600:
        fail("marcador de código não possui permissão 0600")

    foreign_owner_lock = temp / "publish-foreign-owner/.uonix-operation.lock"
    foreign_owner_lock.mkdir(parents=True)
    foreign_owner_path = foreign_owner_lock / "owner"
    foreign_owner_path.write_text("other-run\n", encoding="utf-8")
    foreign_owner_path.chmod(0o600)
    result = subprocess.run(
        ["bash", str(script), str(foreign_owner_lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or (foreign_owner_lock / "code-mutation-started").exists():
        fail("publicação aceitou owner do lock pertencente a outra execução")

    public_owner_lock = temp / "publish-public-owner/.uonix-operation.lock"
    public_owner_lock.mkdir(parents=True)
    public_owner_path = public_owner_lock / "owner"
    public_owner_path.write_text(RUN_ID + "\n", encoding="utf-8")
    public_owner_path.chmod(0o644)
    result = subprocess.run(
        ["bash", str(script), str(public_owner_lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or (public_owner_lock / "code-mutation-started").exists():
        fail("publicação aceitou owner do lock sem permissão 0600")

    foreign_lock = temp / "publish-foreign/.uonix-operation.lock"
    foreign_lock.mkdir(parents=True)
    foreign_lock_owner = foreign_lock / "owner"
    foreign_lock_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    foreign_lock_owner.chmod(0o600)
    foreign_marker = foreign_lock / "code-mutation-started"
    foreign_marker.write_text("other-run\n", encoding="utf-8")
    foreign_marker.chmod(0o600)
    result = subprocess.run(
        ["bash", str(script), str(foreign_lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or foreign_marker.read_text(encoding="utf-8") != "other-run\n":
        fail("marcador de código existente foi sobrescrito")

    symlink_lock = temp / "publish-symlink/.uonix-operation.lock"
    symlink_lock.mkdir(parents=True)
    symlink_owner = symlink_lock / "owner"
    symlink_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    symlink_owner.chmod(0o600)
    outside = temp / "outside-marker"
    outside.write_text("do-not-touch\n", encoding="utf-8")
    (symlink_lock / "code-mutation-started").symlink_to(outside)
    result = subprocess.run(
        ["bash", str(script), str(symlink_lock), RUN_ID],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or outside.read_text(encoding="utf-8") != "do-not-touch\n":
        fail("marcador de código seguiu symlink fora do lock")

    race_lock = temp / "publish-race/.uonix-operation.lock"
    race_lock.mkdir(parents=True)
    race_owner = race_lock / "owner"
    race_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    race_owner.chmod(0o600)
    race_marker = race_lock / "code-mutation-started"
    bash_env = temp / "race-bash-env"
    bash_env.write_text(
        """test() {
  builtin test "$@"
  rc=$?
  if builtin test "$#" -eq 3 && builtin test "$1" = '!' && \
     builtin test "$2" = '-L' && builtin test "$3" = "$RACE_MARKER"; then
    if builtin test ! -e "$RACE_MARKER"; then
      builtin printf 'racer-run\\n' > "$RACE_MARKER"
    fi
  fi
  return "$rc"
}
""",
        encoding="utf-8",
    )
    race_env = os.environ.copy()
    race_env.update({"BASH_ENV": str(bash_env), "RACE_MARKER": str(race_marker)})
    result = subprocess.run(
        ["bash", str(script), str(race_lock), RUN_ID],
        env=race_env,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or race_marker.read_text(encoding="utf-8") != "racer-run\n":
        fail("corrida entre checagem e gravação sobrescreveu marcador concorrente")


def module_directories(document_root: pathlib.Path) -> list[str]:
    modules_root = document_root / "wp-content/mu-plugins"
    return sorted(path.name for path in modules_root.glob("uonix-*") if path.is_dir())


def make_module_prune_fixture(root: pathlib.Path) -> pathlib.Path:
    document_root = root / "document-root"
    modules_root = document_root / "wp-content/mu-plugins"
    modules_root.mkdir(parents=True)
    for name in ("uonix-alpha", "uonix-beta", "uonix-shared"):
        module = modules_root / name
        module.mkdir()
        (module / "keep.php").write_text("<?php\n", encoding="utf-8")
    return document_root


def test_module_prune_allowlist(script: pathlib.Path, temp: pathlib.Path) -> None:
    empty_root = make_module_prune_fixture(temp / "publish-modules-empty")
    before = module_directories(empty_root)
    result = subprocess.run(
        ["bash", str(script), str(empty_root)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or module_directories(empty_root) != before:
        fail("poda de módulos aceitou allowlist vazia ou alterou o destino antes de falhar")

    invalid_root = make_module_prune_fixture(temp / "publish-modules-invalid")
    before = module_directories(invalid_root)
    result = subprocess.run(
        ["bash", str(script), str(invalid_root), "uonix-alpha", "uonix-bad;command"],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or module_directories(invalid_root) != before:
        fail("poda de módulos aceitou nome inválido ou alterou o destino antes de falhar")

    valid_root = make_module_prune_fixture(temp / "publish-modules-valid")
    result = subprocess.run(
        ["bash", str(script), str(valid_root), "uonix-alpha", "uonix-shared"],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        fail(f"poda com allowlist válida falhou: {result.stderr[-300:]}")
    if module_directories(valid_root) != ["uonix-alpha", "uonix-shared"]:
        fail("poda com allowlist válida não convergiu exatamente os módulos esperados")


def run_allowlist_prelude(script: pathlib.Path, root: pathlib.Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(script)],
        cwd=root,
        text=True,
        capture_output=True,
        check=False,
    )


def test_publish_allowlist_prelude(script: pathlib.Path, temp: pathlib.Path) -> None:
    empty_root = temp / "publish-allowlist-empty"
    (empty_root / ".deploy/mu-plugins").mkdir(parents=True)
    result = run_allowlist_prelude(script, empty_root)
    if result.returncode == 0:
        fail("runner aceitou bundle sem nenhum módulo uonix-*")
    if "allowlist de módulos vazia" not in result.stderr:
        fail("runner não executou seu guard explícito de allowlist vazia no Bash corrente")

    invalid_root = temp / "publish-allowlist-invalid"
    invalid_modules = invalid_root / ".deploy/mu-plugins"
    invalid_modules.mkdir(parents=True)
    (invalid_modules / "uonix-valid").mkdir()
    (invalid_modules / "uonix-bad;command").mkdir()
    result = run_allowlist_prelude(script, invalid_root)
    if result.returncode == 0 or "nome de módulo inválido" not in result.stderr:
        fail("runner aceitou nome de módulo inválido antes da primeira mutação")

    valid_root = temp / "publish-allowlist-valid"
    valid_modules = valid_root / ".deploy/mu-plugins"
    valid_modules.mkdir(parents=True)
    (valid_modules / "uonix-alpha").mkdir()
    (valid_modules / "uonix-shared").mkdir()
    result = run_allowlist_prelude(script, valid_root)
    if result.returncode != 0:
        fail(f"runner rejeitou allowlist válida: {result.stderr[-300:]}")
    reported = sorted(
        line.removeprefix("ALLOWLIST_MODULE=")
        for line in result.stdout.splitlines()
        if line.startswith("ALLOWLIST_MODULE=")
    )
    if reported != ["uonix-alpha", "uonix-shared"]:
        fail(f"runner não derivou allowlist exata do bundle: {reported!r}")


def run_expected_manifest_verifier(
    script: pathlib.Path,
    document_root: pathlib.Path,
    manifest: pathlib.Path,
    expected_checksum: str | None = None,
) -> subprocess.CompletedProcess[str]:
    if expected_checksum is None:
        try:
            expected_checksum = hashlib.sha256(manifest.read_bytes()).hexdigest()
        except OSError:
            expected_checksum = "0" * 64
    return subprocess.run(
        ["bash", str(script), str(document_root), str(manifest), expected_checksum],
        text=True,
        capture_output=True,
        check=False,
    )


def test_expected_manifest_reservation(script: pathlib.Path, temp: pathlib.Path) -> None:
    root = temp / "expected-manifest-reservation"
    root.mkdir()

    manifest = root / "manifest.expected.sha256"
    result = subprocess.run(
        ["bash", str(script), str(manifest)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        fail(f"reserva exclusiva do manifesto esperado falhou: {result.stderr[-500:]}")
    if not manifest.is_file() or manifest.is_symlink() or manifest.read_bytes() != b"":
        fail("reserva não criou manifesto esperado regular e vazio")
    if manifest.stat().st_mode & 0o777 != 0o600:
        fail("reserva não criou manifesto esperado em modo 0600")

    occupied = root / "manifest.occupied.sha256"
    occupied.write_text("não truncar\n", encoding="utf-8")
    occupied.chmod(0o600)
    result = subprocess.run(
        ["bash", str(script), str(occupied)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or occupied.read_text(encoding="utf-8") != "não truncar\n":
        fail("reserva truncou ou aceitou manifesto esperado preexistente")

    outside = root / "outside-manifest"
    outside.write_text("fora\n", encoding="utf-8")
    linked = root / "manifest.linked.sha256"
    linked.symlink_to(outside)
    result = subprocess.run(
        ["bash", str(script), str(linked)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or outside.read_text(encoding="utf-8") != "fora\n":
        fail("reserva seguiu ou aceitou symlink de manifesto esperado")

    broken = root / "manifest.broken.sha256"
    broken.symlink_to(root / "missing-target")
    result = subprocess.run(
        ["bash", str(script), str(broken)],
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode == 0 or not broken.is_symlink():
        fail("reserva aceitou ou substituiu symlink quebrado de manifesto esperado")

    race_manifest = root / "manifest.race.sha256"
    bash_env = root / "race-bash-env"
    bash_env.write_text(
        """test() {
  builtin test "$@"
  rc=$?
  if builtin test "$#" -eq 3 && builtin test "$1" = '!' && \
     builtin test "$2" = '-L' && builtin test "$3" = "$RACE_MANIFEST"; then
    if builtin test ! -e "$RACE_MANIFEST"; then
      builtin printf 'racer-run\\n' > "$RACE_MANIFEST"
    fi
  fi
  return "$rc"
}
""",
        encoding="utf-8",
    )
    race_env = os.environ.copy()
    race_env.update({"BASH_ENV": str(bash_env), "RACE_MANIFEST": str(race_manifest)})
    result = subprocess.run(
        ["bash", str(script), str(race_manifest)],
        env=race_env,
        text=True,
        capture_output=True,
        check=False,
    )
    if (
        result.returncode == 0
        or race_manifest.read_text(encoding="utf-8") != "racer-run\n"
    ):
        fail("reserva sobrescreveu manifesto criado por processo concorrente")


def test_expected_manifest_verification(script: pathlib.Path, temp: pathlib.Path) -> None:
    root = temp / "expected-manifest"
    document_root = root / "document-root"
    theme_file = document_root / "wp-content/themes/kadence-child/index.php"
    core_file = document_root / "wp-content/mu-plugins/uonix-core.php"
    theme_file.parent.mkdir(parents=True)
    core_file.parent.mkdir(parents=True)
    theme_file.write_text("theme publicado\n", encoding="utf-8")
    core_file.write_text("core publicado\n", encoding="utf-8")

    valid_payload = "".join(
        (
            f"{hashlib.sha256(theme_file.read_bytes()).hexdigest()}  theme/index.php\n",
            f"{hashlib.sha256(core_file.read_bytes()).hexdigest()}  mu-plugins/uonix-core.php\n",
        )
    )
    valid_manifest = root / "manifest.valid.sha256"
    valid_manifest.write_text(valid_payload, encoding="utf-8")
    valid_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(script, document_root, valid_manifest)
    if result.returncode != 0:
        fail(f"manifesto esperado válido foi recusado: {result.stderr[-500:]}")

    truncated_manifest = root / "manifest.truncated.sha256"
    truncated_manifest.write_text(valid_payload.splitlines(keepends=True)[0], encoding="utf-8")
    truncated_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(
        script,
        document_root,
        truncated_manifest,
        hashlib.sha256(valid_payload.encode("utf-8")).hexdigest(),
    )
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou manifesto truncado com newline válido")

    empty_manifest = root / "manifest.empty.sha256"
    empty_manifest.write_text("", encoding="utf-8")
    empty_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(script, document_root, empty_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou manifesto esperado vazio sem conferir arquivo algum")

    unterminated_manifest = root / "manifest.unterminated.sha256"
    unterminated_manifest.write_text(
        f"{hashlib.sha256(theme_file.read_bytes()).hexdigest()}  theme/index.php\n"
        f"{'0' * 64}  mu-plugins/uonix-core.php",
        encoding="utf-8",
    )
    unterminated_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(script, document_root, unterminated_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação ignorou a última linha divergente sem newline")

    public_manifest = root / "manifest.public.sha256"
    public_manifest.write_text(valid_payload, encoding="utf-8")
    public_manifest.chmod(0o644)
    result = run_expected_manifest_verifier(script, document_root, public_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou manifesto esperado legível por grupo/outros")

    linked_target = root / "manifest.external.sha256"
    linked_target.write_text(valid_payload, encoding="utf-8")
    linked_target.chmod(0o600)
    linked_manifest = root / "manifest.linked.sha256"
    linked_manifest.symlink_to(linked_target)
    result = run_expected_manifest_verifier(script, document_root, linked_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou manifesto esperado como symlink válido")
    if linked_target.read_text(encoding="utf-8") != valid_payload:
        fail("verificação pós-publicação alterou alvo externo do manifesto symlink")

    linked_file_document = root / "linked-file-document-root"
    linked_file_theme = linked_file_document / "wp-content/themes/kadence-child/index.php"
    linked_file_theme.parent.mkdir(parents=True)
    external_code = root / "external-code.php"
    external_code.write_text("código externo\n", encoding="utf-8")
    linked_file_theme.symlink_to(external_code)
    linked_file_manifest = root / "manifest.linked-file.sha256"
    linked_file_manifest.write_text(
        f"{hashlib.sha256(external_code.read_bytes()).hexdigest()}  theme/index.php\n",
        encoding="utf-8",
    )
    linked_file_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(
        script,
        linked_file_document,
        linked_file_manifest,
    )
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou arquivo gerenciado como symlink")
    if external_code.read_text(encoding="utf-8") != "código externo\n":
        fail("verificação pós-publicação alterou arquivo externo alcançado por symlink")

    traversal_document = root / "traversal-document-root"
    (traversal_document / "wp-content/themes/kadence-child").mkdir(parents=True)
    traversal_external = root / "traversal-external.php"
    traversal_external.write_text("fora da raiz gerenciada\n", encoding="utf-8")
    traversal_manifest = root / "manifest.traversal.sha256"
    traversal_manifest.write_text(
        f"{hashlib.sha256(traversal_external.read_bytes()).hexdigest()}  "
        "theme/../../../../traversal-external.php\n",
        encoding="utf-8",
    )
    traversal_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(
        script,
        traversal_document,
        traversal_manifest,
    )
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou path traversal para fora da raiz gerenciada")
    if traversal_external.read_text(encoding="utf-8") != "fora da raiz gerenciada\n":
        fail("verificação pós-publicação alterou arquivo externo alcançado por traversal")

    linked_parent_document = root / "linked-parent-document-root"
    linked_parent_theme = linked_parent_document / "wp-content/themes/kadence-child"
    linked_parent_theme.mkdir(parents=True)
    external_directory = root / "external-directory"
    external_directory.mkdir()
    external_nested_code = external_directory / "index.php"
    external_nested_code.write_text("código externo por diretório\n", encoding="utf-8")
    (linked_parent_theme / "linked-directory").symlink_to(external_directory)
    linked_parent_manifest = root / "manifest.linked-parent.sha256"
    linked_parent_manifest.write_text(
        f"{hashlib.sha256(external_nested_code.read_bytes()).hexdigest()}  "
        "theme/linked-directory/index.php\n",
        encoding="utf-8",
    )
    linked_parent_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(
        script,
        linked_parent_document,
        linked_parent_manifest,
    )
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou diretório gerenciado intermediário como symlink")
    if external_nested_code.read_text(encoding="utf-8") != "código externo por diretório\n":
        fail("verificação pós-publicação alterou arquivo externo por diretório symlink")

    broken_manifest = root / "manifest.broken.sha256"
    broken_manifest.symlink_to(root / "missing-manifest-target")
    result = run_expected_manifest_verifier(script, document_root, broken_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou manifesto esperado como symlink quebrado")

    malformed_manifest = root / "manifest.malformed.sha256"
    malformed_manifest.write_text("not-a-sha256  theme/index.php\n", encoding="utf-8")
    malformed_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(script, document_root, malformed_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou checksum malformado")

    mismatched_manifest = root / "manifest.mismatched.sha256"
    mismatched_manifest.write_text(f"{'0' * 64}  theme/index.php\n", encoding="utf-8")
    mismatched_manifest.chmod(0o600)
    result = run_expected_manifest_verifier(script, document_root, mismatched_manifest)
    if result.returncode == 0:
        fail("verificação pós-publicação aceitou arquivo com hash divergente")


def test_rollback(rollback_script: pathlib.Path, cleanup_script: pathlib.Path, temp: pathlib.Path) -> None:
    success = RollbackFixture(temp / "success", db_owner=RUN_ID, code_owner=RUN_ID)
    result = success.run(rollback_script)
    if result.returncode != 0:
        fail(f"rollback seletivo válido falhou: {result.stderr[-500:]}")
    if not success.code_is_old() or success.code_is_new():
        fail("rollback seletivo válido não restaurou exatamente o código antigo")
    if (success.operation_lock / "db-mutation-started").exists():
        fail("rollback seletivo válido não removeu seu marcador de banco")
    if not (success.operation_lock / "code-mutation-started").exists():
        fail("marcador de código foi removido antes dos smokes externo/HTTP")
    php_log = success.php_log.read_text(encoding="utf-8")
    for option in (
        f"--mutation-marker={success.operation_lock / 'db-mutation-started'}",
        f"--mutation-owner={RUN_ID}",
        f"--migration-lock={success.migration_lock}",
    ):
        if option not in php_log:
            fail(f"rollback real não encaminhou {option}")

    foreign_cleanup_lock = temp / "cleanup-foreign/.uonix-operation.lock"
    foreign_cleanup_lock.mkdir(parents=True)
    foreign_cleanup_owner = foreign_cleanup_lock / "owner"
    foreign_cleanup_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    foreign_cleanup_owner.chmod(0o600)
    foreign_cleanup_marker = foreign_cleanup_lock / "code-mutation-started"
    foreign_cleanup_marker.write_text("other-run\n", encoding="utf-8")
    foreign_cleanup_marker.chmod(0o600)
    foreign_cleanup = subprocess.run(
        ["bash", str(cleanup_script), str(foreign_cleanup_lock), RUN_ID],
        env=success.env,
        text=True,
        capture_output=True,
        check=False,
    )
    if (
        foreign_cleanup.returncode == 0
        or not foreign_cleanup_marker.exists()
        or foreign_cleanup_marker.read_text(encoding="utf-8") != "other-run\n"
    ):
        fail("cleanup pós-smoke consumiu marcador de código estrangeiro")

    public_cleanup_lock = temp / "cleanup-public/.uonix-operation.lock"
    public_cleanup_lock.mkdir(parents=True)
    public_cleanup_owner = public_cleanup_lock / "owner"
    public_cleanup_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    public_cleanup_owner.chmod(0o600)
    public_cleanup_marker = public_cleanup_lock / "code-mutation-started"
    public_cleanup_marker.write_text(RUN_ID + "\n", encoding="utf-8")
    public_cleanup_marker.chmod(0o644)
    public_cleanup = subprocess.run(
        ["bash", str(cleanup_script), str(public_cleanup_lock), RUN_ID],
        env=success.env,
        text=True,
        capture_output=True,
        check=False,
    )
    if public_cleanup.returncode == 0 or not public_cleanup_marker.exists():
        fail("cleanup pós-smoke consumiu marcador sem permissão 0600")

    linked_cleanup_lock = temp / "cleanup-linked/.uonix-operation.lock"
    linked_cleanup_lock.mkdir(parents=True)
    linked_cleanup_owner = linked_cleanup_lock / "owner"
    linked_cleanup_owner.write_text(RUN_ID + "\n", encoding="utf-8")
    linked_cleanup_owner.chmod(0o600)
    linked_cleanup_target = temp / "cleanup-external-marker"
    linked_cleanup_target.write_text(RUN_ID + "\n", encoding="utf-8")
    linked_cleanup_target.chmod(0o600)
    (linked_cleanup_lock / "code-mutation-started").symlink_to(linked_cleanup_target)
    linked_cleanup = subprocess.run(
        ["bash", str(cleanup_script), str(linked_cleanup_lock), RUN_ID],
        env=success.env,
        text=True,
        capture_output=True,
        check=False,
    )
    if linked_cleanup.returncode == 0:
        fail("cleanup pós-smoke aceitou marcador symlink de alvo válido")
    if linked_cleanup_target.read_text(encoding="utf-8") != RUN_ID + "\n":
        fail("cleanup pós-smoke alterou alvo externo do marcador symlink")

    cleanup = subprocess.run(
        ["bash", str(cleanup_script), str(success.operation_lock), RUN_ID],
        env=success.env,
        text=True,
        capture_output=True,
        check=False,
    )
    if cleanup.returncode != 0 or (success.operation_lock / "code-mutation-started").exists():
        fail("heredoc pós-smoke não removeu o marcador de código da própria execução")
    assert_no_mysql(success, "success")

    failed = RollbackFixture(temp / "failed", db_owner=RUN_ID, code_owner=RUN_ID, rollback_status=9)
    result = failed.run(rollback_script)
    if result.returncode == 0:
        fail("falha do rollback seletivo foi convertida em sucesso")
    if not failed.code_is_new() or failed.code_is_old():
        fail("falha seletiva trocou código e criou incompatibilidade código/banco")
    for marker in ("db-mutation-started", "code-mutation-started"):
        if not (failed.operation_lock / marker).exists():
            fail(f"falha seletiva removeu marcador fail-closed: {marker}")
    assert_no_mysql(failed, "failed")

    corrupt_backup = RollbackFixture(
        temp / "corrupt-backup",
        db_owner=None,
        code_owner=RUN_ID,
        corrupt_backup_manifest=True,
    )
    result = corrupt_backup.run(rollback_script)
    if result.returncode == 0:
        fail("backup de código com hash divergente foi aceito")
    if not corrupt_backup.code_is_new() or corrupt_backup.code_is_old():
        fail("backup de código corrompido apagou/trocou o código corrente")
    if not (corrupt_backup.operation_lock / "code-mutation-started").exists():
        fail("backup de código corrompido removeu o marcador fail-closed")
    assert_no_mysql(corrupt_backup, "corrupt-backup")

    public_manifest = RollbackFixture(
        temp / "public-manifest",
        db_owner=None,
        code_owner=RUN_ID,
    )
    public_manifest_path = public_manifest.backup_dir / "manifest.backup.sha256"
    public_manifest_path.chmod(0o644)
    result = public_manifest.run(rollback_script)
    if result.returncode == 0:
        fail("rollback aceitou manifesto do backup sem permissão 0600")
    if not public_manifest.code_is_new() or public_manifest.code_is_old():
        fail("manifesto público permitiu substituir o código corrente")
    if not (public_manifest.operation_lock / "code-mutation-started").exists():
        fail("manifesto público removeu o marcador fail-closed")
    assert_no_mysql(public_manifest, "public-manifest")

    symlink_backup_entry = RollbackFixture(
        temp / "symlink-backup-entry",
        db_owner=None,
        code_owner=RUN_ID,
    )
    backed_file = symlink_backup_entry.backup_dir / "managed/themes/kadence-child/old.txt"
    backed_file.unlink()
    outside_backed_file = temp / "external-backed-file"
    outside_backed_file.write_text("old\n", encoding="utf-8")
    backed_file.symlink_to(outside_backed_file)
    result = symlink_backup_entry.run(rollback_script)
    if result.returncode == 0:
        fail("rollback aceitou symlink dentro do backup mesmo com hash/conteúdo válidos")
    if not symlink_backup_entry.code_is_new() or symlink_backup_entry.code_is_old():
        fail("symlink no backup permitiu substituir o código corrente")
    if outside_backed_file.read_text(encoding="utf-8") != "old\n":
        fail("rollback alterou alvo externo do symlink no backup")
    assert_no_mysql(symlink_backup_entry, "symlink-backup-entry")

    corrupt_restore = RollbackFixture(
        temp / "corrupt-restore",
        db_owner=None,
        code_owner=RUN_ID,
        corrupt_restored_code=True,
    )
    result = corrupt_restore.run(rollback_script)
    if result.returncode == 0:
        fail("corrupção após a cópia escapou da validação pós-restauração")
    if not (corrupt_restore.operation_lock / "code-mutation-started").exists():
        fail("corrupção pós-cópia removeu marcador/lock fail-closed")
    if "código restaurado diverge" not in result.stderr:
        fail("corrupção pós-cópia não foi atribuída ao manifesto restaurado")
    assert_no_mysql(corrupt_restore, "corrupt-restore")

    active = RollbackFixture(
        temp / "active",
        db_owner=RUN_ID,
        code_owner=RUN_ID,
        active_migration_owner=RUN_ID,
    )
    result = active.run(rollback_script)
    if result.returncode == 0 or not active.code_is_new():
        fail("rollback escreveu enquanto o processo de migração permanecia ativo")
    if active.php_log.exists() and " --rollback " in f" {active.php_log.read_text(encoding='utf-8')} ":
        fail("rollback chamou WP-CLI apesar do lock ativo")
    assert_no_mysql(active, "active")

    public_operation_owner = RollbackFixture(
        temp / "public-operation-owner",
        db_owner=RUN_ID,
        code_owner=RUN_ID,
    )
    (public_operation_owner.operation_lock / "owner").chmod(0o644)
    result = public_operation_owner.run(rollback_script)
    if result.returncode == 0 or not public_operation_owner.code_is_new():
        fail("rollback aceitou owner da operação sem permissão 0600")
    if public_operation_owner.php_log.exists():
        fail("rollback chamou WP-CLI com owner da operação público")
    assert_no_mysql(public_operation_owner, "public-operation-owner")

    foreign_operation_owner = RollbackFixture(
        temp / "foreign-operation-owner",
        db_owner=RUN_ID,
        code_owner=RUN_ID,
    )
    (foreign_operation_owner.operation_lock / "owner").write_text("other-run\n", encoding="utf-8")
    result = foreign_operation_owner.run(rollback_script)
    if result.returncode == 0 or not foreign_operation_owner.code_is_new():
        fail("rollback aceitou owner da operação pertencente a outra execução")
    if foreign_operation_owner.php_log.exists():
        fail("rollback chamou WP-CLI com owner da operação estrangeiro")
    assert_no_mysql(foreign_operation_owner, "foreign-operation-owner")

    linked_operation_owner = RollbackFixture(
        temp / "linked-operation-owner",
        db_owner=RUN_ID,
        code_owner=RUN_ID,
    )
    linked_owner_target = temp / "rollback-external-operation-owner"
    linked_owner_target.write_text(RUN_ID + "\n", encoding="utf-8")
    linked_owner_target.chmod(0o600)
    (linked_operation_owner.operation_lock / "owner").unlink()
    (linked_operation_owner.operation_lock / "owner").symlink_to(linked_owner_target)
    result = linked_operation_owner.run(rollback_script)
    if result.returncode == 0 or not linked_operation_owner.code_is_new():
        fail("rollback aceitou owner da operação como symlink de alvo válido")
    if linked_operation_owner.php_log.exists():
        fail("rollback chamou WP-CLI com owner da operação symlink")
    assert_no_mysql(linked_operation_owner, "linked-operation-owner")

    public_db_marker = RollbackFixture(
        temp / "public-db-marker",
        db_owner=RUN_ID,
        code_owner=RUN_ID,
    )
    (public_db_marker.operation_lock / "db-mutation-started").chmod(0o644)
    result = public_db_marker.run(rollback_script)
    if result.returncode == 0 or not public_db_marker.code_is_new():
        fail("rollback aceitou marcador de banco sem permissão 0600")
    if public_db_marker.php_log.exists():
        fail("rollback chamou WP-CLI com marcador de banco público")
    assert_no_mysql(public_db_marker, "public-db-marker")

    public_code_marker = RollbackFixture(
        temp / "public-code-marker",
        db_owner=None,
        code_owner=RUN_ID,
    )
    (public_code_marker.operation_lock / "code-mutation-started").chmod(0o644)
    result = public_code_marker.run(rollback_script)
    if result.returncode == 0 or not public_code_marker.code_is_new():
        fail("rollback aceitou marcador de código sem permissão 0600")
    assert_no_mysql(public_code_marker, "public-code-marker")

    symlink_db_marker = RollbackFixture(
        temp / "symlink-db-marker",
        db_owner=None,
        code_owner=RUN_ID,
    )
    outside_db_marker = temp / "rollback-missing-db-marker-target"
    (symlink_db_marker.operation_lock / "db-mutation-started").symlink_to(outside_db_marker)
    result = symlink_db_marker.run(rollback_script)
    if result.returncode == 0 or not symlink_db_marker.code_is_new():
        fail("rollback aceitou marcador de banco como symlink pendurado")
    if outside_db_marker.exists():
        fail("rollback criou ou alterou alvo externo do marcador symlink")
    if symlink_db_marker.php_log.exists():
        fail("rollback chamou WP-CLI com marcador de banco symlink")
    assert_no_mysql(symlink_db_marker, "symlink-db-marker")

    linked_db_marker = RollbackFixture(
        temp / "linked-db-marker",
        db_owner=None,
        code_owner=RUN_ID,
    )
    outside_linked_db = temp / "rollback-external-db-marker"
    outside_linked_db.write_text(RUN_ID + "\n", encoding="utf-8")
    outside_linked_db.chmod(0o600)
    (linked_db_marker.operation_lock / "db-mutation-started").symlink_to(outside_linked_db)
    result = linked_db_marker.run(rollback_script)
    if result.returncode == 0 or not linked_db_marker.code_is_new():
        fail("rollback aceitou marcador symlink cujo alvo satisfaz tipo, modo e owner")
    if outside_linked_db.read_text(encoding="utf-8") != RUN_ID + "\n":
        fail("rollback alterou alvo externo do marcador symlink válido")
    if linked_db_marker.php_log.exists():
        fail("rollback chamou WP-CLI com marcador symlink de alvo válido")
    assert_no_mysql(linked_db_marker, "linked-db-marker")

    foreign_db = RollbackFixture(temp / "foreign-db", db_owner="other-run", code_owner=RUN_ID)
    result = foreign_db.run(rollback_script)
    if result.returncode == 0 or not foreign_db.code_is_new():
        fail("rollback consumiu marcador de banco pertencente a outra execução")
    assert_no_mysql(foreign_db, "foreign-db")

    foreign_code = RollbackFixture(temp / "foreign-code", db_owner=None, code_owner="other-run")
    result = foreign_code.run(rollback_script)
    if result.returncode == 0 or not foreign_code.code_is_new():
        fail("rollback consumiu marcador de código pertencente a outra execução")
    assert_no_mysql(foreign_code, "foreign-code")


def make_release_lock(
    root: pathlib.Path,
    *,
    marker_owner: str | None = None,
    lock_owner: str = RUN_ID,
    owner_mode: int = 0o600,
    marker_mode: int = 0o600,
) -> pathlib.Path:
    lock = root / ".uonix-operation.lock"
    lock.mkdir(parents=True)
    owner = lock / "owner"
    owner.write_text(lock_owner + "\n", encoding="utf-8")
    owner.chmod(owner_mode)
    if marker_owner is not None:
        marker = lock / "db-mutation-started"
        marker.write_text(marker_owner + "\n", encoding="utf-8")
        marker.chmod(marker_mode)
    return lock


def run_release(script: pathlib.Path, lock: pathlib.Path, status: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(script), str(lock), RUN_ID, "true", status],
        text=True,
        capture_output=True,
        check=False,
    )


def test_release(release_script: pathlib.Path, temp: pathlib.Path) -> None:
    success_lock = make_release_lock(temp / "release-success", marker_owner=RUN_ID)
    result = run_release(release_script, success_lock, "success")
    if result.returncode != 0 or success_lock.exists():
        fail("release de sucesso não removeu lock/marcador da própria execução")

    foreign_lock = make_release_lock(temp / "release-foreign", marker_owner="other-run")
    result = run_release(release_script, foreign_lock, "success")
    if result.returncode == 0 or not foreign_lock.exists():
        fail("release de sucesso apagou marcador pertencente a outra execução")

    foreign_owner_lock = make_release_lock(
        temp / "release-foreign-owner",
        lock_owner="other-run",
    )
    result = run_release(release_script, foreign_owner_lock, "failure")
    if result.returncode == 0 or not foreign_owner_lock.exists():
        fail("release aceitou owner do lock pertencente a outra execução")

    public_owner_lock = make_release_lock(
        temp / "release-public-owner",
        owner_mode=0o644,
    )
    result = run_release(release_script, public_owner_lock, "failure")
    if result.returncode == 0 or not public_owner_lock.exists():
        fail("release aceitou owner do lock sem permissão 0600")

    public_marker_lock = make_release_lock(
        temp / "release-public-marker",
        marker_owner=RUN_ID,
        marker_mode=0o644,
    )
    result = run_release(release_script, public_marker_lock, "success")
    if result.returncode == 0 or not public_marker_lock.exists():
        fail("release aceitou marcador sem permissão 0600")

    symlink_marker_lock = make_release_lock(temp / "release-symlink-marker")
    outside_marker = temp / "release-missing-marker-target"
    (symlink_marker_lock / "db-mutation-started").symlink_to(outside_marker)
    result = run_release(release_script, symlink_marker_lock, "success")
    if result.returncode == 0 or not symlink_marker_lock.exists():
        fail("release aceitou marcador como symlink pendurado")
    if outside_marker.exists():
        fail("release criou ou alterou alvo externo de symlink pendurado")

    linked_marker_lock = make_release_lock(temp / "release-linked-marker")
    linked_outside_marker = temp / "release-external-marker"
    linked_outside_marker.write_text(RUN_ID + "\n", encoding="utf-8")
    linked_outside_marker.chmod(0o600)
    (linked_marker_lock / "db-mutation-started").symlink_to(linked_outside_marker)
    result = run_release(release_script, linked_marker_lock, "success")
    if result.returncode == 0 or not linked_marker_lock.exists():
        fail("release aceitou marcador symlink cujo alvo satisfaz tipo, modo e owner")
    if linked_outside_marker.read_text(encoding="utf-8") != RUN_ID + "\n":
        fail("release alterou alvo externo do marcador symlink válido")

    failed_lock = make_release_lock(temp / "release-failed", marker_owner=RUN_ID)
    result = run_release(release_script, failed_lock, "failure")
    if result.returncode == 0 or not failed_lock.exists():
        fail("release após falha abriu lock apesar de marcador pendente")

    clean_failed_lock = make_release_lock(temp / "release-clean")
    result = run_release(release_script, clean_failed_lock, "failure")
    if result.returncode != 0 or clean_failed_lock.exists():
        fail("release após rollback limpo não removeu lock sem marcadores")


def main() -> None:
    document = WORKFLOW.read_text(encoding="utf-8")
    acquire_blocks = remote_blocks(named_step_run(document, "Acquire exclusive production lock"))
    backup_blocks = remote_blocks(named_step_run(document, "Back up managed remote paths"))
    publish_run = named_step_run(document, "Publish only managed paths and verify manifest")
    publish_blocks = remote_blocks(publish_run)
    allowlist_prelude = publish_allowlist_prelude(publish_run)
    rollback_blocks = remote_blocks(named_step_run(document, "Roll back managed code after failure"))
    release_blocks = remote_blocks(named_step_run(document, "Release exclusive production lock"))
    if len(acquire_blocks) != 1:
        fail(f"esperado 1 heredoc de aquisição, encontrados {len(acquire_blocks)}")
    if len(backup_blocks) != 1:
        fail(f"esperado 1 heredoc de backup, encontrados {len(backup_blocks)}")
    if len(rollback_blocks) != 2:
        fail(f"esperados 2 heredocs de rollback, encontrados {len(rollback_blocks)}")
    if len(release_blocks) != 1:
        fail(f"esperado 1 heredoc de release, encontrados {len(release_blocks)}")
    if len(publish_blocks) != 4:
        fail(f"esperados 4 heredocs de publicação, encontrados {len(publish_blocks)}")

    with tempfile.TemporaryDirectory(prefix="uonix-production-rollback-runtime-") as tmp_value:
        temp = pathlib.Path(tmp_value)
        acquire_script = temp / "acquire.sh"
        backup_script = temp / "backup.sh"
        rollback_script = temp / "rollback.sh"
        cleanup_script = temp / "cleanup.sh"
        release_script = temp / "release.sh"
        publish_marker_script = temp / "publish-marker.sh"
        publish_allowlist_script = temp / "publish-allowlist.sh"
        publish_module_prune_script = temp / "publish-module-prune.sh"
        expected_manifest_reservation_script = temp / "expected-manifest-reservation.sh"
        expected_manifest_script = temp / "expected-manifest.sh"
        write_executable(acquire_script, acquire_blocks[0])
        write_executable(backup_script, backup_blocks[0])
        write_executable(publish_marker_script, publish_blocks[0])
        write_executable(publish_allowlist_script, allowlist_prelude)
        write_executable(publish_module_prune_script, publish_blocks[1])
        write_executable(expected_manifest_reservation_script, publish_blocks[2])
        write_executable(expected_manifest_script, publish_blocks[3])
        write_executable(rollback_script, rollback_blocks[0])
        write_executable(cleanup_script, rollback_blocks[1])
        write_executable(release_script, release_blocks[0])
        test_acquire_lock(acquire_script, temp)
        test_backup_manifest(backup_script, temp)
        test_publish_allowlist_prelude(publish_allowlist_script, temp)
        test_publish_marker(publish_marker_script, temp)
        test_module_prune_allowlist(publish_module_prune_script, temp)
        test_expected_manifest_reservation(expected_manifest_reservation_script, temp)
        test_expected_manifest_verification(expected_manifest_script, temp)
        test_rollback(rollback_script, cleanup_script, temp)
        test_release(release_script, temp)

    print("PASS: ciclo real de aquisição, backup, rollback e release preserva owner, manifesto, lock, código e pedidos fail-closed.")


if __name__ == "__main__":
    main()
