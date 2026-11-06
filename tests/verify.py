#!/usr/bin/env python3
"""Portable local/CI gate. Caller supplies an isolated DB; private env is never printed."""
import argparse
import datetime
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
SUITES = ['catalogue','catalogue_foundation','identity','cart','orders','payment','history','database',
          'rental_capacity','rental_capacity_database','rentals','rental_payment','rental_race',
          'admin_catalogue','admin_operations','admin_rentals','admin_race',
          'integration_workflows','integration_failures','integration_race','catalogue_fixture',
          'catalogue_variants','catalogue_variants_seed','compositions','compositions_admin','compositions_race','composition_payment','collections']

def run(command, log):
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=180)
    output = result.stdout + result.stderr
    log.write_text(output)
    if result.returncode or re.search(r'(?:PHP )?(?:Warning|Deprecated|Fatal error):', output):
        raise RuntimeError(f'Gate failed; inspect {log.relative_to(ROOT)} locally.')
    return output

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--evidence', default='tests/evidence')
    parser.add_argument('--security', action='store_true', help='Include security payment and HTTP suites (loopback8094).')
    parser.add_argument('--catalogue-http', action='store_true', help='Include family/compatibility admin route checks (loopback8095).')
    parser.add_argument('--composition-http', action='store_true', help='Include arrangement ownership/form checks (loopback8095).')
    parser.add_argument('--collection-http', action='store_true', help='Include read-only collection/filter route checks (loopback8095).')
    args = parser.parse_args()
    if os.getenv('APP_ENV') != 'local' or not re.fullmatch(r'verdant_test_[a-z0-9_]+', os.getenv('DB_NAME','')):
        parser.error('Use APP_ENV=local and an isolated verdant_test_* database; no tests were run.')
    evidence = (ROOT / args.evidence).resolve()
    if not evidence.is_relative_to(ROOT):
        parser.error('Evidence directory must remain inside this repository.')
    evidence.mkdir(parents=True, exist_ok=True)
    summary = {'assessed_revision':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
               'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
               'database':os.environ['DB_NAME'], 'environment':'local synthetic', 'suites':{}, 'status':'running'}
    try:
        lint = []
        for folder in ['app','public','tests','database/bin']:
            for file in sorted((ROOT / folder).rglob('*.php')):
                result = subprocess.run(['php','-l',str(file)],capture_output=True,text=True,timeout=15)
                lint.append(result.stdout+result.stderr)
                if result.returncode: raise RuntimeError('PHP syntax gate failed: '+str(file.relative_to(ROOT)))
        (evidence/'php-lint.log').write_text(''.join(lint))
        summary['php_syntax_files'] = len(lint)
        print(f'PASS: PHP syntax ({len(lint)} files).',flush=True)
        suites = SUITES + (['security_payment'] if args.security else [])
        for suite in suites:
            output = run(['php',str(ROOT/'tests'/f'{suite}.php')], evidence/f'{suite}.log')
            match = re.search(r'(\d+) passed, 0 failed',output) or re.search(r'(\d+) identity checks',output)
            if not match: raise RuntimeError('Missing assertion count for '+suite)
            count = int(match.group(1))
            summary['suites'][suite] = count
            print(f'PASS: {suite} ({count}).',flush=True)
        if args.security:
            output = run([sys.executable,str(ROOT/'tests/security_http.py')], evidence/'security-http.log')
            match = re.search(r'(\d+) passed, 0 failed',output)
            if not match: raise RuntimeError('Missing HTTP assertion count.')
            summary['suites']['security_http'] = int(match.group(1))
            print(f'PASS: security HTTP ({match.group(1)}).',flush=True)
        if args.catalogue_http:
            output = run([sys.executable,str(ROOT/'tests/catalogue_variants_http.py')], evidence/'catalogue-variants-http.log')
            match = re.search(r'(\d+) passed, 0 failed',output)
            if not match: raise RuntimeError('Missing catalogue HTTP assertion count.')
            summary['suites']['catalogue_variants_http'] = int(match.group(1))
            print(f'PASS: catalogue variants HTTP ({match.group(1)}).',flush=True)
        if args.composition_http:
            output = run([sys.executable, str(ROOT/'tests/compositions_http.py')], evidence/'compositions-http.log')
            match = re.search(r'(\d+) passed, 0 failed', output)
            if not match: raise RuntimeError('Missing composition HTTP assertion count.')
            summary['suites']['compositions_http'] = int(match.group(1))
            print(f'PASS: composition HTTP ({match.group(1)}).', flush=True)
        if args.collection_http:
            output = run([sys.executable, str(ROOT/'tests/collections_http.py')], evidence/'collections-http.log')
            match = re.search(r'(\d+) passed, 0 failed', output)
            if not match: raise RuntimeError('Missing collection HTTP assertion count.')
            summary['suites']['collections_http'] = int(match.group(1))
            print(f'PASS: collection HTTP ({match.group(1)}).', flush=True)
        summary['assertions'] = sum(summary['suites'].values())
        summary['status'] = 'passed'
        print(f"Verification gate: {summary['assertions']} assertions pass. Hosted CI/deployment were not run.")
        return 0
    except (OSError,subprocess.SubprocessError,RuntimeError) as error:
        summary['status'] = 'failed'
        # Only our safe diagnostic is printed; subprocess output remains a local log.
        print(str(error) if isinstance(error,RuntimeError) else 'Verification tool failed; inspect local evidence.',file=sys.stderr)
        return 1
    finally:
        (evidence/'verification.json').write_text(json.dumps(summary,indent=2)+'\n')

if __name__ == '__main__':
    raise SystemExit(main())
