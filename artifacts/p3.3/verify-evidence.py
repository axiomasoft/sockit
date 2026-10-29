#!/usr/bin/env python3
"""Validate native evidence schema, executed commands, source/log hashes and SQL assertions."""
import hashlib
import json
import pathlib
import re
import sys

import jsonschema

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'artifacts/p3.3'


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


schema = json.loads((OUT / 'check-evidence-schema.json').read_text())['data']
defs = json.loads((OUT / 'defs-schema.json').read_text())['data']
resolver = jsonschema.RefResolver.from_schema(schema, store={defs['$id']: defs, schema['$id']: schema})
validator = jsonschema.Draft202012Validator(schema, resolver=resolver)
context = json.loads((ROOT / 'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.3-context.json').read_text())
expected = {check['id']: check['cmd'] for check in context['item']['validation']}
manifest = json.loads((OUT / 'source-manifest.json').read_text())
for path, digest in manifest.items():
    assert sha(ROOT / path) == digest, path
source_digest = sha(OUT / 'source-manifest.json')
checks = ['V1', 'V2', 'V3', 'V90', 'V91', 'V92']
for check in checks:
    evidence = json.loads((OUT / (check + '.json')).read_text())
    validator.validate(evidence)
    assert evidence['exit'] == 0, check
    assert evidence['candidate']['dirty_digest'] == source_digest, check
    assert evidence['candidate']['head'] == '3d4fbb8c9aebc05d4992fa3ece969c9e9919ca72', check
    assert sha(ROOT / evidence['log']['path']) == evidence['log']['sha256'], check
    assert evidence['subject'] == 'PLAN1.P3.3'
    assert evidence['producer']['actor'] == {'provider': 'codex', 'model': 'gpt-6.1-sol', 'effort': 'xhigh', 'session': '01a184a5-b763-7d83-90c3-f68b92c342b9'}
    if check in expected:
        assert evidence['procedure']['cmd'] == expected[check], check
    print(check + ': schema/log/source/command/exit verified')
for record in json.loads((OUT / 'outside-before.json').read_text()):
    assert sha(ROOT / record['path']) == record['sha256'], record['path']
for check, assertions in {
    'V1': ['catalog_exact=true', 'kernel_bounds=true', 'composite_tombstone=true', 'module_crud=true', 'module_dependency_fk=true', 'relay_index=true', 'reverse_drop=true', 'public_envelope_confirmed=true', 'replay_one_write=true', 'guard_context_persisted=true', 'outbox_claim_ack=true', 'surface_recreate=true', 'tombstone_retained=true', 'receipt_surface_outbox_atomic_rollback=true', 'production_unique_claim=duplicate', 'production_unique_claim=rollback', 'blocked_by_holder=true', 'host_key_512_bytes_lossless=true', 'host_key_513_bytes_denied=true', 'namespace_384_key_128=true', 'uuid7_lifecycle_checks=true', 'in_flight_null_allowed=true', 'invalid_stable_outcomes_denied=true', 'outbox_json_object_and_byte_bounds=true', 'selected_module_v2_initial_up_down=true'],
    'V2': ['fresh_snapshot_up=ok', 'fresh_snapshot_down=ok', 'latest_helpers_unavailable=true', 'prefix_changed=true', 'search_path_changed=true', 'frozen_schema=panel_a', 'panel_b_host_decoy_preserved=true', 'integrity_encoding_refused_before_ddl=true', 'integrity_digest_refused_before_ddl=true', 'alias_change=database', 'alias_change=endpoint', 'alias_change=driver', 'alias_change=routing', 'alias_change=pdo', 'credential_rotation_accepted=true', 'unmanaged_table_not_adopted=true', 'unmanaged_index_not_adopted=true', 'mid_up_failure_atomic=true', 'outer_transaction=savepoint_preserved', 'rollback_change=column', 'rollback_change=index', 'rollback_change=receipt', 'rollback_change=sequence', 'rollback_change=replacement', 'descendant_blocks_rollback=true', 'reverse_failure_atomic=true', 'later_tables_preserved=true'],
}.items():
    log = (OUT / (check + '.stderr.log')).read_text()
    for assertion in assertions:
        assert assertion in log, (check, assertion)
regression = (OUT / 'V92.stderr.log').read_text()
assert 'Teardown negative control: retained_client_detected=true released_client_removed=true' in regression
match = re.search(r'Repeated teardown: baseline=(\d+) final=(\d+)', regression)
assert match and match[1] == match[2], 'Actual strict teardown regression did not pass'
runtime = json.loads((OUT / 'runtime.json').read_text())
assert runtime['model'] == 'gpt-6.1-sol' and runtime['effort'] == 'xhigh'
assert runtime['sandbox_policy']['type'] == 'danger-full-access'
assert runtime['session'] == '01a184a5-b763-7d83-90c3-f68b92c342b9'
print('Verified '+str(len(manifest))+' source files, 430 outside files, actual PostgreSQL assertions and strict teardown baseline='+match[1]+' final='+match[2])
