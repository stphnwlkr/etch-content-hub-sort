# Content Explorer for Etch

Content Explorer for Etch gives you a dedicated workspace for finding, managing, and understanding WordPress content inside Etch. It replaces the earlier Etch Content Hub Sort overlay with its own panel, a searchable List, and visual maps of saved content and shared resources.

## Open Content Explorer

Click the compass in Etch's upper sidebar. List always opens first. Expand Map to choose Site or Current item. Settings is an accordion, closed initially. Click the active sidebar button again to close its view.

A separate connections icon opens the document currently active in Etch directly in its item map. Back to list returns to your existing list state. Both sidebar controls inherit Etch's icon sizing, colors, and active styling.

Drag the left menu divider to resize it between 160px and 300px, or collapse the menu to a narrow rail. The divider also supports arrow keys, Home, and End. Menu width, collapsed state, and column visibility are saved per user in the current browser. General > Sidebar can hide Etch's native Content Hub shortcut.

## Find and manage content in List

Choose a content type or taxonomy, then search, sort, and page through its records. Sorting and search cover the full result set. Hierarchical types support expandable parent/child rows, Open all / Close all, and additional child batches. Search shows matches at every depth in a flat list and identifies their parents.

Quick edit and Connections sit in a dedicated column immediately after Title, so long titles do not displace those actions. The Columns control can hide or show ID, Slug, Status, Created, and Last Edited. All five start enabled. Pagination remains visible while the table scrolls vertically or horizontally.

For posts and custom content types, New item supports Draft or Published status and an optional parent. Quick edit changes title, slug, site-local date, status, and parent where supported. A future publication date uses WordPress scheduling. The status picker supports Published, Scheduled, Private, Pending Review, Draft, and registered workflow statuses allowed for the current user, including WordPress's quick_edit_statuses filter.

Edit with Etch is available for supported documents with builder access. Edit with WP opens the WordPress editor, including its custom fields. Trash asks for confirmation and uses WordPress Trash. WordPress capabilities control which records and actions are available.

Taxonomy lists support search, sorting, pagination, hierarchy, creation, and quick editing of name, slug, description, and parent. Term rows link to the WordPress term editor. WordPress has no Trash for terms: deleting a term is permanent after confirmation, and its children move up one level. Taxonomies have their own initial sort preferences.

## Explore an item's connections

An item map places the chosen record in the center, with incoming links, outgoing links, and shared resources around it. Templates, template parts, patterns, Etch components, and saved navigation references appear where the saved content provides those relationships. The index also includes Etch's resolved saved template and reverse shared-resource usage.

Saved selections from ACF Relationship and Post Object fields, Meta Box Post fields, and MB Relationships appear under **Incoming relationships** when another item references the selected item, and under **Outgoing relationships** when the selected item references another item. Select a card to see the field or relationship name. The first item map on a site with supported fields indexes existing selections in batches so reverse connections appear without resaving each post.

Large connection groups show five cards at a time, with independent search, paging, and full totals. Search checks every loaded item in that group.

Select a card to inspect its content type, path, status, and available actions. View connections opens the selected card's own map; Previous item returns through the maps you have explored. Edit with Etch, Edit with WP, and View on site appear only where supported and permitted. Internal resources identify that they have no public URL. Shared-resource maps show which items use that resource.

The current editor document and the selected map item are distinct. Exploring a different record does not switch the document open in Etch. The separate Current item shortcut always resolves the active editor document when clicked.

## Explore the Site map

The Site graph groups public content types and entries with their taxonomies and terms. It shows content links, shared usage between visible entries, type membership, term assignments, and hierarchy relationships. Nonpublic content types and internal shared resources remain available through List and item maps.

The graph fits on opening. Drag nodes, pan and zoom the graph, use Fit to recenter it, and use its filters and display controls to focus the view. Search hides connection lines unrelated to matching items. The X beside Find clears the search while keeping the map open.

Select a Site entry and choose View connections to inspect that record in the Selected item map. Switch between Site and Selected item without changing the editor document. Details sit in a separate rounded panel beside the graph.

## Settings and transfer

Open **Settings > Editors** to choose **Etch and WordPress**, **WordPress only**, or **Etch only** for each post type. Each row shows the active choice in List, Current item maps, and the Site map. Post types that support Etch start with both editors. Other post types show WordPress only. Saved patterns, Etch components, templates, and template parts keep both editor shortcuts when available. WordPress permissions still apply.

**General > Appearance > Text size** adjusts Content Explorer text from 100% to 200% and remembers the choice in the current browser.

Settings includes initial post and taxonomy sorting, action placement, button appearance, active sort heading colors, hierarchy styling, and status colors. Status groups have separate background and text controls, previews, and reset actions. Text colors must meet 4.5:1 contrast. Custom hierarchy borders and carets must meet 3:1 contrast against their rows.

Settings transfer exports the saved site preferences, including editor choices, to JSON and imports them on another site. Imports validate the full document before replacing settings, including colors and supported values. Only administrators who can manage site options can transfer settings. Export a backup before replacing a destination's preferences, and reload Etch after importing.

Exports do not include content, credentials, site URLs, update caches, or browser-local menu and column preferences. Older exports remain compatible.

## Map scope and limits

Maps read saved WordPress and Etch block content without executing blocks, loops, or shortcodes. They resolve literal local links, saved shared references, and supported template assignments. Dynamic URLs, automatically queried related posts, unsaved editor changes, classic menu membership, and theme-file-only resources are outside the map's scope.

To integrate another source of saved post relationships, use the `etch_hub_sort_post_relationships` filter. It receives an array and the source `WP_Post`. Return target post IDs as array keys with an array of field or relationship labels per ID. The callback runs during indexing as well as map requests, so read saved values and keep lookups scoped to the source post. Use `Etch_Hub_Relationships::invalidate()` when changing relationships outside normal post or post-meta saves so reverse connections are rebuilt.

Indexing and Site graph loading use batches. Saving content updates its relationships; template, permalink, and identity changes trigger reindexing. Earlier map indexes migrate automatically. Item maps show a notice when they reach their 500 incoming-relationship or 100 nested-resource processing limit. Only editable records and permitted relationships are returned to the current user.

## Install and update

Requires WordPress 7.1+, PHP 8.3+, and an active Etch installation. Tested with WordPress 7.2 RC2. Upload the release ZIP through Plugins > Add New > Upload Plugin, then activate it. The builder integration uses Etch's current interface and may need a compatibility update when Etch changes it.

The plugin's display name is Content Explorer for Etch. Its directory, text domain, REST namespace, and GitHub update identity remain `etch-content-hub-sort`. Install the update over the existing plugin rather than renaming its directory. Existing site settings remain in place.

From 1.7.6 onward, WordPress checks published GitHub Releases for updates. Releases include an installable `etch-content-hub-sort-X.Y.Z.zip` containing one `etch-content-hub-sort` directory. Sites on 1.7.5 or earlier need one manual upgrade before these checks work. WordPress's per-plugin automatic update option remains optional.

## Changes in 2.0

See [CHANGELOG.md](CHANGELOG.md) for the consolidated release notes and [readme.txt](readme.txt) for the WordPress changelog. Beta-by-beta development notes are omitted.

The old choice between the native Etch table and the plugin browse view no longer controls this panel. List is always the default. The former option to hide the plugin's shortcut no longer applies. Older exports still accept these retired preferences for compatibility.

## Licensing

The plugin is GPLv3-or-later. Map rendering and styles adapt Delta 0.5.0 by 21Press under GPLv2-or-later. UI icons use Lucide with retained ISC/MIT notices; the compass rose is original GPLv3-or-later artwork. The WordPress icon comes from Dashicons. Web Awesome Core 3.15.0 and its dependency notices ship locally with the accordion, without a remote kit or subscription requirement. See [NOTICE.txt](NOTICE.txt), assets/icon-sources.json, and assets/vendor/webawesome for attribution and build details.

## Development

Keep the plugin header, asset version, and readme stable tag in sync. PHP integration suites in tests run on an isolated WordPress installation and clean up their fixtures. The updater smoke test runs without WordPress. Release verification includes Plugin Check, permission tests, PHP/JavaScript syntax, CSS parsing, archive validation, and a builder smoke check.

Push a matching vX.Y.Z tag only when publication is authorized. GitHub Actions packages the committed production files and publishes the installable ZIP with the consolidated release notes. Browser-specific styling and future Etch changes still require visual checks on their target versions.
