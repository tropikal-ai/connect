import copy
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

from release_package import GitHubRepository, main, publish, validate_context


SHA = "a" * 40
VERSION = "v0.1.4"


class FakeRepository:
    def __init__(self):
        self.main = SHA
        self.tag = None
        self.release = None
        self.writes = []
        self.fail_after = None

    def main_sha(self):
        return self.main

    def tag_sha(self, version):
        return self.tag

    def get_release(self, version):
        return copy.deepcopy(self.release)

    def create_tag(self, version, sha):
        self.writes.append(("tag", version, sha))
        self.tag = sha
        if self.fail_after == "tag":
            raise RuntimeError("uncertain tag response")

    def create_release(self, version, sha):
        self.writes.append(("release", version, sha))
        self.release = {"id": 1, "tag_name": version, "target_commitish": sha,
                        "draft": False, "prerelease": False, "immutable": False}
        if self.fail_after == "release":
            raise RuntimeError("uncertain release response")


class ReleasePolicyTest(unittest.TestCase):
    def context(self):
        return {"GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": "tropikal-ai/connect",
                "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_REF": "refs/heads/main",
                "GITHUB_SHA": SHA, "EXPECTED_SHA": SHA, "RELEASE_VERSION": VERSION,
                "QUALITY_RESULT": "success", "POLICY_RESULT": "success"}

    def test_only_exact_protected_main_dispatch_is_accepted(self):
        self.assertEqual(validate_context(self.context(), SHA, ""), (VERSION, SHA))
        for key, value in [("GITHUB_ACTIONS", "false"), ("GITHUB_REPOSITORY", "other/connect"),
                           ("GITHUB_EVENT_NAME", "push"), ("GITHUB_REF", "refs/heads/feature"),
                           ("GITHUB_SHA", "b" * 40), ("EXPECTED_SHA", "main"),
                           ("QUALITY_RESULT", "failure"), ("POLICY_RESULT", "skipped")]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                validate_context({**self.context(), key: value}, SHA, "")
        for version in ["v0.1.4;echo bad", "$(bad)", "0.1.4", "v01.1.4", "v0.1.4-rc.1", ""]:
            with self.subTest(version=version), self.assertRaises(ValueError):
                validate_context({**self.context(), "RELEASE_VERSION": version}, SHA, "")
        for head, dirty in [("b" * 40, ""), (SHA, " M src/example.php")]:
            with self.assertRaises(ValueError):
                validate_context(self.context(), head, dirty)

    def test_missing_cancelled_and_pending_gates_reject(self):
        for key in ["QUALITY_RESULT", "POLICY_RESULT"]:
            for result in ["", "cancelled", "skipped", "pending", "failure"]:
                with self.subTest(key=key, result=result), self.assertRaises(ValueError):
                    validate_context({**self.context(), key: result}, SHA, "")

    def test_exact_release_is_idempotent_without_overwrite(self):
        repo = FakeRepository()
        result = publish(repo, VERSION, SHA)
        self.assertEqual(result["id"], 1)
        self.assertFalse(result["immutable"])
        self.assertEqual(repo.writes, [("tag", VERSION, SHA), ("release", VERSION, SHA)])
        publish(repo, VERSION, SHA)
        self.assertEqual(len(repo.writes), 2)

    def test_moved_main_and_conflicting_tag_never_write(self):
        for field in ["main", "tag"]:
            repo = FakeRepository()
            setattr(repo, field, "b" * 40)
            with self.subTest(field=field), self.assertRaises(ValueError):
                publish(repo, VERSION, SHA)
            self.assertEqual(repo.writes, [])

    def test_uncertain_tag_or_release_reconciles_without_recreating(self):
        for failure in ["tag", "release"]:
            repo = FakeRepository()
            repo.fail_after = failure
            with self.subTest(failure=failure), self.assertRaises(RuntimeError):
                publish(repo, VERSION, SHA)
            repo.fail_after = None
            publish(repo, VERSION, SHA)
            self.assertEqual(repo.writes, [("tag", VERSION, SHA), ("release", VERSION, SHA)])

    def test_release_with_missing_or_conflicting_tag_rejects(self):
        repo = FakeRepository()
        publish(repo, VERSION, SHA)
        for tag in [None, "b" * 40]:
            repo.tag = tag
            with self.assertRaises(ValueError):
                publish(repo, VERSION, SHA)
        self.assertEqual(len(repo.writes), 2)

    def test_readback_mismatch_is_not_success(self):
        for field, value in [("draft", True), ("prerelease", True),
                             ("target_commitish", "main"), ("tag_name", "v0.1.5"), ("id", None)]:
            repo = FakeRepository()
            publish(repo, VERSION, SHA)
            repo.release[field] = value
            with self.subTest(field=field), self.assertRaises(ValueError):
                publish(repo, VERSION, SHA)
            self.assertEqual(len(repo.writes), 2)

    def test_moved_main_between_tag_and_release_fails_closed(self):
        repo = FakeRepository()
        original = repo.create_tag
        def move(version, sha):
            original(version, sha)
            repo.main = "b" * 40
        repo.create_tag = move
        with self.assertRaises(ValueError):
            publish(repo, VERSION, SHA)
        self.assertEqual(repo.writes, [("tag", VERSION, SHA)])

    def test_runner_rejection_never_reaches_remote_api(self):
        for context in [{}, {**self.context(), "GITHUB_REF": "refs/heads/feature"}]:
            with patch("release_package.os.environ", context), patch(
                "release_package.subprocess.check_output", side_effect=[SHA + "\n", ""]
            ), patch("release_package.GitHubRepository") as repository:
                with self.assertRaises(ValueError):
                    main()
                repository.assert_not_called()

    def test_api_only_declared_404_means_missing(self):
        repository = GitHubRepository()
        for status in [401, 403, 422, 429, 500]:
            result = subprocess.CompletedProcess([], 1, "", f"gh: sensitive failure (HTTP {status})")
            with patch("release_package.subprocess.run", return_value=result):
                with self.assertRaisesRegex(RuntimeError, "^GitHub API request failed;") as error:
                    repository.get_release(VERSION)
                self.assertNotIn("sensitive", str(error.exception))
        missing = subprocess.CompletedProcess([], 1, "", "gh: Not Found (HTTP 404)")
        with patch("release_package.subprocess.run", return_value=missing):
            self.assertIsNone(repository.get_release(VERSION))
            with self.assertRaises(RuntimeError):
                repository.create_tag(VERSION, SHA)

    def test_api_writes_are_explicit_json_without_shell_or_force(self):
        ok = subprocess.CompletedProcess([], 0, "{}", "")
        with patch("release_package.subprocess.run", return_value=ok) as run:
            GitHubRepository().create_tag(VERSION, SHA)
            command = run.call_args.args[0]
            self.assertEqual(command, ["gh", "api", "--hostname", "github.com",
                                      "repos/tropikal-ai/connect/git/refs", "--method", "POST", "--input", "-"])
            self.assertEqual(json.loads(run.call_args.kwargs["input"]), {"ref": "refs/tags/" + VERSION, "sha": SHA})
            self.assertNotIn("shell", run.call_args.kwargs)
        for body in ["invalid", "[]", "null"]:
            with patch("release_package.subprocess.run", return_value=subprocess.CompletedProcess([], 0, body, "")):
                with self.assertRaises(RuntimeError):
                    GitHubRepository().get_release(VERSION)

    def test_annotated_tag_is_dereferenced_and_unbounded_chain_rejects(self):
        repository = GitHubRepository()
        with patch.object(repository, "request", side_effect=[
            {"object": {"type": "tag", "sha": "b" * 40}},
            {"object": {"type": "commit", "sha": SHA}},
        ]) as request:
            self.assertEqual(repository.tag_sha(VERSION), SHA)
            self.assertEqual(request.call_args.args, ("git/tags/" + "b" * 40,))
        with patch.object(repository, "request", return_value={"object": {"type": "tag", "sha": "b" * 40}}):
            with self.assertRaises(ValueError):
                repository.tag_sha(VERSION)

    def test_workflow_binds_publish_to_same_sha_and_successful_gates(self):
        workflow = (Path(__file__).resolve().parents[1] / ".github/workflows/release.yml").read_text()
        self.assertIn("needs: [quality, policy]", workflow)
        self.assertIn("needs.quality.result == 'success' && needs.policy.result == 'success'", workflow)
        self.assertIn("github.ref == 'refs/heads/main'", workflow)
        self.assertIn("uses: ./.github/workflows/php-quality.yml", workflow)
        self.assertIn("EXPECTED_SHA: ${{ inputs.expected_sha }}", workflow)
        self.assertIn("RELEASE_VERSION: ${{ inputs.version }}", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertEqual(workflow.count("contents: write"), 1)
        self.assertNotIn("run: ${{", workflow)


if __name__ == "__main__":
    unittest.main()
