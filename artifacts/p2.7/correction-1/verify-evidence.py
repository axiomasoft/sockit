import datetime,hashlib,json,pathlib,subprocess,re
import jsonschema
root=pathlib.Path.cwd();out=root/'artifacts/p2.7/correction-1'
schema=json.loads((out/'check-evidence-schema.json').read_text())['data'];defs=json.loads((out/'defs-schema.json').read_text())['data']
resolver=jsonschema.RefResolver.from_schema(schema,store={defs['$id']:defs})
validator=jsonschema.Draft202012Validator(schema,resolver=resolver)
paths=sorted([*pathlib.Path('packages/core').rglob('*.php'),*pathlib.Path('tests').rglob('*.php'),*pathlib.Path('docs/architecture').glob('*.md'),pathlib.Path('composer.json'),pathlib.Path('composer.lock')]);digest=hashlib.sha256()
for p in paths:digest.update(str(p).encode()+b'\0'+p.read_bytes())
head=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip();manifest={};expected={'V1','V2','V3','V4','V5','V6','V7','V8','V90','V91'}
for p in sorted(out.glob('V*-final.json')):
 v=json.loads(p.read_text());validator.validate(v)
 if v['exit']!=0:raise RuntimeError('Failed check '+p.name)
 if v['subject']!='PLAN1.P2.7' or v['check_id'] not in expected:raise RuntimeError('Wrong check identity')
 if v['candidate']!={'head':head,'dirty_digest':digest.hexdigest()}:raise RuntimeError('Stale check candidate '+p.name)
 log=root/v['log']['path']
 if hashlib.sha256(log.read_bytes()).hexdigest()!=v['log']['sha256']:raise RuntimeError('Changed log '+str(log))
 t=re.sub(r'\x1b\[[0-9;]*m','',log.read_text());manifest[v['check_id']]={'evidence':str(p.relative_to(root)),'exit':v['exit'],'summary':re.findall(r'Tests:\s+[^\n]+|\[OK\][^\n]+|"result":"passed"',t),'log_sha256':v['log']['sha256']}
if set(manifest)!=expected:raise RuntimeError('Missing final checks')
missing=json.loads((out/'PG-missing.json').read_text())
if missing['exit']!=2 or 'Missing private PostgreSQL run manifest' not in (root/missing['log']['path']).read_text():raise RuntimeError('Expected fail-closed bootstrap not proved')
v={'subject':'PLAN1.P2.7','head':head,'source_check_digest':digest.hexdigest(),'schema_validated':True,'logs_verified':True,'checks':manifest,'V99':'round 1 RED; targeted round 2 pending; no final receipt generated','negative_bootstrap_exit':2}
(out/'evidence-verified.json').write_text(json.dumps(v,indent=2)+'\n');print(json.dumps(v,indent=2))
