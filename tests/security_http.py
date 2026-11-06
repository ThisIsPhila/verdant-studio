#!/usr/bin/env python3
"""Phase009 HTTP security checks against the isolated local test database."""

from __future__ import annotations

import hashlib
import hmac
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path
from shutil import which


EXPECTED_DATABASE = "verdant_test_phase009"
DEFAULT_BASE_URL = "http://127.0.0.1:8094"
EXPECTED_SECRET = "sk_test_phase009_placeholder"
EXPECTED_CALLBACK = "http://127.0.0.1:8094/payment/return"
DEMO_ADMIN_EMAIL = "admin@example.test"
DEMO_PASSWORD = "VerdantDemo!2026"


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, str]] = []
        self._form: dict[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {key: value or "" for key, value in attrs}
        if tag == "form":
            self._form = {"action": values.get("action", ""), "method": values.get("method", "get").lower()}
        elif tag == "input" and self._form is not None and values.get("name"):
            self._form[values["name"]] = values.get("value", "")

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self._form is not None:
            self.forms.append(self._form)
            self._form = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def client() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def request(opener: urllib.request.OpenerDirector, url: str, fields: dict[str, str] | None = None,
            headers: dict[str, str] | None = None, raw: bytes | None = None) -> tuple[int, object, str]:
    data = raw if raw is not None else (urllib.parse.urlencode(fields).encode("utf-8") if fields is not None else None)
    request_headers = dict(headers or {})
    if "Host" not in request_headers:
        request_path = urllib.parse.urlsplit(url).path
        request_headers["Host"] = "admin.verdant.localhost:8088" if (request_path.startswith("/admin") ) else "127.0.0.1:8088"
    if fields is not None and raw is None:
        request_headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
    req = urllib.request.Request(url, data=data, headers=request_headers)
    try:
        response = opener.open(req, timeout=7)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8", errors="replace")


def form(body: str, action: str) -> dict[str, str]:
    parser = FormParser()
    parser.feed(body)
    return next((values for values in parser.forms if values.get("action") == action and values.get("method") == "post"), {})


def complete_admin_2fa(opener: urllib.request.OpenerDirector, base: str, email: str, location: str) -> int:
    import admin_2fa
    if location == "/admin/2fa/setup":
        status, _, body = request(opener, f"{base}/admin/2fa/setup")
        if status != 200 or "Set up two-factor" not in body:
            raise RuntimeError("Two-factor setup did not load.")
        secret = admin_2fa.credential_secret(EXPECTED_DATABASE, email)
        fields = dict(form(body, "/admin/2fa/setup"))
        fields["code"] = admin_2fa.current_code(secret)
        status, _, body = request(opener, f"{base}/admin/2fa/setup", fields)
        if status != 200 or "Save your backup codes" not in body:
            raise RuntimeError("Two-factor setup did not complete.")
    status, headers, body = request(opener, f"{base}/admin/2fa")
    if status == 303:
        return status
    if status != 200:
        raise RuntimeError("Two-factor challenge did not load.")
    secret = admin_2fa.credential_secret(EXPECTED_DATABASE, email)
    fields = dict(form(body, "/admin/2fa"))
    fields["code"] = admin_2fa.current_code(secret)
    status, headers, _ = request(opener, f"{base}/admin/2fa", fields)
    if status != 303 or headers.get("Location") != "/admin":
        raise RuntimeError("Two-factor challenge did not sign in.")
    return status


def session_id(opener: urllib.request.OpenerDirector) -> str | None:
    jar = next((getattr(handler, "cookiejar") for handler in opener.handlers
                if hasattr(handler, "cookiejar")), None)
    if jar is None:
        return None
    return next((cookie.value for cookie in jar
                 if cookie.name in ("VERDANT_STORE_SESSION", "VERDANT_ADMIN_SESSION")), None)


def fixture(*arguments: str) -> dict[str, int | str]:
    php = which("php")
    if php is None:
        raise RuntimeError("PHP CLI is required for the security fixture.")
    script = Path(__file__).with_name("security_http_fixture.php")
    try:
        process = subprocess.run([php, str(script), *arguments], check=True, capture_output=True,
                                 text=True, timeout=15)
        return json.loads(process.stdout)
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        raise RuntimeError("Security database fixture failed or returned invalid JSON.") from error


def main() -> int:
    if (os.environ.get("APP_ENV") != "local" or os.environ.get("HTTP_TEST_DATABASE") != EXPECTED_DATABASE
            or os.environ.get("DB_NAME") != EXPECTED_DATABASE):
        print("Refusing security HTTP checks outside the exact local Phase009 test database.", file=sys.stderr)
        return 2
    if (os.environ.get("PAYSTACK_SECRET_KEY") != EXPECTED_SECRET
            or os.environ.get("PAYSTACK_CALLBACK_URL") != EXPECTED_CALLBACK):
        print("Refusing security HTTP checks unless the Phase009 placeholder payment configuration is active.", file=sys.stderr)
        return 2
    base = os.environ.get("HTTP_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    try:
        parsed = urllib.parse.urlsplit(base)
        safe_base = (parsed.scheme == "http" and parsed.hostname in {"127.0.0.1", "localhost", "::1"}
                     and parsed.port == 8094 and parsed.path in {"", "/"} and not parsed.query
                     and not parsed.fragment and not parsed.username and not parsed.password)
    except ValueError:
        safe_base = False
    if not safe_base:
        print("Refusing security HTTP checks: expected plain HTTP on loopback port 8094.", file=sys.stderr)
        return 2

    checks = 0
    failures: list[str] = []

    def check(condition: bool, label: str) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            failures.append(label)

    try:
        seed = fixture("create")
        ids = {key: str(seed[key]) for key in (
            "customer_id", "other_customer_id", "product_id", "foreign_order_id", "foreign_rental_id")}
        customer_id = ids["customer_id"]
        product_id = ids["product_id"]
        other_id = ids["other_customer_id"]
        foreign_order = ids["foreign_order_id"]
        foreign_rental = ids["foreign_rental_id"]
        customer = client()
        anonymous = client()
        admin = client()

        status, headers, login_page = request(anonymous, f"{base}/login")
        initial_id = session_id(anonymous)
        cookie_headers = headers.get_all("Set-Cookie") or []
        check(status == 200 and initial_id is not None, "Anonymous login page starts a local PHP session.")
        check(any("httponly" in value.lower() and "samesite=lax" in value.lower() for value in cookie_headers),
              "Session cookie includes HttpOnly and SameSite=Lax.")
        check(headers.get("X-Content-Type-Options") == "nosniff"
              and "default-src 'self'" in headers.get("Content-Security-Policy", ""),
              "HTTP response carries nosniff and the configured content security policy.")

        anon_admin, anon_admin_headers, _ = request(anonymous, f"{base}/admin/order?id={foreign_order}")
        check(anon_admin == 303 and anon_admin_headers.get("Location", "").startswith("/login"),
              "Anonymous direct admin access redirects to sign-in.")
        _, _, anon_admin_login = request(anonymous, f"{base}/login", None, {"Host": "admin.verdant.localhost:8088"})
        login_fields = form(anon_admin_login, "/login")
        anon_post, anon_post_headers, _ = request(anonymous, f"{base}/admin/order/cancel", {
            "csrf": login_fields.get("csrf", ""), "order_id": foreign_order})
        check(anon_post == 303 and anon_post_headers.get("Location", "").startswith("/login"),
              "Anonymous direct admin mutation redirects to sign-in with a valid session CSRF token.")

        # A separate synthetic customer account owns the escaped profile and test product.
        customer_page_status, customer_headers, customer_login_page = request(customer, f"{base}/login")
        customer_session_before_login = session_id(customer)
        customer_login = form(customer_login_page, "/login")
        login_status, login_headers, _ = request(customer, f"{base}/login", {
            "csrf": customer_login.get("csrf", ""), "email": str(seed["customer_email"]),
            "password": str(seed["password"])})
        logged_in_id = session_id(customer)
        login_cookie_headers = login_headers.get_all("Set-Cookie") or []
        check(customer_page_status == 200 and login_status == 303 and login_headers.get("Location") == "/account",
              "Synthetic customer authenticates successfully.")
        check(logged_in_id is not None and logged_in_id != customer_session_before_login,
              "Successful login renews the session identifier.")
        check(any("httponly" in value.lower() and "samesite=lax" in value.lower() for value in login_cookie_headers),
              "Renewed login cookie retains HttpOnly and SameSite=Lax.")

        catalogue_status, catalogue_headers, catalogue = request(customer, f"{base}/catalogue")
        unsafe_product = str(seed["product_name"])
        check(catalogue_status == 200 and "&lt;script&gt;" in catalogue and unsafe_product not in catalogue,
              "Catalogue escapes the synthetic product name before rendering it as HTML.")
        product_status, _, product = request(customer, f"{base}/product?id={product_id}")
        check(product_status == 200 and "&lt;script&gt;" in product and unsafe_product not in product,
              "Product detail escapes the stored XSS payload.")
        account_status, account_headers, account = request(customer, f"{base}/account")
        unsafe_customer = str(seed["customer_name"])
        check(account_status == 200 and "&lt;script&gt;" in account and unsafe_customer not in account,
              "Customer account page escapes the stored profile name.")
        check(catalogue_headers.get("X-Content-Type-Options") == "nosniff"
              and account_headers.get("Content-Security-Policy"),
              "Catalogue and authenticated pages retain the expected security headers.")

        # Ownership checks return the same not-found response for another customer's records.
        foreign_order_status, _, foreign_order_body = request(customer,
            f"{base}/account/order?id={foreign_order}")
        foreign_rental_status, _, foreign_rental_body = request(customer,
            f"{base}/account/rental?id={foreign_rental}")
        check(foreign_order_status == 404 and "unavailable" in foreign_order_body.lower(),
              "A customer cannot read another customer's order detail.")
        check(foreign_rental_status == 404 and "unavailable" in foreign_rental_body.lower(),
              "A customer cannot read another customer's rental detail.")

        customer_logout_token = form(account, "/logout").get("csrf", "")
        before_mutations = fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id)
        customer_admin_gets = [
            f"/admin/order?id={foreign_order}", f"/admin/rental?id={foreign_rental}",
            f"/admin/customer?id={other_id}",
        ]
        for path in customer_admin_gets:
            status, denial_headers, _ = request(customer, f"{base}{path}")
            check(status == 303 and denial_headers.get("Location", "").startswith("/login"),
                  "Authenticated customer is denied admin page " + path.split("?")[0] + ".")
        customer_posts = [
            ("/admin/order/cancel", {"order_id": foreign_order}),
            ("/admin/rental/cancel", {"rental_id": foreign_rental}),
            ("/admin/customer/archive", {"customer_id": other_id, "archived": "1"}),
        ]
        _, _, customer_admin_login = request(customer, f"{base}/login", None, {"Host": "admin.verdant.localhost:8088"})
        customer_admin_token = form(customer_admin_login, "/login").get("csrf", "")
        for path, fields in customer_posts:
            status, denial_headers, _ = request(customer, f"{base}{path}", {**fields, "csrf": customer_admin_token})
            check(status == 303 and denial_headers.get("Location", "").startswith("/login"),
                  "Valid-CSRF customer request cannot mutate through " + path + ".")
        surface_customer = client()
        _, _, surface_login_page = request(surface_customer, f"{base}/login", None,
                                           {"Host": "admin.verdant.localhost:8088"})
        surface_login_fields = form(surface_login_page, "/login")
        surface_login_status, _, _ = request(surface_customer, f"{base}/login", {
            "csrf": surface_login_fields.get("csrf", ""), "email": str(seed["customer_email"]),
            "password": str(seed["password"])}, {"Host": "admin.verdant.localhost:8088"})
        check(surface_login_status == 422, "Customer credentials are rejected on the admin surface without a session.")
        surface_denied, surface_denied_headers, _ = request(surface_customer, f"{base}/admin/order?id={foreign_order}")
        check(surface_denied == 303 and surface_denied_headers.get("Location", "").startswith("/login"),
              "Sessionless customer is bounced to sign-in by the admin boundary.")
        after_mutations = fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id)
        check(after_mutations == before_mutations,
              "Forbidden admin requests leave order, holds, rental, allocation, role and stock unchanged.")

        admin_login_status, _, admin_login_page = request(admin, f"{base}/login", None,
                                                             {"Host": "admin.verdant.localhost:8088"})
        admin_login_fields = form(admin_login_page, "/login")
        admin_status, admin_headers, _ = request(admin, f"{base}/login", {
            "csrf": admin_login_fields.get("csrf", ""), "email": DEMO_ADMIN_EMAIL, "password": DEMO_PASSWORD},
            {"Host": "admin.verdant.localhost:8088"})
        admin_location = admin_headers.get("Location", "")
        check(admin_login_status == 200 and admin_status == 303
              and admin_location in ("/admin/2fa", "/admin/2fa/setup"),
              "Seeded synthetic administrator password is accepted.")
        admin_status = complete_admin_2fa(admin, base, DEMO_ADMIN_EMAIL, admin_location)
        check(admin_status == 303, "Seeded synthetic administrator can authenticate.")
        _, _, admin_customer = request(admin, f"{base}/admin/customer?id={customer_id}")
        check("&lt;script&gt;" in admin_customer and unsafe_customer not in admin_customer,
              "Administrator customer detail escapes stored profile XSS.")
        check("password_hash" not in admin_customer and "$2y$" not in admin_customer,
              "Administrator customer detail does not disclose password hash fields.")
        admin_id = session_id(admin)
        check(admin_id is not None and admin_headers.get("Set-Cookie"),
              "Administrator login also issues a session renewal cookie.")

        before_csrf = fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id)
        for token in ("", "forged-token"):
            csrf_status, _, _ = request(admin, f"{base}/admin/order/cancel", {
                "order_id": foreign_order, "csrf": token})
            check(csrf_status == 419, "Administrator mutation rejects missing or forged CSRF with the request-expired response.")
        check(fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id) == before_csrf,
              "Invalid-CSRF administrator mutations preserve protected records and stock.")

        # Registration does not accept role escalation fields and does not create the account.
        register_client = client()
        _, _, register_page = request(register_client, f"{base}/register")
        register_fields = form(register_page, "/register")
        injected_email = f"phase009-injected-{seed['tag']}@example.test"
        registration_status, _, registration_body = request(register_client, f"{base}/register", {
            "csrf": register_fields.get("csrf", ""), "name": "Injected Role Fixture", "email": injected_email,
            "password": "Phase009 Injected Role!", "password_confirmation": "Phase009 Injected Role!",
            "role": "admin"})
        identity = fixture("identity", injected_email)
        check(registration_status == 422 and "privileges cannot be selected" in registration_body.lower(),
              "Registration rejects a client-supplied administrator role.")
        check(identity["count"] == 0, "Rejected role injection creates no customer record.")

        # SQL-shaped usernames are treated as literal invalid credentials, not SQL.
        injection_client = client()
        _, _, injection_login_page = request(injection_client, f"{base}/login")
        injection_fields = form(injection_login_page, "/login")
        injection_status, _, injection_body = request(injection_client, f"{base}/login", {
            "csrf": injection_fields.get("csrf", ""), "email": "' OR 1=1 -- ", "password": DEMO_PASSWORD})
        check(injection_status == 422 and "incorrect" in injection_body.lower(),
              "SQL injection shaped login input is rejected as an ordinary bad credential.")
        injection_admin_status, injection_admin_headers, _ = request(injection_client, f"{base}/admin")
        check(injection_admin_status == 303 and injection_admin_headers.get("Location", "").startswith("/login"),
              "SQL injection shaped login cannot establish an authenticated admin session.")

        # GET cannot perform admin mutations even for an authenticated administrator.
        for path in ("/admin/order/cancel", "/admin/rental/cancel", "/admin/customer/archive"):
            get_status, _, _ = request(admin, f"{base}{path}")
            check(get_status == 405, "GET is rejected for mutation route " + path + ".")

        # Both webhook routes authenticate raw bytes. Invalid signatures and validly signed
        # malformed JSON are rejected before a payment, allocation or event record changes.
        before_webhooks = fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id)
        secret = EXPECTED_SECRET.encode("ascii")
        for route, reference in (("/payment/webhook", "verdant-retail-security-" + str(seed["tag"])),
                                 ("/rental/payment/webhook", "verdant-rental-security-" + str(seed["tag"]))):
            valid_shape = json.dumps({"event": "charge.success", "data": {"reference": reference}},
                                     separators=(",", ":")).encode("utf-8")
            forged_status, _, _ = request(admin, f"{base}{route}", raw=valid_shape, headers={
                "Content-Type": "application/json", "X-Paystack-Signature": "forged-signature"})
            check(forged_status == 401, "Forged webhook signature is rejected for " + route + ".")

            malformed = b'{"event":'
            signature = hmac.new(secret, malformed, hashlib.sha512).hexdigest()
            malformed_status, _, malformed_body = request(admin, f"{base}{route}", raw=malformed, headers={
                "Content-Type": "application/json", "X-Paystack-Signature": signature})
            check(malformed_status == 401 and '"received":false' in malformed_body,
                  "Signed malformed JSON is rejected for " + route + ".")

        after_webhooks = fixture("inspect", customer_id, product_id, foreign_order, foreign_rental, other_id)
        check(after_webhooks == before_webhooks,
              "Forged and malformed callbacks do not change stock, workflow claims or provider-event rows.")

        error_status, _, safe_error = request(customer, f"{base}/account/order?id=not-an-id")
        check(error_status == 404 and "Fatal error" not in safe_error and "Stack trace" not in safe_error,
              "Malformed authenticated detail input returns a safe not-found response without a PHP error.")

        logout_fields = form(account, "/logout")
        old_login_id = logged_in_id
        logout_status, logout_headers, _ = request(customer, f"{base}/logout", logout_fields)
        cookie_cleared = any("expires=" in value.lower() and "httponly" in value.lower()
                             and "samesite=lax" in value.lower() for value in (logout_headers.get_all("Set-Cookie") or []))
        check(logout_status == 303 and cookie_cleared, "CSRF-protected logout expires the hardened session cookie.")
        stale_client = client()
        stale_status, stale_headers, _ = request(stale_client, f"{base}/account", headers={"Cookie": f"VERDANT_STORE_SESSION={old_login_id}"})
        check(stale_status == 303 and stale_headers.get("Location") == "/login",
              "A destroyed pre-logout session identifier cannot reopen the account.")

        private_paths = ("/.env", "/.local", "/.git/config", "/.aws/credentials",
                         "/app/bootstrap.php", "/database/migrations/001_foundation.sql",
                         "/tests/security_http_fixture.php")
        forbidden_fragments = ("sk_test_phase009_placeholder", "DB_PASSWORD", "password_hash", "SQL syntax", "/Users/")
        for path in private_paths:
            private_status, _, private_body = request(anonymous, f"{base}{path}")
            check(private_status == 404 and not any(fragment in private_body for fragment in forbidden_fragments),
                  "Private path is unavailable without exposing settings or source: " + path)
        if failures:
            print(f"Security HTTP checks: {checks - len(failures)} passed, {len(failures)} failed.", file=sys.stderr)
            for failure in failures:
                print("FAIL:", failure, file=sys.stderr)
            return 1
        print(f"Security HTTP checks: {checks} passed, 0 failed.")
        return 0
    except Exception as error:
        print(f"Security HTTP checks aborted after {checks} checks: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
