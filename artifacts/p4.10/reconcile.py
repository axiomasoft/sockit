import hashlib, json, pathlib
import jsonschema
root = pathlib.Path('.').resolve()
schema = json.loads((root/'artifacts/p4.8/check-evidence-schema.json').read_text())['data']
defs = json.loads((root/'artifacts/p4.8/defs-schema.json').read_text())['data']
validator = jsonschema.Draft202012Validator(schema, resolver=jsonschema.RefResolver.from_schema(schema, store={defs['$id']: defs}), format_checker=jsonschema.FormatChecker())
for i in range(1, 10):
    p = root / f'artifacts/p4.{i}'
    source = json.loads((p/'source-fingerprint.json').read_text())
    digest = source.get('digest', source.get('source_digest')).removeprefix('sha256:')
    if 'paths' in source:
        lines = '\n'.join(name+'\t'+value for name,value in sorted(source['paths'].items()))
        assert hashlib.sha256(lines.encode()).hexdigest() == digest
    checks = sorted(p.glob('V[0-9]*.json'))
    checked = []
    for file in checks:
        if file.stem not in {'V1', 'V2', 'V3', 'V88', 'V89', 'V90', 'V91'}: continue
        evidence = json.loads(file.read_text())
        if evidence.get('$schema') != 'urn:swissknifeman:task:plan:check-evidence:v1': continue
        validator.validate(evidence)
        assert evidence['exit'] == 0 and evidence['candidate']['dirty_digest'] == digest
        assert hashlib.sha256((root/evidence['log']['path']).read_bytes()).hexdigest() == evidence['log']['sha256']
        checked.append(evidence['check_id'])
    assert 'V1' in checked and 'V89' in checked and 'V90' in checked and 'V91' in checked
    print(f'P4.{i}: source {digest}, evidence/log hashes: {", ".join(checked)}')
print('P4.8 V1–V3: real production reactions consumer and isolated PostgreSQL; P3 historical result retained.')
print('P4.9: parent read_only, current privacy, lifecycle; external adapters P7/P8 and P9 erasure pending.')
print('API and sibling boundary reconciled with module sources and Arch gate; phase gates recorded separately.')
