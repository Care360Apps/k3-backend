#!/usr/bin/env python3
"""No-network regression checks for the guarded Care360 staging smoke tool."""
import contextlib
import importlib.util
import io
import os
from pathlib import Path
import unittest
from unittest.mock import patch

src = Path(__file__).with_name("k3_staging_login_smoke.py")
spec = importlib.util.spec_from_file_location("k3_staging_login_smoke", src)
target = importlib.util.module_from_spec(spec)
spec.loader.exec_module(target)

BASE = "https://staging.care360apps.example.org/api/"
HOST = "staging.care360apps.example.org"


class FakeClient:
    def __init__(self, fail_role=None):
        self.calls = []
        self.fail_role = fail_role

    def post_login(self, endpoint, email, password):
        self.calls.append((endpoint, email))
        if self.fail_role and email.startswith(self.fail_role + "@"):
            return {"success": False, "msg": "private server details"}
        return {"success": True, "data": {"token": "synthetic-temporary-token"}}


class SmokeToolTest(unittest.TestCase):
    def test_strict_approved_staging_host(self):
        self.assertEqual(target.validate_target(BASE, HOST), BASE)
        for url, host in [
            ("http://staging.care360apps.example.org/api/", HOST),
            ("https://care360apps.example.org/api/", "care360apps.example.org"),
            ("https://notstaging.care360apps.example.org/api/", "notstaging.care360apps.example.org"),
            (BASE, "production.care360apps.example.org"),
            ("https://staging.care360apps.example.org/api?x=1", HOST),
            ("https://staging.care360apps.example.org/api/", "staging.care360apps.example.org"),
            ("https://127.0.0.1/api/", "127.0.0.1"),
            ("https://staging.invalid/api/", "staging.invalid"),
        ]:
            with self.subTest(url=url), self.assertRaises(ValueError):
                target.validate_target(url, host)

    def test_no_network_by_default(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), patch.object(
            target, "HttpsJsonClient", side_effect=AssertionError("must not connect")
        ):
            status = target.main(["--base-url", BASE, "--approved-host", HOST])
        self.assertEqual(status, 0)
        self.assertIn("no network requests", output.getvalue())

    def test_requires_opt_in_and_credential(self):
        args = ["--base-url", BASE, "--approved-host", HOST, "--execute"]
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(target.main(args), 2)
        with patch.dict(os.environ, {"K3_STAGING_PROBE_ACK": target.ACKNOWLEDGEMENT}, clear=True):
            self.assertEqual(target.main(args), 2)

    def test_login_contract_patient_doctor_provider(self):
        client = FakeClient()
        result = target.smoke_logins(BASE, "synthetic-test-password", client)
        self.assertTrue(all(row[1] for row in result))
        self.assertEqual([u for u, _ in client.calls], [
            BASE + "login", BASE + "doctor_login", BASE + "provider_login"
        ])
        self.assertEqual(len(client.calls), 3)

    def test_failed_provider_does_not_print_sensitive_api_data(self):
        client = FakeClient(fail_role="provider")
        result = target.smoke_logins(BASE, "synthetic-test-password", client)
        self.assertEqual(result[-1][0], "provider")
        self.assertFalse(result[-1][1])
        self.assertNotIn("private server details", result[-1][2])

    def test_success_requires_auth_token(self):
        for response in [
            {"success": False, "data": {"token": "secret-token"}},
            {"success": True, "data": {}},
            {"success": True, "data": {"token": "short"}},
            {"success": True},
        ]:
            with self.subTest(response=response), self.assertRaises(RuntimeError):
                target.check_auth_contract(response)
        target.check_auth_contract({"success": True, "data": {"token": "long-fake-token"}})


if __name__ == "__main__":
    unittest.main()
