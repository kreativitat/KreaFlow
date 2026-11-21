# KreaFlow brand assets

Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>

KreaFlow is the public brand of the Dolibarr module installed as `kreaflow`.
Tagline: **Automate. Connect. Grow.**

## Supplied originals

The nine PNG assets and the identity guide were copied without alteration from the supplied `kreaflow_assets` folder. The guide was found inside that folder rather than at the originally mentioned Desktop path.

- [Primary logo](primary_logo.png)
- [Logo with tagline](logo_with_tagline.png)
- [Horizontal logo](horizontal_logo.png)
- [Dark logo](dark_logo.png)
- [Symbol](symbol.png)
- [Light app icon](app_icon_light.png)
- [Dark app icon](app_icon_dark.png)
- [Orange app icon](app_icon_orange.png)
- [Colour palette](color_palette.png)
- [Identity guide](<Guia de identidade visual KreaFlow.png>)

## Identity

| Role | Colour |
| --- | --- |
| Primary | `#E63D00` |
| Secondary | `#FF8A00` |
| Accent | `#FFBC47` |
| Text | `#0F0F0F` |
| Background | `#F7F7F7` |

Use the horizontal lockup for narrow spaces, the symbol or app icon for compact views, and the dark logo on dark backgrounds. The supplied files are raster assets with opaque backgrounds; keep their proportions and do not represent them as transparent or vector artwork. Preserve Dolibarr theme styling for functional screens.

## Runtime compatibility

- `img/logo.png` is an exact copy of `horizontal_logo.png`.
- `img/dolizsynch.png` and `img/object_dolizsynch.png` are exact copies of `symbol.png`. The supplied orange app-icon crop includes part of its size caption, so the clean symbol is used for runtime pictograms.
- Keep module ID `156558`, `modDoliZSynch`, `MAIN_MODULE_DOLIZSYNCH`, directory names, API routes, database identifiers, translation keys, hook/trigger classes and permissions unchanged.
- Existing `DZ` cron labels are registration identities. Do not rename them without a separately tested migration: module reactivation can otherwise duplicate scheduled jobs.
- Existing invoice notes and historical release evidence retain their original attribution. New invoice attribution uses KreaFlow through the existing lifecycle.
- The canonical 3.0.0 folder is `kreaflow`; legacy technical IDs remain behind compatibility adapters. See `compatibility/dolizsynch/README.md` for the required two-directory deployment.

## Runtime assets from 3.0.3

- `img/kreaflow-logo-transparent.png` is the transparent horizontal logo used by the About page; supplied originals remain unchanged.
- `app-icon-master.png` is the square KreaFlow icon master on an opaque light background. Artwork stays inside the maskable safe circle.
- `app/public/kreaflow-*-v3.png` contains the resized browser, Apple touch, standard PWA and maskable icons. Rebuild frontend/index.html, manifest.webmanifest and sw.js together; retain previous hashed bundles for installed clients.
