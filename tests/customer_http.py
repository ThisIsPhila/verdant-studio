#!/usr/bin/env python3
"""Loopback HTTP acceptance checks for the Phase 004 customer application."""

from __future__ import annotations

import http.cookiejar
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path


EXPECTED_DATABASE = "verdant_test_phase005_http2"
DEFAULT_BASE_URL = "http://127.0.0.1:8089"


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, str]] = []
        self.links: list[str] = []
        self._form: dict[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {key: value or "" for key, value in attrs}
        if tag == "form":
            self._form = {"action": values.get("action", ""), "method": values.get("method", "get").lower()}
        elif tag == "input" and self._form is not None:
            name = values.get("name", "")
            if name:
                self._form[name] = values.get("value", "")
        elif tag == "a" and values.get("href"):
            self.links.append(values["href"])

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self._form is not None:
            self.forms.append(self._form)
            self._form = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def request(opener: urllib.request.OpenerDirector, url: str, data: bytes | None = None,
            headers: dict[str, str] | None = None) -> tuple[int, object, str]:
    merged = {"Host": "127.0.0.1:8088"}
    if headers:
        merged.update(headers)
    req = urllib.request.Request(url, data=data, headers=merged)
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode("utf-8", errors="replace")
    return response.code, response.headers, body


def form_token(body: str, action: str) -> str | None:
    parser = FormParser()
    parser.feed(body)
    for form in parser.forms:
        if form.get("action") == action and form.get("method") == "post":
            return form.get("csrf") or None
    return None


def encode(fields: dict[str, str]) -> bytes:
    return urllib.parse.urlencode(fields).encode("ascii")


def verify_link(database: str, email: str) -> str:
    script = Path(__file__).with_name("verify_account_fixture.php")
    env = {**os.environ, "APP_ENV": "local", "DB_NAME": database}
    try:
        process = subprocess.run(["php", str(script), email], capture_output=True, text=True,
                                 timeout=15, env=env, check=True)
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        raise RuntimeError("Verification fixture failed.") from error
    link = process.stdout.strip()
    if not link.startswith("/verify-email?token="):
        raise RuntimeError("Verification fixture returned an unusable link.")
    return link


def main() -> int:
    if os.environ.get("HTTP_TEST_DATABASE") != EXPECTED_DATABASE:
        print(f"Refusing HTTP checks: set HTTP_TEST_DATABASE={EXPECTED_DATABASE} explicitly.", file=sys.stderr)
        return 2
    if os.environ.get("APP_ENV") != "local":
        print("Refusing HTTP checks unless APP_ENV=local.", file=sys.stderr)
        return 2

    base_url = os.environ.get("HTTP_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    parsed = urllib.parse.urlsplit(base_url)
    if (parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost", "::1"}
            or parsed.username or parsed.password or parsed.path not in {"", "/"}
            or parsed.query or parsed.fragment or parsed.port != 8089):
        print("Refusing HTTP checks: base URL must be plain HTTP on loopback port 8089.", file=sys.stderr)
        return 2

    checks = 0
    failures: list[str] = []

    def check(condition: bool, label: str) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            failures.append(label)

    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
    initial_session: tuple[str, str] | None = None
    logged_in_session: tuple[str, str] | None = None
    logout_cookie_cleared = False

    try:
        status, headers, catalogue = request(opener, base_url + "/catalogue")
        first_cookie_headers = headers.get_all("Set-Cookie") or []
        parser = FormParser()
        parser.feed(catalogue)
        product_ids = {int(match) for match in re.findall(r"/product\?id=(\d+)", catalogue)}
        check(status == 200 and len(product_ids) == 3, "Catalogue renders all three seeded products.")
        for key, value in (("category_id", "2001"), ("light_level", "indirect"),
                           ("watering_level", "weekly"), ("toxicity", "non_toxic")):
            query = urllib.parse.urlencode({key: value})
            filter_status, _, filter_body = request(opener, base_url + "/catalogue?" + query)
            check(filter_status == 200 and "product-grid" in filter_body,
                  f"Catalogue accepts stored {key} filter.")
        invalid_status, _, invalid_body = request(opener, base_url + "/catalogue?category_id=-1")
        check(invalid_status == 422 and "Please check your filters" in invalid_body,
              "Invalid catalogue filter returns a safe 422.")
        detail_status, _, _ = request(opener, base_url + "/product?id=999999")
        check(detail_status == 404, "Missing product detail returns 404.")
        for path in ("/app/bootstrap.php", "/.env", "/database/README.md", "/config/trusted-origins.php"):
            private_status, _, _ = request(opener, base_url + path)
            check(private_status == 404, f"Private path {path} is unavailable.")

        status, headers, login_form = request(opener, base_url + "/login")
        check(status == 200, "Login form loads.")
        session_cookie_name = "PHPSESSID"
        for cookie in jar:
            if cookie.name == session_cookie_name:
                initial_session = (cookie.name, cookie.value)
        set_cookie_headers = first_cookie_headers + (headers.get_all("Set-Cookie") or [])
        cookie_policy = any("httponly" in item.lower() and "samesite=lax" in item.lower()
                            for item in set_cookie_headers)
        check(cookie_policy, "Local session cookie is HttpOnly and SameSite=Lax.")

        no_csrf_status, _, _ = request(opener, base_url + "/login", encode({"email": "x", "password": "x"}),
                                       {"Content-Type": "application/x-www-form-urlencoded"})
        check(no_csrf_status == 419, "Missing CSRF token returns 419.")
        get_logout_status, _, _ = request(opener, base_url + "/logout")
        check(get_logout_status == 405, "GET logout is rejected with 405.")

        email = "customer@example.test"
        password = "VerdantDemo!2026"
        token = form_token(login_form, "/login")
        if token is None:
            check(False, "Login form exposes a CSRF token.")
        else:
            login_status, login_headers, login_body = request(
                opener, base_url + "/login", encode({"csrf": token, "email": email, "password": password}),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(login_status == 303 and login_headers.get("Location") == "/account",
                  "Seed customer login redirects to the account.")
            check(password not in login_body and email not in login_body,
                  "Successful login response does not echo credentials.")
            for cookie in jar:
                if cookie.name == session_cookie_name:
                    logged_in_session = (cookie.name, cookie.value)
            check(initial_session is not None and logged_in_session is not None
                  and initial_session[1] != logged_in_session[1], "Session identifier changes at login.")
            cookie_policy = any("httponly" in item.lower() and "samesite=lax" in item.lower()
                                for item in (login_headers.get_all("Set-Cookie") or []))
            check(cookie_policy, "Renewed local session cookie is HttpOnly and SameSite=Lax.")

            account_status, _, account_body = request(opener, base_url + "/account")
            check(account_status == 200, "Authenticated account page loads.")
            check("Demo Customer" in account_body and "5001" in account_body and "6001" in account_body,
                  "Customer account shows its seeded retail order and rental.")
            check("$2y$" not in account_body and password not in account_body
                  and "<?php" not in account_body,
                  "Account response contains no password hash, credential, or PHP source.")
            for path, record_id in (("/account/order?id=5001", "5001"),
                                    ("/account/rental?id=6001", "6001")):
                own_status, _, own_body = request(opener, base_url + path)
                check(own_status == 200 and record_id in own_body,
                      f"Owned transaction {record_id} is visible to its customer.")
            for path in ("/account/order?id=999999", "/account/rental?id=999999"):
                missing_status, _, _ = request(opener, base_url + path)
                check(missing_status == 404, f"Missing transaction at {path} returns 404.")
            admin_status, _, _ = request(opener, base_url + "/admin")
            check(admin_status == 403, "Customer cannot access the admin route.")

            # A separate client proves another customer cannot read seeded private records.
            other_jar = http.cookiejar.CookieJar()
            other_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(other_jar), NoRedirect())
            register_status, _, register_form = request(other_opener, base_url + "/register")
            register_token = form_token(register_form, "/register")
            check(register_status == 200 and register_token is not None,
                  "Registration form loads with a CSRF token.")
            synthetic_email = "http-test-" + os.urandom(6).hex() + "@example.test"
            synthetic_password = "HTTP synthetic passphrase 2026!"
            if register_token:
                fields = {"csrf": register_token, "name": "HTTP Test Customer", "email": synthetic_email,
                          "password": synthetic_password, "password_confirmation": synthetic_password,
                          "phone": "", "role": "admin"}
                register_status, register_headers, _ = request(
                    other_opener, base_url + "/register", encode(fields),
                    {"Content-Type": "application/x-www-form-urlencoded"})
                check(register_status == 422, "Public registration rejects role injection.")

            # Test validation paths with fresh CSRF forms so each request has a valid token.
            for label, changes in (
                ("malformed registration", {"email": "not-an-email"}),
                ("password mismatch", {"password_confirmation": "different synthetic passphrase"}),
            ):
                _, _, fresh_form = request(other_opener, base_url + "/register")
                fresh_token = form_token(fresh_form, "/register")
                fields = {"csrf": fresh_token or "", "name": "Validation Fixture", "email": synthetic_email,
                          "password": synthetic_password, "password_confirmation": synthetic_password,
                          **changes}
                invalid_reg_status, _, _ = request(
                    other_opener, base_url + "/register", encode(fields),
                    {"Content-Type": "application/x-www-form-urlencoded"})
                check(invalid_reg_status == 422, f"{label.capitalize()} is rejected with 422.")

            # Create one durable synthetic account and verify both output escaping and empty history.
            _, _, fresh_form = request(other_opener, base_url + "/register")
            fresh_token = form_token(fresh_form, "/register")
            xss_name = '<script>alert("http")</script>'
            fields = {"csrf": fresh_token or "", "name": xss_name, "email": synthetic_email,
                      "password": synthetic_password, "password_confirmation": synthetic_password,
                      "phone": ""}
            create_status, _, create_body = request(
                other_opener, base_url + "/register", encode(fields),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(create_status == 200 and "Check your email" in create_body,
                  "Valid synthetic registration persists and asks for email verification.")
            confirm_status, _, confirm_body = request(
                other_opener, base_url + verify_link(EXPECTED_DATABASE, synthetic_email))
            check(confirm_status == 200 and "Email verified" in confirm_body,
                  "Verification link activates the account and signs in.")
            check("&lt;script&gt;" in request(other_opener, base_url + "/account")[2],
                  "Registered markup-like name is escaped in rendered account output.")
            empty_status, _, empty_body = request(other_opener, base_url + "/account")
            check(empty_status == 200 and "5001" not in empty_body and "6001" not in empty_body,
                  "New customer receives empty history without another customer's records.")
            for path in ("/account/order?id=5001", "/account/rental?id=6001"):
                cross_status, _, _ = request(other_opener, base_url + path)
                check(cross_status == 404, f"Cross-customer access at {path} returns 404.")

            _, _, synthetic_account_page = request(other_opener, base_url + "/account")
            synthetic_logout_token = form_token(synthetic_account_page, "/logout")
            synthetic_logout_status, _, _ = request(
                other_opener, base_url + "/logout", encode({"csrf": synthetic_logout_token or ""}),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(synthetic_logout_status == 303, "Synthetic customer can log out before duplicate check.")
            _, _, duplicate_form = request(other_opener, base_url + "/register")
            duplicate_token = form_token(duplicate_form, "/register")
            duplicate_status, _, _ = request(
                other_opener, base_url + "/register",
                encode({"csrf": duplicate_token or "", "name": "Duplicate Fixture", "email": synthetic_email,
                        "password": synthetic_password, "password_confirmation": synthetic_password}),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(duplicate_status == 422, "Duplicate registration is rejected with 422.")

            # Wrong-password responses must be safe and must not echo the password; retaining the escaped email helps correction.
            _, _, wrong_login_form = request(other_opener, base_url + "/login")
            wrong_token = form_token(wrong_login_form, "/login")
            wrong_email = "absent-http-test@example.test"
            wrong_password = "wrong synthetic credential"
            wrong_status, _, wrong_body = request(
                other_opener, base_url + "/login",
                encode({"csrf": wrong_token or "", "email": wrong_email, "password": wrong_password}),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(wrong_status == 422 and wrong_password not in wrong_body and "The email or password is incorrect." in wrong_body,
                  "Wrong login returns safe 422 without echoing the password.")

            # Logout through its CSRF-protected form, inspect cookie invalidation, then replay the old ID.
            _, _, logout_form_page = request(opener, base_url + "/account")
            logout_token = form_token(logout_form_page, "/logout")
            logout_status, logout_headers, _ = request(
                opener, base_url + "/logout", encode({"csrf": logout_token or ""}),
                {"Content-Type": "application/x-www-form-urlencoded"})
            check(logout_status == 303 and logout_headers.get("Location") == "/catalogue",
                  "CSRF-protected logout redirects to the catalogue.")
            logout_cookie_cleared = any(
                session_cookie_name.lower() in item.lower()
                and ("max-age=0" in item.lower() or "expires=" in item.lower())
                for item in (logout_headers.get_all("Set-Cookie") or [])
            )
            check(logout_cookie_cleared, "Logout clears the session cookie.")
            anon_status, anon_headers, _ = request(opener, base_url + "/account")
            check(anon_status == 303 and anon_headers.get("Location") == "/login",
                  "Logout removes access to the protected account page.")
            if logged_in_session:
                revoked_opener = urllib.request.build_opener(NoRedirect())
                revoked_status, revoked_headers, _ = request(
                    revoked_opener, base_url + "/account",
                    headers={"Cookie": f"{logged_in_session[0]}={logged_in_session[1]}"})
                check(revoked_status == 303 and revoked_headers.get("Location") == "/login",
                      "Authenticated session replay after logout remains anonymous.")
            else:
                check(False, "Authenticated session cookie was available for revocation check.")
            if initial_session:
                replay_opener = urllib.request.build_opener(NoRedirect())
                replay_status, replay_headers, _ = request(
                    replay_opener, base_url + "/account",
                    headers={"Cookie": f"{initial_session[0]}={initial_session[1]}"})
                check(replay_status == 303 and replay_headers.get("Location") == "/login",
                      "Pre-login session replay after logout remains anonymous.")
            else:
                check(False, "Pre-login session cookie was available for replay check.")

        # Responses must not contain PHP source, password hashes, or supplied raw credentials.
        status, _, login_again = request(opener, base_url + "/login")
        check(status == 200 and "<?php" not in login_again and "$2y$" not in login_again,
              "Rendered public page contains no PHP source or password hash.")

    except Exception:
        failures.append("HTTP checks encountered an unexpected request or parsing failure; details suppressed.")

    if failures:
        for label in failures:
            print(f"FAIL: {label}", file=sys.stderr)
        print(f"Customer HTTP checks: {checks} completed, {len(failures)} failed.", file=sys.stderr)
        return 1
    print(f"Customer HTTP checks: {checks} passed, 0 failed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
