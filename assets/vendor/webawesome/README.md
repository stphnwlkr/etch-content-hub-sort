Web Awesome accordion vendor bundle

Version: @awesome.me/webawesome 3.15.0
Entry imports:
  @awesome.me/webawesome/dist/components/accordion/accordion.js
  @awesome.me/webawesome/dist/components/accordion-item/accordion-item.js

Bundled as an IIFE with esbuild, minified, with legal comments retained at EOF.
Only the accordion and its dependencies are included. It loads locally before sort.js.
DEPENDENCIES.txt records the shipped versions. licenses/ contains their full notices.

Rebuild in a temporary directory with the recorded package versions installed:
  esbuild accordion-entry.js --bundle --format=iife --minify --legal-comments=eof --outfile=accordion.js
Review component APIs and rerun browser checks before changing the pinned Web Awesome version.
