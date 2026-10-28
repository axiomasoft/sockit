#!/usr/bin/env python3
import hashlib
import json
import pathlib
import sys

import jsonschema

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts/p3.2'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


schema = json.loads((OUT / 'check-evidence-schema.json').read_text())['data']
defs = json.loads((OUT / 'defs-schema.json').read_text())['data']
resolver = jsonschema.RefResolver.from_schema(schema, store={defs['$id']: defs, schema['$id']: schema})
validator = jsonschema.Draft202012Validator(schema, resolver=resolver)
context = json.loads((ROOT / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.2-context.json').read_text())
expected = {check['id']: check['cmd'] for check in context['item']['validation']}
source_manifest = json.loads((OUT / 'source-manifest.json').read_text())
for path, digest in source_manifest.items():
    assert sha(ROOT / path) == digest, path
source_digest = sha(OUT / 'source-manifest.json')
for check in ['V1', 'V2', 'V90', 'V91', 'V92', 'V93']:
    evidence = json.loads((OUT / (check + '.json')).read_text())
    validator.validate(evidence)
    assert evidence['exit'] == 0, check
    assert evidence['candidate']['dirty_digest'] == source_digest, check
    assert sha(ROOT / evidence['log']['path']) == evidence['log']['sha256'], check
    assert evidence['subject'] == 'PLAN1.P3.2'
    if check in expected:
        assert evidence['procedure']['cmd'] == expected[check], check
    print(check + ': schema/log/source/command/exit verified')
outside = json.loads((OUT / 'outside-before.json').read_text())
for record in outside:
    assert sha(ROOT / record['path']) == record['sha256'], record['path']
sql = (OUT / 'V2.stderr.log').read_text()
for assertion in ['distinct_pdo_backends=true', 'same_physical_key=true', 'collision_before_ddl=true', 'transaction_read_only=on', 'catalog_unchanged=true', 'frozen_schema=p32_owned', 'search_path_changed=true', 'existing_relation_rejected=true', 'neighbor=untouched', 'decoy=untouched', 'schema_bytes=63', 'table_bytes=63', 'column_bytes=63', 'overflow_64_rejected_before_ddl=true', 'database_mismatch=denied', 'endpoint_mismatch=denied', 'missing_schema=denied', 'unresolved_search_path=denied']:
    assert assertion in sql, assertion
runtime = json.loads((OUT / 'runtime.json').read_text())
assert runtime['model'] == 'gpt-6.1-sol' and runtime['effort'] == 'high'
assert runtime['sandbox_policy']['type'] == 'danger-full-access'
print('Verified ' + str(len(source_manifest)) + ' source files, ' + str(len(outside)) + ' outside files and actual PostgreSQL SQL assertions')
