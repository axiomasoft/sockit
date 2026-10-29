import hashlib,json,pathlib,subprocess,sys
root=pathlib.Path.cwd(); out=root/'artifacts/p2.7';sys.path.insert(0,str(root));from scripts.review_candidate import fingerprint
v=json.loads((out/'candidate.json').read_text());current=set()
for doc in out.glob('review-item-p2.*.json'):
 for row in json.loads(doc.read_text())['task_files']:
  if row['path'].startswith('artifacts/'):continue
  for m in root.glob(row['path']):
   current.update(p.relative_to(root).as_posix() for p in (m.rglob('*') if m.is_dir() else [m]) if p.is_file() or p.is_symlink())
if current!=set(v['candidate']['paths']):raise RuntimeError('Expanded candidate scope changed')
if fingerprint(root,current)!=v['candidate']:raise RuntimeError('Source candidate changed')
for p,h in json.loads((out/'source-manifest.json').read_text()).items():
 if hashlib.sha256((root/p).read_bytes()).hexdigest()!=h:raise RuntimeError('Source bytes changed: '+p)
print(json.dumps({'subject':v['subject'],'head':v['candidate']['head'],'digest':v['candidate']['digest'],'paths':len(current),'verified':True},indent=2))
