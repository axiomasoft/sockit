#!/usr/bin/env python3
import datetime, hashlib, json, os, platform, subprocess, sys
from pathlib import Path

root = Path(__file__).resolve().parents[2]
check = sys.argv[1]
commands = {
    'V1': 'python3 scripts/testing/pg-run.py composer test -- tests/Feature/Consumer/StandaloneRuntimeTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
    'V2': 'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Integration/Runtime --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
    'V3': 'python3 scripts/testing/pg-run.py composer check',
    'V4': 'composer refactor:check', 'V5': 'git diff --check',
    'V6': 'composer test -- tests/Unit/Docs/CheckDocsTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
    'V90': 'composer analyse -- --memory-limit=512M', 'V91': 'composer lint',
}
cmd = commands[check]
now = lambda: datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
start = now()
log = root / 'artifacts' / 'p2.10' / (check + '-evidence.log')
with log.open('wb') as out:
    result = subprocess.run(cmd, shell=True, cwd=root, stdout=out, stderr=subprocess.STDOUT)
evidence = {'$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p2.10-'+check, 'check_id': check, 'subject': 'PLAN1.P2.10', 'procedure': {'cmd': cmd, 'cwd': '.'}, 'candidate': {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()}, 'exit': result.returncode, 'started_at': start, 'finished_at': now(), 'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-terra', 'effort': 'high', 'session': os.environ['CODEX_SESSION_ID']}}, 'environment': {'platform': platform.platform(), 'python': platform.python_version()}, 'log': {'path': str(log.relative_to(root)), 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()}}
(root / 'artifacts' / 'p2.10' / (check + '.json')).write_text(json.dumps(evidence, indent=2)+'\n')
print(check, result.returncode, flush=True)
sys.exit(result.returncode)
