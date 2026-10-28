#!/usr/bin/env python3
"""Record executed checks, immutable source inventory and secret-free evidence."""
import datetime
import hashlib
import json
import os
import pathlib
import platform
import shlex
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts/p3.2'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def inventory():
    files = []
    for directory in ('packages', 'tests', 'scripts'):
        files += [p for p in (ROOT / directory).rglob('*') if p.is_file() and 'vendor' not in p.parts and '__pycache__' not in p.parts]
    files += [ROOT / name for name in ('composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.pg.xml', 'phpstan.neon', 'pint.json', 'rector.php') if (ROOT / name).is_file()]
    return {str(p.relative_to(ROOT)): digest(p.read_bytes()) for p in sorted(set(files))}


def run(check):
    context = json.loads((ROOT / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.2-context.json').read_text())
    commands = {c['id']: c['cmd'] for c in context['item']['validation']}
    commands['V92'] = 'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Integration/Runtime/ConsoleCommandsTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky'
    commands['V93'] = 'python3 scripts/testing/pg-run.py composer test -- --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky'
    cmd = commands[check]
    source = inventory()
    source_bytes = (json.dumps(source, sort_keys=True, indent=2) + '\n').encode()
    source_digest = digest(source_bytes)
    source_file = OUT / 'source-manifest.json'
    if source_file.exists() and source_file.read_bytes() != source_bytes:
        raise RuntimeError('Sources changed since the first final check; archive evidence before rerunning')
    source_file.write_bytes(source_bytes)
    env = os.environ.copy()
    env['PATH'] = '/tmp/sockit-p32-bin:' + env['PATH']
    env['XDEBUG_MODE'] = 'off'
    start = datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
    stdout = OUT / (check + '.stdout.log')
    stderr = OUT / (check + '.stderr.log')
    with stdout.open('wb') as out, stderr.open('wb') as err:
        try:
            code = subprocess.run(shlex.split(cmd), cwd=ROOT, env=env, stdout=out, stderr=err, timeout=300).returncode
        except subprocess.TimeoutExpired:
            code = 124
    finish = datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
    if inventory() != source:
        raise RuntimeError('Sources mutated during validation; evidence refused')
    log = OUT / (check + '.log')
    log.write_bytes(('command: ' + cmd + '\ncwd: ' + str(ROOT) + '\nsource_manifest_sha256: ' + source_digest + '\nexit: ' + str(code) + '\n--- stdout ---\n').encode() + stdout.read_bytes() + b'\n--- stderr ---\n' + stderr.read_bytes())
    evidence = {
        '$schema': 'urn:swissknifeman:task:plan:check-evidence:v1',
        'evidence_id': 'p3.2-' + check,
        'check_id': check, 'subject': 'PLAN1.P3.2',
        'procedure': {'cmd': cmd, 'cwd': '.'},
        'candidate': {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(), 'dirty_digest': source_digest},
        'exit': code, 'started_at': start, 'finished_at': finish,
        'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'high', 'session': os.environ['CODEX_SESSION_ID']}},
        'environment': {'platform': platform.platform(), 'python': platform.python_version(), 'env_digest': digest(json.dumps(env, sort_keys=True).encode())},
        'log': {'path': str(log.relative_to(ROOT)), 'sha256': digest(log.read_bytes())},
    }
    (OUT / (check + '.json')).write_text(json.dumps(evidence, indent=2) + '\n')
    print(check + ' exit=' + str(code) + ' source=' + source_digest, flush=True)
    print(stdout.read_text()[-3000:], flush=True)
    print(stderr.read_text()[-1500:], flush=True)
    return code


if __name__ == '__main__':
    os.chdir(ROOT)
    for check_id in sys.argv[1:]:
        status = run(check_id)
        if status != 0:
            sys.exit(status)
