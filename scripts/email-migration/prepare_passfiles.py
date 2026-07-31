#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import re
import tempfile
from pathlib import Path
from typing import Mapping, Sequence

EXPECTED_LOCALS = (
    "site",
    "administrativo",
    "financeiro",
    "marketing",
    "atendimento",
    "vendas01",
    "contato",
)
EMAIL_RE = re.compile(
    r"^(?P<local>[A-Za-z0-9._%+-]+)@(?:(?:site)\.)?uonix\.com\.br$",
    re.IGNORECASE,
)
MARKER = "emails sao iguais"


def parse_manifest(source: Path) -> dict[str, str]:
    lines = source.read_text(encoding="utf-8").splitlines()
    records: dict[str, str] = {}
    index = 0
    marker_found = False

    while index < len(lines):
        line = lines[index].strip()
        index += 1
        if not line:
            continue
        if line.casefold().startswith(MARKER):
            marker_found = True
            break

        match = EMAIL_RE.fullmatch(line)
        if match is None:
            raise ValueError(f"unexpected manifest entry before marker: line {index}")

        local = match.group("local").casefold()
        if local not in EXPECTED_LOCALS:
            raise ValueError(f"unexpected mailbox local part: {local}")
        if local in records:
            raise ValueError(f"duplicate mailbox: {local}")

        while index < len(lines) and not lines[index].strip():
            index += 1
        if index >= len(lines):
            raise ValueError(f"missing password for mailbox: {local}")

        password = lines[index].strip()
        index += 1
        if not password:
            raise ValueError(f"empty password for mailbox: {local}")
        records[local] = password

    if not marker_found:
        raise ValueError("manifest marker not found")

    missing = sorted(set(EXPECTED_LOCALS) - set(records))
    if missing:
        raise ValueError(f"missing mailboxes: {', '.join(missing)}")
    return records


def write_passfiles(
    records: Mapping[str, str], output: Path, *, force: bool = False
) -> list[Path]:
    if set(records) != set(EXPECTED_LOCALS):
        raise ValueError("records must contain exactly the expected mailboxes")

    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(output, 0o700)
    targets = [output / f"{local}.pass" for local in EXPECTED_LOCALS]
    existing = [path.name for path in targets if path.exists()]
    if existing and not force:
        raise FileExistsError(f"passfiles already exist: {', '.join(existing)}")

    created: list[Path] = []
    try:
        for local, target in zip(EXPECTED_LOCALS, targets, strict=True):
            fd, temporary_name = tempfile.mkstemp(prefix=f".{local}.", dir=output)
            temporary = Path(temporary_name)
            try:
                os.fchmod(fd, 0o600)
                with os.fdopen(fd, "w", encoding="utf-8") as handle:
                    handle.write(records[local])
                    handle.flush()
                    os.fsync(handle.fileno())
                os.replace(temporary, target)
                os.chmod(target, 0o600)
                created.append(target)
            except BaseException:
                try:
                    os.close(fd)
                except OSError:
                    pass
                temporary.unlink(missing_ok=True)
                raise
    except BaseException:
        for path in created:
            path.unlink(missing_ok=True)
        raise

    return created


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Prepare private imapsync passfiles without printing secrets."
    )
    parser.add_argument("--source", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--force", action="store_true")
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    records = parse_manifest(args.source)
    created = write_passfiles(records, args.output, force=args.force)
    print("prepared passfiles:", ", ".join(path.stem for path in created))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
