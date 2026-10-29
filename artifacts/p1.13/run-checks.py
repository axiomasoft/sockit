#!/usr/bin/env python3
"""Capture actual P1.13 checks against a scoped source snapshot."""
import datetime
import hashlib
import json
import os
import pathlib
import platform
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts/p1.13'
os.chdir(ROOT)

def write(name, value):
    (OUT / name).write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')

def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')

paths = json.loads((OUT / 'source-paths.json').read_text())
paths += [str(path.relative_to(ROOT)) for path in (ROOT / 'packages/core/resources/contracts/fixtures/panels').glob('*.json')]
paths += ['packages/core/resources/contracts/fixtures/problems/registry.json', 'packages/core/src/Errors/Enums/ProblemCode.php']
manifest = {path: hashlib.sha256((ROOT / path).read_bytes()).hexdigest() for path in sorted(paths)}
digest = hashlib.sha256(json.dumps(manifest, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
write('source-manifest.json', {'head': head, 'dirty_digest': digest, 'algorithm': 'sha256(sorted compact JSON path:sha256)', 'files': manifest})
actor = {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'high'}
env = dict(os.environ)
env['PHP_INI_SCAN_DIR'] = env.get('PHP_INI_SCAN_DIR', '') + ':' + str(OUT / 'php-ini')
checks = [
    ('V2', 'vendor/bin/phpstan analyse --no-progress'),
    ('V3', 'vendor/bin/pint --test'),
    ('V1', 'vendor/bin/pest tests/Unit/Panels tests/Feature/Panels tests/Unit/Contracts tests/Arch'),
    ('V99', 'vendor/bin/pest'),
]
for check_id, cmd in checks:
    start = now()
    log = OUT / (check_id + '.log')
    with log.open('wb') as stream:
        result = subprocess.run(cmd.split(), cwd=ROOT, env=env, stdout=stream, stderr=subprocess.STDOUT)
    write(check_id + '.json', {
        '$schema': 'urn:swissknifeman:task:plan:check-evidence:v1',
        'evidence_id': 'PLAN1.P1.13.' + check_id,
        'check_id': check_id, 'subject': 'PLAN1.P1.13',
        'procedure': {'cmd': cmd, 'cwd': '.'},
        'candidate': {'head': head, 'dirty_digest': digest},
        'exit': result.returncode,
        'environment': {'platform': platform.platform(), 'env_digest': hashlib.sha256(env['PHP_INI_SCAN_DIR'].encode()).hexdigest()},
        'log': {'path': str(log.relative_to(ROOT)), 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()},
        'started_at': start, 'finished_at': now(), 'producer': {'kind': 'executor', 'actor': actor},
    })
    print(check_id, 'exit', result.returncode, flush=True)
    print('\n'.join(log.read_text().splitlines()[-5:]), flush=True)
    if result.returncode:
        raise SystemExit(result.returncode)
changed = [p for p, sha in manifest.items() if hashlib.sha256((ROOT / p).read_bytes()).hexdigest() != sha]
if changed:
    raise SystemExit('Source changed during checks: ' + repr(changed))
print('Source snapshot unchanged.', flush=True)
