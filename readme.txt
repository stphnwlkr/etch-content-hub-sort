=== Etch Content Hub Sort ===
Contributors: stephenwalker
Tags: etch, content hub, search, sort, pagination
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds search, sorting, pagination, and WordPress Trash actions to the Etch Content Hub.

== Description ==

This plugin adds a browse table to Etch's Content Hub. It searches and sorts across the full result set and offers 25, 50, or 100 items per page. An Etch table button opens the original Etch view for inline editing and new item creation. A separate Hub search and sort tab in Etch settings turns the feature on or off and offers three Actions layouts: both at the end (default), both at the start, or Open after Title and Trash at the end.

Etch must be installed and active. The plugin uses Etch's current builder interface, so a future Etch update may require a compatibility update. Tested with Etch 1.6.7 and WordPress 7.1.2 on a local site.

Only users with appropriate WordPress capabilities can list, manage, or move items to Trash. The Trash action asks for confirmation and moves eligible items to the standard WordPress Trash.

== Installation ==

1. Install and activate Etch.
2. Upload the etch-content-hub-sort ZIP in Plugins > Add New > Upload Plugin, then activate it.
3. Open the Etch builder and its Content Hub. Use the Hub search and sort tab in Etch settings to enable or disable the feature.

== Frequently Asked Questions ==

= Why can I not edit or create items in the browse table? =

The browse table reads WordPress content through its own paginated API. Etch's inline editing and creation belong to Etch's original table. Use the Etch table button for those actions.

= What happens when I select Trash? =

The item moves to WordPress Trash after confirmation, subject to the user's delete permission. It can be restored through WordPress while it remains in Trash.

== Changelog ==

= 1.0.2 =

* Add a split Actions layout with Open after Title and Trash last.
* Stack settings vertically in the Hub search and sort panel.

= 1.0.1 =

* Add an Actions-column placement setting and improve the settings-tab label layout.

= 1.0.0 =

* Initial distribution release with Content Hub search, sorting, pagination, setting, and Trash action.
