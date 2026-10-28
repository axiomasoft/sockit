import hashlib,json,os,pathlib,re,subprocess,sys,tarfile
import jsonschema
root=pathlib.Path.cwd();out=root/'.swissknifeman/state/p2.7-finalization';c=root/'artifacts/p2.7/correction-1'
sys.path.insert(0,str(root));from scripts.review_candidate import fingerprint
receipt_path=root/'.swissknifeman/state/p2.7-receipt.json';receipt=json.loads(receipt_path.read_text())
schema=json.loads((out/'review-receipt-schema.json').read_text())['data'];defs=json.loads((out/'defs-schema.json').read_text())['data']
resolver=jsonschema.RefResolver.from_schema(schema,store={defs['$id']:defs});jsonschema.Draft202012Validator(schema,resolver=resolver).validate(receipt)
manifest=json.loads((c/'candidate.json').read_text());candidate=manifest['candidate'];writer=json.loads((c/'runtime.json').read_text())
if receipt['subject']!='PLAN1.P2.7' or receipt['base']!=manifest['base'] or receipt['candidate']!=candidate:raise RuntimeError('Receipt subject/base/candidate differs')
if receipt['round']!=2 or receipt['verdict']!='GREEN' or receipt['open']!={'major':0,'blocker':0}:raise RuntimeError('Receipt is not final round-2 GREEN')
if receipt['reviewer']['session']==writer['session']:raise RuntimeError('Reviewer is owning writer')
if {k:receipt['reviewer'][k] for k in ['provider','model','effort']}!={'provider':'codex','model':'gpt-6.1-sol','effort':'high'}:raise RuntimeError('Wrong actual reviewer route')
if {f['id'] for f in receipt['findings']}!={'F1','F2'} or any(f['status']!='corrected' for f in receipt['findings']):raise RuntimeError('Findings not corrected')
# Match the actual runtime record without copying prompt/auth/session content.
runtime_files=list(pathlib.Path('/home/vostrikov/.codex/sessions/2026/10/28').glob('*'+receipt['reviewer']['session']+'*.jsonl'))
if len(runtime_files)!=1:raise RuntimeError('Cannot locate actual reviewer runtime')
contexts=[]
for line in runtime_files[0].open():
 v=json.loads(line)
 if v.get('type')=='turn_context':contexts.append(v['payload'])
if not contexts or contexts[-1]['model']!='gpt-6.1-sol' or contexts[-1]['effort']!='high':raise RuntimeError('Actual reviewer runtime differs from receipt')
source=root/'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p2.7-rereview.jsonl';messages=[]
for line in source.open():
 try:v=json.loads(line)
 except json.JSONDecodeError:continue
 if v.get('item',{}).get('type')=='agent_message':messages.append(v)
message=messages[-1];body=message['item']['text']
if any(s not in body for s in ['GREEN','round 2/2',candidate['digest'],receipt['reviewer']['session']]):raise RuntimeError('Actual targeted review does not match receipt')
# Expand the CURRENT native contracts, not only the old context snapshots.
paths=set();items={}
for ref in manifest['covers']:
 doc=json.loads(subprocess.check_output(['python3','-I','.swissknifeman/launcher.py','planner','show','PLAN1',ref,'--json']));items[ref]=doc
 for row in doc['task_files']:
  if row['path'].startswith('artifacts/'):continue
  if pathlib.Path(row['path']).is_absolute() or '..' in pathlib.Path(row['path']).parts:raise RuntimeError('Unsafe native path')
  for m in root.glob(row['path']):
   paths.update(p.relative_to(root).as_posix() for p in (m.rglob('*') if m.is_dir() else [m]) if p.is_file() or p.is_symlink())
if paths!=set(candidate['paths']) or fingerprint(root,paths)!=candidate:raise RuntimeError('Actual expanded source candidate changed')
if items['P2.7']['status']!='in_progress' or items['P2.7']['review']['rounds_max']!=2:raise RuntimeError('Wrong native lifecycle/review limit')
for p in receipt.get('outside',{}):
 if pathlib.Path(p).is_absolute() or '..' in pathlib.Path(p).parts:raise RuntimeError('Unsafe outside path')
if fingerprint(root,set(receipt['outside']))['paths']!=receipt['outside']:raise RuntimeError('Receipt outside snapshot changed')
# Verify all reviewed bytes/artifacts before any authorized handoff updates.
for p,h in json.loads((c/'artifact-sha256.json').read_text()).items():
 if hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Reviewed handoff artifact changed: '+p)
source_sha=json.loads((c/'source-manifest.json').read_text())
with tarfile.open(c/'candidate-source.tar.gz','r:gz') as tar:
 members={m.name:m for m in tar.getmembers() if m.isfile()}
 if set(members)!=paths:raise RuntimeError('Source archive scope differs')
 for p,h in source_sha.items():
  stream=tar.extractfile(members[p])
  if stream is None or hashlib.sha256(stream.read()).hexdigest()!=h or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Archive/source mismatch: '+p)
check_schema=json.loads((out/'check-evidence-schema.json').read_text())['data'];validator=jsonschema.Draft202012Validator(check_schema,resolver=jsonschema.RefResolver.from_schema(check_schema,store={defs['$id']:defs}))
all_source=sorted([*pathlib.Path('packages/core').rglob('*.php'),*pathlib.Path('tests').rglob('*.php'),*pathlib.Path('docs/architecture').glob('*.md'),pathlib.Path('composer.json'),pathlib.Path('composer.lock')]);digest=hashlib.sha256()
for p in all_source:digest.update(str(p).encode()+b'\0'+p.read_bytes())
expected={x['id'] for x in items['P2.7']['validation']}-{'V99'};checks={}
for p in sorted(c.glob('V*-final.json')):
 v=json.loads(p.read_text());validator.validate(v)
 if v['subject']!='PLAN1.P2.7' or v['exit']!=0 or v['candidate']!={'head':candidate['head'],'dirty_digest':digest.hexdigest()}:raise RuntimeError('Invalid/stale final check: '+str(p))
 if hashlib.sha256((root/v['log']['path']).read_bytes()).hexdigest()!=v['log']['sha256']:raise RuntimeError('Check log changed')
 checks[v['check_id']]={'exit':v['exit'],'evidence':str(p.relative_to(root)),'log_sha256':v['log']['sha256']}
if set(checks)!=expected:raise RuntimeError('Required check coverage differs')
for label,baseline in [('original_foreign',root/'artifacts/p2.7/foreign-baseline.json'),('current_workspace',c/'workspace-before.json')]:
 delta=set(json.loads((c/'correction-delta.json').read_text())['paths']) if label=='current_workspace' else set()
 for p,h in json.loads(baseline.read_text()).items():
  if p not in delta and (not (root/p).is_file() or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h):raise RuntimeError('Foreign file changed: '+p)
if receipt_path.read_bytes()!=(out/'review-receipt.json').read_bytes():raise RuntimeError('Receipt copy differs')
(out/'review-agent-message.json').write_text(json.dumps(message,ensure_ascii=False,indent=2)+'\n')
result={'subject':'PLAN1.P2.7','schema_valid':True,'exact_candidate':candidate['digest'],'head':candidate['head'],'source_paths':len(paths),'outside_paths_verified':len(receipt['outside']),'actual_reviewer':receipt['reviewer'],'writer_session':writer['session'],'round':2,'verdict':'GREEN','findings_corrected':['F1','F2'],'open':receipt['open'],'receipt_sha256':hashlib.sha256(receipt_path.read_bytes()).hexdigest(),'review_source':str(source.relative_to(root)),'review_source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'review_runtime':{'model':contexts[-1]['model'],'effort':contexts[-1]['effort'],'session':receipt['reviewer']['session']},'checks':checks,'source_check_digest':digest.hexdigest(),'V99':'pass','native_status_before':'in_progress'}
(out/'review-verification.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps({k:v for k,v in result.items() if k!='checks'},indent=2))
