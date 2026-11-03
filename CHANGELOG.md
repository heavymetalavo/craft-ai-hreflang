# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.0.0] - Unreleased

### Added

- Content Language field, created on install, holding the language each site's copy of a page is written in
- Language detection with AI when a page is saved, skipped when the text hasn't changed
- AI providers: Anthropic, OpenAI, Langdock and OpenRouter, as in the AI Alt Text plugin
- "Detect content language" in an entry's action menu
- hreflang values in SEOmatic's alternate links built from each site's Content Language, in the sites' language format, e.g. `en-FR`
- Utility with per-site coverage, the hreflang values in use, bulk detection, and a paginated list of pages in another language
