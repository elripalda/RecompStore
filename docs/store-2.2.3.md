# Recomp Store 2.2.3 (build 37)

What changed since 2.2.2, for whoever works on the store next.

## Speed
- The screens no longer read the disk each frame. What's installed is kept per app (`Store::inst_v`):
  read once, looked at again when the catalog, the job, the queue or the console's apps change (the
  job's app at once, the rest six a frame) and the oldest one a frame every 10 s (for FTP changes).
- The USB drives (every 2 s) and the download cache's size are looked at on an engine thread of their
  own (`Engine::usb_seen`, `Engine::cache_seen`); the Drives view's folder sizes too (`drive_folders`,
  only `/data`, `/mnt/extN` and `/mnt/usbN`, never anything else).
- A picture's main colour is worked out where it's decoded (the loader thread), never on the screen's.

## Downloads and the one HTTP session
- A quick thread (`quick_main`) sends logs at any time, and while a job runs fetches patch notes and
  pictures, so none of them waits for an install.
- A long GitHub download (over 8 MB, Range asked first) hands the console's one HTTP session over
  when another thread is waiting (`Session::others_waiting`), then carries on in parts from where it
  was. Progress is set at most every 0.1 s and the download yields every MB.

## Screens
- Tabs: Discover, Library (the whole store: All, Games, Emulators, Apps, Tools; sorted by Name,
  Popular, New or Updated), My Apps (Installed, Updates, On this PS5, Drives, Betas), Settings. Category
  tabs only when the catalog's `tabs` or the player asks for them.
- Drives: each drive's space, and what's at the top of it (look only); Cross shows its apps in On this
  PS5, where they move and delete. A toast when a USB drive comes or goes.
- Under a tile: Installed, Update ready, Coming Soon, or who made it.
- Discover's category cards: the icon and the name in capitals, in the middle. New icons (256 px).
- The downloads pill: even padding, the name cut with an ellipsis, how far as a fill inside the pill,
  the rest waiting in their own badge; Refresh's note beside it.
- A dark band behind the button hints everywhere; lists fade out under their headers and into the band.
- App page: the art goes soft and dark as you scroll to the rows, which sit on their own glass sheet;
  the actions are one bar (Delete, Send logs, Versions, the star) and Auto-update is a row with a switch.
- Patch notes: GitHub's HTML read as text (`notes_text`: pictures as [Image: alt], links as their
  words, breaks and paragraphs, details, entities), Markdown pictures and links the same, long
  addresses as their host and an ellipsis, and words too long for a line broken to fit.
- The focused app's colour: a soft tint behind everything, and a glow round its icon. Icons that are
  still loading shimmer. Reduce motion turns off the shimmer and the easing.
- Customize in groups: Tabs, Look, Discover, Motion, Advanced.
