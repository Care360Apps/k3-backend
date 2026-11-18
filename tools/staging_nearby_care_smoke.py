#!/usr/bin/env python3
"""Care360 Nearby Care isolated STAGING synthetic-professional smoke test.

No credentials or patient records are stored, echoed or written to disk.
This test intentionally starts and stops a real backend shift ONLY for an
explicitly authorized synthetic staging professional; never run on production.
Requires Python 3 standard library only.
"""
import json
import math
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone


class SmokeFailure(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        # Never send synthetic professional credentials to a redirect target.
        # A wrongly configured staging hostname must fail rather than follow.
        return None


STAGING_HTTP = urllib.request.build_opener(NoRedirect())


def checked_base(value, expected_host=None):
    parsed = urllib.parse.urlsplit(value.strip())
    host = (parsed.hostname or "").lower()
    is_staging = any(word in host for word in ("staging", "sandbox", "test", "localhost"))
    if parsed.scheme != "https" or not host or not is_staging:
        raise SmokeFailure("A verified HTTPS staging/sandbox/test hostname is required.")
    if expected_host is not None and host != expected_host.strip().lower():
        raise SmokeFailure("Staging host does not match operator's explicit allowlist.")
    if parsed.username or parsed.password or parsed.fragment or parsed.query:
        raise SmokeFailure("Staging base URL must have no userinfo, query or fragment.")
    # Never allow a production host disguised as a staging subdomain override.
    if host.startswith(("prod.", "production.")):
        raise SmokeFailure("Production-like hostname rejected.")
    path = parsed.path.rstrip("/")
    if not path.endswith("/api"):
        raise SmokeFailure("Staging base URL must end in /api/.")
    return urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, path + "/", "", ""))


def request(base, route, method="GET", body=None, token=None):
    url = urllib.parse.urljoin(base, route)
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = None
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, headers=headers, data=data, method=method)
    try:
        with STAGING_HTTP.open(req, timeout=18) as response:
            status, raw = response.status, response.read(256_000)
    except urllib.error.HTTPError as exc:
        status, raw = exc.code, exc.read(256_000)
    # The caller deliberately never prints JSON responses, error bodies or URLs.
    try:
        payload = json.loads(raw.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        payload = {}
    return status, payload


def expect(condition, phase):
    if not condition:
        raise SmokeFailure("Failed: " + phase)
    print("PASS: " + phase)


def search(base, lat, lng, radius):
    return request(base, "nearby-care", method="POST",
                   body={"lat": round(lat, 2), "lng": round(lng, 2),
                         "radius_km": radius, "limit": 50})


def entries(payload):
    if not isinstance(payload, dict) or payload.get("success") is not True:
        return None
    rows = payload.get("data")
    return rows if isinstance(rows, list) else None


def included(rows, professional_id):
    return any(isinstance(row, dict) and row.get("id") == professional_id
               and row.get("accepting_requests") is True
               for row in rows)


def parse_expiry(value):
    if not isinstance(value, str):
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def offline_self_test():
    for invalid in ("http://staging.example.test/api/",
                    "https://care360.example.org/api/",
                    "https://staging.example.test/other/",
                    "https://staging.example.test/api/?token=bad"):
        try:
            checked_base(invalid)
        except SmokeFailure:
            continue
        raise SmokeFailure("Offline origin restriction test failed")
    expect(checked_base("https://staging.example.test/api/",
                        expected_host="staging.example.test") ==
           "https://staging.example.test/api/", "staging URL allowlist")
    try:
        checked_base("https://staging.example.test/api/",
                     expected_host="another-staging.example.test")
    except SmokeFailure:
        pass
    else:
        raise SmokeFailure("Mismatched staging host should be denied")
    dummy_request = urllib.request.Request("https://staging.example.test/api/")
    expect(NoRedirect().redirect_request(
        dummy_request, None, 302, "Moved", {},
        "https://different-host.example.test/api/") is None,
        "never follow redirects with staging credentials")
    expect(included([{"id": 42, "accepting_requests": True}], 42),
           "synthetic inclusion checker")
    expect(not included([{"id": 42, "accepting_requests": False}], 42),
           "synthetic offline exclusion checker")
    expiry = parse_expiry("2026-11-07T05:00:00+00:00")
    expect(expiry is not None and expiry.tzinfo is not None,
           "timezone-qualified expiry parsing")
    print("OFFLINE SELF-TEST PASSED (no network or account changes)")


def main():
    if len(sys.argv) == 2 and sys.argv[1] == "--offline-self-test":
        offline_self_test()
        return
    if len(sys.argv) != 1:
        raise SmokeFailure("Usage: python3 tools/staging_nearby_care_smoke.py [--offline-self-test]")

    expected_host = os.getenv("K3_STAGING_EXPECTED_HOST", "").strip().lower()
    if not expected_host:
        raise SmokeFailure("Operator must provide an exact staging hostname allowlist.")
    base = checked_base(os.getenv("K3_STAGING_API_BASE_URL", ""),
                        expected_host=expected_host)
    token = os.getenv("K3_STAGING_SYNTHETIC_PROFESSIONAL_BEARER", "").strip()
    ack = os.getenv("K3_STAGING_NEARBY_CARE_SYNTHETIC_ACK", "").strip()
    if not token or ack != "YES_SYNTHETIC_STAGING_ONLY":
        raise SmokeFailure("Explicit synthetic account authorization and staging bearer are required.")

    try:
        lat = float(os.environ["K3_STAGING_TEST_SERVICE_LAT"])
        lng = float(os.environ["K3_STAGING_TEST_SERVICE_LNG"])
    except (KeyError, ValueError):
        raise SmokeFailure("Provide synthetic staging professional service coordinates.")
    if not all(math.isfinite(x) for x in (lat, lng)) or not (-89 <= lat <= 89) or not (-179 <= lng <= 179) or (round(lat, 2) == 0 and round(lng, 2) == 0):
        raise SmokeFailure("Test coordinates must be valid, finite and not 0,0.")

    # Do not touch a real active shift or profile.
    status, before = request(base, "professional/presence", token=token)
    expect(status == 200 and before.get("success") is True and isinstance(before.get("data"), dict),
           "authorized professional presence endpoint")
    expect(before["data"].get("accepting_requests") is False,
           "test professional starts offline")
    unauth_status, _ = request(base, "professional/presence")
    expect(unauth_status in (401, 403), "unauthenticated professional status denied")

    try:
        professional_id = int(os.environ["K3_STAGING_TEST_PROFESSIONAL_ID"])
        if professional_id < 1:
            raise ValueError
    except (ValueError, KeyError):
        raise SmokeFailure("A positive synthetic professional ID is required.")
    started = False
    try:
        # Always attempt cleanup if the server might have accepted this write,
        # even when a socket/response error prevents observing success.
        started = True
        status, response = request(base, "professional/presence", "POST",
            body={"accepting_requests": 1,
                  "public_lat": round(lat, 2), "public_lng": round(lng, 2),
                  "service_radius_km": 10, "minutes_on_duty": 15,
                  "confirm_public_location": 1},
            token=token)
        expect(status == 200 and isinstance(response.get("data"), dict) and
               response["data"].get("accepting_requests") is True,
               "synthetic professional activates consented shift")
        expiry = parse_expiry(response["data"].get("on_duty_until"))
        expect(expiry is not None and expiry.tzinfo is not None and
               expiry.astimezone(timezone.utc) > datetime.now(timezone.utc),
               "server returns an unexpired UTC shift")

        status, nearby = search(base, lat, lng, 5)
        rows = entries(nearby)
        expect(status == 200 and rows is not None, "nearby patient search returns a list")
        # Match the published public point, not a private precise address.
        matches = [x for x in rows if isinstance(x, dict) and
                   x.get("lat") == round(lat, 2) and x.get("lng") == round(lng, 2)]
        expect(len(matches) > 0, "synthetic on-duty public point is discoverable")
        # A unique professional ID is mandatory to avoid false passing when
        # another synthetic professional shares the same public coordinate.
        expect(included(rows, professional_id), "exact synthetic professional appears")
        matching = next(x for x in rows if x.get("id") == professional_id)
        expect(matching.get("distance_km") is not None and
               matching.get("accepting_requests") is True and
               matching.get("on_duty_until"), "distance, consent and shift fields returned")
        sensitive = {"email", "phone", "dob", "patient_address", "agora_token",
                     "device_token", "clinical_notes", "home_address"}
        expect(not sensitive.intersection(matching.keys()),
               "public response excludes private and clinical fields")

        far_lat = lat - 0.5 if lat > 85 else lat + 0.5
        status, distant = search(base, far_lat, lng, 5)
        distant_rows = entries(distant)
        expect(status == 200 and distant_rows is not None, "distant query is valid")
        expect(not included(distant_rows, professional_id),
               "far-away patient cannot match synthetic professional")

    finally:
        if started:
            try:
                off_status, off_body = request(
                    base, "professional/presence", "POST",
                    body={"accepting_requests": 0}, token=token)
                expect(off_status == 200 and isinstance(off_body.get("data"), dict)
                       and off_body["data"].get("accepting_requests") is False,
                       "synthetic professional automatically switched offline")
                off_details = off_body["data"]
                expect(off_details.get("public_lat") is None and
                       off_details.get("public_lng") is None and
                       off_details.get("public_location_consent_at") is None,
                       "offline revokes published service location and consent")
                check_status, check = search(base, lat, lng, 5)
                rows = entries(check)
                expect(check_status == 200 and rows is not None and
                       not included(rows, professional_id),
                       "offline professional removed from patient results")
            except Exception:
                raise SmokeFailure(
                    "OFFLINE CLEANUP NOT VERIFIED. Manually use the staging professional account "
                    "to stop accepting requests; do not publish this pilot.")

    print("PASS: staging online > discover > distance > offline journey")
    print("NOTE: This does not test payment, acceptance, booking or real-time tracking.")


if __name__ == "__main__":
    try:
        main()
    except (SmokeFailure, OSError, urllib.error.URLError, ValueError):
        # Avoid printing URLs, bearer tokens, server responses or locations.
        print("FAIL: staging Nearby Care test; inspect authorized staging privately.")
        sys.exit(1)
