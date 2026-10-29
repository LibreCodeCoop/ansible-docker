"""Regression tests for application-specific static credential detection."""

import importlib.util
import unittest
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[2] / "scripts/check_secret_literals.py"
SPEC = importlib.util.spec_from_file_location("secret_literals", SCRIPT)
guard = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(guard)


class SecretLiteralTests(unittest.TestCase):
    talk_path = "roles/nextcloud-talk/templates/server.conf.j2"
    pgadmin_path = "roles/pgadmin/defaults/main.yml"

    def test_four_talk_fields_reject_literals(self):
        for field in ("hashkey", "blockkey", "internalsecret", "apikey"):
            with self.subTest(field=field):
                self.assertEqual(
                    guard.scan_file(self.talk_path, f"{field} = synthetic-value\n"),
                    [(1, field)],
                )

    def test_talk_fields_accept_simple_jinja_variables(self):
        text = (
            "[sessions]\n"
            "hashkey = {{ talk_sessions_hashkey }}\n"
            "blockkey = {{ talk_sessions_blockkey }}\n"
            "[clients]\n"
            "internalsecret = {{ talk_internal_secret }}\n"
            "[turn]\n"
            "apikey = {{ talk_turn_apikey }}\n"
        )
        self.assertEqual(guard.scan_file(self.talk_path, text), [])

    def test_quoted_jinja_variable_and_comment(self):
        self.assertEqual(
            guard.scan_file(
                self.pgadmin_path,
                'pgadmin_password: "{{ vault_pgadmin_password }}" # external\n',
            ),
            [],
        )

    def test_pgadmin_password_rejects_literal(self):
        self.assertEqual(
            guard.scan_file(
                self.pgadmin_path, "pgadmin_password: synthetic-example\n"
            ),
            [(1, "pgadmin_password")],
        )

    def test_pgadmin_password_rejects_yaml_block_scalar(self):
        self.assertEqual(
            guard.scan_file(self.pgadmin_path, "pgadmin_password: |\n  hidden\n"),
            [(1, "pgadmin_password")],
        )

    def test_pgadmin_allows_ansible_vault_tag(self):
        self.assertEqual(
            guard.scan_file(
                self.pgadmin_path, "pgadmin_password: !vault |\n  encrypted-block\n"
            ),
            [],
        )

    def test_jinja_literal_and_filter_are_not_whitelisted(self):
        for content in (
            "hashkey = {{ 'inline-literal' }}",
            "hashkey = {{ source | default('fallback-value') }}",
        ):
            with self.subTest(content=content):
                self.assertEqual(
                    guard.scan_file(self.talk_path, content),
                    [(1, "hashkey")],
                )

    def test_ignored_key_path_does_not_trigger(self):
        self.assertEqual(
            guard.scan_file(
                "roles/nextcloud-talk/templates/turnserver.conf.j2",
                "pkey=/path/to/private-key.pem\n",
            ),
            [],
        )

    def test_environment_file_detects_literals_and_allows_empty(self):
        file_path = "roles/onlyoffice/files/.env"
        self.assertEqual(
            guard.scan_file(file_path, "DB_PWD=\nJWT_SECRET=\n"),
            [],
        )
        self.assertEqual(
            guard.scan_file(file_path, "DB_PWD=synthetic-value\n"),
            [(1, "DB_PWD")],
        )

    def test_environment_file_accepts_external_reference(self):
        self.assertEqual(
            guard.scan_file(
                "roles/onlyoffice/files/.env",
                "JWT_SECRET=${JWT_SECRET}\n",
            ),
            [],
        )

    def test_env_jinja_template_rejects_static_pgadmin_password(self):
        self.assertEqual(
            guard.scan_file(
                "roles/postgres/templates/env.j2",
                "PGADMIN_DEFAULT_PASSWORD=synthetic-example\n",
            ),
            [(1, "PGADMIN_DEFAULT_PASSWORD")],
        )

    def test_no_sensitive_values_are_returned(self):
        findings = guard.scan_file(
            self.pgadmin_path, "pgadmin_password: synthetic-sample\n"
        )
        self.assertEqual(findings, [(1, "pgadmin_password")])
        self.assertNotIn("synthetic-sample", repr(findings))

    def test_comments_are_ignored(self):
        self.assertEqual(
            guard.scan_file(self.talk_path, "# hashkey=example\n"),
            [],
        )


if __name__ == "__main__":
    unittest.main()
