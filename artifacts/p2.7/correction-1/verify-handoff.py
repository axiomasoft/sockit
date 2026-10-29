import hashlib,json,pathlib,tarfile,subprocess
root=pathlib.Path.cwd();out=root/'artifacts/p2.7/correction-1'
for p,h in json.loads((out/'artifact-sha256.json').read_text()).items():
 if hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Changed handoff artifact: '+p)
source=json.loads((out/'source-manifest.json').read_text())
with tarfile.open(out/'candidate-source.tar.gz','r:gz') as tar:
 files={m.name:m for m in tar.getmembers() if m.isfile()}
 if set(files)!=set(source):raise RuntimeError('Source archive scope differs')
 for p,h in source.items():
  stream=tar.extractfile(files[p])
  if stream is None or hashlib.sha256(stream.read()).hexdigest()!=h:raise RuntimeError('Archive mismatch: '+p)
changed=set(json.loads((out/'correction-delta.json').read_text())['paths'])
base=json.loads((out/'workspace-before.json').read_text());protected={p:h for p,h in base.items() if p not in changed}
original=json.loads((root/'artifacts/p2.7/foreign-baseline.json').read_text())
historical=json.loads((root/'artifacts/p2.7/artifact-sha256.json').read_text())
for group in [protected,original,historical]:
 for p,h in group.items():
  if not (root/p).is_file() or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Protected file changed: '+p)
v=json.loads(subprocess.check_output(['python3','-I','.swissknifeman/launcher.py','planner','show','PLAN1','P2.7','--json']))
started=json.loads((out/'review-item-p2.7.json').read_text())['result']
if v['status']!='in_progress' or v['result']!=started:raise RuntimeError('Native result differs from recorded start metadata')
request=json.loads((out/'review-request.json').read_text())
if request['requested_round']!=2 or request['rounds_completed']!=1 or request['rounds_max']!=2:raise RuntimeError('Wrong review round')
print(json.dumps({'subject':'PLAN1.P2.7','handoff_artifacts_verified':True,'archived_sources_verified':len(source),'correction_paths':len(changed),'protected_workspace_files':len(protected),'original_foreign_files':len(original),'historical_review_artifacts':len(historical),'native_status':v['status'],'native_result':'start/executor metadata only','review':'targeted round 2/2 pending; actual receipt required','V99':'not passed'},indent=2))
