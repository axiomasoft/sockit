import hashlib,json,pathlib
from jsonschema import Draft202012Validator, RefResolver
root=pathlib.Path(__file__).resolve().parents[2]
schema=json.loads((root/'artifacts/p3.6/check-evidence-schema.json').read_text())['data']
defs=json.loads((root/'artifacts/p3.6/defs-schema.json').read_text())['data']
validator=Draft202012Validator(schema,resolver=RefResolver.from_schema(schema,store={schema['$id']:schema,defs['$id']:defs}))
for phase,checks in [('p3.5',['V1','V2']),('p3.6',['V1','V2','V3']),('p3.8',['V1'])]:
 for check in checks:
  d=root/'artifacts'/phase
  e=json.loads((d/(check+'.json')).read_text()); validator.validate(e)
  assert e['exit']==0 and hashlib.sha256((root/e['log']['path']).read_bytes()).hexdigest()==e['log']['sha256']
  f=json.loads((d/(check+'-source-fingerprints.json')).read_text())
  assert hashlib.sha256(json.dumps(f['files'],sort_keys=True,**({'separators':(',',':')} if phase == 'p3.8' else {})).encode()).hexdigest()==f['digest']==e['candidate']['dirty_digest']
  print(phase,check,'exit=0 schema/log/source fingerprint valid')
a=(root/'artifacts/p3.5/V2.stderr.log').read_text(); b=(root/'artifacts/p3.8/V1.stderr.log').read_text(); test=(root/'tests/Feature/Consumer/GeneratedPanelsInstallTest.php').read_text(); guide=(root/'docs/guides/panel-install-upgrade.md').read_text()
for s in ['--dry-run','sockit:make-panel','SQL assertions: owner catalog unchanged','exit=0']: assert s in a
for s in ['package:discover','sockit:make-panel','sockit:panel:migrations','--upgrade','config:cache','2026_10_29_040818_000001_sockit_deb76148b67581c0.php','SQL assertions: both panels','Product checksum before/after Article rollback','quarantine']: assert s in b
for s in ["$oldHashes", "hash_file('sha256'",'pg_tables','eventBefore','productBefore','deliveries::jsonb']: assert s in test
for s in ['pending в P4','не verified','--dry-run','2026_10_29_040818_000001_sockit_deb76148b67581c0.php']: assert s in guide
print('Manual cross-check: commands and emitted paths match P3.5 V2 / P3.8 V1 successful consumer logs; P3.8 test asserts frozen/custom hashes, SQL catalogs, outbox and rollback checksum; P3.6 V1/V2/V3 receipts and reports cover upgrade/disable. Historical source fingerprints checked for internal integrity, not equated to current sources. Doctor unverified; P4 reactions pending.')
