# AI Hreflang Detector

A Craft CMS plugin that detects the language each page is written in with AI, and sets the page's hreflang to match.

A page on the French site (`fr-FR`) whose content is still the English copy is announced as `en-FR` instead of `fr-FR`,
so search engines aren't told an English page is French. Translated pages keep their site's hreflang.

## Requirements

- Craft CMS 5.8+
- PHP 8.2+
- SEOmatic, which outputs the hreflang tags
- An API key for one of the AI providers: Anthropic, OpenAI, Langdock or OpenRouter

## Installation

Install it with Composer, or for local development through a Composer path repository with `"symlink": true`.

On a fresh install, `php craft plugin/install ai-hreflang-detector` creates the **Content Language** field
(`contentLanguage`). When the plugin is installed by applying the project config, the field comes from the project
config instead. Both use the same UID, so they never conflict.

Add the field to the field layout of each entry type with public pages, e.g. at the top of an **SEO** tab.

## How it works

1. When an entry is saved, a queue job reads its title and text fields on each of its sites. That includes plain text,
   CKEditor, tables, and nested Matrix entries and Neo blocks; related entries are skipped.
2. If a site's text has at least 50 words and has changed since it was last detected, the first 2,000 characters are
   sent to the chosen AI provider with the prompt, which asks for the language's BCP 47 language subtag (e.g. `en`), or `und` if it can't be told (the
   page then keeps its site's own hreflang). The code is saved to the Content Language field with a hash of the text,
   so unchanged text isn't sent again.
3. When a page is rendered, SEOmatic's alternate links are rewritten from each site's Content Language: the detected
   language with the site's region, as a BCP 47 language tag such as `en-FR`. A page that hasn't been detected keeps
   its site's own hreflang. `x-default` is left alone.

hreflang values are written in the format of the sites' language settings (`en-GB`), for every alternate link on a
page, rather than SEOmatic's lower case (`en-gb`). They're case-insensitive, so both mean the same.

Editors can choose a page's language in the field instead, and then it isn't detected. The field's options name each
language for the page's site, with the hreflang it gives the page, e.g. "English (Germany) — en-DE". **Detect content language** in an entry's action menu (…) detects it on the
site being edited straight away.

## Settings

**Settings → Plugins → AI Hreflang Detector**

| Setting | Description |
| --- | --- |
| AI Provider | Anthropic, OpenAI, Langdock or OpenRouter. Only the chosen provider's settings are shown |
| API Key | The chosen provider's key, e.g. `$OPENROUTER_API_KEY` |
| Model | A small model is enough, e.g. `claude-haiku-5-5`, `gpt-5-nano` or `openai/gpt-5-nano` |
| Langdock Model Author | Anthropic or OpenAI, which picks the Langdock API requests go to |
| Reasoning Effort | OpenAI, Langdock (OpenAI models) and OpenRouter only. Only sent to OpenAI reasoning models (`low` by default) |
| Prompt | The instructions sent with each page's text. The reply must be a BCP 47 language subtag such as `en`, or `und` when the language can't be told |
| Detect when a page is saved | On by default |
| Minimum Words | Pages with fewer words (50 by default) aren't sent, and keep their site's own hreflang |
| Maximum Characters | How much of a page's text is sent (2,000 by default) |

The providers are the same as the AI Alt Text plugin's, and requests are made the same way, but with this plugin's
own settings:

| Provider | API |
| --- | --- |
| Anthropic | Messages API, `https://api.anthropic.com/v1/messages` |
| OpenAI | Responses API, `https://api.openai.com/v1/responses`, with `store: false` |
| Langdock | Anthropic models through its Messages API, OpenAI models through its Responses API, in the EU region |
| OpenRouter | Responses API, `https://openrouter.ai/api/v1/responses` |

## Utility

**Utilities → AI Hreflang Detector** shows each site's coverage (detected pages out of all its pages) and the hreflang
values its pages use, with buttons to detect them for one site or all sites:

- **Detect missing** sends only pages that haven't been detected or whose text has changed.
- **Re-detect all** sends every page again.

Below, a paginated table lists the pages that are in another language on at least one site, with the hreflang each
site gives them.

Using it needs the utility's permission.

## Why fields rather than the rendered page

The text comes from the entry's fields rather than its rendered URL. A rendered page includes the navigation, footer
and cookie banner, which are in the site's language, and would bias detection towards it on exactly the pages that
are still in English. Fetching a page straight after a save could also return Blitz's cached copy before its refresh
job runs, and disabled or pending pages can't be fetched at all. The order of a page's fields doesn't matter for
telling which language most of its text is in.

## Limitations

- Without SEOmatic, languages are still detected, but no hreflang tags are changed.
- SEOmatic's sitemaps still list each alternate with its site's language, as SEOmatic offers no way to change them.
- The `<html lang>` attribute isn't changed.
