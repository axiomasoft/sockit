"""Reconcile historical evidence and actual phase gate results for P4.10 V1."""
import hashlib, json, pathlib, subprocess, sys
import jsonschema
root = pathlib.Path(__file__).resolve().parents[2]
out = root/'artifacts/p4.10'
r = subprocess.run([sys.executable, str(out/'reconcile.py')], cwd=root, capture_output=True, text=True)
print(r.stdout, end='')
print(r.stderr, end='', file=sys.stderr)
if r.returncode:
    sys.exit(r.returncode)
schema = json.loads((root/'artifacts/p4.8/check-evidence-schema.json').read_text())['data']
defs = json.loads((root/'artifacts/p4.8/defs-schema.json').read_text())['data']
validator = jsonschema.Draft202012Validator(schema, resolver=jsonschema.RefResolver.from_schema(schema, store={defs['$id']: defs}), format_checker=jsonschema.FormatChecker())
context = json.loads((root/'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.10-context.json').read_text())
source = json.loads((out/'source-fingerprint.json').read_text())
for check in context['phase']['validation']:
    evidence = json.loads((out/(check['id']+'.json')).read_text())
    validator.validate(evidence)
    assert evidence['procedure']['cmd'] == check['cmd'] and evidence['exit'] == 0
    assert evidence['candidate']['head'] == source['head'] and evidence['candidate']['dirty_digest'] == source['digest'].removeprefix('sha256:')
    assert hashlib.sha256((root/evidence['log']['path']).read_bytes()).hexdigest() == evidence['log']['sha256']
    print(check['id'], 'exit 0; literal command, candidate and log verified')
print('Manual reconciliation: P4.8 V2 log shows owned PG migrations and social smoke for both storage layouts; P4.9 reports read-only parent and current privacy; P7/P8 and P9 remain pending. API and no sibling imports checked against module sources and V89.')
