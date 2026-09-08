"""Pinned delivery interface; protected owner intent authorizes exact payloads."""
import base64
import json
import os
from pathlib import Path
import re
import subprocess

from release_package import GitHubRepository, publish

OWNER = "tropikal-ai/connect-filament"
LINES = {"filament-3": ("0.1", "maintenance/filament-3", "^3.2"),
         "filament-5": ("0.2", "main", "^5.0")}
INTENTS = ".github/release-intents.json"


def exact_sha(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{40}", value) is not None


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Duplicate release metadata key")
        result[key] = value
    return result


def validate_intents(document):
    if (not isinstance(document, dict) or set(document) != {"schema", "releases"}
            or type(document["schema"]) is not int or document["schema"] != 1
            or not isinstance(document["releases"], list) or len(document["releases"]) > 2):
        raise ValueError("Invalid bounded release intent document")
    seen = set()
    for record in document["releases"]:
        if not isinstance(record, dict) or set(record) != {"version", "line", "source_sha", "source_pr"}:
            raise ValueError("Invalid exact release intent")
        line = record["line"]
        if not isinstance(line, str) or line not in LINES or line in seen:
            raise ValueError("Unsupported or duplicate release line")
        prefix = LINES[line][0]
        if (not isinstance(record["version"], str)
                or not re.fullmatch(r"v" + re.escape(prefix) + r"\.(0|[1-9][0-9]*)", record["version"])
                or not exact_sha(record["source_sha"])
                or type(record["source_pr"]) is not int or record["source_pr"] <= 0):
            raise ValueError("Release version, source or PR does not match its supported line")
        seen.add(line)
    return document["releases"]


def validate_publish_context(environment, control_head, control_dirty, source_head, source_dirty, record):
    required = {"GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": OWNER,
                "GITHUB_EVENT_NAME": "workflow_dispatch", "GITHUB_REF": "refs/heads/main",
                "RESOLVE_RESULT": "success", "QUALITY_RESULT": "success"}
    if any(environment.get(key) != value for key, value in required.items()):
        raise ValueError("Publication requires protected owner main and successful exact-source gates")
    validate_intents({"schema": 1, "releases": [record]})
    if (not exact_sha(control_head) or control_head != environment.get("GITHUB_SHA")
            or control_head != environment.get("EXPECTED_CONTROL_SHA") or control_dirty or source_dirty):
        raise ValueError("Control checkout and approved event must be exact and clean")
    if source_head != record["source_sha"] or source_head != environment.get("VERIFIED_SOURCE_SHA"):
        raise ValueError("Payload is not the exact approved and tested source")
    return record


def git(directory, *arguments):
    return subprocess.check_output(["git", "-C", str(directory), *arguments], text=True).strip()


def checkout_directory(environment, key):
    workspace = Path(environment["GITHUB_WORKSPACE"]).resolve()
    value = environment.get(key, "")
    if not value:
        raise ValueError("An explicit checkout directory is required")
    directory = (workspace / value).resolve()
    if directory != workspace and workspace not in directory.parents:
        raise ValueError("Checkout must remain inside the owning runner workspace")
    return directory


def read_intents(control):
    payload = (control / INTENTS).read_bytes()
    if len(payload) > 65536:
        raise ValueError("Release intent exceeds the bounded contract")
    return validate_intents(json.loads(payload, object_pairs_hook=unique_object))


def verify_source_authority(repository, record):
    pull = repository.request(f"pulls/{record['source_pr']}")
    base = pull.get("base", {})
    if (pull.get("state") != "closed" or pull.get("merged") is not True
            or pull.get("merge_commit_sha") != record["source_sha"]
            or base.get("ref") != LINES[record["line"]][1]
            or base.get("repo", {}).get("full_name") != OWNER):
        raise ValueError("Release intent must name the exact same-owner merged source PR")
    response = repository.request(f"contents/composer.json?ref={record['source_sha']}")
    if response.get("encoding") != "base64" or response.get("size", 65537) > 65536:
        raise ValueError("Source Composer metadata could not be verified")
    manifest = json.loads(base64.b64decode(response["content"].replace("\n", ""), validate=True))
    if (manifest.get("name") != OWNER
            or manifest.get("require", {}).get("filament/filament") != LINES[record["line"]][2]):
        raise ValueError("Approved source package does not match its supported line")


def resolve(environment, control, repository):
    if environment.get("GITHUB_ACTIONS") != "true" or environment.get("GITHUB_REPOSITORY") != OWNER:
        raise ValueError("Only the supported owning Actions context can resolve release intent")
    event = environment.get("GITHUB_EVENT_NAME")
    ref = environment.get("GITHUB_REF")
    allowed_refs = {"refs/heads/main", "refs/heads/maintenance/filament-3"}
    if not (event == "pull_request" and environment.get("GITHUB_BASE_REF") in {"main", "maintenance/filament-3"}
            or event == "push" and ref in allowed_refs
            or event == "workflow_dispatch" and ref == "refs/heads/main"):
        raise ValueError("Unsupported release-intent event or control ref")
    head = git(control, "rev-parse", "HEAD")
    if not exact_sha(head) or head != environment.get("GITHUB_SHA") or git(control, "status", "--porcelain"):
        raise ValueError("Release intent must come from the exact clean control checkout")
    records = read_intents(control)
    version = environment.get("RELEASE_VERSION", "")
    if version:
        records = [record for record in records if record["version"] == version]
        if len(records) != 1:
            raise ValueError("Requested version has no protected owner release intent")
    for record in records:
        verify_source_authority(repository, record)
    return records


def execute(environment):
    control = checkout_directory(environment, "CONTROL_DIRECTORY")
    repository = GitHubRepository(OWNER)
    records = resolve(environment, control, repository)
    mode = environment.get("RELEASE_MODE")
    if mode == "resolve":
        result = {"matrix": json.dumps({"include": records}, separators=(",", ":")),
                  "has_intents": "true" if records else "false",
                  "source_sha": records[0]["source_sha"] if len(records) == 1 else ""}
        with open(environment["GITHUB_OUTPUT"], "a", encoding="utf-8") as output:
            for key, value in result.items():
                output.write(f"{key}={value}\n")
        return result
    if mode != "publish" or len(records) != 1 or not environment.get("RELEASE_VERSION"):
        raise ValueError("Publication requires one explicit approved version")
    source = checkout_directory(environment, "SOURCE_DIRECTORY")
    record = records[0]
    control_head = git(control, "rev-parse", "HEAD")
    validate_publish_context(environment, control_head, git(control, "status", "--porcelain"),
                             git(source, "rev-parse", "HEAD"), git(source, "status", "--porcelain"), record)
    # GITHUB_TOKEN cannot publish an off-main workflow change. Compare entire
    # workflow trees before any tag/write, not a truncated API file listing.
    if git(control, "rev-parse", "HEAD:.github/workflows") != git(source, "rev-parse", "HEAD:.github/workflows"):
        raise ValueError("Payload workflow bytes differ from approved default main")
    result = publish(repository, record["version"], record["source_sha"], control_sha=control_head)
    return {"version": record["version"], "source_sha": record["source_sha"],
            "control_sha": control_head, "release_id": result["id"],
            "platform_immutable": result.get("immutable") is True}


if __name__ == "__main__":
    print(json.dumps(execute(os.environ), sort_keys=True))
