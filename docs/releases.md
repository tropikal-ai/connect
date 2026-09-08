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
