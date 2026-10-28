#!/usr/bin/env python3
"""Execute the exact item command and capture native evidence, without environment values."""
import datetime
import hashlib
import json
import os
import pathlib
import platform
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[2]
base = root / 'artifacts/p3.1'
check = sys.argv[1]
context = json.loads((root / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.1-context.json').read_text())
commands = {v['id']: v['cmd'] for v in context['item']['validation']}
commands['V92'] = 'python3 scripts/testing/pg-run.py composer test -- --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky'
cmd = commands[check]
now = lambda: datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
manifest = json.loads((base / 'source-manifest.json').read_text())
for path, digest in manifest['files'].items():
    assert hashlib.sha256((root / path).read_bytes()).hexdigest() == digest, path + ' source changed'
started = now()
result = subprocess.run(cmd, shell=True, cwd=root, capture_output=True)
finished = now()
(base / (check + '.stdout.log')).write_bytes(result.stdout)
(base / (check + '.stderr.log')).write_bytes(result.stderr)
log = base / (check + '.log')
log.write_bytes(('command=' + cmd + '\ncwd=' + str(root) + '\nstdout:\n').encode() + result.stdout + b'\nstderr:\n' + result.stderr)
environment = {'platform': platform.platform(), 'python': platform.python_version(), 'env_digest': hashlib.sha256(json.dumps({k: os.environ.get(k) for k in ['PATH', 'CODEX_SESSION_ID', 'APP_ENV']}, sort_keys=True).encode()).hexdigest()}
evidence = {
    '$schema': 'urn:swissknifeman:task:plan:check-evidence:v1',
    'evidence_id': 'p3.1-' + check, 'check_id': check, 'subject': 'PLAN1.P3.1',
    'procedure': {'cmd': cmd, 'cwd': '.'},
    'candidate': {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip(), 'dirty_digest': hashlib.sha256((base / 'source-manifest.json').read_bytes()).hexdigest()},
    'exit': result.returncode, 'started_at': started, 'finished_at': finished,
    'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'high', 'session': os.environ['CODEX_SESSION_ID']}},
    'environment': environment,
    'log': {'path': str(log.relative_to(root)), 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()},
}
(base / (check + '.json')).write_text(json.dumps(evidence, indent=2) + '\n')
print(check + ': exit=' + str(result.returncode))
print(result.stdout.decode(errors='replace')[-1200:])
print(result.stderr.decode(errors='replace')[-1200:])
sys.exit(result.returncode)
