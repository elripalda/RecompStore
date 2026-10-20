# Recomp Store 2.1: what's new between the store, the site and the signer

Everything in docs/api-2.0.md still holds. 2.1 adds signatures, and a few fields older stores ignore.

## Signatures (Recomp Store Signer)

The signer (`signer/`, a Windows program in Go) keeps an Ed25519 key on the owner's PC, locked to the
Windows account (DPAPI), plus a backup key handed out once for a USB drive. Its public halves are
built into the store and the installer (`app/src/rs_sig_keys.h`, from `tools/make-sig-keys.py`;
`tools/build-store.sh` refuses to package a release with the throwaway test keys).

A signature is a small text file:

    RecompStore signature v1
    kind: catalog | catalog-test | store | beta
    file: catalog.json
    size: 48213
    sha256: <64 hex>
    signed: 2026-10-20T04:30:00Z
    key: <16 hex: the first 8 bytes of SHA-256 of the public key>
    sig: <128 hex>

The Ed25519 signature covers `"RecompStore signature v1\nkind: K\nsha256: H\nsigned: T\n"`. The same
message is built in `signer/sig.go`, `app/src/rs_sig.c` (Monocypher 4.0.2, CC0) and
`site/public_html/admin/inc/sign.php` (sodium).

What a 2.1 store checks:
- **Catalog**: `GET /catalog.json` and `GET /catalog.sig`; kind `catalog`, the SHA-256 of the exact bytes,
  and not older (`signed`) than the one it saved last (`catalog.json.sig`). Otherwise it keeps the last
  one it trusted and says so on an empty Discover. A mismatch between the two requests is asked again once.
- **Testers' catalog**: `POST /api/catalog.php a=test` and `a=testsig` (same form); kind `catalog-test`.
- **Beta zips**: the beta list entry's `sig` (kind `beta`) against the downloaded zip's SHA-256. No `sig`,
  no install.
- **Its own updates**: only a release whose assets include both `PPSA92000.zip` and `PPSA92000.zip.sig`,
  from `github.com/elripalda/RecompStore/releases/download/`; kind `store`. The store saves the `.sig`
  beside the zip and the installer checks it again; the installer's own GitHub path checks it too.
- **GitHub apps**: no signatures (developers don't sign), but the download link must be
  `https://github.com/<the app's repo>/releases/download/...`, whatever the release list says.
- **Fixed links**: need `sha256` in the (signed) catalog.

## The site's side

- **Publish**, once the signer has told the site its keys, writes `pending-catalog.json` and
  `pending-catalog-test.json` in the private folder (`recompstore-private` beside public_html) instead of
  `catalog.json`. Nothing goes out until the signer signs; then `catalog.json` + `catalog.sig` (public) and
  `catalog-test.json` + `.sig` (private, through api/catalog.php) are written as one.
- `api/sign.php` (header `X-Signer-Token`, a token made in Settings > Signer; only its SHA-256 is kept):
  - `POST a=hello keys=<public-keys.txt>`: the first keys told are kept.
  - `GET a=pending`: `{ok, items:[{kind, id?, name?, file, size, sha256, when, note?}]}`.
  - `GET a=get kind=catalog|catalog-test`: the exact bytes waiting.
  - `POST a=put sig=<signature>`: checked against the keys and what's waiting, then published (a beta
    signature is matched to a beta build by its SHA-256 and kept in the `signatures` table).
- Every admin change, login and failed login goes in `admin_log` (Settings > Change log). Logging in can
  need an authenticator code (Settings > Two-step login: TOTP, 8 one-time recovery codes), and is limited
  to 10 failed tries per address per 15 minutes.

## New catalog fields (older stores skip them)

- `categories`: `{"games":{"sort":S},"emulators":{...},"apps":{...},"tools":{...}}`, S one of `manual`
  (the `order` field), `az`, `installs` (charts), `new` and `updated` (the charts' lists first).
- `discover.header`: `{"style": "carousel"|"spotlight"|"split"|"mosaic"|"none", "interval": 4..30,
  "games": [ids, optional; default the featured ones]}`.

## Beta list entries

- `sig`: the current build's signature.
- `betaNotes`: what's new in this build, for testers.
- `versions`: earlier builds a tester can go back to, newest first (up to 10):
  `[{version, file, url, sha256, bytes, size, notes, date, sig}]`. The url is
  `/api/beta.php?a=file&id=<id>&file=<zip>&install=<install>&sig=<HMAC of id|zip>`.

## Analytics

Counted day by day (UTC): `console_days` (each console that made a request), `app_events`
(install / update / remove per app, from api/stats.php), `beta_downloads` (from this site; R2 isn't
counted), `site_pages` and `site_visitors` (from `POST /api/hit.php {p, r}`, sent by the website on each
page: no cookies; a visitor is a code from the address and browser with a salt that changes daily).

## Submissions

`POST /api/submit.php` (multipart): kind (games, emulators, apps, tools), name, author, contact, repo,
asset, title_id, version, blurb, description, needs, credits, link1-3, notes, rights=1, and optional
pictures `icon`, `hero`, `logo`, `shots[]` (PNG/JPEG/WebP, 8 MB each, kept outside public_html). They show
in the Inbox under Submissions; "Add to the store as a draft" makes a hidden entry with all of it.
