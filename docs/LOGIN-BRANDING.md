# BISU Balilihan login branding

The login page uses locally served assets from the official BISU Balilihan campus
website. The university website, https://bisu.edu.ph/, links to that campus site.
Downloaded on 26 October 2026; the seal artwork is unchanged.

| Asset | Official source |
| --- | --- |
| `public/images/bisu/bisu-seal.png` | https://bisubalilihan.com/wp-content/uploads/2022/04/BISU-Logo-1.png |
| `public/images/bisu/favicon-36.png` | https://bisubalilihan.com/wp-content/uploads/2022/04/BISU-Logo-1-36x36.png |
| `public/images/bisu/balilihan-campus-entrance.png` | https://bisubalilihan.com/wp-content/uploads/2023/01/new-gatE-FIN.png |

The entrance asset is the campus's monochrome photograph with a transparent sky,
not the architectural rendering also present in its media library. The login
places that photograph at its natural aspect ratio across the full campus
column, with its location caption underneath. A luminosity blend and soft edge
mask integrate it into the purple panel, as requested. There is no separate
gray photo block or fixed-height crop; the seal retains its original colors.
No replacement seal, AI image or
photograph of another BISU campus is used.

The campus information page describes the seal color as blue-violet:
https://bisubalilihan.com/about-bisu/campus-information/.
The revised interface uses the campus website's actual Elementor theme values:
primary purple **`#31146E`** and secondary gold **`#FFCD04`**, with white.
Source: https://bisubalilihan.com/wp-content/uploads/elementor/css/post-14.css
(`--e-global-color-primary` and `--e-global-color-secondary`, retrieved on
26 October 2026). These are website theme values, not a claim about a separate
university brand manual.

The page fills the viewport without an outer card, margins or rounded frame.
Branding, heading and campus photograph occupy separate layout rows so the image
cannot overlap the copy. The student tab uses a direct evaluation action; the
account registration prompt is retained only in the staff tab when enabled.
The official 36-pixel seal favicon is configured for the Filament panel and the
standalone evaluation, preview, report and printable-copy pages.

The stylesheet is scoped to the login page and versioned by its modification time.
Images, fonts and styles load from the campus server, so sign-in does not depend
on the public website or another external asset host. The optional Campus website
link opens the official site separately.

Student access offers ID-only faculty evaluations. The assessment ID/PIN section
is temporarily hidden from this login, as assessments use a separate system.
The assessment backend is retained. Faculty & staff opens the existing password
form, including configured password-reset and Google access. Registration remains
subject to the existing panel setting. Authorization, PIN checks, rate limiting,
evaluation permissions and stored campus data are unchanged.

Validation: **66 tests passed (398 assertions)** across sign-in, evaluation entry,
forms, individual copies and reports. Browser checks cover eight layouts from
320 to 2560 pixels, including the supplied screenshot's 1876-by-864 viewport,
phone landscape and a device preferring dark mode. Checks verify full-width
layout, the exact purple, no text/photo overlap, natural photo proportions,
full-column photo width, purple photo blending, a caption below the image,
favicon HTTP status and image
type, hidden assessment controls and both access modes. Local
browser screenshots and receipts are kept under `storage/app/maintenance/` and are
excluded from source control.

Login runtime: Livewire uses the stable `/livewire/livewire.js` endpoint in both
debug and campus modes, with `/livewire/livewire.min.js` retained as an alias.
This prevents a route cache built in the other mode from disabling login tabs.
The staff-tab repair passed 15 sign-in/asset tests (69 assertions) and all eight
browser layouts on the running local server, including switching both ways.
