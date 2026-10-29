#!/usr/bin/env python3
import hashlib,json,pathlib
from jsonschema import Draft202012Validator,RefResolver
root=pathlib.Path(__file__).resolve().parents[2]
out=root/'artifacts/p3.5'
schema=json.loads((out/'check-evidence-schema.json').read_text())['data']
defs=json.loads((out/'defs-schema.json').read_text())['data']
validator=Draft202012Validator(schema,resolver=RefResolver.from_schema(schema,store={schema['$id']:schema,defs['$id']:defs}))
result=[]
for check in ('V1','V2','V3','V90','V91'):
    evidence=json.loads((out/(check+'.json')).read_text())
    validator.validate(evidence)
    assert evidence['exit']==0
    assert hashlib.sha256((root/evidence['log']['path']).read_bytes()).hexdigest()==evidence['log']['sha256']
    source=json.loads((out/(check+'-source-fingerprints.json')).read_text())
    for path,expected in source['files'].items():
        assert hashlib.sha256((root/path).read_bytes()).hexdigest()==expected,path
    assert evidence['candidate']['dirty_digest']==source['digest']
    result.append({'check':check,'exit':0,'schema':'valid','log_digest':'valid','sources':'unchanged','digest':source['digest']})
assert len({r['digest'] for r in result})==1
(out/'evidence-validation.json').write_text(json.dumps({'exit':0,'results':result},indent=2)+'\n')
print(json.dumps(result,indent=2))
