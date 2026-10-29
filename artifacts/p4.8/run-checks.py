"""Run literal P4.8 checks and keep real logs plus source-bound check-evidence."""
import datetime, hashlib, importlib.util, json, os, pathlib, platform, shlex, subprocess, sys

sys.dont_write_bytecode = True

root = pathlib.Path(__file__).resolve().parents[2]
os.chdir(root)
out = root / 'artifacts/p4.8'
context = json.loads((root / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.8-context.json').read_text())
commands = {check['id']: check['cmd'] for check in context['item']['validation']}
commands.update({
    'V92': 'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Feature/Consumer/GeneratedPanelsInstallTest.php tests/Feature/Consumer/StandaloneRuntimeTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
})
spec = importlib.util.spec_from_file_location('review_candidate', root / 'scripts/review_candidate.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
paths = set()
for directory in ['packages/core', 'tests', 'scripts/testing']:
    paths.update(path.relative_to(root).as_posix() for path in (root / directory).rglob('*') if path.is_file())
paths.update(['composer.json', 'composer.lock', 'packages/core/composer.json', 'phpunit.xml', 'phpunit.pg.xml', 'phpstan.neon', 'pint.json'])
source = module.fingerprint(root, paths)
digest = source['digest'].removeprefix('sha256:')
source_file = out / 'source-fingerprint.json'
if source_file.exists() and json.loads(source_file.read_text()) != source:
    archive = out / 'source-fingerprints'
    archive.mkdir(exist_ok=True)
    prior = json.loads(source_file.read_text())
    (archive / (prior['digest'].removeprefix('sha256:') + '.json')).write_text(json.dumps(prior, indent=2) + '\n')
source_file.write_text(json.dumps(source, indent=2) + '\n')
for check_id in sys.argv[1:] or list(commands)[:6]:
    cmd = commands[check_id]
    path = out / (check_id + '.json')
    if path.exists():
        prior = json.loads(path.read_text())
        stamp = prior['started_at'].replace(':', '').replace('-', '')
        path.rename(out / (check_id + '-attempt-' + stamp + '.json'))
        (out / (check_id + '.log')).rename(out / (check_id + '-attempt-' + stamp + '.log'))
    started = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    print('START', check_id, cmd, flush=True)
    result = subprocess.run(shlex.split(cmd), cwd=root, capture_output=True)
    finished = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    log = out / (check_id + '.log')
    log.write_bytes(b'STDOUT\n' + result.stdout + b'\nSTDERR\n' + result.stderr)
    evidence = {'$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p4.8-' + check_id + '-' + started,
        'check_id': check_id, 'subject': 'PLAN1.P4.8', 'procedure': {'cmd': cmd, 'cwd': '.'},
        'candidate': {'head': source['head'], 'dirty_digest': digest}, 'exit': result.returncode,
        'environment': {'platform': platform.platform(), 'python': platform.python_version(),
            'env_digest': hashlib.sha256(json.dumps(dict(os.environ), sort_keys=True).encode()).hexdigest()},
        'log': {'path': log.relative_to(root).as_posix(), 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()},
        'started_at': started, 'finished_at': finished,
        'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'high', 'session': os.environ['CODEX_SESSION_ID']}}}
    path.write_text(json.dumps(evidence, indent=2) + '\n')
    if module.fingerprint(root, paths) != source:
        raise RuntimeError('Source changed during check ' + check_id)
    print('EXIT', check_id, result.returncode, flush=True)
    if result.returncode:
        print(log.read_text()[-14000:], flush=True)
        sys.exit(result.returncode)
