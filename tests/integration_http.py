#!/usr/bin/env python3
"""Focused Phase008 customer/admin and callback integration checks on loopback."""

from __future__ import annotations

import datetime as dt
from zoneinfo import ZoneInfo
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


EXPECTED_DATABASE = "verdant_test_phase008"
DEFAULT_BASE_URL = "http://127.0.0.1:8093"
EXPECTED_SECRET = "sk_test_phase008_placeholder"
EXPECTED_CALLBACK = "http://127.0.0.1:8093/payment/return"


class Forms(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, str]] = []
        self._current: dict[str, str] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {key: value or "" for key, value in attrs}
        if tag == "form":
            self._current = {"action": values.get("action", ""), "method": values.get("method", "get").lower()}
        elif tag == "input" and self._current is not None and values.get("name"):
            self._current[values["name"]] = values.get("value", "")

    def handle_endtag(self, tag: str) -> None:
        if tag == "form" and self._current is not None:
            self.forms.append(self._current)
            self._current = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def opener() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


def request(client: urllib.request.OpenerDirector, url: str, fields: dict[str, str] | None = None,
            headers: dict[str, str] | None = None) -> tuple[int, object, str]:
    data = urllib.parse.urlencode(fields).encode("ascii") if fields is not None else None
    request_headers = dict(headers or {})
    if "Host" not in request_headers:
        request_path = urllib.parse.urlsplit(url).path
        request_headers["Host"] = "admin.verdant.localhost:8088" if (request_path.startswith("/admin") ) else "127.0.0.1:8088"
    if data is not None:
        request_headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
    req = urllib.request.Request(url, data=data, headers=request_headers)
    try:
        response = client.open(req, timeout=7)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8", errors="replace")


def form(body: str, action: str) -> dict[str, str]:
    parser = Forms()
    parser.feed(body)
    return next((item for item in parser.forms if item.get("action") == action and item.get("method") == "post"), {})


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


def fixture(*arguments: str) -> dict[str, int | str]:
    php = shutil_which("php")
    if php is None:
        raise RuntimeError("PHP CLI is required for the Phase008 HTTP fixture.")
    script = Path(__file__).with_name("integration_http_fixture.php")
    try:
        result = subprocess.run([php, str(script), *arguments], check=True, capture_output=True,
                                text=True, timeout=15)
        return json.loads(result.stdout)
    except (subprocess.CalledProcessError, subprocess.TimeoutExpired, json.JSONDecodeError) as error:
        # Do not relay child stdout/stderr, which could contain private environment details.
        raise RuntimeError("Phase008 database fixture failed or returned invalid JSON.") from error


def shutil_which(command: str) -> str | None:
    from shutil import which
    return which(command)


def main() -> int:
    if os.environ.get("HTTP_TEST_DATABASE") != EXPECTED_DATABASE or os.environ.get("APP_ENV") != "local":
        print("Refusing Phase008 HTTP checks outside the explicitly named local test database.", file=sys.stderr)
        return 2
    if os.environ.get("DB_NAME") != EXPECTED_DATABASE:
        print("Refusing Phase008 HTTP checks: DB_NAME must match the isolated test database.", file=sys.stderr)
        return 2
    if os.environ.get("PAYSTACK_SECRET_KEY") != EXPECTED_SECRET or os.environ.get("PAYSTACK_CALLBACK_URL") != EXPECTED_CALLBACK:
        print("Refusing Phase008 HTTP checks unless the local placeholder Paystack configuration is active.", file=sys.stderr)
        return 2
    base = os.environ.get("HTTP_TEST_BASE_URL", DEFAULT_BASE_URL).rstrip("/")
    try:
        parsed = urllib.parse.urlsplit(base)
        safe_base = (parsed.scheme == "http" and parsed.hostname in {"127.0.0.1", "localhost", "::1"}
                     and parsed.port == 8093 and parsed.path in {"", "/"} and not parsed.query
                     and not parsed.fragment and not parsed.username and not parsed.password)
    except ValueError:
        safe_base = False
    if not safe_base:
        print("Refusing Phase008 HTTP checks: expected plain HTTP on loopback port 8093.", file=sys.stderr)
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
        customer_id = int(seed["customer_id"])
        product_id = int(seed["product_id"])
        email = str(seed["email"])
        password = str(seed["password"])
        start = (dt.datetime.now(ZoneInfo("Africa/Johannesburg")).date() + dt.timedelta(days=10)).isoformat()
        end = start

        customer = opener()
        status, _, product = request(customer, f"{base}/product?id={product_id}")
        add = form(product, "/cart/add")
        check(status == 200 and bool(add.get("csrf")), "Synthetic dual-use product renders a CSRF-protected add form.")
        add_status, add_headers, _ = request(customer, f"{base}/cart/add", {
            "csrf": add.get("csrf", ""), "product_id": str(product_id), "quantity": "3"})
        check(add_status == 303 and add_headers.get("Location") == "/cart",
              "The customer adds the fixture's full physical quantity to the basket.")

        _, _, basket = request(customer, f"{base}/cart")
        update = form(basket, "/cart/update")
        invalid_status, _, invalid_body = request(customer, f"{base}/cart/update", {
            "csrf": update.get("csrf", ""), "product_id": str(product_id), "quantity": "0"})
        check(invalid_status == 422 and str(seed["sku"]) in invalid_body and 'value="3"' in invalid_body,
              "An invalid cart quantity is rejected while the saved basket remains intact.")
        csrf_status, _, _ = request(customer, f"{base}/cart/update", {
            "product_id": str(product_id), "quantity": "2"})
        check(csrf_status == 419, "A cart mutation without CSRF is rejected.")

        # Guest basket state must survive login and secure session renewal.
        _, _, login_page = request(customer, f"{base}/login")
        login_fields = form(login_page, "/login")
        login_status, login_headers, _ = request(customer, f"{base}/login", {
            "csrf": login_fields.get("csrf", ""), "email": email, "password": password})
        check(login_status == 303 and login_headers.get("Location") == "/account",
              "Synthetic customer signs in through the HTTP login flow.")
        _, _, basket_after_login = request(customer, f"{base}/cart")
        checkout_fields = form(basket_after_login, "/checkout")
        check(str(seed["sku"]) in basket_after_login and bool(checkout_fields.get("csrf")),
              "The customer basket survives login and exposes checkout with CSRF.")
        no_csrf_status, _, _ = request(customer, f"{base}/checkout", {})
        check(no_csrf_status == 419, "Checkout without CSRF is rejected before creating an order.")

        # A different authenticated customer cannot inspect either callback belonging to the fixture owner.
        foreign_order_status, _, foreign_order_body = request(customer,
            f"{base}/payment/return?reference={urllib.parse.quote(str(seed['foreign_order_reference']))}")
        foreign_rental_status, _, foreign_rental_body = request(customer,
            f"{base}/rental/payment/return?reference={urllib.parse.quote(str(seed['foreign_rental_reference']))}")
        check(foreign_order_status == 404 and "unavailable" in foreign_order_body.lower(),
              "Retail payment callback hides a reference owned by another customer.")
        check(foreign_rental_status == 404 and "unavailable" in foreign_rental_body.lower(),
              "Rental payment callback hides a reference owned by another customer.")

        before_webhooks = fixture("inspect", str(customer_id), str(product_id))
        secret = EXPECTED_SECRET.encode("ascii")
        for route, reference in (("/payment/webhook", f"verdant-retail-unknown-{seed['tag']}"),
                                 ("/rental/payment/webhook", f"verdant-rental-unknown-{seed['tag']}")):
            payload = json.dumps({"event": "charge.success", "data": {"reference": reference}},
                                 separators=(",", ":")).encode("utf-8")
            signature = hmac.new(secret, payload, hashlib.sha512).hexdigest()
            req = urllib.request.Request(f"{base}{route}", data=payload, headers={
                "Content-Type": "application/json", "X-Paystack-Signature": signature,
                "Host": "127.0.0.1:8088"})
            try:
                response = customer.open(req, timeout=7)
            except urllib.error.HTTPError as error:
                response = error
            valid_status = response.code
            valid_body = response.read().decode("utf-8", errors="replace")
            check(valid_status == 200 and '"received":true' in valid_body,
                  f"Valid signed unknown {reference.split('-')[1]} webhook is accepted for quarantine.")

            bad_req = urllib.request.Request(f"{base}{route}", data=payload, headers={
                "Content-Type": "application/json", "X-Paystack-Signature": "invalid",
                "Host": "127.0.0.1:8088"})
            try:
                bad_response = customer.open(bad_req, timeout=7)
            except urllib.error.HTTPError as error:
                bad_response = error
            check(bad_response.code == 401, f"Invalid signature is rejected for {route}.")

        after_webhooks = fixture("inspect", str(customer_id), str(product_id))
        for key in ("inventory_on_hand", "order_count", "pending_order_count", "cancelled_order_count",
                    "order_item_count", "active_hold_quantity", "released_hold_quantity", "rental_count",
                    "rental_item_count", "active_rental_quantity"):
            check(after_webhooks[key] == before_webhooks[key],
                  f"Signed unknown and invalid-signature webhooks leave {key} unchanged.")
        check(after_webhooks["provider_event_count"] == before_webhooks["provider_event_count"] + 2,
              "Only the two valid signed unknown events create quarantine audit rows.")

        checkout_status, _, checkout_body = request(customer, f"{base}/checkout", checkout_fields)
        order_match = re.search(r"\bOrder (\d+)\b", checkout_body)
        first_order = order_match.group(1) if order_match else None
        second_order = None
        check(checkout_status == 200 and first_order is not None and "Your order is prepared" in checkout_body and "Order items" in checkout_body,
              "HTTP checkout creates one pending order and server-side stock hold.")
        retry_status, _, retry_body = request(customer, f"{base}/checkout", checkout_fields)
        retry_match = re.search(r"\bOrder (\d+)\b", retry_body)
        retry_order = retry_match.group(1) if retry_match else None
        check(retry_status == 200 and retry_order == first_order,
              "Repeated checkout submission returns the same order ID.")

        # The active retail hold consumes all three units. A rental quote and checkout
        # against the same dates must not make a second allocation.
        rental_query = urllib.parse.urlencode({"product_id": str(product_id), "quantity": "1",
                                               "start_date": start, "end_date": end})
        rental_status, _, rental_quote = request(customer, f"{base}/rental?{rental_query}")
        check(rental_status == 200 and "not available" in rental_quote.lower()
              and not form(rental_quote, "/rental/checkout"),
              "Retail hold for all three units makes the overlapping rental unavailable.")
        cart_token = form(basket_after_login, "/cart/update").get("csrf", "")
        conflict_status, _, conflict_body = request(customer, f"{base}/rental/checkout", {
            "csrf": cart_token, "product_id": str(product_id), "quantity": "1",
            "start_date": start, "end_date": end})
        check(conflict_status == 409 and "available" in conflict_body.lower(),
              "Rental checkout rejects the dates while the same product is fully held for retail.")
        past = (dt.datetime.now(ZoneInfo("Africa/Johannesburg")).date() - dt.timedelta(days=1)).isoformat()
        invalid_dates_status, _, invalid_dates_body = request(customer,
            f"{base}/rental?" + urllib.parse.urlencode({"product_id": str(product_id), "quantity": "1",
                                                        "start_date": past, "end_date": start}))
        check(invalid_dates_status == 422 and "past" in invalid_dates_body.lower(),
              "Rental availability rejects dates in the past.")
        conflict_state = fixture("inspect", str(customer_id), str(product_id))
        check(conflict_state["rental_count"] == 0 and conflict_state["active_rental_quantity"] == 0,
              "Rejected cross-workflow rental checkout creates no reservation or allocation.")
        check(conflict_state["active_hold_quantity"] == 3
              and conflict_state["active_hold_quantity"] + conflict_state["active_rental_quantity"]
              <= conflict_state["inventory_on_hand"],
              "Retail and rental claims remain within the fixture's physical stock.")

        if first_order is not None and first_order.isdigit():
            admin = opener()
            _, _, admin_login = request(admin, f"{base}/login", None, {"Host": "admin.verdant.localhost:8088"})
            admin_fields = form(admin_login, "/login")
            admin_status, admin_headers, _ = request(admin, f"{base}/login", {
                "csrf": admin_fields.get("csrf", ""), "email": "admin@example.test",
                "password": "VerdantDemo!2026"}, {"Host": "admin.verdant.localhost:8088"})
            admin_location = admin_headers.get("Location", "")
            check(admin_status == 303 and admin_location in ("/admin/2fa", "/admin/2fa/setup"),
                  "Seeded synthetic administrator password is accepted for order cancellation.")
            admin_status = complete_admin_2fa(admin, base, "admin@example.test", admin_location)
            check(admin_status == 303, "Seeded synthetic administrator signs in for order cancellation.")
            _, _, order_page = request(admin, f"{base}/admin/order?id={first_order}")
            cancel_fields = form(order_page, "/admin/order/cancel")
            check(bool(cancel_fields.get("csrf")), "Pending HTTP order exposes its protected cancellation form.")
            missing_admin_csrf, _, _ = request(admin, f"{base}/admin/order/cancel", {
                "order_id": first_order})
            check(missing_admin_csrf == 419, "Administrator cancellation without CSRF is rejected.")
            cancel_status, _, _ = request(admin, f"{base}/admin/order/cancel", cancel_fields)
            check(cancel_status == 303, "Administrator cancels the unstarted pending checkout.")

            after_cancel = fixture("inspect", str(customer_id), str(product_id))
            check(after_cancel["cancelled_order_count"] == 1 and after_cancel["active_hold_quantity"] == 0
                  and after_cancel["released_hold_quantity"] == 3,
                  "Cancellation releases the pending order hold while preserving the order record.")

            _, _, cancelled_customer_basket = request(customer, f"{base}/cart")
            edit_fields = form(cancelled_customer_basket, "/cart/update")
            edit_status, edit_headers, _ = request(customer, f"{base}/cart/update", {
                "csrf": edit_fields.get("csrf", ""), "product_id": str(product_id), "quantity": "2"})
            check(edit_status == 303 and edit_headers.get("Location") == "/cart",
                  f"Customer can edit the basket after admin cancellation (HTTP {edit_status}).")
            _, _, edited_basket = request(customer, f"{base}/cart")
            check('value="2"' in edited_basket,
                  "Basket quantity reflects the post-cancellation edit.")
            second_checkout_fields = form(edited_basket, "/checkout")
            second_status, _, second_body = request(customer, f"{base}/checkout", second_checkout_fields)
            second_match = re.search(r"\bOrder (\d+)\b", second_body)
            second_order = second_match.group(1) if second_match else None
            check(second_status == 200 and second_order is not None and second_order != first_order,
                  f"New checkout after cancellation creates a new order ID (old {first_order}, new {second_order}).")

            final_state = fixture("inspect", str(customer_id), str(product_id))
            check(final_state["order_count"] == 2 and final_state["cancelled_order_count"] == 1
                  and final_state["pending_order_count"] == 1 and final_state["order_item_count"] == 2,
                  "Database records one cancelled attempt and one distinct retry order.")
            check(final_state["active_hold_quantity"] == 2 and final_state["released_hold_quantity"] == 3
                  and final_state["active_hold_quantity"] <= final_state["inventory_on_hand"],
                  "Only the new order's quantity is held after the retry.")

        # The real payment services settle a deterministic fixture outside the browser.
        # This proves session recovery; it is not genuine Paystack network delivery.
        if first_order is not None and second_order is not None:
            settled = fixture("settle-order", str(customer_id), second_order, "success")
            check(settled["state"] == "succeeded", "Local signed event with provider double confirms the HTTP-created order.")
            _, _, history = request(customer, f"{base}/account/order?id={second_order}")
            _, _, admin_paid = request(admin, f"{base}/admin/order?id={second_order}")
            fulfil = form(admin_paid, "/admin/order/fulfil")
            check("confirmed" in history.lower() and bool(fulfil.get("csrf")),
                  "Customer history and administrator fulfilment form reflect the same confirmed HTTP order.")
            fulfil_status, _, _ = request(admin, f"{base}/admin/order/fulfil", fulfil)
            stock = fixture("inspect", str(customer_id), str(product_id))
            check(fulfil_status == 303 and stock["inventory_on_hand"] == 1,
                  "Administrator fulfils the verified order without deducting its two units again.")
            _, _, settled_basket = request(customer, f"{base}/cart")
            check(str(seed["sku"]) not in settled_basket,
                  "Payment completed outside the browser clears the already-purchased basket on its next visit.")
            _, _, product = request(customer, f"{base}/product?id={product_id}")
            add = form(product, "/cart/add")
            status, _, _ = request(customer, f"{base}/cart/add", {
                "csrf":add.get("csrf", ""), "product_id":str(product_id), "quantity":"1"})
            check(status == 303, "A customer can add the remaining physical unit after payment without a browser callback.")
            _, _, basket = request(customer, f"{base}/cart")
            status, _, third_body = request(customer, f"{base}/checkout", form(basket, "/checkout"))
            third = re.search(r"\bOrder (\d+)\b", third_body)
            check(status == 200 and third is not None and third.group(1) != second_order,
                  "A subsequent purchase starts a distinct order after externally settled checkout.")
            if third is not None:
                declined = fixture("settle-order", str(customer_id), third.group(1), "failed")
                _, _, declined_basket = request(customer, f"{base}/cart")
                status, _, _ = request(customer, f"{base}/cart/update", {
                    "csrf":form(declined_basket, "/cart/update").get("csrf", ""),
                    "product_id":str(product_id), "quantity":"1"})
                check(declined["state"] == "failed" and str(seed["sku"]) in declined_basket and status == 303,
                      "Definitive failure outside the browser preserves the basket and unlocks editing.")

        account_status, _, account = request(customer, f"{base}/account")
        logout_fields = form(account, "/logout")
        get_logout_status, _, _ = request(customer, f"{base}/logout")
        check(get_logout_status == 405, "GET logout is rejected.")
        logout_status, logout_headers, _ = request(customer, f"{base}/logout", logout_fields)
        check(account_status == 200 and logout_status == 303 and logout_headers.get("Location") == "/catalogue",
              "CSRF-protected logout ends the customer session.")
        after_logout, logout_headers, _ = request(customer, f"{base}/account")
        check(after_logout == 303 and logout_headers.get("Location") == "/login",
              "The logged-out session can no longer open the customer account.")

        stale = opener()
        _, _, login_page = request(stale, f"{base}/login")
        status, _, _ = request(stale, f"{base}/login", {
            "csrf":form(login_page, "/login").get("csrf", ""), "email":email, "password":password})
        check(status == 303, "Synthetic customer signs in before account archival invalidation.")
        before_archive = fixture("inspect", str(customer_id), str(product_id))
        archived = fixture("archive-customer", str(customer_id))
        status, headers, _ = request(stale, f"{base}/account")
        after_archive = fixture("inspect", str(customer_id), str(product_id))
        check(archived["changed"] == 1 and status == 303 and headers.get("Location") == "/login",
              "An existing session loses access immediately when its database account is archived.")
        check(after_archive == before_archive, "Invalidating customer access preserves all checkout, payment and inventory records.")

        if failures:
            print(f"Phase008 HTTP checks: {checks - len(failures)} passed, {len(failures)} failed.", file=sys.stderr)
            for failure in failures:
                print("FAIL:", failure, file=sys.stderr)
            return 1
        print(f"Phase008 HTTP checks: {checks} passed, 0 failed.")
        return 0
    except Exception as error:
        print(f"Phase008 HTTP checks aborted after {checks} checks: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
