"""Execute PLAN1.P2.2 checks and retain actual streams and source fingerprints."""
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
from pathlib import Path
import platform
import shlex
import subprocess
import sys

root = Path(__file__).resolve().parents[2]
output = root / 'artifacts/p2.2'
spec = importlib.util.spec_from_file_location('candidate', root / 'scripts/review_candidate.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
patterns = ['packages/core/src/Operations/Data/OperationHandle.php', 'packages/core/src/Operations/OperationHandles.php',
            'packages/core/src/Operations/Internal/Storage/**', 'packages/core/src/SocKitServiceProvider.php',
            'tests/Unit/Operations/OperationHandleTest.php', 'tests/Integration/Runtime/PanelStorageTest.php',
            'tests/Feature/BootTest.php', 'tests/Fixtures/Runtime/**']
paths = module.expand_task_files(root, [{'task_files': [{'path': p} for p in patterns]}])
candidate = module.fingerprint(root, paths)
(output / 'source-manifest.json').write_text(json.dumps(candidate, indent=2) + '\n')
strict = ['--fail-on-skipped', '--fail-on-incomplete', '--fail-on-warning', '--fail-on-risky']
checks = {
    'V90': ['composer', 'analyse', '--', '--memory-limit=512M'],
    'V91': ['composer', 'lint'],
    'V1': ['composer', 'test', '--', 'tests/Unit/Operations/OperationHandleTest.php', *strict],
    'V2': ['python3', 'scripts/testing/pg-run.py', 'composer', 'test', '--', '--configuration', 'phpunit.pg.xml', 'tests/Integration/Runtime/PanelStorageTest.php', *strict],
    'V3': ['composer', 'test', '--', 'tests/Feature/BootTest.php', 'tests/Feature/Panels', 'tests/Arch', *strict],
}
php = subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], cwd=root, text=True)
for check, argv in checks.items():
    started = datetime.now(timezone.utc).isoformat()
    result = subprocess.run(argv, cwd=root, capture_output=True, timeout=180)
    finished = datetime.now(timezone.utc).isoformat()
    (output / (check + '.stdout.log')).write_bytes(result.stdout)
    (output / (check + '.stderr.log')).write_bytes(result.stderr)
    log = result.stdout + b'\n--- stderr ---\n' + result.stderr
    (output / (check + '.log')).write_bytes(log)
    evidence = {
        '$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p22-' + check,
        'check_id': check, 'subject': 'PLAN1.P2.2',
        'procedure': {'cmd': shlex.join(argv), 'cwd': str(root)},
        'candidate': {'head': candidate['head'], 'dirty_digest': candidate['digest'].removeprefix('sha256:')},
        'exit': result.returncode,
        'environment': {'platform': platform.platform(), 'php': php, 'database': 'P2.1 private PostgreSQL 16 container, exact ownership manifest; SQL identities in V2 stderr' if check == 'V2' else 'No database mutations'},
        'log': {'path': 'artifacts/p2.2/' + check + '.log', 'sha256': hashlib.sha256(log).hexdigest()},
        'started_at': started, 'finished_at': finished,
        'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'high'}},
    }
    (output / (check + '.json')).write_text(json.dumps(evidence, indent=2) + '\n')
    print(check + ' exit=' + str(result.returncode), flush=True)
    if module.fingerprint(root, paths) != candidate:
        raise RuntimeError('Source changed during validation')
    if result.returncode:
        sys.exit(result.returncode)
