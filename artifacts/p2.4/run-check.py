#!/usr/bin/env python3
"""Capture actual child exit/output; environment identities are deliberately whitelisted."""
import datetime,hashlib,json,os,pathlib,platform,shlex,subprocess,sys
root=pathlib.Path.cwd();out=root/'artifacts/p2.4';check=sys.argv[1];argv=sys.argv[2:]
if argv and argv[0]=='--':argv=argv[1:]
def now():return datetime.datetime.now(datetime.timezone.utc).isoformat()
started=now()
source=[]
for directory in ['packages/core/src','packages/core/resources/contracts/fixtures/targets','tests','docs/architecture/spi.md']:
 p=root/directory
 for f in sorted(p.rglob('*')) if p.is_dir() else [p]:
  if f.is_file():source.append(str(f.relative_to(root))+':'+hashlib.sha256(f.read_bytes()).hexdigest())
digest=hashlib.sha256('\n'.join(source).encode()).hexdigest()
proc=subprocess.run(argv,cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
stdout=proc.stdout;stderr=proc.stderr
(out/(check+'.stdout.log')).write_bytes(stdout);(out/(check+'.stderr.log')).write_bytes(stderr)
log=out/(check+'.log');log.write_bytes(b'COMMAND: '+shlex.join(argv).encode()+b'\nCWD: '+str(root).encode()+b'\nSTDOUT:\n'+stdout+b'\nSTDERR:\n'+stderr)
identity={'platform':platform.platform(),'php':subprocess.check_output(['php','-r','echo PHP_VERSION;'],text=True),'database':'Private owned PostgreSQL16 via P2.1 run manifest' if 'pg-run.py' in ' '.join(argv) else 'No database mutations; no ambient .env targets','session':os.environ.get('CODEX_THREAD_ID',os.environ.get('CODEX_SESSION_ID','unknown'))}
envfile=out/(check+'.environment.json');envfile.write_text(json.dumps(identity,indent=2)+'\n')
r={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':'p24-'+check,'check_id':check,'subject':'PLAN1.P2.4','procedure':{'cmd':shlex.join(argv),'cwd':str(root)},'candidate':{'head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'dirty_digest':digest},'exit':proc.returncode,'environment':{'platform':platform.platform(),'env_digest':hashlib.sha256(envfile.read_bytes()).hexdigest()},'log':{'path':str(log.relative_to(root)),'sha256':hashlib.sha256(log.read_bytes()).hexdigest()},'started_at':started,'finished_at':now(),'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-sol','effort':'high'}}}
(out/(check+'.json')).write_text(json.dumps(r,indent=2)+'\n')
print(json.dumps({'check':check,'exit':proc.returncode,'log':str(log.relative_to(root))}))
print((stdout+stderr).decode(errors='replace')[-2500:])
sys.exit(proc.returncode)
