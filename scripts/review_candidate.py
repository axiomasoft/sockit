"""Expand native task_files and fingerprint the actual files in a review scope."""

import hashlib
import os
from pathlib import Path
import subprocess


def expand_task_files(root, documents):
    """A directory match (including a terminal /**) owns all descendant files."""
    paths = set()
    for document in documents:
        for entry in document["task_files"]:
            pattern = entry["path"]
            if Path(pattern).is_absolute() or ".." in Path(pattern).parts:
                raise ValueError("Review paths must be repository-relative")
            matches = list(root.glob(pattern))
            found = set()
            for match in matches:
                descendants = match.rglob("*") if match.is_dir() else [match]
                found.update(p.relative_to(root).as_posix() for p in descendants if p.is_file() or p.is_symlink())
            if not found:
                raise ValueError("Review pattern has no files: " + pattern)
            paths.update(found)
    return paths


def fingerprint(root, paths):
    """Native mode/blob digest of working files, including additions and mode changes."""
    result = {}
    for name in sorted(paths):
        path = root / name
        if path.is_symlink():
            mode, data = "120000", os.fsencode(os.readlink(path))
        elif path.is_file():
            mode = "100755" if path.stat().st_mode & 0o100 else "100644"
            data = path.read_bytes()
        else:
            result[name] = "absent"
            continue
        blob = subprocess.check_output(["git", "hash-object", "--stdin"], input=data, cwd=root).decode().strip()
        result[name] = mode + " " + blob
    lines = "\n".join(name + "\t" + result[name] for name in sorted(result))
    return {
        "head": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip(),
        "digest": "sha256:" + hashlib.sha256(lines.encode()).hexdigest(),
        "paths": result,
    }


def verify(root, documents, additional_paths, candidate):
    """Reject scope additions/removals as well as changes to any frozen working file."""
    paths = expand_task_files(root, documents) | set(additional_paths)
    if paths != set(candidate["paths"]):
        raise ValueError("Review covers differ from the frozen expanded paths")
    if fingerprint(root, paths) != candidate:
        raise ValueError("Review source changed after capture")
