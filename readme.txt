=== Etch Content Hub Sort ===
Contributors: stephenwalker
Tags: etch, content hub, search, sort, pagination
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds search, sorting, pagination, and WordPress Trash actions to the Etch Content Hub.

== Description ==

This plugin adds a browse table to Etch's Content Hub. It searches and sorts across the full result set and offers 25, 50, or 100 items per page. An Etch table button opens the original Etch view for inline editing and new item creation. A separate Hub search and sort tab in Etch settings turns the feature on or off and offers four Actions layouts: both at the end (default), both at the start, Open after Title with Trash last, or Open first with Trash last.

The browse table also has a New item button with Draft or Publish status. For hierarchical content types, choose a parent while creating an item. Each existing item has Quick edit for changing its status and, for hierarchical types, its parent. The parent picker searches the full content type. WordPress permissions control which actions appear and which changes are accepted.

Hierarchical content types, such as Pages, open in a parent and child view. Expand a parent to load its children, or use the Open all / Close all control for every branch on the current page. Large branches have a Load more children control. Sorting applies within each level, while the main pagination counts top-level items. The Hierarchy button switches between the tree and flat list. Hierarchy controls appear only in the browse table. Search results appear as a flat list so matches at every depth remain visible; child results name their parent.

Etch must be installed and active. The plugin uses Etch's current builder interface, so a future Etch update may require a compatibility update. Tested with Etch 1.7.0 and WordPress 7.1.3 on a local site.

Only users with appropriate WordPress capabilities can list, manage, or move items to Trash. The Trash action asks for confirmation and moves eligible items to the standard WordPress Trash.

== Installation ==

1. Install and activate Etch.
2. Upload the etch-content-hub-sort ZIP in Plugins > Add New > Upload Plugin, then activate it.
3. Open the Etch builder and its Content Hub. Use the Hub search and sort tab in Etch settings to enable or disable the feature.

== Frequently Asked Questions ==

= Can I edit an item's content in the browse table? =

Quick edit changes status and parent. Use the Open button to edit the item's content in Etch. The Etch table button returns to Etch's original inline editing and creation view.

= What happens when I select Trash? =

The item moves to WordPress Trash after confirmation, subject to the user's delete permission. It can be restored through WordPress while it remains in Trash.

== Changelog ==

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
