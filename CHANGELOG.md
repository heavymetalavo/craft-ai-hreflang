# Release Notes for AI Hreflang

## 1.0.3 - 2026-11-11

- Added a **Batch Size** setting, 50 by default, for how many pages each batch of a bulk detection detects. Craft's default is 100, which is too many pages to fetch and send to an AI provider within the queue's time limit on a slow site or provider. Jobs already queued keep their size.

## 1.0.2 - 2026-11-11

- Added a **Skip Scout When Saving Detections** setting, off by default, which stops [Scout](https://plugins.craftcms.com/scout) queueing an indexing job for each detected page. Scout's `sync` setting is switched off only for the plugin's own save, in the process making it, and put back straight after, even if the save fails, so pages editors save and other queue workers keep syncing. It has no effect without Scout, or if its `sync` setting is already off.
- Changed **Detect missing** to only queue pages that haven't been detected, with neither a language nor a hash, so the queue's count is the number of pages it will send. It used to queue every page, then skip the ones already detected, so a site with 351 pages, 67 of them detected, showed 351 in the queue and sent 284.
- Changed **Detect missing** to no longer fetch pages that have been detected. It used to fetch each one to compare its text with the saved hash.
- Changed the **Detect missing** button's tooltip, the utility's help text and the documentation to match.
- Changed pages detected without a language, as they had too little text or the language couldn't be told, to be left alone by **Detect missing**, even if their text has grown since. Pages that couldn't be detected, such as when the AI provider failed, are still picked up by the next one.
- Fixed saving a detection making plugins that queue a job for each saved element, such as Scout, queue a job for each of the page's nested elements too. A detection is saved as a resave, which Craft treats as every field having changed, so Matrix, Neo and Content Block fields saved all their blocks again, and each save was announced. A page with 10 blocks queued 10 Scout "Indexing element" jobs for the same page, and 100 detected pages queued about 1,000. Jobs queued while a nested element is saving are now skipped, and the page's own save still queues its jobs.

## 1.0.1 - 2026-11-11

- Changed **Detect when a page is saved** to be off by default.
- Changed Craft CMS support to 5.0 or later, not only 5.8 or later.
- Changed the provider and model to be logged with each detection.
- Fixed saving a detection making Craft queue jobs to update the slugs and URIs of a structure page's descendants, which other plugins, such as search indexers, would queue a job for each of.

## 1.0.0 - 2026-11-11

- Initial release
- Content Language field, created on install, holding the language each site's copy of a page is written in
- Language detection with AI when a page is saved, queued after Blitz's jobs with the **Queue Priority** setting, and skipped when the text hasn't changed
- Text read from the live page's `<main>` element, or the element the **Content Selector** setting matches, so only the text visitors see is sent
- AI providers: Anthropic, OpenAI, Langdock and OpenRouter, as in the AI Alt Text plugin
- Support for any element type with pages, such as entries, categories and Commerce products
- "Detect content language" in the action menu of an element with a page
- hreflang values in SEOmatic's alternate links, built from each site's Content Language, and `craft.aiHreflang.alternates(element)` for templates that output the hreflang tags without SEOmatic
- A page whose hreflang would be the same as another site's copy of it keeps its site's own hreflang, and the clash is shown in the field and the utility
- A utility with per-site coverage, the hreflang values in use, bulk detection and a list of pages in another language
