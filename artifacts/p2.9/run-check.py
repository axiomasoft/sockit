#!/usr/bin/env python3
"""Capture a real P2.9 check and native check-evidence without environment secrets."""
import datetime, hashlib, json, os, pathlib, platform, subprocess, sys
root=pathlib.Path(__file__).resolve().parents[2]
check=sys.argv[1]
item=json.loads((root/'plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p2.9-context.json').read_text())['item']
commands={v['id']:v['cmd'] for v in item['validation']}
commands['V4']='python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Integration/Runtime/EnvelopeAtomicityTest.php tests/Integration/Runtime/EnvelopeConcurrencyTest.php tests/Integration/Runtime/ReplayAuthorizationTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky'
commands['V99']='python3 artifacts/p2.9/verify-evidence.py'
cmd=commands[check]
log=root/'artifacts/p2.9'/f'{check}.log'
now=lambda:datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00','Z')
start=now()
with log.open('w') as f:
 f.write(f'command={cmd}\ncwd={root}\n');f.flush()
 result=subprocess.run(cmd,shell=True,cwd=root,stdout=f,stderr=subprocess.STDOUT)
data={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':'p2.9-'+check,'check_id':check,'subject':'PLAN1.P2.9','procedure':{'cmd':cmd,'cwd':'.'},'candidate':{'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()},'exit':result.returncode,'started_at':start,'finished_at':now(),'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-sol','effort':'high','session':os.environ['CODEX_SESSION_ID']}},'environment':{'platform':platform.platform(),'python':platform.python_version(),'env_digest':hashlib.sha256(json.dumps({k:os.environ.get(k) for k in ['CODEX_SESSION_ID','APP_ENV','DB_DATABASE','DB_CONNECTION','SOCKIT_PG_MANIFEST']},sort_keys=True).encode()).hexdigest()},'log':{'path':str(log.relative_to(root)),'sha256':hashlib.sha256(log.read_bytes()).hexdigest()}}
(root/'artifacts/p2.9'/f'{check}.json').write_text(json.dumps(data,indent=2)+'\n')
print(f'{check} exit={result.returncode}')
print(log.read_text()[-18000:])
sys.exit(result.returncode)
