#!/usr/bin/env python3
"""Validate actual check evidence, log hashes, literal commands and source identity."""
import hashlib
import importlib.util
import json
import pathlib
import sys
from jsonschema import Draft202012Validator, RefResolver

root = pathlib.Path(__file__).resolve().parents[2]
out = root / 'artifacts/p4.4'
schema = json.loads((out / 'check-evidence-schema.json').read_text())['data']
defs = json.loads((out / 'defs-schema.json').read_text())['data']
resolver = RefResolver.from_schema(schema, store={defs['$id']: defs})
validator = Draft202012Validator(schema, resolver=resolver)
pack = json.loads((root / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.4-context.json').read_text())
spec = importlib.util.spec_from_file_location('candidate', root / 'scripts/review_candidate.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
paths = module.expand_task_files(root, [{'task_files': [p for p in pack['item']['task_files'] if not p['path'].startswith('artifacts/')] + [{'path': 'tests/Feature/Social/ModuleSelectionTest.php', 'change': 'modify'}]}])
fingerprint = json.loads((out / 'source-fingerprint.json').read_text())
if '--committed' in sys.argv:
    # Commit changes HEAD only; the already-tested file identities must remain exact.
    current = module.fingerprint(root, paths)
    assert current['paths'] == fingerprint['paths'] and current['digest'] == fingerprint['digest']
else:
    assert module.fingerprint(root, paths) == fingerprint
for check in pack['item']['validation']:
    evidence = json.loads((out / (check['id'] + '.json')).read_text())
    validator.validate(evidence)
    assert evidence['check_id'] == check['id'] and evidence['subject'] == 'PLAN1.P4.4'
    assert evidence['procedure'] == {'cmd': check['cmd'], 'cwd': '.'}
    assert evidence['exit'] == 0
    assert evidence['candidate'] == {'head': fingerprint['head'], 'dirty_digest': fingerprint['digest'].removeprefix('sha256:')}
    assert evidence['log']['sha256'] == hashlib.sha256((root / evidence['log']['path']).read_bytes()).hexdigest()
    print(check['id'] + '=0 schema/log/command/source verified')
print('Final source fingerprint: ' + fingerprint['digest'])
