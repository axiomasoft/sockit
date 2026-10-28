import json,subprocess,pathlib
v=json.loads(pathlib.Path('artifacts/p2.7/native-item.json').read_text())
if 'task_files' not in v: v=v['data']
paths=v['task_files']
for p in ['packages/core/src/Operations/Internal/Storage/SurfaceInstances.php','packages/core/src/Operations/Internal/Storage/OwnerConnection.php']:
 if not any(x['path']==p for x in paths): paths.append({'path':p,'change':'any','note':'P2.7 current surface sampling and owned transaction fault boundary'})
pathlib.Path('artifacts/p2.7/task-files-patch.json').write_text(json.dumps({'task_files':paths},indent=2))
