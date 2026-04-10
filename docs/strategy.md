# Strategy Notes

This document captures product and business direction ideas for Garner.
It is intentionally lightweight. These are guiding notes, not locked decisions.

## Product Shape

Garner should not try to win by having more features than every other CMS.
The stronger edge is coherence:

- calm editing experience
- file-native content model
- ordinary PHP hosting
- Twig-first public rendering
- modern Studio without a Node.js production runtime
- CLI as a first-class surface

Garner should feel easier to reason about than heavier CMS stacks.

## Likely Edge

The current long-term differentiators look like this:

- file-native content with a derived SQLite index
- modern authoring UI on top of a simple PHP runtime
- no Node.js backend requirement in production
- strong CLI surface for automation and LLM-assisted workflows
- explicit project structure that stays understandable on disk

The important point is not “AI CMS”.
CLI and LLM support should strengthen the product, not become the whole pitch.

## Audience

The most likely early fit is:

- PHP/Twig freelancers
- small agencies
- bespoke website builders
- teams that want self-hosting without operational weight

Garner does not need to be positioned first for:

- enterprise organizations
- headless-first application teams
- no-code users

## Positioning

Garner should be positioned as a calm, file-native PHP CMS for custom sites.

Messaging should emphasize:

- clarity over magic
- files over database lock-in
- boring hosting over infrastructure sprawl
- modern editing without a heavy runtime

Avoid trying to present Garner as a universal replacement for every CMS.

## Business Model Direction

The most natural commercial direction currently looks similar to the model used by some established self-hosted CMS products:

- public source repository
- fully functional self-hosted product
- paid production license
- free development and local evaluation
- simple pricing, ideally one tier at first

Why this direction currently looks strong:

- it matches the likely audience
- it gives a clear revenue path without open-core feature gating
- it is easier to loosen later than to close after launching as fully permissive open source

Open-core and feature gating should be avoided if possible.

## Product Discipline

Garner should avoid trying to solve too many categories too early.

The first things that need to be excellent are:

- pages
- files
- blueprints
- Twig rendering
- Studio editing
- CLI workflows

Plugin architecture, enterprise features, and advanced extension surfaces should come later.

## Go-To-Market Notes

When Garner becomes public, the first launch should stay small and polished.

Priority assets:

- one strong example site
- one clear Studio demo
- a short explanation of what Garner is for
- a simple explanation of why it exists

The initial story should be about focus and taste, not scale.
