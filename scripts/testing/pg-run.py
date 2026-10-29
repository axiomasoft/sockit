#!/usr/bin/env python3
"""Run the PG suite against a container created and removed by this invocation."""
import json, os, pathlib, secrets, subprocess, sys, tempfile, time

run = secrets.token_hex(8)
name = 'sockit-p21-' + run
owner = 'sockit_p2_' + run + '_test'
decoy = 'sockit_p2_decoy_' + run + '_test'
password = secrets.token_hex(24)
container = None
manifest = None

def docker(*args):
    return subprocess.check_output(['docker', *args], text=True).strip()

try:
    container = docker('run', '-d', '--name', name, '--label', 'sockit.test.run=' + run,
                       '-e', 'POSTGRES_PASSWORD=' + password, '-e', 'POSTGRES_DB=' + owner,
                       '-p', '127.0.0.1::5432', 'postgres:16-alpine')
    inspect = json.loads(docker('inspect', container))[0]
    if inspect['Config']['Labels']['sockit.test.run'] != run:
        raise RuntimeError('container ownership mismatch')
    binding = inspect['NetworkSettings']['Ports']['5432/tcp']
    if len(binding) != 1 or binding[0]['HostIp'] != '127.0.0.1':
        raise RuntimeError('container endpoint is not private loopback')
    mounts = inspect.get('Mounts', [])
    if len(mounts) != 1 or mounts[0]['Type'] != 'volume' or mounts[0]['Destination'] != '/var/lib/postgresql/data':
        raise RuntimeError('unexpected private PostgreSQL mounts')
    print('private volume=' + mounts[0]['Name'], flush=True)
    port = int(binding[0]['HostPort'])
    for attempt in range(60):
        if subprocess.run(['docker', 'exec', container, 'psql', '-U', 'postgres', '-d', owner, '-Atqc', 'SELECT current_database()'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            break
        time.sleep(.5)
    else:
        raise RuntimeError('private PostgreSQL did not become ready')
    # Names and marker are generated locally and checked before any SQL mutation.
    if not owner.endswith('_test') or not decoy.endswith('_test') or owner == decoy:
        raise RuntimeError('unsafe database names')
    docker('exec', container, 'psql', '-U', 'postgres', '-d', owner, '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE DATABASE "' + decoy + '"')
    for database in (owner, decoy):
        docker('exec', container, 'psql', '-U', 'postgres', '-d', database, '-v', 'ON_ERROR_STOP=1', '-c', "COMMENT ON DATABASE \"" + database + "\" IS 'sockit-p21-" + run + "'")
    fd, manifest = tempfile.mkstemp(prefix='sockit-p21-', suffix='.json')
    with os.fdopen(fd, 'w') as f:
        json.dump({'run': run, 'container': container, 'host': '127.0.0.1', 'port': port, 'owner': owner, 'decoy': decoy, 'user': 'postgres', 'password': password}, f)
    os.chmod(manifest, 0o600)
    env = os.environ.copy()
    env['SOCKIT_PG_MANIFEST'] = manifest
    print('private container=' + container + ' owner=' + owner + ' decoy=' + decoy + ' port=' + str(port), flush=True)
    sys.exit(subprocess.run(sys.argv[1:] or ['composer', 'test', '--', '--configuration', 'phpunit.pg.xml', 'tests/Integration/Runtime/Infrastructure', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-warning', '--fail-on-risky'], env=env).returncode)
finally:
    if container:
        # Exact container id, validated ownership label; never operate on other services.
        try:
            info = json.loads(docker('inspect', container))[0]
            if info['Config']['Labels'].get('sockit.test.run') == run:
                subprocess.run(['docker', 'container', 'stop', container], check=True, stdout=subprocess.DEVNULL)
                # This invocation created every anonymous volume; no host/named mounts are accepted.
                subprocess.run(['docker', 'container', 'remove', '--volumes', container], check=True, stdout=subprocess.DEVNULL)
        except (subprocess.SubprocessError, KeyError, ValueError) as exc:
            print('cleanup failed: ' + str(exc), file=sys.stderr)
            raise
    if manifest:
        pathlib.Path(manifest).unlink(missing_ok=True)
