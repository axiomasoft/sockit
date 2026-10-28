import datetime, hashlib, json, os, pathlib, subprocess, sys
root=pathlib.Path.cwd()
checks=json.loads((root/'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p3.4-context.json').read_text())['item']['validation']
check=next(c for c in checks if c['id']==sys.argv[1])
label=sys.argv[2] if len(sys.argv)>2 else check['id']
base=root/'artifacts/p3.4'
sources={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for folder in ['packages/core/src','packages/core/resources/stubs','tests/Fixtures/Schema','tests/Integration/Schema'] for p in sorted((root/folder).rglob('*')) if p.is_file()}
fingerprint=hashlib.sha256(json.dumps(sources,sort_keys=True).encode()).hexdigest()
(base/(label+'-sources.json')).write_text(json.dumps({'sha256':fingerprint,'files':sources},indent=2)+'\n')
env=os.environ.copy()
env['PATH']=str(base/'toolchain')+':'+env['PATH']
env['PHP_INI_SCAN_DIR']='/etc/php/8.4/cli/conf.d:'+str(base/'toolchain/conf.d')
(base/(label+'-environment.json')).write_text(json.dumps({'cwd':str(root),'php':subprocess.check_output(['php','-v'],env=env,text=True),'php_path':subprocess.check_output(['sh','-c','command -v php'],env=env,text=True).strip(),'platform':subprocess.check_output(['uname','-srm'],text=True).strip(),'PATH':env['PATH'],'PHP_INI_SCAN_DIR':env['PHP_INI_SCAN_DIR']},indent=2)+'\n')
started=datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z')
r=subprocess.run(check['cmd'],shell=True,cwd=root,capture_output=True,env=env)
(base/(label+'.stdout.log')).write_bytes(r.stdout)
(base/(label+'.stderr.log')).write_bytes(r.stderr)
log=base/(label+'.log')
log.write_bytes(b'COMMAND '+check['cmd'].encode()+b'\nSTDOUT\n'+r.stdout+b'\nSTDERR\n'+r.stderr)
e={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':'PLAN1.P3.4.'+label,'check_id':check['id'],'subject':'PLAN1.P3.4','procedure':{'cmd':check['cmd'],'cwd':'.'},'candidate':{'head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'dirty_digest':fingerprint},'exit':r.returncode,'log':{'path':str(log.relative_to(root)),'sha256':hashlib.sha256(log.read_bytes()).hexdigest()},'started_at':started,'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z'),'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-sol','effort':'high'}}}
(base/(label+'.json')).write_text(json.dumps(e,indent=2)+'\n')
print(label,'exit',r.returncode)
print(r.stdout.decode()[-1000:])
print(r.stderr.decode()[-700:])
sys.exit(r.returncode)
