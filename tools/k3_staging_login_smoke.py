#!/usr/bin/env python3
"""Opt-in Care360 HTTPS staging login smoke check — synthetic accounts only.

Default is configuration-only, with NO network. The --execute switch requires
an explicit operator acknowledgement, an exact preapproved hostname and a
private synthetic password. Never use this against production.
"""
import argparse
import ipaddress
import json
import os
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener


ROLES = {
    "patient": ("/login", "patient@care360.example.test"),
    "doctor": ("/doctor_login", "doctor@care360.example.test"),
    "provider": ("/provider_login", "provider@care360.example.test"),
}
ACKNOWLEDGEMENT = "ISOLATED_SYNTHETIC_ONLY"
MAX_REPLY = 65536


def validate_target(base_url: str, approved_host: str) -> str:
    if not base_url or base_url != base_url.strip():
        raise ValueError("Missing/invalid staging base URL")
    url = urlsplit(base_url)
    host = (url.hostname or "").lower()
    approval = (approved_host or "").lower()
    if (url.scheme != "https" or url.netloc != host or url.path != "/api/"
            or url.query or url.fragment or url.username or url.password
            or not approval or approval != host
            or url.port not in (None, 443) or host.endswith(
                (".invalid", ".test", ".example", ".local", ".localhost"))):
        raise ValueError("URL must be the exact approved HTTPS staging host and /api/ base path")
    try:
        ipaddress.ip_address(host)
        raise ValueError("IP-address targets are not allowed")
    except ValueError as exc:
        if str(exc) == "IP-address targets are not allowed":
            raise
    # Only a distinct subdomain or a hyphen-delimited environment label.
    labels = host.split(".")
    if not any(part in ("staging", "sandbox", "test", "dev")
               for label in labels for part in label.split("-")):
        raise ValueError("Approved hostname must explicitly identify a staging environment")
    return base_url


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class HttpsJsonClient:
    def __init__(self):
        self.opener = build_opener(NoRedirect(), HTTPSHandler(context=ssl.create_default_context()))

    def post_login(self, endpoint: str, email: str, password: str) -> dict:
        body = json.dumps({"email": email, "password": password}).encode("utf-8")
        request = Request(
            endpoint, data=body, method="POST",
            headers={"Content-Type": "application/json", "Accept": "application/json",
                     "Cache-Control": "no-store", "User-Agent": "Care360-Staging-Smoke/1.0"},
        )
        try:
            with self.opener.open(request, timeout=12) as response:
                status = response.status
                raw = response.read(MAX_REPLY + 1)
        except HTTPError as exc:
            # No raw HTTP response/body is printed, as it may contain credentials.
            raise RuntimeError("HTTP rejected synthetic login (status %d)" % exc.code) from None
        except (URLError, TimeoutError, ssl.SSLError):
            raise RuntimeError("Staging HTTPS endpoint unavailable") from None
        if status != 200 or len(raw) > MAX_REPLY:
            raise RuntimeError("Invalid staging login HTTP response")
        try:
            result = json.loads(raw)
        except (ValueError, UnicodeDecodeError):
            raise RuntimeError("Invalid JSON login response") from None
        if not isinstance(result, dict):
            raise RuntimeError("Unexpected staging response shape")
        return result


def check_auth_contract(data: dict) -> None:
    if data.get("success") is not True:
        raise RuntimeError("Synthetic login was not accepted")
    user = data.get("data")
    if not isinstance(user, dict) or not isinstance(user.get("token"), str) or len(user["token"]) < 8:
        raise RuntimeError("Synthetic login returned no usable API token")
    # Never write/print tokens, user IDs or account details.


def smoke_logins(base_url: str, password: str, client) -> list:
    results = []
    for role, (route, email) in ROLES.items():
        try:
            result = client.post_login(base_url + route.lstrip("/"), email, password)
            check_auth_contract(result)
            results.append((role, True, ""))
        except RuntimeError as exc:
            results.append((role, False, str(exc)))
    return results


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--approved-host", required=True)
    parser.add_argument("--execute", action="store_true",
                        help="Explicitly send three synthetic login requests")
    args = parser.parse_args(argv)
    try:
        target = validate_target(args.base_url, args.approved_host)
    except ValueError as exc:
        print("BLOCKED: " + str(exc), file=sys.stderr)
        return 2
    if not args.execute:
        print("PASS: approved staging URL shape verified; no network requests were made.")
        return 0
    if os.environ.get("K3_STAGING_PROBE_ACK") != ACKNOWLEDGEMENT:
        print("BLOCKED: explicit synthetic-only acknowledgement is required", file=sys.stderr)
        return 2
    secret = os.environ.get("K3_SYNTHETIC_PASSWORD", "")
    if len(secret) < 16:
        print("BLOCKED: a private 16+ character synthetic password is required", file=sys.stderr)
        return 2
    results = smoke_logins(target, secret, HttpsJsonClient())
    for role, ok, message in results:
        print("%s: %s%s" % (role, "PASS" if ok else "FAIL", "" if ok else " (" + message + ")"))
    return 0 if all(ok for _, ok, _ in results) else 1


if __name__ == "__main__":
    raise SystemExit(main())
