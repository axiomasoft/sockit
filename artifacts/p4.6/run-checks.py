#!/usr/bin/env python3
"""Execute the item commands literally and preserve actual exits, logs and source fingerprints."""
import datetime, hashlib, json, os, pathlib, platform, subprocess, sys
ROOT=pathlib.Path(__file__).resolve().parents[2]
os.chdir(ROOT)
OUT=pathlib.Path('artifacts/p4.6')
pack=json.loads(pathlib.Path('plans/2026.10.17-№1-SOCKIT-FOUNDATION/artifacts/autopilot/p4.6-context.json').read_text())
if sys.argv[1:] == ['regressions']:
 pack['item']['validation']=[{'id':'V92','cmd':'python3 scripts/testing/pg-run.py composer test -- --configuration phpunit.pg.xml tests/Integration/Social/ReactionMutationTest.php tests/Integration/Social/ReactionConcurrencyTest.php tests/Integration/Social/ReactionCountsTest.php tests/Integration/Social/ReactionRebuildTest.php tests/Integration/Social/PostMutationTest.php tests/Integration/Social/PostConcurrencyTest.php tests/Integration/Social/PostLifecycleTest.php tests/Integration/Social/PostPrivacyTest.php --fail-on-skipped --fail-on-incomplete --fail-on-warning --fail-on-risky'}]
 sys.argv=sys.argv[:1]
head=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
paths=subprocess.check_output(['rg','--files','packages/core/src','packages/core/modules','tests','scripts/testing'],text=True).splitlines()
paths+=['composer.json','composer.lock','phpunit.pg.xml','phpunit.xml','phpstan.neon','pint.json']
fingerprint={p:hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest() for p in sorted(set(paths)) if pathlib.Path(p).is_file()}
digest=hashlib.sha256(json.dumps(fingerprint,sort_keys=True,separators=(',',':')).encode()).hexdigest()
(OUT/'source-fingerprint.json').write_text(json.dumps({'head':head,'source_digest':digest,'sha256':fingerprint},indent=2)+'\n')
(OUT/'source-fingerprints').mkdir(exist_ok=True)
(OUT/'source-fingerprints'/f'{digest}.json').write_text((OUT/'source-fingerprint.json').read_text())
stamp=lambda:datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
failed=[]
for check in pack['item']['validation']:
 cid=check['id']
 if sys.argv[1:] and cid not in sys.argv[1:]: continue
 base=OUT/cid
 if base.with_suffix('.json').exists():
  n=1
  while (OUT/f'{cid}-attempt-{n}.json').exists():n+=1
  for suffix in ['.json','.log']:
   base.with_suffix(suffix).rename(OUT/f'{cid}-attempt-{n}{suffix}')
  archived=OUT/f'{cid}-attempt-{n}.json'
  previous=json.loads(archived.read_text())
  previous['log']['path']=str(archived.with_suffix('.log'))
  archived.write_text(json.dumps(previous,ensure_ascii=False,indent=2)+'\n')
 started=stamp()
 print(cid+' running '+check['cmd'],flush=True)
 with base.with_suffix('.log').open('w') as log:
  result=subprocess.run(check['cmd'],shell=True,executable='/bin/bash',stdout=log,stderr=subprocess.STDOUT)
 finished=stamp()
 evidence={'$schema':'urn:swissknifeman:task:plan:check-evidence:v1','evidence_id':f'p4.6-{cid}-{started}','check_id':cid,'subject':'PLAN1.P4.6',
  'procedure':{'cmd':check['cmd'],'cwd':'.'},'candidate':{'head':head,'dirty_digest':digest},'exit':result.returncode,
  'environment':{'platform':platform.platform(),'python':platform.python_version(),'env_digest':hashlib.sha256(json.dumps(dict(os.environ),sort_keys=True).encode()).hexdigest()},
  'log':{'path':str(base.with_suffix('.log')),'sha256':hashlib.sha256(base.with_suffix('.log').read_bytes()).hexdigest()},
  'started_at':started,'finished_at':finished,'producer':{'kind':'executor','actor':{'provider':'codex','model':'gpt-6.1-sol','effort':'xhigh','session':os.environ['CODEX_SESSION_ID']}}}
 base.with_suffix('.json').write_text(json.dumps(evidence,ensure_ascii=False,indent=2)+'\n')
 print(cid+' exit='+str(result.returncode),flush=True)
 if result.returncode:
  failed.append(cid)
  print('\n'.join(base.with_suffix('.log').read_text().splitlines()[-75:]),flush=True)

sys.exit(1 if failed else 0)
