# Protected package publication

1. Verify the feature branch using every command in `CONTRIBUTING.md`, including
   release-policy tests. Open a pull request to protected `main`. Merge only after
   the exact revision's required `CI green` check succeeds; do not push to main,
   bypass protection, or create a workstation tag/release.
2. Wait for the merged main revision's CI. Dispatch **Publish package** on `main`
   with that full 40-character SHA and a new canonical stable version such as
   `v0.1.4`. The workflow reruns the canonical PHP 8.2/8.3/8.4 matrix and publication
   policy before granting only its publication job `contents: write`. Dispatching
   a different branch/repository/event or an outdated SHA cannot publish.
3. The runner checks a clean exact checkout and current main, creates a tag only
   when absent, and verifies its dereferenced commit before publishing. It never
   deletes, moves or overwrites a tag or release. An uncertain response is a failed
   run, not permission for a second blind write. Inspect the exact tag and release;
   rerunning the same source/version reconciles an exact partial/completed result.
   Conflicting state or advanced main requires an explicit release decision, not
   rewriting history. Publication does not cancel an in-flight publication.
4. Record the terminal run, full source SHA, dereferenced tag, release ID/state and
   archive digest. Download the release's normal source archive and compare its
   `composer.json` and runtime files with the tested commit. A successful job is
   tag/release proof, not archive or consumer-install proof. Do not add a hard-coded
   Composer version or replace immutable consumer references with a branch.
5. Before updating consumers, verify the new version is available in the existing
   Packagist `p2/tropikal-ai/connect.json` channel with matching source **and** dist
   references. Run normal Composer resolution/install and the consuming owner's
   complete checks. A GitHub VCS override in CI is not evidence of Packagist
   visibility; a synthetic local prerelease is not a published stable release.
   If indexing is delayed, wait and inspect the existing owner process—do not add
   credentials/hooks/settings or silently substitute a different repository.

The helper reports observed platform immutability separately. Exact commit pins
and this no-retag policy do not imply GitHub reports `immutable: true`. No new
downstream workflow is assumed to trigger from the workflow token's tag/release.
The release script uses the runner's existing ephemeral token only; never run it
on a workstation with forged Actions environment variables or production secrets.

## Immutable adapter delivery interface

The root composite action is a delivery interface, not a Composer/runtime API.
Filament callers pin it and the canonical PHP workflow to an exact reviewed Core
commit. It supports only `tropikal-ai/connect-filament` and its two existing lines.
Never copy the helper into a consumer or execute a candidate's release script in
the write job. The action executes its own pinned `github.action_path` tooling.

The owner supplies `.github/release-intents.json` in a protected-main PR:

```json
{"schema":1,"releases":[{"version":"v0.1.15","line":"filament-3","source_sha":"FULL_SAME_OWNER_MERGED_COMMIT","source_pr":45}]}
```

The literal example SHA is intentionally invalid. Each actual record requires a
full lowercase commit SHA, a merged same-owner source PR, and the corresponding
`filament-3` / `v0.1.*` / `maintenance/filament-3` / `^3.2` or
`filament-5` / `v0.2.*` / `main` / `^5.0` package. At most one pending release per
line is supported. Empty records permit initial workflow-only preparation, not
publication. Duplicate JSON keys, unsupported fields/lines/versions and guessed
branch references fail closed.

Owner CI checks out its event into `control`, calls `mode: resolve`, and runs the
canonical PHP matrix on **every** returned `source_sha` using `checkout-ref`.
An empty intent list may skip only the payload matrix; the owner's normal matrix
and resolver must still succeed. A populated list requires the payload matrix to
succeed, not be skipped/cancelled. This makes the protected-main PR approve and
test the exact maintenance payload; it does **not** protect the maintenance branch.

The owning dispatcher runs only on protected main, resolves one intended version,
and reruns that exact payload's PHP matrix read-only. Its final, serialized,
non-cancelling write job checks out event main into `control` and the tested source
into `source`, with checkout credentials disabled. It calls `mode: publish`, passes
the actual resolver/quality job results, selected `verified-source-sha`, intended
`expected-control-sha` and version, and supplies only the runner token as GH_TOKEN.
It must not run Composer, candidate tests or candidate scripts in that job.

The action rereads the exact clean control intent, verifies source PR/package line
and both checkout SHAs, rejects dirty/mismatched sources, compares complete workflow
Git trees, and rechecks current protected main before publishing the exact source.
Main/control SHA and payload SHA are intentionally distinct. The selected quality
SHA must equal the approved payload, not merely the main revision. Matching workflow
bytes are necessary for off-main publication with GITHUB_TOKEN; do not use extra
credentials when that API policy rejects a release. Existing no-overwrite,
uncertain-result reconciliation and tag/release readback mechanics are shared with
Core's unchanged main-only release path.

After an owner workflow or source intent changes, rerun its required CI and the
same targeted safety review before dispatch. Archive/channel/normal-install proof
remains mandatory after publication, exactly as for Core itself.
