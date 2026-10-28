"""Exercise native wildcard coverage and stale-candidate detection in disposable Git repos."""

import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("review_candidate", Path(__file__).resolve().parents[1] / "review_candidate.py")
scope = importlib.util.module_from_spec(spec)
spec.loader.exec_module(scope)


class ReviewCandidateTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="sockit-review-scope-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.files = {
            "packages/core/src/Actors/ActorContexts.php": "<?php // actor contexts\n",
            "packages/core/src/Actors/Data/ActorContext.php": "<?php // actor\n",
            "packages/core/src/Support/Data/ActorRef.php": "<?php // actor ref\n",
            "packages/core/src/Support/Data/InstallationRef.php": "<?php // installation\n",
            "packages/core/src/Support/Data/ResourceRef.php": "<?php // resource\n",
            "tests/Unit/Actors/ContextTest.php": "<?php // actor tests\n",
            "tests/Unit/Support/RefsTest.php": "<?php // ref tests\n",
            "fixtures/a.json": "{}\n",
        }
        for name, content in self.files.items():
            self.write(name, content)
        self.git("init", "-q")
        self.git("add", ".")
        self.git("-c", "user.name=Fixture", "-c", "user.email=fixture@example.test", "-c", "commit.gpgsign=false", "commit", "-qm", "fixture")
        self.documents = [
            {"task_files": [{"path": "packages/core/src/Support/**"}, {"path": "tests/Unit/Support/**"}, {"path": "fixtures/*.json"}]},
            {"task_files": [{"path": "packages/core/src/Actors/**"}, {"path": "tests/Unit/Actors/**"}, {"path": "packages/core/src/Actors/ActorContexts.php"}]},
        ]

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, stderr=subprocess.STDOUT)

    def write(self, name, content):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)

    def candidate(self):
        return scope.fingerprint(self.root, scope.expand_task_files(self.root, self.documents))

    def test_terminal_glob_covers_every_actor_ref_and_test_file(self):
        candidate = self.candidate()
        self.assertEqual(set(self.files), set(candidate["paths"]))
        for row in self.git("ls-tree", "-r", "HEAD").decode().splitlines():
            metadata, name = row.split("\t")
            mode, _, blob = metadata.split()
            self.assertEqual(mode + " " + blob, candidate["paths"][name])
        scope.verify(self.root, self.documents, [], candidate)

    def test_mutation_in_each_covered_file_changes_digest_and_rejects_staleness(self):
        candidate = self.candidate()
        for name, original in self.files.items():
            with self.subTest(path=name):
                self.write(name, original + "changed\n")
                self.assertNotEqual(candidate["digest"], self.candidate()["digest"])
                with self.assertRaisesRegex(ValueError, "source changed"):
                    scope.verify(self.root, self.documents, [], candidate)
                self.write(name, original)

    def test_new_and_deleted_files_in_covers_are_detected(self):
        candidate = self.candidate()
        added = "packages/core/src/Actors/Data/New.php"
        self.write(added, "<?php\n")
        with self.assertRaisesRegex(ValueError, "covers differ"):
            scope.verify(self.root, self.documents, [], candidate)
        (self.root / added).unlink()
        (self.root / "packages/core/src/Support/Data/ActorRef.php").unlink()
        with self.assertRaisesRegex(ValueError, "covers differ"):
            scope.verify(self.root, self.documents, [], candidate)

    def test_missing_patterns_do_not_silently_shrink_covers(self):
        with self.assertRaisesRegex(ValueError, "no files"):
            scope.expand_task_files(self.root, [{"task_files": [{"path": "missing/**"}]}])


if __name__ == "__main__":
    unittest.main()
