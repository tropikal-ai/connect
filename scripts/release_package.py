"""Protected-runner package publication; never a workstation deploy command."""
import json
import os
import re
import subprocess


REPOSITORY = "tropikal-ai/connect"


def validate_context(environment, head, dirty):
    required = {"GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": REPOSITORY,
                "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_REF": "refs/heads/main",
                "QUALITY_RESULT": "success", "POLICY_RESULT": "success"}
    if any(environment.get(key) != value for key, value in required.items()):
        raise ValueError("Publication requires the owning protected-main dispatch and all gates")
    sha = environment.get("EXPECTED_SHA", "")
    version = environment.get("RELEASE_VERSION", "")
    if not re.fullmatch(r"[0-9a-f]{40}", sha) or head != sha or environment.get("GITHUB_SHA") != sha:
        raise ValueError("Checkout, event and intended source must be the same full SHA")
    if dirty:
        raise ValueError("Publication checkout must be clean")
    if not re.fullmatch(r"v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)", version):
        raise ValueError("Publication requires a canonical stable semantic version")
    return version, sha


def verify_release(release, version, sha):
    if not isinstance(release, dict) or type(release.get("id")) is not int or release["id"] <= 0:
        raise ValueError("Published release identity was not verified")
    if (release.get("tag_name") != version or release.get("target_commitish") != sha
            or release.get("draft") is not False or release.get("prerelease") is not False):
        raise ValueError("Existing or returned release differs from the intended stable source")
    return release


def publish(repository, version, sha):
    if repository.main_sha() != sha:
        raise ValueError("Protected main advanced; start a newly verified release")
    tag = repository.tag_sha(version)
    release = repository.get_release(version)
    if tag is not None and tag != sha:
        raise ValueError("Existing tag conflicts; tags must never be moved or overwritten")
    if release is not None:
        if tag != sha:
            raise ValueError("Existing release has no matching source tag")
        return verify_release(release, version, sha)
    if tag is None:
        repository.create_tag(version, sha)
    # A lost response intentionally raises. A later invocation reconciles the
    # exact tag/release, never deleting or repeating an uncertain write blindly.
    if repository.tag_sha(version) != sha or repository.main_sha() != sha:
        raise ValueError("Source changed before release publication; preserve the tag for reconciliation")
    repository.create_release(version, sha)
    if repository.tag_sha(version) != sha:
        raise ValueError("Published tag read-back did not match the tested commit")
    return verify_release(repository.get_release(version), version, sha)


class GitHubRepository:
    def request(self, path, *, fields=None, allow_missing=False):
        command = ["gh", "api", "--hostname", "github.com", f"repos/{REPOSITORY}/{path}"]
        if fields is not None:
            command.extend(["--method", "POST", "--input", "-"])
        result = subprocess.run(command, input=json.dumps(fields) if fields is not None else None,
                                text=True, capture_output=True, check=False)
        if result.returncode:
            if allow_missing and "(HTTP 404)" in result.stderr:
                return None
            # Do not print a runner token, request body or unbounded API error.
            raise RuntimeError("GitHub API request failed; inspect the exact remote state before retry")
        try:
            value = json.loads(result.stdout)
        except ValueError as error:
            raise RuntimeError("GitHub returned an invalid publication response") from error
        if not isinstance(value, dict):
            raise RuntimeError("GitHub returned an unexpected publication response")
        return value

    def main_sha(self):
        return self.request("git/ref/heads/main").get("object", {}).get("sha")

    def tag_sha(self, version):
        ref = self.request(f"git/ref/tags/{version}", allow_missing=True)
        if ref is None:
            return None
        target = ref.get("object", {})
        for _ in range(5):
            if target.get("type") == "commit" and re.fullmatch(r"[0-9a-f]{40}", target.get("sha", "")):
                return target["sha"]
            if target.get("type") != "tag" or not re.fullmatch(r"[0-9a-f]{40}", target.get("sha", "")):
                break
            target = self.request(f"git/tags/{target['sha']}").get("object", {})
        raise ValueError("Tag does not dereference to a verified commit")

    def get_release(self, version):
        return self.request(f"releases/tags/{version}", allow_missing=True)

    def create_tag(self, version, sha):
        self.request("git/refs", fields={"ref": f"refs/tags/{version}", "sha": sha})

    def create_release(self, version, sha):
        self.request("releases", fields={"tag_name": version, "target_commitish": sha,
                     "name": version, "draft": False, "prerelease": False,
                     "body": f"Published by the protected owning workflow from tested commit {sha}."})


def main():
    head = subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip()
    dirty = subprocess.check_output(["git", "status", "--porcelain"], text=True).strip()
    version, sha = validate_context(os.environ, head, dirty)
    result = publish(GitHubRepository(), version, sha)
    # Platform immutability is distinct from exact-source/no-retag policy.
    print(json.dumps({"version": version, "sha": sha, "release_id": result["id"],
                      "platform_immutable": result.get("immutable") is True}, sort_keys=True))


if __name__ == "__main__":
    main()
