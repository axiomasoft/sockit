import hashlib,json,pathlib,tarfile,subprocess
root=pathlib.Path.cwd();out=root/'artifacts/p2.7'
for p,h in json.loads((out/'artifact-sha256.json').read_text()).items():
 if hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Changed handoff artifact: '+p)
source=json.loads((out/'source-manifest.json').read_text())
with tarfile.open(out/'candidate-source.tar.gz','r:gz') as tar:
 files={m.name:m for m in tar.getmembers() if m.isfile()}
 if set(files)!=set(source):raise RuntimeError('Source archive scope differs')
 for p,h in source.items():
  stream=tar.extractfile(files[p])
  if stream is None or hashlib.sha256(stream.read()).hexdigest()!=h:raise RuntimeError('Source archive mismatch: '+p)
baseline=json.loads((out/'foreign-baseline.json').read_text())
for p,h in baseline.items():
 if not (root/p).is_file() or hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Foreign file changed: '+p)
v=json.loads(subprocess.check_output(['python3','-I','.swissknifeman/launcher.py','planner','show','PLAN1','P2.7','--json']))
started=json.loads((out/'review-item-p2.7.json').read_text())['result']
if v['status']!='in_progress' or v['result']!=started:raise RuntimeError('Native result differs from recorded start metadata')
print(json.dumps({'handoff_artifacts_verified':True,'archived_sources_verified':len(source),'foreign_files_preserved':len(baseline),'native_status':v['status'],'result':v['result'],'review':'pending; no receipt fabricated','V99':'not passed'},indent=2))
