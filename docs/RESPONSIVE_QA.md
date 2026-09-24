# Responsive layout checks

The browser suite uses an isolated SQLite database. No test fixtures or test accounts are created in production.

`tests/Browser/responsive.spec.ts` visits every reachable page in Chromium and WebKit, at 320×568, 390×844, 844×390, 768×1024, 1024×768, 1440×900 and 1080×1920. WebKit also enables touch input. Portrait administration views exercise light mode, while the other administration views use dark mode. Restricted officials and linked athletes are checked separately. The suite covers authentication, race lists/setup/control (draft, running and finished), participants/create/edit/import preview, timing stations, results/splits/display mode, users/edit/athlete selection, roles, health, password settings, athlete dashboards, public results and the account-free public race-day timing view. The unused Dashboard component is reviewed in code; its route redirects to the race list.

Assertions catch document overflow, off-screen form controls, overlapping authentication headers, inaccessible short-screen dialogs, navigation state after moving between pages, missing split precision and browser runtime errors. Deliberately long race, participant, team, category, role and checkpoint names exercise wrapping. Screenshots of timing stations and expanded results are uploaded as the `responsive-layout-screenshots` CI artifact for visual review. Existing browser tests cover recording, offline recovery, race finish synchronization, onboarding and role editing.

Phone results use cards with place, name, total, gap and expandable splits. Wider screens retain the comparison table. A focused, labelled scroll region contains wider tables, including display mode. Header navigation collapses below 1280px; race navigation remains visible. Short screens release the sticky header and nested station scrolling. Safe-area padding protects notched screens, and form text stays at least 16px to avoid focus zoom on mobile browsers.

This is browser-engine and viewport testing, not certification of every physical device. Real-device keyboard, browser chrome and fullscreen behavior can vary; portrait and landscape remain supported without an orientation lock.
