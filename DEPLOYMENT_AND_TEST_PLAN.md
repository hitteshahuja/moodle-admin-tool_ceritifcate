# Deployment & Test Pack

**Plugin:** `tool_certificate` (Certificate manager), plus one file in `mod_coursecertificate` (Course certificate activity)
**Change:** Revoked certificates are no longer downloadable
**Plugin version:** `2026071300` → `2026071302` (`admin/tool/certificate/version.php`)
**Database change:** None — no new tables, fields, or upgrade step
**New settings / capabilities:** None
**Related docs:** See `changes.txt` in this folder, section 4, for the full technical diff-by-diff description.

---

## 1. What this change does

Certificates in this site can be **revoked** by a manager/teacher (e.g. if issued in error, or a qualification is withdrawn). Before this change, revoking a certificate marked it as revoked in the certificate list, but the actual PDF file could still be produced and downloaded again — via:

- the direct "view certificate" link (`view.php?code=...`),
- the mobile app's certificate view,
- the admin "Regenerate file" button, which worked even on a revoked certificate.

This meant a revoked certificate's PDF could keep circulating even after revocation, which defeats the purpose of revoking it.

**After this change:**

- A revoked certificate's PDF is never regenerated or served, from any of the above paths.
- The certificate **issue record itself is kept** — it still shows up in reports and in the learner's "My certificates" list, with a status of "Revoked". Only the PDF download is blocked.
- The admin issues report hides the "View" and "Regenerate file" buttons for revoked rows (the "Revoke" button was already hidden once a certificate is revoked).
- The learner's "My certificates" page shows a "Revoked" badge in place of the download icon for that row.

No visible or invisible data is deleted by this change beyond what revoking already deleted (the stored PDF file). No user-facing settings changed. No new capabilities were introduced, so no permissions/roles need to be reviewed.

**Also bundled into this same deploy:** an unrelated, unused "Expire" row action (and its web service `tool_certificate_expire_issue`) has been removed from the admin issues report. It was custom code, never part of upstream, and had no callers relying on it — it simply no longer appears in the row actions.

---

## 2. Files changed

Repository: `admin/tool/certificate` (submodule)
- `classes/template.php`
- `lib.php`
- `view.php`
- `classes/external/issues.php`
- `classes/reportbuilder/local/systemreports/issues.php`
- `classes/certificate.php`
- `classes/my_certificates_table.php`
- `version.php` (version bump only, no schema change)
- `tests/template_test.php`, `tests/external_test.php`, `tests/my_certificates_table_test.php` (new/updated automated tests)

Repository: `mod/coursecertificate`
- `classes/output/mobile.php` (one-line defensive guard so the mobile app doesn't try to fetch a file for a revoked certificate)

Nothing else in the codebase was touched for this change.

---

## 3. Deployment steps

This is a standard code-only plugin update — no data migration, no manual DB steps.

1. Take the usual pre-deployment backup/snapshot (DB + moodledata) per your standard change process.
2. Deploy the updated code for `admin/tool/certificate` and `mod/coursecertificate` to the target environment (git pull / submodule update / however code is normally shipped to this site).
3. Put the site into maintenance mode (recommended, as with any plugin update):
   ```
   php admin/cli/maintenance.php --enable
   ```
4. Run the Moodle upgrade to register the new plugin version and clear caches:
   ```
   php admin/cli/upgrade.php --non-interactive
   ```
   You should see `tool_certificate` version change from `2026071300` to `2026071302`. There is **no new database table or field** — the upgrade step exists purely to bump the version and purge caches.
5. Purge all caches (the upgrade step above does this, but if deploying without running upgrade.php for any reason, run explicitly):
   ```
   php admin/cli/purge_caches.php
   ```
6. Disable maintenance mode:
   ```
   php admin/cli/maintenance.php --disable
   ```
7. Run the smoke test in section 4, then the full QA checklist in section 5.

### Rollback

If an issue is found, this can be rolled back like any other code-only deploy: redeploy the previous code revision for both repositories and re-run `admin/cli/upgrade.php --non-interactive` (this will not need to reverse any schema change, since none was made). No data restore should be necessary since no certificate data or files are deleted by the fix itself beyond what revoking already removes today.

---

## 4. Quick smoke test (5 minutes)

Do this once, right after deployment, before running the full checklist:

1. Log in as an admin/manager. Go to **Site administration → Certificates** (or a course's certificate template) → **Manage issued certificates**.
2. Issue a certificate to a test user, or pick an existing non-revoked one.
3. Confirm the "View" (magnifying glass) icon opens the certificate PDF as before.
4. Click "Revoke" on that same row and confirm.
5. Confirm the row now shows status "Revoked", and the "View" and "Regenerate file" icons are **gone** from that row.
6. Log in (or "Log in as") the certificate holder, go to **Profile → Preferences → My certificates** (or `/admin/tool/certificate/my.php`), and confirm that row now shows a "Revoked" badge instead of a download icon.

If all 6 steps behave as described, the deployment is working as intended. Continue with the full checklist below before signing off.

---

## 5. Full QA test plan

### 5.1 Revoke blocks the PDF everywhere (core fix)

| # | Steps | Expected result |
|---|-------|------------------|
| 1 | As a manager, issue a certificate to a test user. Open the certificate's direct view link (the code link, e.g. `/admin/tool/certificate/view.php?code=XXXX`). | PDF opens/downloads normally. |
| 2 | Revoke that certificate from the issues report. | Row status changes to "Revoked". No error. |
| 3 | Re-open the **same** direct view link from step 1 (reuse the bookmarked/saved URL). | A clear "This certificate has been revoked and is no longer valid" message is shown. **No PDF is downloaded or regenerated.** |
| 4 | As admin, in the issues report, confirm the "View" and "Regenerate file" icons no longer appear on the revoked row. | Icons are hidden — there is nothing to click that could bring the PDF back. |
| 5 | As the certificate holder, go to "My certificates". Confirm the row for this certificate is still listed (issue not deleted) with a "Revoked" badge and no download link. | Entry remains visible; no working download link. |
| 6 | If using **mod_coursecertificate** (course certificate activity) with the Moodle mobile app: open the course certificate activity in the app for a user whose certificate has been revoked. | No download/file link is shown for the revoked certificate in the mobile view. |

### 5.2 Regression check — non-revoked certificates still work

| # | Steps | Expected result |
|---|-------|------------------|
| 1 | Issue a fresh certificate, do **not** revoke it. Download it from "My certificates" and from the admin issues report "View" action. | PDF downloads correctly in both places, as before this change. |
| 2 | As admin, use "Regenerate file" on a **non-revoked** issue. | File regenerates successfully, exactly as before this change (this action is unaffected for active certificates). |
| 3 | Verify the certificate verification page (`index.php?code=...`, public verify screen) still works for both a valid and a revoked certificate. | Valid certificate verifies successfully; revoked certificate shows "This certificate has been revoked and is no longer valid" (this message already existed before this change and is unaffected). |
| 4 | If your site uses **mod_coursecertificate** for automatic course-completion certificates, complete a course and confirm the certificate is still issued and downloadable as normal. | Certificate issues and downloads normally — this flow is unrelated to the revoke fix. |

### 5.3 Technical / API checks (optional, for technical QA)

| # | Steps | Expected result |
|---|-------|------------------|
| 1 | Call the `tool_certificate_regenerate_issue_file` web service (or the equivalent AJAX call the admin UI makes) directly with the ID of a **revoked** issue. | Call fails with an error (a "certificate revoked" exception) — no file is created. |
| 2 | Request the pluginfile URL for a revoked issue directly (e.g. `.../pluginfile.php/1/tool_certificate/issues/<code>.pdf`) even if you have a valid old link cached. | Returns a "file not found" (404-equivalent) response, not a PDF. |

### 5.4 Automated tests (if your process runs PHPUnit for plugin updates)

The following test files cover this change and all passed in the development environment prior to handover:

```
admin/tool/certificate/tests/template_test.php
admin/tool/certificate/tests/external_test.php
admin/tool/certificate/tests/my_certificates_table_test.php
admin/tool/certificate/tests/certificate_test.php
mod/coursecertificate/tests/helper_test.php
```

If PHPUnit is set up in the target environment:
```
php admin/tool/phpunit/cli/init.php   # only if not already initialised
vendor/bin/phpunit --configuration phpunit.xml admin/tool/certificate/tests/template_test.php
vendor/bin/phpunit --configuration phpunit.xml admin/tool/certificate/tests/external_test.php
vendor/bin/phpunit --configuration phpunit.xml admin/tool/certificate/tests/my_certificates_table_test.php
vendor/bin/phpunit --configuration phpunit.xml admin/tool/certificate/tests/certificate_test.php
vendor/bin/phpunit --configuration phpunit.xml mod/coursecertificate/tests/helper_test.php
```
All should report `OK` with no failures.

---

## 6. Sign-off checklist

- [ ] Code deployed for `admin/tool/certificate` and `mod/coursecertificate`
- [ ] `admin/cli/upgrade.php --non-interactive` run, plugin version shows `2026071302`
- [ ] Admin issues report no longer shows an "Expire" row action anywhere (feature removed)
- [ ] Caches purged
- [ ] Smoke test (section 4) passed
- [ ] Full QA checklist (section 5.1–5.3) passed
- [ ] Automated tests (section 5.4) passed, if applicable to this environment
