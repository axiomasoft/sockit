#!/usr/bin/env python3
"""Execute P4.3's literal checks; preserve streams and source-bound native evidence."""
import datetime
import glob
import hashlib
import importlib.util
import json
import os
import pathlib
import platform
import shlex
import subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts/p4.3'
CONTEXT = ROOT / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.3-context.json'
pack = json.loads(CONTEXT.read_text())
spec = importlib.util.spec_from_file_location('candidate', ROOT / 'scripts/review_candidate.py')
candidate_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(candidate_module)
source_document = {'task_files': [p for p in pack['item']['task_files'] if not p['path'].startswith('artifacts/')]}
paths = candidate_module.expand_task_files(ROOT, [source_document])
fingerprint = candidate_module.fingerprint(ROOT, paths)
(OUT / 'source-fingerprint.json').write_text(json.dumps(fingerprint, indent=2) + '\n')
session = os.environ['CODEX_SESSION_ID']
rollouts = glob.glob(str(pathlib.Path.home() / '.codex/sessions' / '**' / ('*' + session + '*')), recursive=True)
if len(rollouts) != 1:
    raise RuntimeError('Expected exactly one actual runtime session')
meta = turn = None
for line in pathlib.Path(rollouts[0]).open():
    entry = json.loads(line)
    if entry.get('type') == 'session_meta':
        meta = entry['payload']
    if entry.get('type') == 'turn_context':
        turn = entry['payload']
        break
if meta['id'] != session or meta['originator'] != 'codex_exec' or turn['model'] != 'gpt-6.1-sol' or turn['effort'] != 'high' or turn['sandbox_policy']['type'] != 'danger-full-access':
    raise RuntimeError('Actual runtime does not match execution pin')
runtime = {'session': session, 'provider': 'codex', 'backend': meta['model_provider'], 'originator': meta['originator'], 'cli_version': meta['cli_version'],
           'model': turn['model'], 'effort': turn['effort'], 'sandbox': turn['sandbox_policy']['type'], 'approval_policy': turn['approval_policy']}
(OUT / 'runtime.json').write_text(json.dumps(runtime, indent=2) + '\n')
environment = {'platform': platform.platform() + '; ' + subprocess.check_output(['php', '-v'], text=True).splitlines()[0],
               'python': platform.python_version(), 'env_digest': hashlib.sha256(json.dumps(dict(os.environ), sort_keys=True).encode()).hexdigest()}
now = lambda: datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds').replace('+00:00', 'Z')
exits = {}
import sys
for check in pack['item']['validation']:
    if len(sys.argv) > 1 and check['id'] not in sys.argv[1:]:
        continue
    check_id = check['id']
    started = now()
    completed = subprocess.run(shlex.split(check['cmd']), cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    finished = now()
    log = OUT / (check_id + '.log')
    log.write_bytes(b'=== stdout ===\n' + completed.stdout + b'\n=== stderr ===\n' + completed.stderr)
    evidence = {'$schema': 'urn:swissknifeman:task:plan:check-evidence:v1', 'evidence_id': 'p4.3-' + check_id + '-' + started,
                'check_id': check_id, 'subject': 'PLAN1.P4.3', 'procedure': {'cmd': check['cmd'], 'cwd': '.'},
                'candidate': {'head': fingerprint['head'], 'dirty_digest': fingerprint['digest'].removeprefix('sha256:')},
                'exit': completed.returncode, 'environment': environment,
                'log': {'path': log.relative_to(ROOT).as_posix(), 'sha256': hashlib.sha256(log.read_bytes()).hexdigest()},
                'started_at': started, 'finished_at': finished,
                'producer': {'kind': 'executor', 'actor': {'provider': 'codex', 'model': runtime['model'], 'effort': runtime['effort'], 'session': session}}}
    (OUT / (check_id + '.json')).write_text(json.dumps(evidence, indent=2) + '\n')
    exits[check_id] = completed.returncode
    print(check_id + '=' + str(completed.returncode), flush=True)
    if completed.returncode:
        print(log.read_text()[-12000:], flush=True)
        break
if candidate_module.fingerprint(ROOT, paths) != fingerprint:
    raise RuntimeError('Source changed while checks were executing')
(OUT / 'check-exits.json').write_text(json.dumps(exits, indent=2) + '\n')
raise SystemExit(next((code for code in exits.values() if code), 0))
