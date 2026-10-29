import sys,subprocess,json,pathlib,hashlib,datetime,platform,os,shlex
root=pathlib.Path.cwd(); ident=sys.argv[1]; args=sys.argv[2:]; dest=root/'.swissknifeman/state/p2.7-finalization'; now=lambda: datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z')
paths=sorted([*pathlib.Path('packages/core').rglob('*.php'),*pathlib.Path('tests').rglob('*.php'),*pathlib.Path('docs/architecture').glob('*.md'),pathlib.Path('composer.json'),pathlib.Path('composer.lock')]); digest=hashlib.sha256()
for p in paths: digest.update(str(p).encode()+b'\0'+p.read_bytes())
start=now(); proc=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE); end=now()
log=dest/(ident+'.log'); log.write_bytes(b'COMMAND '+shlex.join(args).encode()+b'\nCWD '+str(root).encode()+b'\nSTDOUT\n'+proc.stdout+b'\nSTDERR\n'+proc.stderr+b'\nEXIT '+str(proc.returncode).encode()+b'\n')
env={k:os.environ.get(k) for k in ['APP_ENV','SOCKIT_PG_MANIFEST','DB_CONNECTION','DB_DATABASE','CODEX_THREAD_ID']}; evidence={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':'p27-finish-'+ident,'check_id':ident.split('-')[0],'subject':'PLAN1.P2.7','procedure':{'cmd':shlex.join(args),'cwd':'.'},'candidate':{'head':subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip(),'dirty_digest':digest.hexdigest()},'exit':proc.returncode,'environment':{'platform':platform.platform(),'env_digest':hashlib.sha256(json.dumps(env,sort_keys=True).encode()).hexdigest()},'log':{'path':str(log.relative_to(root)),'sha256':hashlib.sha256(log.read_bytes()).hexdigest()},'started_at':start,'finished_at':end,'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-sol','effort':'xhigh'}}}

if not __import__('re').match(r'^V[0-9]+(?:-|$)',ident):
 evidence.pop('$schema',None); evidence.pop('check_id',None); evidence['kind']='supplemental-command'
(dest/(ident+'.json')).write_text(json.dumps(evidence,indent=2)+'\n'); print(log.read_text()); sys.exit(proc.returncode)
