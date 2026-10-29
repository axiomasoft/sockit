#!/usr/bin/env python3
"""Validate planner check-evidence and its exact local logs and source bindings."""
import hashlib, json, pathlib
from jsonschema import Draft202012Validator, RefResolver

root = pathlib.Path(__file__).resolve().parents[2]
out = root/'artifacts/p3.7'
schema = json.loads((out/'check-evidence-schema.json').read_text())['data']
defs = json.loads((out/'defs-schema.json').read_text())['data']
validator = Draft202012Validator(schema, resolver=RefResolver.from_schema(schema, store={schema['$id']: schema, defs['$id']: defs}))
context = json.loads((root/'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.7-context.json').read_text())
commands = {v['id']: v['cmd'] for v in context['item']['validation']}
result = []
for check in ('V1', 'V2', 'V90', 'V91'):
    evidence = json.loads((out/(check+'.json')).read_text())
    validator.validate(evidence)
    assert evidence['exit'] == 0
    assert evidence['procedure']['cmd'] == commands[check]
    log = (root/evidence['log']['path']).read_bytes()
    assert hashlib.sha256(log).hexdigest() == evidence['log']['sha256']
    assert (out/(check+'.stdout.log')).read_bytes() in log
    assert (out/(check+'.stderr.log')).read_bytes() in log
    source = json.loads((out/(check+'-source-fingerprints.json')).read_text())
    for path, expected in source['files'].items():
        assert hashlib.sha256((root/path).read_bytes()).hexdigest() == expected, path
    assert hashlib.sha256(json.dumps(source['files'],sort_keys=True,separators=(',',':')).encode()).hexdigest() == source['digest']
    assert evidence['candidate']['dirty_digest'] == source['digest']
    result.append({'check': check, 'exit': 0, 'schema': 'valid', 'literal_command': True, 'logs': 'valid', 'sources': 'unchanged', 'digest': source['digest']})
assert len({r['digest'] for r in result}) == 1
(out/'evidence-validation.json').write_text(json.dumps({'exit': 0, 'results': result}, indent=2)+'\n')
print(json.dumps(result, indent=2))
