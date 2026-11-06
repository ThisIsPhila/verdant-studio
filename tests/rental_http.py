#!/usr/bin/env python3
"""Loopback HTTP checks for the Phase 006 rental customer flow."""

from __future__ import annotations

import datetime as dt
import http.cookiejar
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path
from zoneinfo import ZoneInfo


EXPECTED_DATABASE = "verdant_test_phase006_http"
DEFAULT_BASE_URL = "http://127.0.0.1:8090"


class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, str]] = []
        self._form: dict[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {key: value or "" for key, value in attrs}
        if tag == "form":
            self._form = {"action": values.get("action", ""), "method": values.get("method", "get").lower()}
        elif tag == "input" and self._form is not None:
            name = values.get("name", "")
            if name:
                self._form[name] = values.get("value", "")

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self._form is not None:
            self.forms.append(self._form)
            self._form = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def request(opener: urllib.request.OpenerDirector, url: str,
            fields: dict[str, str] | None = None) -> tuple[int, object, str]:
    data = urllib.parse.urlencode(fields).encode("ascii") if fields is not None else None
    headers = {"Host": "127.0.0.1:8088"}
    if data is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8", errors="replace")


def raw_request(opener: urllib.request.OpenerDirector, url: str, body: bytes,
                headers: dict[str, str]) -> tuple[int, object, str]:
    merged = {"Host": "127.0.0.1:8088"}
    merged.update(headers)
    req = urllib.request.Request(url, data=body, headers=merged)
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8", errors="replace")


def token(body: str, action: str) -> str:
    parser = FormParser()
    parser.feed(body)
    for form in parser.forms:
        if form.get("action") == action and form.get("method") == "post":
            return form.get("csrf", "")
    return ""


def archived_product_fixture() -> str:
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("PHP is required for the archived-product HTTP fixture.")
    script = Path(__file__).with_name("rental_http_fixture.php")
    command = [php, str(script), "create"]
    result = subprocess.run(command, check=True, capture_output=True, text=True, timeout=10)
    return result.stdout.strip()


def verify_link(database: str, email: str) -> str:
    php = shutil.which("php")
    if php is None:
        raise RuntimeError("PHP is required for the verification HTTP fixture.")
    script = Path(__file__).with_name("verify_account_fixture.php")
    env = {**os.environ, "APP_ENV": "local", "DB_NAME": database}
    try:
        result = subprocess.run([php, str(script), email], check=True, capture_output=True,
                                text=True, timeout=15, env=env)
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        raise RuntimeError("Verification fixture failed.") from error
    link = result.stdout.strip()
    if not link.startswith("/verify-email?token="):
        raise RuntimeError("Verification fixture returned an unusable link.")
    return link


def main() -> int:
    if os.environ.get("HTTP_TEST_DATABASE") != EXPECTED_DATABASE or os.environ.get("APP_ENV") != "local":
        print("Refusing rental HTTP checks outside the explicitly named local test database.", file=sys.stderr)
        return 2
    base = os.environ.get("HTTP_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    parsed = urllib.parse.urlsplit(base)
    if (parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost", "::1"}
            or parsed.port != 8090 or parsed.path not in {"", "/"} or parsed.query or parsed.fragment
            or parsed.username or parsed.password):
        print("Refusing rental HTTP checks: expected plain HTTP on loopback port 8089.", file=sys.stderr)
        return 2

    checks = 0
    failures: list[str] = []

    def check(condition: bool, label: str) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            failures.append(label)

    try:
        archived_id = archived_product_fixture()
        if not archived_id.isdigit():
            raise RuntimeError("Archived product fixture did not return an ID.")

        jar = http.cookiejar.CookieJar()
        opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
        today = dt.datetime.now(ZoneInfo("Africa/Johannesburg")).date()
        start = today + dt.timedelta(days=1)
        end = today + dt.timedelta(days=2)
        params = urllib.parse.urlencode({"product_id": "3002", "quantity": "1",
                                         "start_date": start.isoformat(), "end_date": start.isoformat()})
        status, _, quote = request(opener, base + "/rental?" + params)
        check(status == 200 and "Large Fern Display" in quote and "Estimated total" in quote,
              "Public rental quote uses server-calculated dates, availability and price.")
        check("action=\"/rental/checkout\"" in quote and token(quote, "/rental/checkout"),
              "Available dates expose a CSRF-protected reservation form.")

        invalid_quantity = urllib.parse.urlencode({"product_id": "3002", "quantity": "0",
                                                   "start_date": start.isoformat(), "end_date": end.isoformat()})
        bad_quantity_status, _, bad_quantity = request(opener, base + "/rental?" + invalid_quantity)
        check(bad_quantity_status == 422 and 'value="0"' in bad_quantity and "positive rental quantity" in bad_quantity,
              "Zero quantity is rejected and remains in the form for correction.")
        past = today - dt.timedelta(days=1)
        invalid_dates = urllib.parse.urlencode({"product_id": "3002", "quantity": "1",
                                                "start_date": past.isoformat(), "end_date": end.isoformat()})
        bad_dates_status, _, bad_dates = request(opener, base + "/rental?" + invalid_dates)
        check(bad_dates_status == 422 and f'value="{past.isoformat()}"' in bad_dates and "past" in bad_dates.lower(),
              "Past rental dates are rejected while the submitted dates remain visible.")
        descending = urllib.parse.urlencode({"product_id": "3002", "quantity": "1",
                                             "start_date": end.isoformat(), "end_date": start.isoformat()})
        descending_status, _, _ = request(opener, base + "/rental?" + descending)
        check(descending_status == 422, "End dates before start dates are rejected.")
        malformed_status, _, malformed = request(opener, base + "/rental?product_id%5B%5D=3002&quantity%5B%5D=1")
        check(malformed_status == 422 and "valid product" in malformed.lower(),
              "Array-shaped query inputs are rejected as malformed values.")
        disabled_status, _, _ = request(opener, base + "/rental?product_id=3001&quantity=1&start_date=" + start.isoformat() + "&end_date=" + start.isoformat())
        check(disabled_status == 404, "A product with rentals disabled is unavailable to rent.")
        archived_status, _, _ = request(opener, base + "/rental?product_id=" + archived_id + "&quantity=1&start_date=" + start.isoformat() + "&end_date=" + start.isoformat())
        check(archived_status == 404, "An archived rental product is unavailable to rent.")

        _, _, login = request(opener, base + "/login")
        login_token = token(login, "/login")
        login_status, login_headers, _ = request(opener, base + "/login", {
            "csrf": login_token, "email": "customer@example.test", "password": "VerdantDemo!2026"})
        check(login_status == 303 and login_headers.get("Location") == "/account",
              "Synthetic customer can authenticate before reserving dates.")

        _, _, product = request(opener, base + "/product?id=3001")
        basket_token = token(product, "/cart/add")
        basket_status, basket_headers, _ = request(opener, base + "/cart/add", {
            "csrf": basket_token, "product_id": "3001", "quantity": "1"})
        check(basket_status == 303 and basket_headers.get("Location") == "/cart",
              "Retail basket fixture is created before rental checkout.")
        _, _, basket_before = request(opener, base + "/cart")
        check("Golden Pothos" in basket_before, "Retail item is in the customer's basket.")

        valid_fields = {"product_id": "3002", "quantity": "1", "start_date": start.isoformat(), "end_date": start.isoformat()}
        no_csrf_status, _, _ = request(opener, base + "/rental/checkout", valid_fields)
        check(no_csrf_status == 419, "Rental reservation without CSRF is rejected.")
        guest_jar = http.cookiejar.CookieJar()
        guest_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(guest_jar), NoRedirect())
        guest_quote_status, _, guest_quote = request(guest_opener, base + "/rental?" + params)
        guest_token = token(guest_quote, "/rental/checkout")
        guest_checkout_status, guest_headers, _ = request(guest_opener, base + "/rental/checkout", {**valid_fields, "csrf": guest_token})
        check(guest_quote_status == 200 and guest_checkout_status == 303 and guest_headers.get("Location") == "/login",
              "Guests can quote, but cannot reserve without signing in.")

        _, _, fresh_quote = request(opener, base + "/rental?" + params)
        checkout_token = token(fresh_quote, "/rental/checkout")
        checkout_status, _, checkout = request(opener, base + "/rental/checkout", {
            **valid_fields, "csrf": checkout_token, "checkout_key": "client-chosen-key", "rental_id": "999999",
            "role": "admin", "expires_at": "2000-01-01", "hold_until": "2000-01-01"})
        match = re.search(r"Rental (\d+)", checkout)
        check(checkout_status == 200 and "Your rental is prepared" in checkout and "Rental items" in checkout and "return required" in checkout and match is not None,
              "Authenticated checkout creates a pending rental hold without contacting Paystack.")
        rental_id = match.group(1) if match else ""
        retry_status, _, retry_body = request(opener, base + "/rental/checkout", {**valid_fields, "csrf": checkout_token})
        retry_match = re.search(r"Rental (\d+)", retry_body)
        check(retry_status == 200 and retry_match is not None and retry_match.group(1) == rental_id,
              "Repeated reservation submission returns the same pending rental.")
        _, _, basket_after = request(opener, base + "/cart")
        check("Golden Pothos" in basket_after, "Rental checkout leaves the retail basket unchanged.")

        account_jar = http.cookiejar.CookieJar()
        account_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(account_jar), NoRedirect())
        _, _, register = request(account_opener, base + "/register")
        register_token = token(register, "/register")
        email = "rental-http-" + os.urandom(6).hex() + "@example.test"
        password = "Rental HTTP test passphrase 2026!"
        register_status, _, register_body = request(account_opener, base + "/register", {
            "csrf": register_token, "name": "Other Rental Customer", "email": email,
            "password": password, "password_confirmation": password, "phone": ""})
        check(register_status == 200 and "Check your email" in register_body,
              "Separate synthetic customer registers and asks for email verification.")
        confirm_status, _, _ = request(account_opener, base + verify_link(EXPECTED_DATABASE, email))
        check(confirm_status == 200, "Separate synthetic customer is available for ownership checks.")
        other_status, _, _ = request(account_opener, base + "/rental/cancel", {"rental_id": rental_id})
        check(other_status == 419, "Rental cancellation from another session still requires its own CSRF token.")
        _, _, other_account = request(account_opener, base + "/account")
        other_csrf = token(other_account, "/logout")
        denied_status, _, denied = request(account_opener, base + "/rental/cancel", {"csrf": other_csrf, "rental_id": rental_id})
        check(denied_status == 404 and "rental is unavailable" in denied.lower(),
              "A different authenticated customer cannot cancel the owner's reservation.")
        cross_detail_status, _, _ = request(account_opener, base + "/account/rental?id=" + rental_id)
        check(cross_detail_status == 404, "A different authenticated customer cannot read the owner's rental details.")

        detail_status, _, detail = request(opener, base + "/account/rental?id=" + rental_id)
        cancel_token = token(detail, "/rental/cancel")
        check(detail_status == 200 and bool(cancel_token), "Unpaid pending rental provides a CSRF-protected cancel action.")
        no_cancel_csrf, _, _ = request(opener, base + "/rental/cancel", {"rental_id": rental_id})
        check(no_cancel_csrf == 419, "Rental cancellation without CSRF is rejected.")
        cancel_status, cancel_headers, _ = request(opener, base + "/rental/cancel", {"csrf": cancel_token, "rental_id": rental_id})
        check(cancel_status == 303 and cancel_headers.get("Location") == "/account/rental?id=" + rental_id,
              "Owner can cancel before payment initialization and release the dates.")
        cancelled_status, _, cancelled = request(opener, base + "/account/rental?id=" + rental_id)
        check(cancelled_status == 200 and "Cancelled" in cancelled, "Cancelled reservation is visible in rental history.")

        no_reference_status, _, _ = request(opener, base + "/rental/payment/return?reference=bad%20reference")
        check(no_reference_status == 400, "Malformed rental payment references are rejected.")
        fake_event = b'{"event":"charge.success","data":{"reference":"verdant-rental-1-synthetic"}}'
        webhook_headers = {"Content-Type": "application/json", "X-Paystack-Signature": "invalid"}
        shared_webhook_status, _, shared_webhook = raw_request(opener, base + "/payment/webhook", fake_event, webhook_headers)
        check(shared_webhook_status == 401 and '"received":false' in shared_webhook,
              "Shared Paystack webhook dispatches rental references through signature verification.")
        rental_webhook_status, _, rental_webhook = raw_request(opener, base + "/rental/payment/webhook", fake_event, webhook_headers)
        check(rental_webhook_status == 401 and '"received":false' in rental_webhook,
              "Rental webhook alias also rejects an invalid signature before processing.")
        print(f"Rental HTTP checks: {checks - len(failures)} passed, {len(failures)} failed.")
        if failures:
            for failure in failures:
                print("FAIL: " + failure, file=sys.stderr)
            return 1
        return 0
    except Exception as error:
        print(f"Rental HTTP checks aborted after {checks} checks: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
