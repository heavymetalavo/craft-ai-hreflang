# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] - Unreleased

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
