# Etch Content Hub Sort

Adds search, sorting, pagination, and hierarchical browsing for posts and taxonomy terms in the Etch Content Hub. Post rows support quick editing, item creation, and WordPress Trash. Term rows support creation, quick editing, parent assignment, and permanent deletion. Etch 1.7.0 is the tested integration point. Install and usage details are in [readme.txt](readme.txt).

## Development

Update the `Version` header, asset version, and `Stable tag` in `readme.txt` together when preparing a new release. Test against the current Etch interface. Push a matching `vX.Y.Z` tag to build and publish the installable ZIP through GitHub Actions.

From version 1.7.6 onward, WordPress checks the latest published GitHub Release during its normal plugin update checks. Only a release with an uploaded `etch-content-hub-sort-X.Y.Z.zip` asset is offered. The ZIP must contain one `etch-content-hub-sort` directory. Sites on 1.7.5 or earlier need one manual upgrade to 1.7.6 before they can detect future releases. WordPress' per-plugin automatic update setting remains optional.

The PHP, JavaScript, and CSS are GPLv3-or-later. The SVG icons were supplied by the site owner; confirm their redistribution terms before making the repository public.
