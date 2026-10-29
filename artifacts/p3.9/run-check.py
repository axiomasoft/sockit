import datetime,hashlib,json,os,pathlib,platform,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2]; out=root/'artifacts/p3.9'; check=sys.argv[1]
cmd={'V1':['php','scripts/check-docs.php'],'V2':['python3','artifacts/p3.9/verify-v2.py']}[check]
files=['README.md','docs/architecture/panels-and-storage.md','docs/architecture/spi.md','docs/architecture/traceability.md','docs/guides/panel-install-upgrade.md','artifacts/p3.9/verify-v2.py']
h=lambda b:hashlib.sha256(b).hexdigest()
f={p:h((root/p).read_bytes()) for p in files}; digest=h(json.dumps(f,sort_keys=True,separators=(',',':')).encode())
now=lambda:datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
start=now(); r=subprocess.run(cmd,cwd=root,capture_output=True); end=now()
log=('command: '+' '.join(cmd)+'\ncwd: '+str(root)+'\nstarted: '+start+'\n--- stdout ---\n').encode()+r.stdout+b'\n--- stderr ---\n'+r.stderr+('\nexit: '+str(r.returncode)+'\nfinished: '+end+'\n').encode(); (out/(check+'.log')).write_bytes(log)
(out/(check+'-sources.json')).write_text(json.dumps({'files':f,'digest':digest},indent=2)+'\n')
e={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':'p3.9-'+check+'-'+start,'check_id':check,'subject':'PLAN1.P3.9','procedure':{'cmd':' '.join(cmd),'cwd':'.'},'candidate':{'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'dirty_digest':digest},'exit':r.returncode,'environment':{'platform':platform.platform(),'python':platform.python_version(),'env_digest':h(json.dumps(dict(os.environ),sort_keys=True).encode())},'log':{'path':'artifacts/p3.9/'+check+'.log','sha256':h(log)},'started_at':start,'finished_at':end,'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-terra','effort':'medium','session':os.environ['CODEX_SESSION_ID']}}}
(out/(check+'.json')).write_text(json.dumps(e,indent=2)+'\n'); print(log.decode()); sys.exit(r.returncode)
