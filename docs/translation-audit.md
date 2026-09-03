# Piwigo Translation System — Audit Report

Scope: 72 locale directories under `language/`, up to 7 domains each (admin, common, install, upgrade, help_quick_search, whats_new_15, whats_new_16), plus translation-call-site coverage across `themes/admin/default/{template,js}`, `themes/default/{template,js}`, `src/Piwigo/Admin`, `src/Piwigo/{Mail,Notification,Menu,Session,Users,Auth}`, and the installer.

## Executive Summary

29 `.po` files across 23 locales fail `msgfmt --check-format` outright (malformed `\n` framing on 27 files, plus Portuguese Latin‑1 byte corruption in `pt_PT/common.po`). A deeper `msgfmt -c` pass — not caught by `--check-format` — surfaces the single largest systemic defect: **37 files across 19 locales** declare a linguistically-correct multi-form `Plural-Forms` header (nplurals=3/4/5/6) but every plural entry supplies only 2 `msgstr[]` forms, so all 37 files are fatal to compile. Beyond mechanical failures, 79 additional catalog-level defects were confirmed (a corrupted 9,600-space msgid in `af_ZA/admin.po`, a blank `"\n"`-only translation in `bg_BG/admin.po`, and ~50 stale/orphaned-msgid clusters spread across roughly 40 locales tracing to three specific upstream template changes never re-synced). Two locales (`te_IN`, `ar_MA`) have zero `.po` files at all despite scaffolded directories. Source-code review found 47 confirmed defects where user-facing text bypasses translation entirely or calls a msgid with no catalog entry, spanning admin templates, public templates, admin/public JS, admin PHP, the installer, and mail/notification/auth code — several reach ordinary (non-admin) visitors or real outbound email. The `en_UK` reference catalog itself carries 20 source-string quality issues (typos, grammar, punctuation) that propagate to every locale falling back to it. The pre-existing `.lang.php`↔`.po` parity tool checked 0 pairs — legitimately, since no `.lang.php` sources exist anywhere in this tree.

---

## 1. Prioritized Action List

### 1.1 Critical — catalog corruption (blocks compilation / silently blanks a string)

| File | Issue |
|---|---|
| `language/af_ZA/admin.po` | Line 1583: msgid corrupted into ~9,604 literal space characters; real msgstr ("Geen foto gekies...") is orphaned from any usable key. |
| `language/bg_BG/admin.po` | Line 3182: msgstr for a real, live string ("This picture is physically linked...") is literally just `"\n"` — an effectively blank translation. |
| `language/pt_PT/common.po` | Raw Latin‑1 bytes despite `charset=UTF-8` header; 7 `msgfmt` fatal "invalid multibyte sequence" errors at 78:37, 84:15, 84:16, 84:27, 84:28, 102:27, 111:26. |

### 1.2 High — systemic Plural-Forms/msgstr[] mismatch (37 files, 19 locales)

Header declares N plural forms; every `msgid_plural` block supplies only 2 `msgstr[]` forms. Fatal to `msgfmt -c` in all cases. Root cause looks like one shared import/tooling gap, not independent mistakes.

| Locale | Files | Declared nplurals |
|---|---|---|
| ar_EG | common.po | 6 |
| ar_SA | admin.po, common.po | 6 |
| br_FR | admin.po, common.po | 5 |
| ca_ES | admin.po, common.po | 3 |
| cs_CZ | admin.po, common.po | 3 |
| es_AR | common.po | 3 |
| es_ES | admin.po, common.po | 3 |
| es_MX | admin.po, common.po | 3 |
| fr_CA | admin.po, common.po | 3 |
| fr_FR | admin.po, common.po | 3 |
| pl_PL | admin.po, common.po | 3 |
| pt_BR | admin.po, common.po | 3 |
| pt_PT | admin.po, common.po | 3 |
| ro_RO | admin.po, common.po | 3 |
| ru_RU | admin.po, common.po | 3 |
| sh_RS | admin.po, common.po | 3 |
| sk_SK | admin.po, common.po | 3 |
| sl_SI | admin.po, common.po | 4 |
| sr_RS | admin.po, common.po | 3 |

Note: `msgfmt --check-format` (section 2) does **not** detect this class — it only surfaces via `msgfmt -c`. Treat the mechanical-check list and this list as complementary, not overlapping.

### 1.3 High — untranslated/bypassed strings reaching real users (source code)

| File | Issue |
|---|---|
| `themes/admin/default/template/layout.latte` | Theme-toggle labels "Dark"/"Light" hardcoded plain text on every admin page (lines 213-217); no `\|translate`. |
| `themes/default/template/identification.latte` (+`register.latte:119`) | msgid "Or sign in with" has zero `.po` entries anywhere in the repo; renders English in every locale, gated only on OAuth plugins being present (not admin). |
| `themes/default/template/include/search_filters.inc.latte` | Lowercase msgids "height"/"width" (filter titles, lines 1031/1080) absent from every `.po` file; public-facing. |
| `src/Piwigo/Admin/Maintenance/MaintenanceActionDispatcher.php` | 'empty_lounge' success message (line 196) bypasses `$this->lang->t()` entirely, unlike every sibling case in the same switch. |
| `src/Piwigo/Admin/Extensions/ExtensionLifecycle.php` | 6 all-caps theme/language lifecycle guard messages (lines 405, 455, 466, 470, 479, 483) hardcoded, unlike sibling entries in the same arrays. |
| `src/Piwigo/Admin/ThemesStandardPagesPageRenderer.php` | Logo-upload "Invalid image file." (line 125) hardcoded, no matching msgid anywhere; sibling error branches correctly translated. |
| `src/Piwigo/Admin/PictureModifyPageRenderer.php` | 'Posted the %s' (line 353) and 'Formats: %s' (line 348) passed to `l10n()` but neither msgid exists in any `.po` file. |
| `themes/default/js/vendor/dataTable.ts` | Entire native DataTables port (Search/Show/entries/Previous/Next/info line, lines 168-290) hardcoded English; powers the User Ratings admin table; no translation hook exists in `DataTableOptions`. |
| `themes/default/js/vendor/uploadQueue.ts` | Direct-upload drop-zone text and file-validation alerts/errors hardcoded (lines 297, 300, 490, 498, 566), on a real, frequently-used admin upload flow. |
| `src/Piwigo/Users/UserService.php` | `checkAndSaveUserInfos()` returns 8 hardcoded validation/permission error strings (lines 1229, 1244, 1293, 1307, 1311, 1346, 1354, 1362) surfaced verbatim in a real JSON API "detail" field, while sibling checks in the same method correctly translate. |
| `themes/admin/default/template/install.latte` | Database-config field labels/overwrite-confirmation warning (lines 92-211) use `\|translate` but the 6 msgids were never added to any catalog the installer loads. |
| `src/Piwigo/Admin/Install/InstallEnvironmentChecker.php` | Filesystem-permissions checklist heading + 5 directory labels translated in the template but missing from every loaded `.po`. |

### 1.4 Medium — stray literal `\n` in msgstr (msgfmt-fatal)

Full raw list with line numbers is in section 2 (mechanical check output); affects: `de_DE/admin.po`, `et_EE/admin.po` (×2), `eu_ES/admin.po`, `fa_IR/admin.po`+`common.po` (×4), `fi_FI/admin.po`, `ca_ES/admin.po`, `ar_SA/admin.po`, `pt_BR/admin.po` (×2), `pt_PT/admin.po` (×5), `ro_RO/common.po`, `sr_RS/admin.po` (×2), `ta_IN/admin.po` (×5)+`common.po` (×2)+`upgrade.po` (×1), `nl_NL/admin.po`, `lv_LV/admin.po`, `hu_HU/admin.po`, `th_TH/admin.po`, `tr_TR/common.po`+`help_quick_search.po`, `ja_JP/admin.po`, `zh_HK/admin.po` (×2), `it_IT/admin.po`, `mn_MN/common.po` (×2)+`install.po`, `km_KH/install.po`, `bg_BG/admin.po` (part of its 6 fatals).

### 1.5 Medium — stale/orphaned msgids (catalog drift vs. en_UK)

Three recurring clusters account for most of these — a removed "search rules" feature (5-string set), a removed admin "Dump Database" feature (4-5 string set), and a reworded verification-code string — plus locale-specific one-offs.

| Locale | File | Count | Cluster |
|---|---|---|---|
| af_ZA | common.po | 5 | search-rules |
| af_ZA | admin.po | 5 | dump-database |
| ar_SA | admin.po | 4 | dump-database |
| ar_SA | common.po | 5 | search-rules |
| ar_EG | common.po | 5 | search-rules |
| bg_BG | common.po | 4 | reworded API-key/passkey UI |
| ca_ES | common.po | 1 | verification-code wording |
| cs_CZ | common.po | 1 | verification-code wording |
| da_DK | common.po | 1 | verification-code wording |
| de_DE | common.po | 1 | verification-code wording |
| dv_MV | common.po | 4 | search-rules |
| el_GR | common.po | 1 | verification-code wording |
| eo_EO | common.po | 5 | search-rules |
| eo_EO | admin.po | 5 | dump-database + stat |
| es_AR | common.po | 5 | search-rules |
| es_MX | common.po | 5 | search-rules |
| gl_ES | admin.po | 4 | dump-database |
| gl_ES | common.po | 5 | search-rules |
| hr_HR | admin.po | 4 | dump-database |
| hr_HR | common.po | 9 | search-rules + others |
| ka_GE | admin.po | 10 | dump-database + others |
| ka_GE | common.po | 9 | search-rules + others |
| id_ID | common.po | 5 | search-rules |
| he_IL | common.po | 1 | verification-code wording |
| hu_HU | common.po | 1 | verification-code wording |
| is_IS | common.po | 1 | verification-code wording |
| it_IT | common.po | 1 | verification-code wording |
| kn_IN | common.po | 5 | search-rules |
| lb_LU | common.po | 5 | search-rules |
| lt_LT | admin.po | 5 | dump-database |
| lt_LT | common.po | 5 | search-rules |
| lv_LV | common.po | 5 | search-rules |
| mk_MK | common.po | 9 | search-rules + max-dimensions |
| mn_MN | common.po | 5 | search-rules |
| nn_NO | admin.po | 4 | misc (msgcmp-verified) |
| pl_PL | common.po | 1 | verification-code wording |
| ro_RO | common.po | 1 | verification-code wording |
| ru_RU | common.po | 1 | verification-code wording |
| sh_RS | admin.po + common.po | 14 total | dump-database + search-rules + others (worst-drift locale) |
| sk_SK | common.po | 1 | verification-code wording |
| sl_SI | common.po | 5 | search-rules |
| sv_SE | common.po | 1 | verification-code wording |
| ta_IN | common.po | 5 | search-rules |
| th_TH | common.po | 5 | search-rules |
| tr_TR | common.po | 1 | verification-code wording |
| vi_VN | admin.po | 5 | dump-database |
| vi_VN | common.po | 5 | search-rules |
| zh_CN | common.po | 1 | verification-code wording |
| zh_HK | admin.po | 5 | dump-database |
| zh_HK | common.po | 5 | search-rules |
| zh_TW | common.po | 1 | verification-code wording |

Also flagged (not msgid-drift, but msgstr identical to msgid on substantial English sentences, not covered by `tools/language/translation_validated.inc.php` allowlists): `he_IL/admin.po`, `kn_IN/common.po`, `mk_MK/common.po` (×2).

Tooling note: `tools/language/translation_validated.inc.php` has **no allowlist entry at all** for `nn_NO`, `sh_RS`, or `ta_IN`, and its `sl_SI` entry is keyed under a typo (`sl_SL`), making that allowlist silently dead.

### 1.6 Medium — remaining hardcoded/missing-translation source findings

| Area | File | Count |
|---|---|---|
| admin templates | `tags.latte`, `group_list.latte`, `stats.latte`, `photos_add_direct.latte`, `user_list.latte`, `configuration_main.latte`, `updates_pwg.latte`, `cat_modify.latte`, `albums.latte`, `install.latte`, `plugins_installed.latte` | 15 findings |
| public templates | `popuphelp.latte`, `search_filters.inc.latte` (Min/Max + 7 admin-only-but-publicly-reachable msgids), `menubar_related_categories.latte`, `picture.latte` | 5 findings |
| admin/public JS | `colorbox.ts`, `jgrowl.ts`, `tags.ts`, `cat_modify.ts`, `photos_add_direct.ts` | 5 findings |
| admin PHP | `InstallEnvWriter.php`, `RatingUserPageRenderer.php`, `PhotosAddDirectPageRenderer.php` (×2), `InstallWizard.php` | 5 findings |
| install/bootstrap | `InstallWizard.php`, `InstallView.php`, `InstallService.php` | 3 findings |
| mail/notification/auth | `UserService.php` ("invalid login format" msgid missing repo-wide; duplicate-registration mail body, 3 msgids missing) | 2 findings |

### 1.7 Coverage gaps — locales with zero `.po` files

`language/te_IN/` and `language/ar_MA/` contain only `iso.txt`/flag image — no `.po` files for any domain. Cannot be content-audited; listed as coverage gaps, not scored as bugs.

---

## 2. Mechanical Check Results (`msgfmt --check-format`)

29 files / 23 locales fail. All are `\n`-framing mismatches (msgid/msgstr don't both begin or end with `\n`) except `pt_PT/common.po`, which fails on invalid multi-byte sequences (Latin‑1 bytes under a declared UTF‑8 charset).

| File | Error lines | Count |
|---|---|---|
| language/pt_PT/common.po | 78:37, 84:15, 84:16, 84:27, 84:28, 102:27, 111:26 | 7 (invalid multibyte sequence) |
| language/pt_PT/admin.po | 2849, 3986, 4049, 4127, 4130 | 5 |
| language/nl_NL/admin.po | 4217 | 1 |
| language/ca_ES/admin.po | 405 | 1 |
| language/et_EE/admin.po | 1187, 2636 | 2 |
| language/km_KH/install.po | 121 | 1 |
| language/lv_LV/admin.po | 3068 | 1 |
| language/ta_IN/common.po | 579, 1111 | 2 |
| language/ta_IN/admin.po | 13, 141, 240, 258, 477 | 6 |
| language/ta_IN/upgrade.po | 58 | 1 |
| language/tr_TR/common.po | 1093 | 1 |
| language/tr_TR/help_quick_search.po | 85 | 1 |
| language/th_TH/admin.po | 2375 | 1 |
| language/fi_FI/admin.po | 1892 | 1 |
| language/pt_BR/admin.po | 2849, 3332 | 2 |
| language/de_DE/admin.po | 3515 | 1 |
| language/fa_IR/common.po | 965, 1048, 1051 | 3 |
| language/fa_IR/admin.po | 2924 | 1 |
| language/ro_RO/common.po | 1099 | 1 |
| language/eu_ES/admin.po | 659 | 1 |
| language/sr_RS/admin.po | 3725, 3731 | 2 |
| language/hu_HU/admin.po | 2738 | 1 |
| language/bg_BG/admin.po | 2849, 3182, 3245, 3422, 3938 | 5 |
| language/ar_SA/admin.po | 2150 | 1 |
| language/ja_JP/admin.po | 2510 | 1 |
| language/zh_HK/admin.po | 2837, 2861 | 2 |
| language/mn_MN/common.po | 1123, 1126 | 2 |
| language/mn_MN/install.po | 142 | 1 |
| language/it_IT/admin.po | 2861 | 1 |

**Scope caveat:** `--check-format` does not check `Plural-Forms` header vs. body consistency; that gap was only found via a separate `msgfmt -c` pass (see section 1.2), which is why 19 locales with a real, fatal plural-forms defect do not appear in this table.

---

## 3. Parity Check (`.lang.php` ↔ `.po`)

`tools/i18n/verify-parity.php`: **0 file pairs checked, 0 errors.** Confirmed via `find language -name '*.lang.php'` → 0 results. No `.lang.php` sources exist anywhere in this tree — only generated `.po` files remain — so the tool had nothing to compare. This is the correct, authoritative output for the current repo state, not a malfunction.

---

## 4. English (en_UK) Source-String Quality Issues

20 issues found, all in the canonical reference catalog (propagate to every locale that falls back to `en_UK`).

**`language/en_UK/admin.po`** (8): "occured" misspelled (should be "occurred") in 4+ entries incl. lines 449-450, 452-453, 455-456, 4039-4040; "succesfully" misspelled in 7 entries (3043-3044, 3046-3047, 3070-3071, 3379-3380, 3385-3386, 3394-3395, 4024-4025); subject-verb agreement "This albums is" (3784-3785); subject-verb agreement "other themes depends" (1174-1175); subject-verb agreement "This plugin have no update" (3715-3716); msgid-only misspelling "Email Adress" (3226-3227); double space "never  calculated" (3658-3659); inconsistent `<b>` tag placement between msgid/msgstr on 2 plugin-count strings (3745-3746, 3748-3749).

**`language/en_UK/common.po`** (4): "occured" + "please got back to" compounded in the same live error string (1272-1273); doubled period "please try later.." (1476-1477); leftover space-before-colon "Search in :" (1134-1135, inconsistent with the file's own cleanup elsewhere); non-standard comma "Please, enter a login" (675-676, inconsistent with an identical construction fixed elsewhere at 909-910).

**`language/en_UK/help_quick_search.po`** (1): msgstr drops the word "return" present in msgid (117-118), breaking the file's own pattern of msgid==msgstr.

**`language/en_UK/install.po`** (3): "Database tables prefix" should be singular "Database table prefix" (line 61, mismatches this file's own msgid and fr_FR's msgid); "hosting provider" vs. the "host provider" wording used everywhere else (line 58); missing trailing period (111-112).

**`language/en_UK/upgrade.po`** (2): "Users and groups permissions have been erased" — ungrammatical plural-as-adjective reword of a correct msgid (51-52); "run upgrade" missing an article, inconsistent with a sibling string on the same page corrected to "run an upgrade" (45-46).

**`language/en_UK/whats_new_15.po`** (1): "features : Activities logs" — space before colon plus non-idiomatic "Activities logs" (should be "Activity logs") (15-16).

**`language/en_UK/whats_new_16.po`** (1): msgid has a stray space before "!" ("Standard pages !") that msgstr drops, an internal inconsistency (12-13).

---

## 5. Per-Locale Completeness (Appendix — informational only, not bugs)

Normal for a volunteer-translated project; domain coverage varies widely by locale. Reference domain sizes (en_UK msgid counts): admin=1402, common=504, install=46, upgrade=19, help_quick_search=51.

- **No `.po` files at all:** `ar_MA`, `te_IN`.
- **Stub-level (<20% common.po coverage), single domain only:** `az_AZ` (~8%), `bn_IN` (~8%), `wo_SN` (~4%), `ga_IE` (~10%), `gu_IN` (~12%), `kok_IN` (~17%), `dv_MV` (~18%, common-only), `es_AR` (~75% but common-only), `ms_MY` (~23%, common-only).
- **Most complete (near-100% across all present domains):** `bg_BG`, `ca_ES`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `es_ES`, `fr_FR`, `he_IL`, `is_IS`, `it_IT`, `ko_KR`, `nb_NO`, `nl_NL`, `pl_PL`, `pt_BR`, `pt_PT`, `sk_SK`, `sv_SE`, `tr_TR`, `zh_CN`, `zh_TW`.
- **Severe outliers (lowest completeness among populated locales):** `ta_IN/admin.po` 149/1402 (~11%, lowest overall); `sh_RS/admin.po` ~60%; `mk_MK/admin.po` ~0.4% (5/1402, essentially unstarted); `es_MX/admin.po` ~2% (stub despite common.po at 75%); `hy_AM/admin.po` ~26%.
- **Partial domain sets (missing help_quick_search and/or whats_new_*):** the majority of locales — full 7-domain coverage is the exception, held by `ca_ES`, `cs_CZ`, `da_DK`, `de_DE`, `es_ES`, `fr_FR`, `he_IL`, `pl_PL`, `pt_BR`, `sk_SK`, `sv_SE`, `zh_CN`, `zh_TW`, `tr_TR`.
- No `#, fuzzy` markers were found in any file across the entire 72-locale sweep. No genuine empty-msgstr gaps beyond the two corruption cases already reported in section 1.1, except `ka_GE/admin.po` ("Element", genuine empty msgstr on a real string).
