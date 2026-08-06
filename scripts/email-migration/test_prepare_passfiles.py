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

            with self.assertRaisesRegex(ValueError, r"^missing password for mailbox"):
                parse_manifest(source)

    def test_blank_lines_before_password_are_skipped(self) -> None:
        """Linhas em branco entre endereço e senha não quebram o parse.

        Este teste substitui um `test_rejects_empty_password` que era falso: ele
        afirmava cobrir o ramo `empty password for mailbox` mas disparava
        `missing password`, e o regex frouxo `"password for mailbox"` — que casa
        os dois — escondia a divergência.

        O ramo `empty password` de prepare_passfiles.py:59-60 é INALCANÇÁVEL: o
        laço das linhas 52-53 consome toda linha em branco antes da leitura, então
        `password` só pode ser vazio se o arquivo tiver esgotado — e nesse caso o
        `raise` de `missing password` já disparou. É código morto defensivo, não
        um caminho testável. O que dá para verificar é o comportamento real: o
        salto das linhas em branco.
        """
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "manifest.txt"
            source.write_text(
                VALID_MANIFEST.replace(
                    "site@site.uonix.com.br\npilot-secret",
                    "site@site.uonix.com.br\n\n   \npilot-secret",
                ),
                encoding="utf-8",
            )

            records = parse_manifest(source)

            self.assertEqual(records["site"], "pilot-secret")
            self.assertEqual(set(records), set(EXPECTED_LOCALS))

    def test_write_passfiles_rejects_unexpected_record_set(self) -> None:
        """`write_passfiles` valida a própria entrada, não confia no chamador.

        Falsificação mostrou que este ramo (prepare_passfiles.py:73-74) não tinha
        cobertura: neutralizá-lo mantinha a suíte verde. É a única guarda entre um
        conjunto de contas errado e a escrita de passfiles no disco — se alguém
        chamar a função com um dicionário parcial, sem esta validação os arquivos
        seriam gravados incompletos e o imapsync migraria caixas de menos.
        """
        with tempfile.TemporaryDirectory() as tmp:
            output = Path(tmp) / "passfiles"

            with self.assertRaisesRegex(ValueError, "exactly the expected mailboxes"):
                write_passfiles({"site": "only-one"}, output)

            extra = {local: "secret" for local in EXPECTED_LOCALS}
            extra["intruso"] = "secret"
            with self.assertRaisesRegex(ValueError, "exactly the expected mailboxes"):
                write_passfiles(extra, output)

            # Nada deve ter sido escrito em nenhuma das duas tentativas.
            self.assertFalse(output.exists())

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
