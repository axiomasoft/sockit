import subprocess,pathlib,json,os,hashlib,sys
root=pathlib.Path.cwd(); out=root/'artifacts/p2.6'; baseline=json.loads((out/'foreign-baseline.json').read_text()); preview=out/'commit-expanded.json'; preview.touch(exist_ok=True)
for name,h in baseline.items():
 assert pathlib.Path(name).is_file() and hashlib.sha256(pathlib.Path(name).read_bytes()).hexdigest()==h, name
names=sorted({os.fsdecode(n) for n in subprocess.check_output(['git','ls-files','--others','--modified','--exclude-standard','-z']).split(b'\0') if n}-set(baseline))
(out/'owned-paths.json').write_text(json.dumps(names,indent=2)+'\n')
cmd=['python3','-I','.swissknifeman/launcher.py','planner','commit','PLAN1','P2.6','--role','executor','--scope','sockit-foundation','--summary','add durable outbox relay and consumer inbox conformance','--paths-only','--leave-outside','--json']
for name in names: cmd.extend(['--path',name])
r=subprocess.run([*cmd,'--dry-run'],capture_output=True,text=True);preview.write_text(r.stdout);sys.stderr.write(r.stderr)
if r.returncode: sys.exit(r.returncode)
data=json.loads(r.stdout);assert set(data['paths'])==set(names),(set(names)-set(data['paths']),set(data['paths'])-set(names));assert set(data['outside'])==set(baseline),(set(data['outside'])-set(baseline),set(baseline)-set(data['outside']))
print(json.dumps({'selection_count':len(names),'foreign_outside':len(data['outside']),'message':data['message']},ensure_ascii=False))
if '--execute' in sys.argv:
 result=subprocess.run(cmd,capture_output=True,text=True);sys.stderr.write(result.stderr)
 if result.returncode:
  print(result.stdout);sys.exit(result.returncode)
 record=json.loads(result.stdout);print(json.dumps({'sha':record['sha'],'path_count':len(record['paths']),'message':record['message'],'repo':record.get('repo'),'workspace_sha':record.get('workspace_sha')},ensure_ascii=False))
