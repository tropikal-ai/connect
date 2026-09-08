import copy
import base64
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from release_adapter import execute, unique_object, validate_intents, validate_publish_context
from test_release_package import FakeRepository


CONTROL = "a" * 40
SOURCE = "b" * 40
RECORD = {"version": "v0.1.15", "line": "filament-3", "source_sha": SOURCE, "source_pr": 45}


class AdapterReleaseTest(unittest.TestCase):
    def context(self):
        return {"GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": "tropikal-ai/connect-filament",
                "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_REF": "refs/heads/main",
                "GITHUB_SHA": CONTROL, "EXPECTED_CONTROL_SHA": CONTROL,
                "RESOLVE_RESULT": "success", "QUALITY_RESULT": "success",
                "VERIFIED_SOURCE_SHA": SOURCE}

    def test_bounded_intent_records_and_supported_lines(self):
        self.assertEqual(validate_intents({"schema": 1, "releases": [RECORD]}), [RECORD])
        self.assertEqual(validate_intents({"schema": 1, "releases": []}), [])
        for field, value in [("version", "v0.2.13"), ("line", "main"),
                             ("source_sha", "main"), ("source_pr", 0), ("source_pr", True)]:
            with self.subTest(field=field), self.assertRaises(ValueError):
                validate_intents({"schema": 1, "releases": [{**RECORD, field: value}]})
        for document in [{"schema": 2, "releases": []}, {"schema": 1, "releases": [RECORD, RECORD]},
                         {"schema": 1, "releases": [{**RECORD, "command": "bad"}]}]:
            with self.assertRaises(ValueError):
                validate_intents(document)

    def test_approved_control_does_not_authorize_an_unapproved_source(self):
        self.assertEqual(validate_publish_context(self.context(), CONTROL, "", SOURCE, "", RECORD), RECORD)
        for source, record in [(CONTROL, RECORD), ("c" * 40, RECORD),
                               (SOURCE, {**RECORD, "source_sha": "c" * 40})]:
            with self.assertRaises(ValueError):
                validate_publish_context(self.context(), CONTROL, "", source, "", record)

    def test_control_source_and_quality_are_independently_bound(self):
        for key, value in [("GITHUB_REPOSITORY", "other/connect-filament"),
                           ("GITHUB_EVENT_NAME", "pull_request"),
                           ("GITHUB_REF", "refs/heads/maintenance/filament-3"),
                           ("GITHUB_SHA", "c" * 40), ("EXPECTED_CONTROL_SHA", "c" * 40),
                           ("VERIFIED_SOURCE_SHA", CONTROL)]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                validate_publish_context({**self.context(), key: value}, CONTROL, "", SOURCE, "", RECORD)
        for key in ["RESOLVE_RESULT", "QUALITY_RESULT"]:
            for value in ["", "failure", "cancelled", "skipped"]:
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    validate_publish_context({**self.context(), key: value}, CONTROL, "", SOURCE, "", RECORD)
        for control_dirty, source_dirty in [("M intent", ""), ("", "M source")]:
            with self.assertRaises(ValueError):
                validate_publish_context(self.context(), CONTROL, control_dirty, SOURCE, source_dirty, RECORD)

    def test_duplicate_json_keys_reject(self):
        with self.assertRaises(ValueError):
            json.loads('{"schema":1,"schema":1,"releases":[]}', object_pairs_hook=unique_object)


class AdapterExecutionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.workspace = Path(self.temporary.name)
        self.control = self.workspace / "control"
        self.source = self.workspace / "source"
        for directory in [self.control, self.source]:
            directory.mkdir()
            self.git(directory, "init", "-q")
            self.git(directory, "config", "user.name", "Release fixture")
            self.git(directory, "config", "user.email", "release@example.invalid")
            (directory / ".github/workflows").mkdir(parents=True)
            (directory / ".github/workflows/ci.yml").write_text("name: exact fixture\n")
        self.manifest = {"name": "tropikal-ai/connect-filament", "require": {"filament/filament": "^3.2"}}
        (self.source / "composer.json").write_text(json.dumps(self.manifest))
        # A payload script is data in the write job, never a trusted executable.
        (self.source / "scripts").mkdir()
        (self.source / "scripts/release_adapter.py").write_text("raise AssertionError('payload code executed')\n")
        self.source_sha = self.commit(self.source)
        self.record = {**RECORD, "source_sha": self.source_sha}
        self.write_intent()
        self.control_sha = self.commit(self.control)
        self.environment = {**AdapterReleaseTest().context(), "GITHUB_WORKSPACE": str(self.workspace),
                            "GITHUB_SHA": self.control_sha, "EXPECTED_CONTROL_SHA": self.control_sha,
                            "VERIFIED_SOURCE_SHA": self.source_sha, "CONTROL_DIRECTORY": "control",
                            "SOURCE_DIRECTORY": "source", "RELEASE_VERSION": "v0.1.15",
                            "RELEASE_MODE": "publish", "GITHUB_OUTPUT": str(self.workspace / "output")}
        self.repository = FakeRepository()
        self.repository.main = self.control_sha
        self.pull = {"state": "closed", "merged": True, "merge_commit_sha": self.source_sha,
                     "base": {"ref": "maintenance/filament-3", "repo": {"full_name": "tropikal-ai/connect-filament"}}}
        def request(path):
            if path == "pulls/45":
                return copy.deepcopy(self.pull)
            self.assertEqual(path, f"contents/composer.json?ref={self.record['source_sha']}")
            payload = json.dumps(self.manifest).encode()
            return {"encoding": "base64", "size": len(payload), "content": base64.b64encode(payload).decode()}
        self.repository.request = request
        self.patch = patch("release_adapter.GitHubRepository", return_value=self.repository)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def git(self, directory, *arguments):
        return subprocess.check_output(["git", "-C", str(directory), *arguments], text=True).strip()

    def commit(self, directory):
        self.git(directory, "add", ".")
        self.git(directory, "commit", "-qm", "fixture")
        return self.git(directory, "rev-parse", "HEAD")

    def write_intent(self):
        (self.control / ".github/release-intents.json").write_text(json.dumps({"schema": 1, "releases": [self.record]}))

    def test_resolve_and_publish_bind_distinct_control_and_payload_without_payload_execution(self):
        resolved = execute({**self.environment, "RELEASE_MODE": "resolve"})
        self.assertEqual(resolved["source_sha"], self.source_sha)
        self.assertNotEqual(self.source_sha, self.control_sha)
        self.assertEqual(json.loads(resolved["matrix"])["include"], [self.record])
        self.assertEqual(self.repository.writes, [])
        result = execute(self.environment)
        self.assertEqual(result["source_sha"], self.source_sha)
        self.assertEqual(result["control_sha"], self.control_sha)
        self.assertEqual(self.repository.writes, [("tag", "v0.1.15", self.source_sha), ("release", "v0.1.15", self.source_sha)])
        execute(self.environment)
        self.assertEqual(len(self.repository.writes), 2)

    def test_unapproved_version_main_instead_of_payload_and_dirty_intent_never_write(self):
        for override in [{"RELEASE_VERSION": "v0.2.13"}, {"SOURCE_DIRECTORY": "control"},
                         {"VERIFIED_SOURCE_SHA": self.control_sha}, {"SOURCE_DIRECTORY": "../outside"}]:
            with self.subTest(override=override), self.assertRaises(ValueError):
                execute({**self.environment, **override})
        self.record["source_sha"] = "c" * 40
        self.write_intent()
        with self.assertRaises(ValueError):
            execute(self.environment)
        self.assertEqual(self.repository.writes, [])

    def test_off_main_workflow_change_rejects_before_creating_a_tag(self):
        (self.source / ".github/workflows/ci.yml").write_text("name: changed payload workflow\n")
        self.record["source_sha"] = self.commit(self.source)
        self.pull["merge_commit_sha"] = self.record["source_sha"]
        self.write_intent()
        control = self.commit(self.control)
        self.repository.main = control
        environment = {**self.environment, "GITHUB_SHA": control, "EXPECTED_CONTROL_SHA": control,
                       "VERIFIED_SOURCE_SHA": self.record["source_sha"]}
        with self.assertRaisesRegex(ValueError, "workflow bytes differ"):
            execute(environment)
        self.assertEqual(self.repository.writes, [])

    def test_source_pr_authority_and_package_line_are_checked_before_payload_quality(self):
        for field, value in [("merged", False), ("merge_commit_sha", self.control_sha), ("state", "open")]:
            original = self.pull[field]
            self.pull[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                execute({**self.environment, "RELEASE_MODE": "resolve"})
            self.pull[field] = original
        self.manifest["require"]["filament/filament"] = "^5.0"
        with self.assertRaisesRegex(ValueError, "supported line"):
            execute({**self.environment, "RELEASE_MODE": "resolve"})
        self.assertEqual(self.repository.writes, [])

    def test_moving_control_and_uncertain_writes_preserve_exact_history(self):
        self.repository.main = "c" * 40
        with self.assertRaises(ValueError):
            execute(self.environment)
        self.assertEqual(self.repository.writes, [])
        self.repository.main = self.control_sha
        self.repository.fail_after = "tag"
        with self.assertRaises(RuntimeError):
            execute(self.environment)
        self.repository.fail_after = None
        execute(self.environment)
        self.assertEqual(len(self.repository.writes), 2)

    def test_immutable_action_executes_only_its_tooling_and_quality_checks_out_payload(self):
        root = Path(__file__).resolve().parents[1]
        action = (root / "action.yml").read_text()
        self.assertIn('PINNED_ACTION_PATH: ${{ github.action_path }}', action)
        self.assertIn('run: python3 -B "$PINNED_ACTION_PATH/scripts/release_adapter.py"', action)
        self.assertNotIn('run: python3 source/', action)
        quality = (root / ".github/workflows/php-quality.yml").read_text()
        self.assertIn('ref: ${{ inputs.checkout-ref || github.sha }}', quality)
        self.assertIn('persist-credentials: false', quality)


if __name__ == "__main__":
    unittest.main()
