#!/usr/bin/env python3
"""Remove only interrupted wrapper containers proven by this item's partial run logs."""
import json,pathlib,re,subprocess
out=pathlib.Path('artifacts/p3.3')
for check in ['V1','V92']:
 log=out/(check+'.stdout.log')
 if not log.exists(): continue
 match=re.search(r'^private container=([0-9a-f]{64}) owner=(sockit_p2_([0-9a-f]{16})_test) decoy=(sockit_p2_decoy_[0-9a-f]{16}_test) port=([0-9]+)$',log.read_text(),re.M)
 if not match: continue
 container,owner,run,decoy,port=match.groups()
 r=subprocess.run(['docker','inspect',container],capture_output=True,text=True)
 if r.returncode: continue
 data=json.loads(r.stdout)[0]
 assert data['Id']==container
 assert data['Config']['Labels']['sockit.test.run']==run
 assert data['Name']=='/sockit-p21-'+run
 binding=data['NetworkSettings']['Ports']['5432/tcp']
 assert binding==[{'HostIp':'127.0.0.1','HostPort':port}]
 for action in ['stop','remove']:
  result=subprocess.run(['docker','container',action,container],capture_output=True,text=True)
  assert result.returncode==0,result.stderr
  print(json.dumps({'check':check,'container':container,'run':run,'owner':owner,'action':action,'exit':result.returncode}))
 for manifest in pathlib.Path('/tmp').glob('sockit-p21-*.json'):
  try: m=json.loads(manifest.read_text())
  except (ValueError,OSError): continue
  if m.get('container')==container and m.get('run')==run and m.get('owner')==owner:
   manifest.unlink()
   print(json.dumps({'run':run,'action':'remove_exact_private_manifest'}))
interrupted=out/'interrupted'
interrupted.mkdir(exist_ok=True)
for name in ['source-manifest.json']+[c+ext for c in ['V1','V2','V3','V90','V91','V92'] for ext in ['.json','.log','.stdout.log','.stderr.log']]:
 source=out/name
 if source.exists():
  target=interrupted/name
  assert not target.exists()
  source.rename(target)
