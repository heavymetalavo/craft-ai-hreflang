# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.1] - 2026-11-11

### Changed

- Detect when a page is saved is off by default
- Craft CMS 5.0 or later is supported, not only 5.8 or later
- The provider and model are logged with each detection

### Fixed

- Saving a detection doesn't make Craft queue jobs to update the slugs and URIs of a structure page's descendants, which other plugins, such as search indexers, would queue a job for each of

## [1.0.0] - 2026-11-11

### Added

- Content Language field, created on install, holding the language each site's copy of a page is written in
- Language detection with AI when a page is saved, skipped when the text hasn't changed, queued after Blitz's jobs with the Queue Priority setting
- Text read from the live page's `<main>` element, or the element the Content Selector setting matches, so only the text visitors see is sent
- AI providers: Anthropic, OpenAI, Langdock and OpenRouter, as in the AI Alt Text plugin
- Support for any element type with pages, such as entries, categories and Commerce products
- "Detect content language" in the action menu of an entry, category or other element with a page
- hreflang values in SEOmatic's alternate links built from each site's Content Language, in the sites' language format, e.g. `en-FR`, or as the language alone with the hreflang Format setting
- A searchable list of every language in the Content Language field, with the sites' languages first
- Utility with per-site coverage, the hreflang values in use, bulk detection, and a paginated list of pages in another language
- A page whose hreflang would be the same as another site's copy of it, such as an untranslated `es-US` page next to the `en-US` site's, keeps its site's own hreflang, and the clash is shown in the field, the utility and the Detect content language notice
- `craft.aiHreflang.alternates(element)`, for templates that output the hreflang tags without SEOmatic
- The Content Language field is found by its type, so its handle can be changed or overridden in a field layout
- Uninstalling the plugin deletes the Content Language field it created
- A warning on API keys saved in plain text in the project config, and a "Don't send" reasoning effort
- Utility buttons Detect missing, Re-detect keep chosen, and Re-detect all, which also replaces languages editors chose after a confirmation
- Saving a page where only its Content Language changed doesn't detect anything
