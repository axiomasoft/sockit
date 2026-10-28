#!/usr/bin/env python3
"""Run an item's literal validation command and record actual, task-scoped evidence."""
import datetime, hashlib, json, os, pathlib, platform, shlex, subprocess, sys, tempfile
root = pathlib.Path(__file__).resolve().parents[2]
out = root / 'artifacts/p3.5'
context = json.loads((root / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.5-context.json').read_text())
check_id = sys.argv[1]
command = next(c['cmd'] for c in context['item']['validation'] if c['id'] == check_id)
paths = ['packages/core/composer.json', 'packages/core/src/SocKitServiceProvider.php', 'packages/core/src/Schema/StoragePreflight.php', 'packages/core/src/Internal/Console/MakePanelCommand.php', 'tests/Feature/Consumer/PanelGeneratorInstallTest.php']
for directory in ['packages/core/src/Internal/Generation', 'packages/core/resources/stubs/panels', 'tests/Feature/Generation', 'tests/Fixtures/PanelConsumer']:
    paths.extend(str(p.relative_to(root)) for p in (root / directory).rglob('*') if p.is_file())
fingerprints = {p: hashlib.sha256((root / p).read_bytes()).hexdigest() for p in sorted(paths)}
digest = hashlib.sha256(json.dumps(fingerprints, sort_keys=True).encode()).hexdigest()
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
now = lambda: datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds').replace('+00:00', 'Z')
# Preserve earlier runs, including failures, before replacing the final-check filenames.
if (out / (check_id + '.json')).exists():
    prior = json.loads((out / (check_id + '.json')).read_text())
    history = out / 'history' / (check_id + '-' + prior['started_at'].replace(':', '-'))
    history.mkdir(parents=True, exist_ok=False)
    for suffix in ['.stdout.log', '.stderr.log', '.log', '-source-fingerprints.json']:
        source = out / (check_id + suffix)
        if source.exists():
            source.rename(history / source.name)
    prior['log']['path'] = str((history / (check_id + '.log')).relative_to(root))
    (history / (check_id + '.json')).write_text(json.dumps(prior, indent=2) + '\n')
with tempfile.TemporaryDirectory(prefix='sockit-p35-toolchain-') as toolchain:
    os.symlink('/usr/bin/php8.4', toolchain + '/php')
    os.symlink('/usr/local/bin/composer', toolchain + '/composer')
    env = {**os.environ, 'PATH': toolchain + ':' + os.environ['PATH'], 'XDEBUG_MODE': 'off'}
    php = subprocess.check_output(['php', '-v'], env=env, text=True).strip()
    started = now()
    result = subprocess.run(shlex.split(command), cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    finished = now()
    (out / (check_id + '.stdout.log')).write_bytes(result.stdout)
    (out / (check_id + '.stderr.log')).write_bytes(result.stderr)
    log = ('command=' + command + '\ncwd=' + str(root) + '\nstarted_at=' + started + '\nfinished_at=' + finished + '\nexit=' + str(result.returncode) + '\nphp=' + php + '\nsource_digest=' + digest + '\n--- stdout ---\n').encode() + result.stdout + b'\n--- stderr ---\n' + result.stderr
    log_path = out / (check_id + '.log')
    log_path.write_bytes(log)
    evidence = {'$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p3.5-' + check_id + '-' + started, 'check_id': check_id, 'subject': 'PLAN1.P3.5', 'procedure': {'cmd': command, 'cwd': '.'}, 'candidate': {'head': head, 'dirty_digest': digest}, 'exit': result.returncode, 'environment': {'platform': platform.platform(), 'python': platform.python_version(), 'env_digest': hashlib.sha256(json.dumps({'php': php, 'XDEBUG_MODE': 'off', 'session': os.environ['CODEX_SESSION_ID']}, sort_keys=True).encode()).hexdigest()}, 'log': {'path': str(log_path.relative_to(root)), 'sha256': hashlib.sha256(log).hexdigest()}, 'started_at': started, 'finished_at': finished, 'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'xhigh', 'session': os.environ['CODEX_SESSION_ID']}}}
    (out / (check_id + '.json')).write_text(json.dumps(evidence, ensure_ascii=False, indent=2) + '\n')
    (out / (check_id + '-source-fingerprints.json')).write_text(json.dumps({'method': 'SHA-256 of sorted item source/test/fixture path-to-content hashes; foreign changes and evidence excluded', 'digest': digest, 'files': fingerprints}, indent=2) + '\n')
    print(check_id + ' exit=' + str(result.returncode) + ' source=' + digest)
    print((result.stdout + result.stderr).decode(errors='replace')[-3500:])
    sys.exit(result.returncode)
