# Release verification: 2.0.0

Date: 2026-11-21

## Automated checks

- WordPress Plugin Check 2.2.0: all 36 available checks, with runtime support, experimental flag, and low-severity results enabled. Scanned the production package with the plugin active on an isolated WordPress 7.1.4 / PHP 8.4 installation. No checks or finding codes were excluded.
- 108 integration assertions passed with actual Etch 1.8.3 active: connections 30, map options 12, settings transfer 14, statuses 28, status colors 24. The same suites also passed with the isolated Etch-availability fixture.
- 37 additional security assertions passed: anonymous/subscriber route denial, denied mutations, invalid query input, taxonomy parent cycles, and WordPress REST cookie nonce handling.
- GitHub updater smoke test passed, including escaped release notes and rejecting invalid release assets/tags.
- Production PHP and JavaScript syntax, CSS parsing, and ZIP structure/integrity passed.
- npm dependency audit of the Web Awesome 3.15.0 build tree reported zero known vulnerabilities. This includes build dependencies and is not a guarantee against undiscovered defects.

## Plugin Check findings retained for review

The complete raw report contains one ERROR and 11 WARNING results. This is not a zero-finding Plugin Check result.

The ERROR is plugin_updater_detected. The plugin deliberately uses an Update URI and a GitHub Releases updater. That is unsuitable for WordPress.org hosting, but required for this existing GitHub distribution. This release does not claim WordPress.org directory approval.

The 11 warnings are in includes/connections.php:

- Three DirectQuery and three NoCaching warnings concern cursor-based batches of at most 50 posts, 100 graph records, or 100 term records. These fresh reads avoid stale inventory while content and index batches change. Result metadata is read through WordPress APIs and permission-filtered before returning it.
- Three InterpolatedNotPrepared warnings identify generated IN-list placeholder strings. The interpolated strings contain only repeated literal %s tokens. Every content-type/taxonomy value and cursor is separately bound through $wpdb->prepare; request values are not interpolated into SQL.
- Two slow metadata query warnings concern reverse-link/usage lookup by the plugin's fixed metadata keys and an integer item ID. Results are bounded to 501 for the displayed 500 limit, with a truncation notice. These queries may still be expensive on very large postmeta tables. No large-site load benchmark was performed.

Manual review also checked per-record capability gates, administrator-only settings transfer, REST argument validation, safe updater URLs, HTML escaping/textContent, no execution of indexed content, local dependency notices, and uninstall cleanup. The missing PHP translation domain was corrected, map translation branches/plurals were made extractable, and uninstall now clears the updater cache.

## Browser coverage and limits

Version 2.0 was smoke-checked on the authorized localhost test site with Etch 1.8.3 and WordPress 7.2-beta5 at 1280 x 720. Checks cover List opening, pagination visibility, absence of whole-window List overflow, current-item details/edit actions, Site graph rendering/search, and return to List. This is a desktop smoke check, not a full cross-browser/mobile certification. The stable WordPress 7.1.4 checks above cover backend integration, not a second full browser matrix.

Map scope is documented in README.md. Dynamic URLs, runtime query results, unsaved changes, classic menu membership, theme-file-only resources, and arbitrary third-party field relationships are outside the index. Site maps show public content types; List and item maps provide access to other supported editable content. Large item-map limits are visible in the UI.

## Release contents

The folder and updater identity remain etch-content-hub-sort. The name is Content Explorer for Etch, version 2.0.0. The installable archive includes production PHP, local assets, README.md, readme.txt, CHANGELOG.md, LICENSE.txt, NOTICE.txt, and uninstall.php. Test fixtures, development files, marketing copy, and this report stay in the source repository rather than the plugin ZIP. RELEASE-NOTES.md supplies consolidated GitHub release notes; individual beta notes are omitted.
