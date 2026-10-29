#!/usr/bin/env python3
"""Execute the literal item checks, retaining real output and source fingerprints."""
import datetime, hashlib, json, os, pathlib, platform, shlex, subprocess, sys, tempfile

root = pathlib.Path(__file__).resolve().parents[2]
out = root / 'artifacts/p3.8'
commands = {
    'V1': 'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Feature/Consumer/GeneratedPanelsInstallTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
    'V2': 'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Feature/Consumer/StandaloneRuntimeTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky',
    'V90': 'composer analyse -- --memory-limit=512M',
    'V91': 'composer lint',
}

def digest(data):
    return hashlib.sha256(data).hexdigest()

def sources():
    tracked = subprocess.check_output(['git', 'ls-files', '-z', '--', 'packages', 'tests', 'scripts', 'composer.json', 'composer.lock', 'phpunit.xml', 'phpunit.pg.xml', 'phpstan.neon', 'pint.json'], cwd=root).decode().split('\0')
    owned = ['tests/Feature/Consumer/GeneratedPanelsInstallTest.php', *[str(p.relative_to(root)) for p in (root/'tests/Fixtures/PanelConsumer').rglob('*.php')], 'docs/architecture/traceability.md']
    files = {p: digest((root/p).read_bytes()) for p in sorted(set(tracked+owned)-{''}) if (root/p).is_file()}
    return {'files': files, 'digest': digest(json.dumps(files, sort_keys=True, separators=(',', ':')).encode())}

def now():
    return datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

check = sys.argv[1]
command = commands[check]
source = sources()
head = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode().strip()
with tempfile.TemporaryDirectory(prefix='sockit-p38-toolchain-') as directory:
    pathlib.Path(directory, 'php').symlink_to('/usr/bin/php8.4')
    env = {**os.environ, 'PATH': directory+':'+os.environ['PATH'], 'XDEBUG_MODE': 'off'}
    version = subprocess.check_output(['php', '-v'], env=env).decode().splitlines()[0]
    start = now()
    result = subprocess.run(shlex.split(command), cwd=root, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    end = now()
    (out/(check+'.stdout.log')).write_bytes(result.stdout)
    (out/(check+'.stderr.log')).write_bytes(result.stderr)
    log = ('command: '+command+'\ncwd: '+str(root)+'\nphp: '+version+'\nstarted: '+start+'\n--- stdout ---\n').encode()+result.stdout+b'\n--- stderr ---\n'+result.stderr+('\nexit: '+str(result.returncode)+'\nfinished: '+end+'\n').encode()
    (out/(check+'.log')).write_bytes(log)
    assert sources() == source, 'Sources changed during validation'
    (out/(check+'-source-fingerprints.json')).write_text(json.dumps(source, indent=2)+'\n')
    if check.startswith('V'):
        evidence = {'$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p3.8-'+check+'-'+start, 'check_id': check, 'subject': 'PLAN1.P3.8',
            'procedure': {'cmd': command, 'cwd': '.'}, 'candidate': {'head': head, 'dirty_digest': source['digest']}, 'exit': result.returncode,
            'environment': {'platform': platform.platform()+'; '+version, 'python': platform.python_version(), 'env_digest': digest(json.dumps(env,sort_keys=True).encode())},
            'log': {'path': 'artifacts/p3.8/'+check+'.log', 'sha256': digest(log)}, 'started_at': start, 'finished_at': end,
            'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': 'gpt-6.1-terra', 'effort': 'high', 'session': os.environ['CODEX_SESSION_ID']}}}
        (out/(check+'.json')).write_text(json.dumps(evidence, indent=2)+'\n')
    print(check+' exit='+str(result.returncode)+' source='+source['digest'], flush=True)
    print((result.stdout+result.stderr).decode()[-2500:], flush=True)
    sys.exit(result.returncode)
