import hashlib,json,pathlib,subprocess,sys,tarfile,io,datetime
root=pathlib.Path.cwd(); out=root/'artifacts/p2.7'; sys.path.insert(0,str(root)); from scripts.review_candidate import fingerprint
refs=['P2.2','P2.3','P2.4','P2.5','P2.6','P2.7']; docs=[]; paths=set(); expansions={}; unmatched=[]
for ref in refs:
 raw=subprocess.check_output(['python3','-I','.swissknifeman/launcher.py','planner','show','PLAN1',ref,'--json'],text=True)
 (out/('review-item-'+ref.lower()+'.json')).write_text(raw); doc=json.loads(raw); docs.append(doc)
 for row in doc['task_files']:
  pattern=row['path']
  if pattern.startswith('artifacts/'):continue
  actual=set()
  for m in root.glob(pattern):
   seq=m.rglob('*') if m.is_dir() else [m]
   actual.update(p.relative_to(root).as_posix() for p in seq if p.is_file() or p.is_symlink())
  expansions[ref+':'+pattern]=sorted(actual)
  if not actual:unmatched.append({'ref':ref,'pattern':pattern})
  paths.update(actual)
base=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(); candidate=fingerprint(root,paths)
manifest={'subject':'PLAN1.P2.7','base':base,'covers':refs,'writer':json.loads((out/'runtime.json').read_text()),'candidate':candidate,'scope':{'expanded':expansions,'empty_patterns':unmatched,'artifact_policy':'Artifact trees are excluded from source fingerprint to avoid self-reference; final evidence is bound separately by SHA-256.'},'at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
(out/'candidate.json').write_text(json.dumps(manifest,indent=2,ensure_ascii=False)+'\n')
source={p:hashlib.sha256((root/p).read_bytes()).hexdigest() for p in sorted(paths)}
(out/'source-manifest.json').write_text(json.dumps(source,indent=2)+'\n')
# Actual source bytes, including new files; review can reconstruct the exact candidate independently.
with tarfile.open(out/'candidate-source.tar.gz','w:gz') as tar:
 for p in sorted(paths):tar.add(root/p,arcname=p,recursive=False)
tracked=set(subprocess.check_output(['git','ls-files','-z']).decode().split('\0'))
parts=[subprocess.check_output(['git','diff','--no-ext-diff','--binary',base,'--',*sorted(paths & tracked)])]
for p in sorted(paths-tracked):
 r=subprocess.run(['git','diff','--no-ext-diff','--no-index','--binary','--','/dev/null',p],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 if r.returncode not in [0,1]:raise RuntimeError(r.stderr.decode())
 parts.append(r.stdout)
(out/'candidate.diff').write_bytes(b'\n'.join(parts))
request={'kind':'independent_review_request','subject':'PLAN1.P2.7','status':'pending_external_review','covers':refs,'owning_session':manifest['writer']['session'],'requested_reviewer':{'provider':'codex','model':'gpt-6.1-sol','effort':'high','session_requirement':'fresh, distinct from owning writer, read-only; do not launch agents from this owning session'},'rounds_completed':0,'rounds_max':2,'base':base,'candidate':candidate,'evidence':[str(p.relative_to(root)) for p in sorted(out.glob('V*-final.json'))],'focus':['receipt unique conflict recovery and subject/digest identity','fixed guard/lock/CAS/persist/outbox order','current replay/lookup authorization and no host calls under locks','full SQL rollback and generation fences','public lifecycle and nested-owner rejection'],'receipt_schema':'artifacts/p2.7/review-receipt-schema.json','source_archive':'artifacts/p2.7/candidate-source.tar.gz'}
(out/'review-request.json').write_text(json.dumps(request,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({'head':base,'digest':candidate['digest'],'source_paths':len(paths),'empty_patterns':unmatched,'archive_sha256':hashlib.sha256((out/'candidate-source.tar.gz').read_bytes()).hexdigest()},indent=2))
