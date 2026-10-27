=== Etch Content Hub Sort ===
Contributors: stephenwalker
Tags: etch, content hub, search, sort, pagination
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.7.18
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds search, sorting, pagination, and hierarchy browsing to posts and taxonomies in the Etch Content Hub.

== Description ==

This plugin adds a browse table to Etch's Content Hub. It searches and sorts across the full result set and offers 25, 50, or 100 items per page. An Etch table button opens the original Etch view for inline editing and new item creation. A dedicated Hub search and sort panel turns the feature on or off, chooses the view used when the builder loads, chooses the initial sort column and direction, and sets the active heading's background and text colors. A view selected during a builder session stays active across content types and taxonomies until switched back or reloaded. The General section can hide its left sidebar shortcut while search and sorting is active; settings remain available in the Content Hub toolbar. Its Buttons section offers individual background and text or icon colors, plus icon only, icon and text, or text only display, for buttons in the browse table. Color pairs must meet 4.5:1 text contrast. The General section also offers four Actions layouts: both at the end (default), both at the start, Open after Title with Trash last, or Open first with Trash last.

Taxonomy terms have a browse table with Name, Slug, Description, Count, and Parent where applicable. Search, sorting, and pagination work across all terms. A separate setting chooses the initial taxonomy sort column and direction. Hierarchical taxonomies can be browsed as a tree, and flat search results identify each child's parent. New term and Quick edit let you set the name, slug, description, and parent. Delete permanently removes a term after confirmation. WordPress moves its children up one level. Terms do not have WordPress drafts or Trash.

The Settings transfer tab exports all saved plugin settings to a JSON file and imports a complete export on another site. It includes general preferences, sorting defaults, button appearance, hierarchy styling, and status colors. Import replaces the destination site's plugin settings after validating every setting, including color contrast. Export a backup first if needed, then choose the file, confirm replacement, and select Import settings. Reload the builder after importing. Only administrators who can manage site options can transfer settings. Content, credentials, site URLs, and update caches are not included.

The Status colors section has its own settings tab. Set background and text colors separately for Published, Draft, Scheduled, Pending Review, and Private, plus one shared pair for all third-party statuses. The existing colors are the defaults. Each group has a preview and a Use default colors action; click Save to apply changes. Text colors must meet 4.5:1 contrast. Saved colors apply to badges and the status picker.

The Hierarchy section can customize parent and child row backgrounds, text, left borders, carets, and border width. Existing colors remain until custom hierarchy styling is enabled. Row text must meet 4.5:1 contrast, and borders and carets must meet 3:1 contrast against their row backgrounds.

The browse table also has a New item button with Draft or Published status. For hierarchical content types, choose a parent while creating an item. Each existing item has Quick edit for changing its title, slug, date, status, and, for hierarchical types, its parent. If both title and slug were blank, entering a title suggests a slug; WordPress generates the saved slug unless the user edits that suggestion. Date and time use the site's timezone; WordPress schedules published items with future dates. Quick edit offers Published, Scheduled, Private, Pending Review, Draft, and registered custom workflow statuses permitted for the current user. It honors the WordPress quick_edit_statuses filter. The parent picker searches the full content type. WordPress permissions control which actions appear and which changes are accepted.

An Edit in WordPress button appears beside Open in Builder for posts and pages. It opens the WordPress editor in a new tab, including custom fields available there. Term rows also link to the WordPress term editor. The Table setting can hide these buttons; they are shown by default and only appear for items the user may edit.

Hierarchical content types, such as Pages, open in a parent and child view. Expand a parent to load its children, or use the Open all / Close all control for every branch on the current page. Large branches have a Load more children control. Sorting applies within each level, while the main pagination counts top-level items. The Hierarchy button switches between the tree and flat list. Hierarchy controls appear only in the browse table. Search results appear as a flat list so matches at every depth remain visible; child results name their parent.

Etch must be installed and active. The plugin uses Etch's current builder interface, so a future Etch update may require a compatibility update. Tested with Etch 1.7.0 and WordPress 7.1.3 on a local site.

Only users with appropriate WordPress capabilities can list, manage, or move items to Trash. The Trash action asks for confirmation and moves eligible items to the standard WordPress Trash.

== Installation ==

1. Install and activate Etch.
2. Upload the etch-content-hub-sort ZIP in Plugins > Add New > Upload Plugin, then activate it.
3. Open the Etch builder and its Content Hub. Use the Hub search and sort button in Etch's left settings bar to open this plugin's settings panel.

Starting with version 1.7.6, WordPress checks this plugin's public GitHub Releases for updates. New versions appear on the Plugins screen and can be installed there or through WordPress automatic updates. Each release must include the installable, versioned plugin ZIP. Version 1.7.5 and older must be upgraded to 1.7.6 manually once to enable these update checks.

== Frequently Asked Questions ==

= Can I edit an item's content in the browse table? =

Quick edit changes title, slug, date, status, and parent. Use Open in Builder to edit the item's content in Etch, or Edit in WordPress to reach the WordPress editor and custom fields. The Etch table button returns to Etch's original inline editing and creation view.

= What happens when I select Trash? =

The item moves to WordPress Trash after confirmation, subject to the user's delete permission. It can be restored through WordPress while it remains in Trash.

= Can I restore a deleted taxonomy term? =

No. WordPress has no Trash for terms. The Delete button asks for confirmation before permanently removing a term. Child terms move to the deleted term's parent.

== Changelog ==

= 1.7.18 =

* Add a Settings transfer tab to export all saved plugin settings and import them on another site.
* Validate the complete import before changing settings, including color contrast, supported values, and administrator permissions.
* Show the installed plugin version in a pill centered at the bottom of the settings sidebar.

= 1.7.15 =

* Use WordPress display labels for post statuses, including translated and customized labels.
* Expand Quick edit to Scheduled, Private, Pending Review, and registered third-party statuses, honoring WordPress's quick_edit_statuses filter and publishing permissions.
* Keep scheduled, pending, private, and custom-status items visible in the table while respecting private-content access, and let WordPress handle scheduling.
* Add colored badges and a grouped status picker, with purple for third-party statuses.
* Add a separate Status colors settings tab with background and text controls for each WordPress status and one shared third-party group. Preserve existing colors as defaults, with previews, resets, and 4.5:1 contrast validation.

= 1.7.9 =

* Add a default-on Edit in WordPress button with the outline Dashicons WordPress icon beside the Etch editor action, with links for editable taxonomy terms too.
* Add a Table setting to show or hide the button and an individual button appearance setting.

= 1.7.8 =

* Align hierarchy color labels and pickers, and show each selected color value.
* Turn on custom hierarchy styling when a color or border width is changed.
* Identify colors that miss the contrast requirement, while keeping Save available to explain and focus the issue.

= 1.7.7 =

* Place Initial taxonomy sorting in the Table settings group, beside post sorting.

= 1.7.6 =

* Add native WordPress update notices and installation from published GitHub Releases.
* Show GitHub release notes in the WordPress plugin details dialog.

= 1.7.5 =

* Suggest a slug when Quick edit begins with a blank title and slug, and let WordPress generate the saved slug from the new title.

= 1.7.4 =

* Add title, slug, and site-local date to post Quick edit.

= 1.7.3 =

* Keep the selected Etch table or Browse all view across content types and taxonomies for the current builder session. Use the saved default after a reload.

= 1.7.2 =

* Load the selected taxonomy directly in Browse all while Etch prepares its original table.
* Show the selected taxonomy in the loading message and clear the previous taxonomy immediately when switching.

= 1.7.1 =

* Keep dialog headers and Create/Cancel buttons visible while item and term fields scroll; tighten dialog spacing.

= 1.7.0 =

* Create, quick edit, assign parents, and delete taxonomy terms from the browse table.
* Add separate initial sort column and direction settings for taxonomies.
* Validate taxonomy permissions and reject parent cycles before saving.

= 1.6.0 =

* Extend the browse table to visible taxonomies with full-result search, sorting, and pagination.
* Add hierarchy browsing for hierarchical taxonomy terms and identify parents in flat results.
* Keep term editing in Etch's original table until browse table controls are added.

= 1.5.4 =

* Sort complete, single-page result sets in the browser without another posts request.
* Keep server-side sorting for paginated and in-flight results.

= 1.5.3 =

* Show button appearance choices in compact expandable rows.
* Display color pickers only when custom colors are enabled for a button.

= 1.5.2 =

* Keep the button appearance preview within its panel so long icon-and-text labels cannot cover Save.

= 1.5.1 =

* Group Content Hub toolbar controls and widen the search field.
* Arrange settings in compact groups and keep the Enable switch visible.
* Let administrators hide the left settings shortcut while search and sorting is active.
* Use the supplied tree and list icons for Hierarchy and Open all / Close all.

= 1.5.0 =

* Match the browse table's button dimensions and native action icons to Etch's controls.
* Add a separate Hierarchy settings section for parent and child indicators.

= 1.4.3 =

* Match the browse table's button size, spacing, borders, and icon placement to Etch's controls.
* Use Etch's native Trash and Open icons when its table provides them.

= 1.4.2 =

* Add a default Content Hub view setting: Etch table or Search and Sort.
* Use the same Hub icon in the left settings rail and the Browse all switch button.
* Center the settings rail icon and restore the Content Hub after closing settings opened from there.

= 1.4.1 =

* Keep sortable column labels and arrows outside button appearance settings.
* Use Etch's Open and logo icons where available, and the supplied Quick edit icon.
* Use Etch's button colors until custom colors are explicitly enabled.
* Align the settings bar icon and refine the settings panel using Etch Toolkit's layout and tokens.

= 1.4.0 =

* Move settings into a dedicated panel opened from Etch's left settings bar or the browse table.
* Add individual button color and label style options, with icon only as the default.
* Arrange browse table controls in compact button groups.

= 1.3.0 =

* Add initial sort column and direction settings.
* Add active sort heading background and text colors with a 4.5:1 contrast check.

= 1.2.2 =

* Refresh Etch's post list only when switching to its table after a create, Quick edit, or Trash action, avoiding an extra full-list request after every change.

= 1.2.1 =

* Refresh Etch's own post list after New item or Quick edit, and show an updating state when switching to the Etch table before that refresh completes.

= 1.2.0 =

* Add New item with Draft or Publish status and an optional parent for hierarchical post types.
* Add Quick edit for status and parent assignments on existing items.

= 1.1.0 =

* Add an expandable hierarchy view for Pages and hierarchical custom post types, with paginated roots and children.
* Keep search across every level by showing search results as a flat list.
* Add an Actions placement choice with Open first and Trash last.
* Place the hierarchy caret before each parent ID and distinguish parent and child rows with subtle color and a left border.
* Add a single Open all / Close all control for the current page of a hierarchy, and hide hierarchy controls in Etch's original table.
* Identify child items and their parents in flat lists and search results.

= 1.0.2 =

* Add a split Actions layout with Open after Title and Trash last.
* Stack settings vertically in the Hub search and sort panel.

= 1.0.1 =

* Add an Actions-column placement setting and improve the settings-tab label layout.

= 1.0.0 =

* Initial distribution release with Content Hub search, sorting, pagination, setting, and Trash action.
