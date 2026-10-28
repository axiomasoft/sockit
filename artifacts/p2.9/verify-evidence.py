#!/usr/bin/env python3
import hashlib, json, pathlib, re, subprocess
from jsonschema import Draft202012Validator, RefResolver
root=pathlib.Path(__file__).resolve().parents[2]
base=root/'artifacts/p2.9'
schema=json.loads((base/'check-evidence-schema.json').read_text())['data']
defs=json.loads((base/'defs-schema.json').read_text())['data']
validator=Draft202012Validator(schema,resolver=RefResolver.from_schema(schema,store={defs['$id']:defs,schema['$id']:schema}))
for check in ['V1','V2','V3','V4','V90','V91']:
 d=json.loads((base/(check+'.json')).read_text());validator.validate(d)
 assert d['exit']==0 and d['check_id']==check and d['subject']=='PLAN1.P2.9', check
 log=(root/d['log']['path']).read_bytes()
 assert hashlib.sha256(log).hexdigest()==d['log']['sha256'],check+' log changed'
 if check in ['V1','V2','V4']:
  text=log.decode();assert 'private container=' in text and 'backend_pid=' in text and 'marker=sockit-p21-' in text
  assert text.index('PG identity database=') < text.index('DDL installed AFTER verified PDO identities')
  assert all(int(v)>=160000 for v in re.findall(r'server_version_num=(\d+)',text))
 print(check+' native schema/exit/log/PG identity OK')
manifest=json.loads((base/'source-manifest.json').read_text())
for path,digest in manifest['files'].items():
 assert hashlib.sha256((root/path).read_bytes()).hexdigest()==digest,path+' source changed'
print('source manifest: '+str(len(manifest['files']))+' paths unchanged')
preview=json.loads((base/'commit-dry-run.json').read_text())
for path in ['.gitignore','.serena/project.yml','CLAUDE.md']:
 assert path in preview['outside'] and path not in preview['paths'],path
assert all(p.startswith(('packages/core/src/Operations/','tests/Fixtures/Runtime/','tests/Integration/Runtime/Worker','artifacts/p2.9/')) or p=='docs/architecture/spi.md' for p in preview['paths'])
foreign=json.loads((base/'outside-before.json').read_text())
for path,digest in foreign.items():
 assert hashlib.sha256((root/path).read_bytes()).hexdigest()==digest,path+' outside content changed'
print('scoped commit preview and '+str(len(foreign))+' outside file digests OK')
