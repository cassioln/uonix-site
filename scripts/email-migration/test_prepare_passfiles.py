from __future__ import annotations

import os
import tempfile
import unittest
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

from prepare_passfiles import EXPECTED_LOCALS, main, parse_manifest, write_passfiles


VALID_MANIFEST = """site@site.uonix.com.br
pilot-secret

administrativo@uonix.com.br
admin-secret

financeiro@uonix.com.br
finance-secret

marketing@uonix.com.br
marketing-secret

atendimento@uonix.com.br
support-secret

vendas01@uonix.com.br
sales-secret

contato@uonix.com.br
contact-secret

emails sao iguais muda apenas o dominio = @site.uonix.com.br com a mesma senha

servidor email destino e origem
IMAP: email-ssl.com.br (porta 993)
"""


class PreparePassfilesTest(unittest.TestCase):
    def test_parses_expected_accounts_and_writes_mode_600_passfiles(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "manifest.txt"
            output = root / "passfiles"
            source.write_text(VALID_MANIFEST, encoding="utf-8")

            records = parse_manifest(source)
            created = write_passfiles(records, output)

            self.assertEqual(set(EXPECTED_LOCALS), set(records))
            self.assertEqual(7, len(created))
            for local, password in records.items():
                passfile = output / f"{local}.pass"
                self.assertEqual(password, passfile.read_text(encoding="utf-8"))
                self.assertEqual(0o600, os.stat(passfile).st_mode & 0o777)

    def test_rejects_manifest_with_missing_mailbox(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            source.write_text(
                VALID_MANIFEST.replace("contato@uonix.com.br\ncontact-secret\n\n", ""),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "missing mailboxes: contato"):
                parse_manifest(source)

    def test_rejects_invalid_line_before_marker(self) -> None:
        """O parser é fail-closed: linha que não é e-mail nem senha aborta.

        Sem esta asserção, trocar o `raise` de prepare_passfiles.py:43-44 por
        `continue` mantinha a suíte verde — comprovado por falsificação. Um
        manifest malformado passaria a ser silenciosamente aceito, e passfiles
        seriam gerados a partir de pares deslocados.
        """
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            source.write_text(
                "isto nao e um endereco de email\n" + VALID_MANIFEST,
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "unexpected manifest entry"):
                parse_manifest(source)

    def test_rejects_manifest_without_marker(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            source.write_text(
                VALID_MANIFEST.split("emails sao iguais")[0], encoding="utf-8"
            )

            with self.assertRaisesRegex(ValueError, "marker not found"):
                parse_manifest(source)

    def test_rejects_unexpected_mailbox_local_part(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            source.write_text(
                "intruso@uonix.com.br\nintruder-secret\n\n" + VALID_MANIFEST,
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "unexpected mailbox local part"):
                parse_manifest(source)

    def test_rejects_mailbox_without_password(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            # Endereço na última linha: não há senha depois dele. O parser pula
            # linhas em branco à procura da senha, então esgotar o arquivo é o
            # único caminho para este ramo.
            source.write_text("site@site.uonix.com.br\n", encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "missing password for mailbox"):
                parse_manifest(source)

    def test_rejects_empty_password(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            # Só espaços após o endereço: o strip esvazia e o arquivo termina.
            source.write_text("site@site.uonix.com.br\n   \n", encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "password for mailbox"):
                parse_manifest(source)

    def test_rejects_duplicate_mailbox(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            duplicate = "site@uonix.com.br\nsecond-pilot-secret\n\n"
            source.write_text(
                VALID_MANIFEST.replace(
                    "emails sao iguais", duplicate + "emails sao iguais"
                ),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "duplicate mailbox: site"):
                parse_manifest(source)

    def test_refuses_to_overwrite_existing_passfiles(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "manifest.txt"
            output = root / "passfiles"
            source.write_text(VALID_MANIFEST, encoding="utf-8")
            records = parse_manifest(source)
            write_passfiles(records, output)

            with self.assertRaises(FileExistsError):
                write_passfiles(records, output)

    def test_cli_never_prints_password_values(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "manifest.txt"
            output = root / "passfiles"
            source.write_text(VALID_MANIFEST, encoding="utf-8")
            stdout = StringIO()

            with redirect_stdout(stdout):
                result = main(["--source", str(source), "--output", str(output)])

            self.assertEqual(0, result)
            rendered = stdout.getvalue()
            self.assertIn("site", rendered)
            self.assertNotIn("secret", rendered)


if __name__ == "__main__":
    unittest.main()
