#!/usr/bin/env python3
"""Loopback HTTP checks for the Phase 005 retail basket."""

from __future__ import annotations

import http.cookiejar
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser


EXPECTED_DATABASE = "verdant_test_phase005_http2"
DEFAULT_BASE_URL = "http://127.0.0.1:8089"


class Forms(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, str]] = []
        self.current: dict[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attrs_map = {key: value or "" for key, value in attrs}
        if tag == "form":
            self.current = {"action": attrs_map.get("action", ""), "method": attrs_map.get("method", "get").lower()}
        elif tag == "input" and self.current is not None and attrs_map.get("name"):
            self.current[attrs_map["name"]] = attrs_map.get("value", "")

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None


def request(opener: urllib.request.OpenerDirector, url: str, fields: dict[str, str] | None = None) -> tuple[int, object, str]:
    data = urllib.parse.urlencode(fields).encode("ascii") if fields is not None else None
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/x-www-form-urlencoded"} if data else {})
    try:
        response = opener.open(req, timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8", errors="replace")


def token(body: str, action: str) -> str:
    parser = Forms()
    parser.feed(body)
    for form in parser.forms:
        if form.get("action") == action and form.get("method") == "post":
            return form.get("csrf", "")
    return ""


def main() -> int:
    if os.environ.get("HTTP_TEST_DATABASE") != EXPECTED_DATABASE or os.environ.get("APP_ENV") != "local":
        print("Refusing cart HTTP checks outside the explicitly named local test database.", file=sys.stderr)
        return 2
    base = os.environ.get("HTTP_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    parsed = urllib.parse.urlsplit(base)
    if parsed.scheme != "http" or parsed.hostname != "127.0.0.1" or parsed.port != 8089 or parsed.path or parsed.query or parsed.fragment:
        print("Refusing cart HTTP checks: expected loopback port 8089.", file=sys.stderr)
        return 2

    jar = http.cookiejar.CookieJar()
    # Prevent automatic redirects so status and redirect destinations are testable.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
            return None
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect())
    checks = 0

    def check(condition: bool, label: str) -> None:
        nonlocal checks
        checks += 1
        if not condition:
            raise RuntimeError(label)

    try:
        status, _, detail = request(opener, base + "/product?id=3001")
        add_token = token(detail, "/cart/add")
        check(status == 200 and bool(add_token), "Buyable product provides a CSRF-protected add form.")
        status, headers, _ = request(opener, base + "/cart/add", {"csrf": add_token, "product_id": "3001", "quantity": "2"})
        check(status == 303 and headers.get("Location") == "/cart", "Eligible retail item adds and redirects to the basket.")
        status, _, basket = request(opener, base + "/cart")
        check(status == 200 and "Golden Pothos" in basket and "R258.00" in basket, "Basket shows stored product and server-calculated total.")
        update_token = token(basket, "/cart/update")
        status, _, invalid = request(opener, base + "/cart/update", {"csrf": update_token, "product_id": "3001", "quantity": "0"})
        check(status == 422 and "Enter a quantity from 1 to 99" in invalid and "Golden Pothos" in invalid,
              "Invalid quantity gives an actionable error and preserves the saved item.")
        no_csrf, _, _ = request(opener, base + "/cart/add", {"product_id": "3001", "quantity": "1"})
        check(no_csrf == 419, "Basket mutation without CSRF is rejected.")

        _, _, login = request(opener, base + "/login")
        login_token = token(login, "/login")
        login_status, headers, _ = request(opener, base + "/login", {"csrf": login_token, "email": "customer@example.test", "password": "VerdantDemo!2026"})
        check(login_status == 303 and headers.get("Location") == "/account", "Synthetic customer login succeeds.")
        _, _, after_login = request(opener, base + "/cart")
        check("Golden Pothos" in after_login and "R258.00" in after_login,
              "Guest basket survives secure session renewal at login.")
        guest_jar = http.cookiejar.CookieJar()
        guest_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(guest_jar), NoRedirect())
        _, _, guest_detail = request(guest_opener, base + "/product?id=3001")
        guest_token = token(guest_detail, "/cart/add")
        request(guest_opener, base + "/cart/add", {"csrf": guest_token, "product_id": "3001", "quantity": "1"})
        _, _, guest_basket = request(guest_opener, base + "/cart")
        guest_checkout_token = token(guest_basket, "/checkout")
        guest_checkout_status, guest_checkout_headers, _ = request(guest_opener, base + "/checkout", {"csrf": guest_checkout_token})
        check(guest_checkout_status == 303 and guest_checkout_headers.get("Location") == "/login",
              "Guest checkout requires an authenticated customer.")
        _, _, guest_basket_after = request(guest_opener, base + "/cart")
        check("Golden Pothos" in guest_basket_after, "Basket remains available after guest checkout redirects to sign-in.")
        checkout_token = token(after_login, "/checkout")
        checkout_status, _, checkout_body = request(opener, base + "/checkout", {"csrf": checkout_token})
        order_match = re.search(r"Order (\d+)", checkout_body)
        check(checkout_status == 200 and "Your order is prepared" in checkout_body and "Order items" in checkout_body and "SKU " in checkout_body and order_match is not None,
              "Authenticated checkout creates a pending held order.")
        retry_status, _, retry_body = request(opener, base + "/checkout", {"csrf": checkout_token})
        retry_match = re.search(r"Order (\d+)", retry_body)
        check(retry_status == 200 and retry_match is not None and retry_match.group(1) == order_match.group(1),
              f"Repeated checkout submission displays the same idempotent order (status {retry_status}, first {order_match.group(1)}, retry {retry_match.group(1) if retry_match else 'none'}).")
        remove_status, _, locked_basket = request(opener, base + "/cart/remove", {"csrf": token(after_login, "/cart/remove"), "product_id": "3001"})
        check(remove_status == 409 and "waiting for a payment result" in locked_basket,
              "Basket cannot change while its order is awaiting payment verification.")
        _, _, detail = request(opener, base + "/product?id=3002")
        check("Add to basket" not in detail, "Rental-only product does not offer a retail add action.")
        print(f"Cart HTTP checks: {checks} passed, 0 failed.")
        return 0
    except Exception as error:
        print(f"Cart HTTP check failed after {checks} checks: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
