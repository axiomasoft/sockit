"""Validate current P4.7 evidence, literal commands, logs and the frozen source map."""
import hashlib, importlib.util, json, pathlib, re, sys
import jsonschema

sys.dont_write_bytecode = True
root = pathlib.Path(__file__).resolve().parents[2]
out = root / 'artifacts/p4.7'
schema = json.loads((out / 'check-evidence-schema.json').read_text())['data']
defs = json.loads((out / 'defs-schema.json').read_text())['data']
resolver = jsonschema.RefResolver.from_schema(schema, store={defs['$id']: defs})
validator = jsonschema.Draft202012Validator(schema, resolver=resolver, format_checker=jsonschema.FormatChecker())
context = json.loads((root / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.7-context.json').read_text())
literal = {check['id']: check['cmd'] for check in context['item']['validation']}
source = json.loads((out / 'source-fingerprint.json').read_text())
spec = importlib.util.spec_from_file_location('review_candidate', root / 'scripts/review_candidate.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
assert module.fingerprint(root, set(source['paths'])) == source, 'Source differs from the current fingerprint'
results = {}
for check in [*literal, 'V92', 'V93']:
    evidence = json.loads((out / (check + '.json')).read_text())
    validator.validate(evidence)
    assert evidence['subject'] == 'PLAN1.P4.7' and evidence['check_id'] == check
    assert evidence['exit'] == 0
    assert evidence['candidate']['head'] == source['head']
    assert evidence['candidate']['dirty_digest'] == source['digest'].removeprefix('sha256:')
    if check in literal:
        assert evidence['procedure']['cmd'] == literal[check]
    log = root / evidence['log']['path']
    assert hashlib.sha256(log.read_bytes()).hexdigest() == evidence['log']['sha256']
    content = re.sub(r'\x1b\[[0-9;]*m', '', log.read_text())
    summaries = [line.strip() for line in content.splitlines() if 'Tests:' in line or '[OK]' in line or '"tool":"pint"' in line]
    for line in summaries:
        if 'Tests:' in line:
            assert re.search(r'\b[1-9][0-9]* passed\b', line)
            assert not re.search(r'\b(skipped|incomplete|warning|risky|failed)\b', line, re.IGNORECASE)
    results[check] = {'exit': evidence['exit'], 'summaries': summaries}
print(json.dumps({'schema': 'valid', 'logs': 'sha256 verified', 'commands': 'literal V1 V2 V3 V89 V90 V91', 'source_digest': source['digest'], 'results': results}, indent=2))
