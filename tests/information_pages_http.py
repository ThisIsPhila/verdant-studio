#!/usr/bin/env python3
"""Information page and policy publication HTTP checks for the isolated Phase 016 app."""
import datetime
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
from urllib.parse import urlsplit

BASE = os.getenv('INFORMATION_HTTP_BASE', 'http://127.0.0.1:8096').rstrip('/')
DATABASE = 'verdant_test_phase016'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Document(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.links = []
        self.headings = []
        self.ids = set()
        self.footer_depth = 0
        self.footer_links = []
        self.current_form = None
        self.current_select = None
        self.current_option = None
        self.current_textarea = None
        self.current_heading = None
        self.current_link = None
        self.main_depth = 0
        self.main_count = 0
        self._footer_open = False

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if attrs.get('id'):
            self.ids.add(attrs['id'])
        if tag == 'main':
            self.main_count += 1
            self.main_depth += 1
        if tag == 'footer':
            self.footer_depth += 1
        if tag == 'a' and attrs.get('href'):
            link = {'href': attrs['href'], 'text': ''}
            self.links.append(link)
            self.current_link = link
            if self.footer_depth:
                self.footer_links.append(link)
        if re.fullmatch(r'h[1-6]', tag):
            self.current_heading = {'tag': tag, 'text': ''}
        if tag == 'form':
            self.current_form = {'action': attrs.get('action', ''), 'method': attrs.get('method', 'get'), 'fields': {}}
        elif tag == 'input' and self.current_form is not None and attrs.get('name'):
            self.current_form['fields'][attrs['name']] = attrs.get('value', '')
        elif tag == 'textarea' and self.current_form is not None and attrs.get('name'):
            self.current_textarea = attrs['name']
            self.current_form['fields'][attrs['name']] = ''
        elif tag == 'select' and self.current_form is not None and attrs.get('name'):
            self.current_select = {'name': attrs['name'], 'selected': None, 'first': None}
        elif tag == 'option' and self.current_select is not None:
            self.current_option = {'value': attrs.get('value'), 'selected': 'selected' in attrs, 'text': ''}

    def handle_data(self, data):
        if self.current_heading is not None:
            self.current_heading['text'] += data
        if self.current_option is not None:
            self.current_option['text'] += data
        if self.current_textarea is not None and self.current_form is not None:
            self.current_form['fields'][self.current_textarea] += data
        if self.current_link is not None:
            self.current_link['text'] += data

    def handle_endtag(self, tag):
        if tag == 'option' and self.current_option is not None and self.current_select is not None:
            value = self.current_option['value']
            if value is None:
                value = self.current_option['text'].strip()
            if self.current_select['first'] is None:
                self.current_select['first'] = value
            if self.current_option['selected']:
                self.current_select['selected'] = value
            self.current_option = None
        elif tag == 'select' and self.current_select is not None and self.current_form is not None:
            value = self.current_select['selected']
            if value is None:
                value = self.current_select['first'] or ''
            self.current_form['fields'][self.current_select['name']] = value
            self.current_select = None
        elif tag == 'textarea':
            self.current_textarea = None
        elif tag == 'form' and self.current_form is not None:
            self.forms.append(self.current_form)
            self.current_form = None
        elif re.fullmatch(r'h[1-6]', tag) and self.current_heading is not None:
            self.headings.append(self.current_heading)
            self.current_heading = None
        elif tag == 'a' and self.links:
            self.current_link = None
        elif tag == 'main' and self.main_depth:
            self.main_depth -= 1
        elif tag == 'footer' and self.footer_depth:
            self.footer_depth -= 1


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())


STORE_HOST = '127.0.0.1:8088'
ADMIN_HOST = 'admin.verdant.localhost:8088'


def request(opener, path, fields=None, host=None):
    data = None if fields is None else urllib.parse.urlencode(fields, doseq=True).encode()
    if host is None:
        host = ADMIN_HOST if (path.startswith('/admin') ) else STORE_HOST
    req = urllib.request.Request(BASE + path, data=data, headers={'Host': host})
    try:
        response = opener.open(req, timeout=12)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8', errors='replace')
    if re.search(r'(?:Warning|Fatal error|Deprecated):', body):
        raise AssertionError('PHP diagnostic leaked into response for ' + path)
    return response.code, response.headers, body


def parse(body):
    document = Document()
    document.feed(body)
    return document


def get_form(body, action):
    return next((entry for entry in parse(body).forms if entry['action'] == action), None)


def fixture(action, snapshot=None):
    command = ['php', 'tests/information_pages_fixture.php', action]
    result = subprocess.run(command, input='' if snapshot is None else json.dumps(snapshot),
                            text=True, capture_output=True, timeout=15, check=True)
    return json.loads(result.stdout) if action == 'snapshot' else result.stdout.strip()


def expect_public_document(opener, path, label, check):
    status, _, body = request(opener, path)
    document = parse(body)
    check(status == 200, label + ' responds successfully.')
    check(document.main_count == 1, label + ' has one semantic main region.')
    h1 = [heading['text'].strip() for heading in document.headings if heading['tag'] == 'h1']
    check(len(h1) == 1 and bool(h1[0]), label + ' has one non-empty primary heading.')
    check(not re.search(r'react(?:-dom)?(?:/|["\'])|id=(["\'])root\1', body, re.I),
          label + ' is server-rendered without a React mount.')
    check(all(form['action'] in ('', '/contact/enquiry', '/catalogue') for form in document.forms), label + ' only presents implemented forms.')
    return body, document


def run_checks(snapshot):
    if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != DATABASE:
        raise RuntimeError(f'Use only APP_ENV=local with DB_NAME={DATABASE}.')
    base = urlsplit(BASE)
    if base.scheme != 'http' or base.hostname not in {'127.0.0.1', 'localhost', '::1'}:
        raise RuntimeError('INFORMATION_HTTP_BASE must point to a loopback HTTP server.')
    checks = 0

    def check(condition, message):
        nonlocal checks
        if not condition:
            raise AssertionError(message)
        checks += 1
        print('PASS:', message)

    anonymous, customer, admin = client(), client(), client()
    nonpolicy = [
        ('/about', 'About'),
        ('/sustainability', 'Sustainability'),
        ('/plant-care', 'Plant Care'),
        ('/journal', 'Journal'),
        ('/journal/article?slug=finding-your-green-corner', 'Journal article'),
        ('/journal/article?slug=happier-houseplants', 'Journal article'),
        ('/journal/article?slug=choosing-the-right-pot', 'Journal article'),
        ('/contact', 'Contact'),
        ('/delivery', 'Delivery'),
        ('/returns', 'Returns'),
    ]
    for path, label in nonpolicy:
        expect_public_document(anonymous, path, path, check)

    for path in ['/journal/article?slug=not-a-published-article', '/journal/article?slug%5B%5D=unknown']:
        status, _, body = request(anonymous, path)
        check(status == 404, 'Unknown or array-valued article slug returns not found: ' + path)
        check('Warning:' not in body and '<script>alert' not in body,
              'Invalid article slug produces a controlled response.')

    for path in ['/privacy', '/terms']:
        status, _, body = request(anonymous, path)
        check(status == 200, path + ' provides a controlled public baseline before publication.')
        check('T016-HTTP-PUBLISHED' not in body and 'Policy publication test' not in body,
              path + ' does not expose draft publication markers.')

    initial_footer = parse(request(anonymous, '/about')[2]).footer_links
    initial_footer_paths = {urlsplit(link['href']).path for link in initial_footer if link['href'].startswith('/')}
    check('/privacy' in initial_footer_paths and '/terms' in initial_footer_paths,
          'Both legal destinations remain visible without exposing private policy drafts.')

    # The real public catalogue and discovery routes used by the shared footer must resolve.
    for path in ['/catalogue', '/catalogue?mode=rent', '/arrangements', '/collections', '/collections/homes']:
        status, _, _ = request(anonymous, path)
        check(status == 200, 'Existing shop destination is live: ' + path)

    status, headers, _ = request(anonymous, '/admin/sections?page=privacy')
    check(status == 303 and headers.get('Location', '').startswith('/login'), 'Anonymous editor access redirects to sign-in.')
    status, headers, _ = request(anonymous, '/admin/information-preview?page=privacy')
    check(status in (303, 403), 'Anonymous policy preview is protected.')
    check(login(customer, 'customer@example.test') == 303, 'Synthetic customer signs in.')
    check(login(admin, 'admin@example.test', True) == 303, 'Synthetic administrator signs in.')
    status, headers, _ = request(customer, '/admin/sections?page=privacy')
    check(status == 303 and headers.get('Location', '').startswith('/login'), 'Customer cannot access the information editor.')
    status, headers, _ = request(customer, '/admin/information-preview?page=privacy')
    check(status == 303 and headers.get('Location', '').startswith('/login'), 'Customer cannot preview draft policy content.')
    customer_account = request(customer, '/account')[2]
    customer_logout = get_form(customer_account, '/logout')
    check(customer_logout is not None and customer_logout['fields'].get('csrf'),
          'Customer session supplies its own valid CSRF token for negative authorization checks.')

    status, _, admin_body = request(admin, '/admin/sections?page=privacy')
    check(status == 200 and 'Storefront sections' in admin_body and 'Policy publication' in admin_body,
          'Administrator sees policy editing and publication controls.')
    edit = get_form(admin_body, '/admin/sections/save')
    publish = get_form(admin_body, '/admin/sections/publish-policy')
    check(edit is not None and edit['fields'].get('section_key') and edit['fields'].get('csrf'),
          'Policy editor provides a selected section and CSRF token.')
    check(publish is not None and publish['fields'].get('revision') and publish['fields'].get('csrf'),
          'Policy publication form carries its draft revision and CSRF token.')
    check('I reviewed this exact draft revision and approve publishing it.' in admin_body,
          'Publication requires a visible exact-revision confirmation.')
    check(request(admin, '/admin/sections/publish-policy')[0] == 405,
          'Policy publication rejects GET.')
    check(request(admin, '/admin/sections/save')[0] == 405, 'Section save rejects GET.')
    check(request(admin, '/admin/sections/publish-policy', {})[0] == 419,
          'Policy publication rejects a missing CSRF token.')
    check(request(admin, '/admin/sections/save', {})[0] == 419,
          'Section save rejects a missing CSRF token.')

    check('/admin/information-preview?page=privacy' in [link['href'] for link in parse(admin_body).links],
          'Protected editor links directly to its policy preview.')

    status, _, preview = request(admin, '/admin/information-preview?page=privacy')
    preview_doc = parse(preview)
    check(status == 200 and len([h for h in preview_doc.headings if h['tag'] == 'h1']) == 1,
          'Administrator can inspect a semantic draft preview.')
    check('/privacy' in [link['href'] for link in preview_doc.footer_links],
          'Preview footer links to the separate public baseline, not the private draft.')

    # Save through the ordinary protected editor and prove the draft stays private.
    draft_heading = 'Policy publication test draft'
    draft_body = 'T016-HTTP-PUBLISHED — isolated policy snapshot marker.'
    save_fields = dict(edit['fields'])
    save_fields.update({'heading': draft_heading, 'body': draft_body})
    status, headers, _ = request(admin, '/admin/sections/save', save_fields)
    check(status == 303 and 'saved=1' in headers.get('Location', ''),
          'A policy section edit saves through the existing editor.')
    status, _, preview = request(admin, '/admin/information-preview?page=privacy')
    check(status == 200 and draft_heading in preview and draft_body in preview,
          'Draft preview displays the saved policy edit.')
    status, _, body = request(anonymous, '/privacy')
    check(status == 200 and draft_body not in body,
          'Unpublished policy draft remains absent from its public route.')

    updated_admin = request(admin, '/admin/sections?page=privacy')[2]
    publish_form = get_form(updated_admin, '/admin/sections/publish-policy')
    edit_form = get_form(updated_admin, '/admin/sections/save')
    check(publish_form is not None and edit_form is not None, 'Current policy forms reload after editing.')
    publish_fields = dict(publish_form['fields'])
    publish_fields.update({'version': 'http-1.0', 'effective_date': datetime.date.today().isoformat()})
    for label, overrides in [
        ('invalid version', {'version': '../bad'}),
        ('invalid effective date', {'effective_date': '2026-02-31'}),
        ('stale revision', {'revision': str(int(publish_fields['revision']) + 1)}),
        ('missing confirmation', {'confirm_publish': ''}),
    ]:
        invalid = dict(publish_fields)
        invalid.update(overrides)
        status, _, response_body = request(admin, '/admin/sections/publish-policy', invalid)
        check(status == 422, 'Policy publication rejects ' + label + '.')
        check('Policy was not published' in response_body,
              'Invalid publication shows a controlled validation response: ' + label + '.')

    _, _, customer_admin_login = request(customer, '/login', None, ADMIN_HOST)
    customer_admin_token = get_form(customer_admin_login, '/login')['fields']['csrf']
    customer_publish_fields = dict(publish_fields)
    customer_publish_fields['csrf'] = customer_admin_token
    status, headers, _ = request(customer, '/admin/sections/publish-policy', customer_publish_fields)
    check(status == 303 and headers.get('Location', '').startswith('/login'),
          'Customer with a valid administrator form token is denied publication.')
    customer_save_fields = dict(save_fields)
    customer_save_fields['csrf'] = customer_admin_token
    status, headers, _ = request(customer, '/admin/sections/save', customer_save_fields)
    check(status == 303 and headers.get('Location', '').startswith('/login'),
          'Customer with a valid administrator form token is denied section edits.')

    publish_fields['confirm_publish'] = '1'
    status, headers, _ = request(admin, '/admin/sections/publish-policy', publish_fields)
    check(status == 303 and 'published=1' in headers.get('Location', ''),
          'Administrator explicitly publishes a valid isolated policy revision.')
    public_before, public_doc = expect_public_document(anonymous, '/privacy', 'Published privacy policy', check)
    check(draft_heading in public_before and draft_body in public_before,
          'Public policy displays the approved snapshot and its versioned copy.')
    check(publish_fields['version'] in public_before and publish_fields['effective_date'] in public_before,
          'Published policy exposes its version and effective date.')

    # A later draft edit must not mutate the append-only public snapshot.
    edit_fields = dict(edit_form['fields'])
    edit_fields.update({'heading': 'Policy publication test later draft',
                        'body': 'T016-HTTP-LATER-DRAFT — must remain private.'})
    status, _, _ = request(admin, '/admin/sections/save', edit_fields)
    check(status == 303, 'A later policy edit creates an unpublished draft revision.')
    status, _, latest_preview = request(admin, '/admin/information-preview?page=privacy')
    check(status == 200 and 'T016-HTTP-LATER-DRAFT' in latest_preview,
          'Draft preview displays the later unpublished revision.')
    status, _, public_after = request(anonymous, '/privacy')
    check(status == 200 and draft_body in public_after and 'T016-HTTP-LATER-DRAFT' not in public_after,
          'Public policy remains the immutable published snapshot after a draft save.')

    history_status, _, history = request(admin, '/admin/sections?page=privacy')
    check(history_status == 200 and 'Published history' in history and 'http-1.0' in history,
          'Administrator can review the immutable publication history.')
    check('unpublished edits' in history, 'Admin state identifies edits made after publication.')

    # Footer destinations are extracted from a public page and checked as actual routes.
    footer_document = parse(public_after)
    footer_paths = []
    for link in footer_document.footer_links:
        href = link['href']
        if href.startswith('/'):
            parsed = urlsplit(href)
            destination = parsed.path + (('?' + parsed.query) if parsed.query else '')
            if destination not in footer_paths:
                footer_paths.append(destination)
    check(bool(footer_paths), 'Public information page renders a shared footer with local destinations.')
    for path in footer_paths:
        status, headers, _ = request(anonymous, path)
        check(status in (200, 303), 'Footer destination resolves safely: ' + path)
        if status == 303:
            check(headers.get('Location', '').startswith('/login'),
                  'Protected account destination redirects to sign-in: ' + path)
    check('/privacy' in footer_paths, 'Published privacy policy is linked visibly from the footer.')
    check('/terms' in footer_paths, 'Terms baseline remains linked from the footer.')
    for anchor in ['corporate', 'trade']:
        check(anchor in parse(request(anonymous, '/contact')[2]).ids,
              'Contact footer section target exists: #' + anchor)

    # Shop destinations remain real application routes from the information footer.
    for path in ['/catalogue', '/catalogue?mode=rent', '/arrangements', '/collections', '/collections/homes']:
        check(path in footer_paths, 'Shared footer retains shop destination: ' + path)

    print(f'Information pages HTTP checks: {checks} passed, 0 failed.')


def login(opener, email, admin_host=False):
    host = ADMIN_HOST if admin_host else STORE_HOST
    status, _, body = request(opener, '/login', None, host)
    form = get_form(body, '/login')
    if status != 200 or form is None:
        raise AssertionError('Sign-in form did not load for fixture account.')
    fields = dict(form['fields'])
    fields.update({'email': email, 'password': 'VerdantDemo!2026'})
    status, headers, _ = request(opener, '/login', fields, host)
    location = headers.get('Location', '')
    if location in ('/admin/2fa', '/admin/2fa/setup'):
        return complete_admin_2fa(opener, email, location)
    return status


def complete_admin_2fa(opener, email, location):
    import admin_2fa
    database = os.getenv('DB_NAME', DATABASE)
    if location == '/admin/2fa/setup':
        status, _, body = request(opener, '/admin/2fa/setup')
        form = get_form(body, '/admin/2fa/setup')
        if status != 200 or form is None:
            raise AssertionError('Two-factor setup did not load for fixture administrator.')
        secret = admin_2fa.credential_secret(database, email)
        fields = dict(form['fields'])
        fields.update({'code': admin_2fa.current_code(secret)})
        status, _, body = request(opener, '/admin/2fa/setup', fields)
        if status != 200 or 'Save your backup codes' not in body:
            raise AssertionError('Two-factor setup did not complete for fixture administrator.')
    status, headers, body = request(opener, '/admin/2fa')
    if status == 303:
        return status
    form = get_form(body, '/admin/2fa')
    if status != 200 or form is None:
        raise AssertionError('Two-factor challenge did not load for fixture administrator.')
    secret = admin_2fa.credential_secret(database, email)
    fields = dict(form['fields'])
    fields.update({'code': admin_2fa.current_code(secret)})
    status, headers, _ = request(opener, '/admin/2fa', fields)
    if status != 303 or headers.get('Location') != '/admin':
        raise AssertionError('Two-factor challenge did not sign in the fixture administrator.')
    return status


def main():
    if os.getenv('APP_ENV') != 'local' or os.getenv('DB_NAME') != DATABASE:
        raise RuntimeError(f'Use only APP_ENV=local with DB_NAME={DATABASE}.')
    snapshot = fixture('snapshot')
    try:
        run_checks(snapshot)
    finally:
        print('Fixture cleanup:', fixture('restore', snapshot))
    if fixture('snapshot') != snapshot:
        raise AssertionError('The isolated privacy/terms policy records were not restored exactly.')
    print('PASS: Original isolated policy sections and publication rows restored exactly.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Information pages HTTP checks failed:', error, file=sys.stderr)
        sys.exit(1)
