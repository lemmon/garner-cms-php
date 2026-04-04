# Garner CMS Known Issues

This document tracks known issues that are accepted for now but should remain visible.

## Studio SPA Prefix Is Hardcoded

Current state:

- the Studio frontend build hardcodes `base: '/studio'` in [frontend/svelte.config.js](../frontend/svelte.config.js)
- the backend still exposes `app.routes.studio_prefix` in [backend/config/app.php](../backend/config/app.php)
- the PHP Studio serving layer assumes those two values match

Why this matters:

- the system currently behaves correctly only while the Studio prefix stays `/studio`
- changing only the backend prefix would break built Studio asset URLs and SPA routing

Current decision:

- accept `/studio` as the fixed Studio prefix for now
- keep the backend config value aligned with that fixed prefix

Future fix options:

- make the Studio prefix non-configurable and treat `/studio` as a hard contract
- or generate the frontend base path from the same source as the backend prefix during the Studio build
