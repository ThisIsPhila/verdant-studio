"""Shared admin two-factor journey for HTTP suites (test-only)."""

import base64
import hashlib
import hmac
import os
import struct
import subprocess
import time
from pathlib import Path


def current_code(secret_base32, for_time=None):
    """Compute the current RFC 6238 TOTP code for a base32 secret."""
    key = base64.b32decode(secret_base32)
    counter = struct.pack('>Q', int((time.time() if for_time is None else for_time) // 30))
    digest = hmac.new(key, counter, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    value = struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return str(value % 1000000).zfill(6)


def credential_secret(database, email):
    """Read an enrolled administrator's base32 TOTP secret from the test database."""
    script = Path(__file__).with_name('totp_secret_fixture.php')
    env = {**os.environ, 'APP_ENV': 'local', 'DB_NAME': database}
    result = subprocess.run(['php', str(script), email], capture_output=True, text=True,
                            timeout=15, env=env)
    if result.returncode != 0:
        raise RuntimeError('TOTP fixture failed: ' + result.stderr[-500:])
    secret = result.stdout.strip()
    if not secret:
        raise RuntimeError('TOTP fixture returned no secret.')
    return secret
