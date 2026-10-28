#!/usr/bin/env python3
"""Verify captured check schema, exit status, source/log hashes and outside preservation."""
import hashlib
import json
import pathlib
import re
from jsonschema import Draft202012Validator, RefResolver

root = pathlib.Path(__file__).resolve().parents[2]
base = root / 'artifacts/p3.1'
schema = json.loads((base / 'check-evidence-schema.json').read_text())['data']
defs = json.loads((base / 'defs-schema.json').read_text())['data']
validator = Draft202012Validator(schema, resolver=RefResolver.from_schema(schema, store={defs['$id']: defs, schema['$id']: schema}))
manifest = base / 'source-manifest.json'
for check in ['V1', 'V2', 'V90', 'V91', 'V92']:
    evidence = json.loads((base / (check + '.json')).read_text())
    validator.validate(evidence)
    assert evidence['exit'] == 0 and evidence['check_id'] == check and evidence['subject'] == 'PLAN1.P3.1', check
    assert evidence['candidate']['dirty_digest'] == hashlib.sha256(manifest.read_bytes()).hexdigest(), check
    log = (root / evidence['log']['path']).read_bytes()
    assert hashlib.sha256(log).hexdigest() == evidence['log']['sha256'], check
    stdout = (base / (check + '.stdout.log')).read_bytes()
    stderr = (base / (check + '.stderr.log')).read_bytes()
    expected = ('command=' + evidence['procedure']['cmd'] + '\ncwd=' + str(root) + '\nstdout:\n').encode() + stdout + b'\nstderr:\n' + stderr
    assert log == expected, check + ' stream mismatch'
    if check == 'V92':
        text = log.decode()
        assert 'private container=' in text and 'PG identity database=' in text and 'Installed package source fingerprint=' in text
        assert re.search(r'PG identity database=\w+_test server_version_num=16\d+ marker=sockit-p21-', text)
        assert text.index('PG identity database=') < text.index('consumer-owned SQL snapshots installed AFTER verified identities')
    print(check + ': native evidence schema, exit and stdout/stderr/log digests OK')
for name, digest in json.loads(manifest.read_text())['files'].items():
    assert hashlib.sha256((root / name).read_bytes()).hexdigest() == digest, name
print('588 source paths match tested candidate')
for name, digest in json.loads((base / 'outside-before-finalization.json').read_text()).items():
    assert hashlib.sha256((root / name).read_bytes()).hexdigest() == digest, name
print('416 outside file digests unchanged')
preview = json.loads((base / 'commit-dry-run.json').read_text())
for name in ['.gitignore', '.serena/project.yml', 'CLAUDE.md']:
    assert name in preview['outside'] and name not in preview['paths'], name
for name in preview['paths']:
    assert name.startswith(('artifacts/p3.1/', 'packages/core/src/Schema/', 'tests/Fixtures/Schema/')) or name in {
        'packages/core/src/Modules/ModuleRegistrar.php', 'packages/core/src/Modules/Internal/CompiledModuleGraph.php',
        'packages/core/src/Modules/Internal/ModuleGraphCompiler.php', 'packages/core/src/Modules/Modules.php',
        'packages/core/src/Panels/PanelStorage.php', 'packages/core/src/Panels/Panel.php',
        'packages/core/src/Panels/Internal/PanelGraphCompiler.php', 'packages/core/src/SocKitServiceProvider.php',
        'tests/Unit/Schema/CompilerTest.php', 'tests/Feature/Schema/CompilationTest.php',
        'tests/Arch/ModuleListTest.php', 'tests/Arch/Support/Gates.php',
    }, name
print('scoped dry-run paths verified')
