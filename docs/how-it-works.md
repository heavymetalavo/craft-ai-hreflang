# 🧠 How it works

[← README](../README.md) · [Getting started](getting-started.md) · **How it works** · [Settings](settings.md)

How pages are read and detected, and how their hreflang values are written.

## 🔄 From save to hreflang

1. With **Detect when a page is saved** on, after a page's element is saved, a queue job for each site the save could have changed runs once caches such as
   Blitz's have been cleared (see [Page caches](getting-started.md#-page-caches)), fetches its live page there and reads the text of the element the **Content Selector** setting matches, `main` by
   default (or of its `<body>`, without its header and footer, if nothing matches), leaving out navigation, scripts
   and styles. Pages that aren't live, and pages on disabled sites, have no public page to fetch, so they aren't
   detected, and nor can pages behind a password, such as on a protected staging site.
2. If a site's text has at least 10 words and has changed since it was last detected, the first 2,000 characters are
   sent to the chosen AI provider with the prompt, which asks for the language's ISO 639-1 code (e.g. `en`), or `und`
   if it can't be told (the page then keeps its site's own hreflang). A reply that isn't a known ISO 639-1 code fails,
   and a language that only has a longer code, such as Hawaiian (`haw`), counts as `und`, as hreflang needs an ISO
   639-1 code. The code is saved to the Content Language field with a hash of the text, the provider, the model and
   the prompt, so a page isn't sent again until one of them changes. Only the characters sent count, so changes after
   the first 2,000 don't.
3. When a page is rendered, SEOmatic's alternate links are rewritten from each site's Content Language: the detected
   language with the site's region, as a BCP 47 language tag such as `en-FR`. A page that hasn't been detected keeps
   its site's own hreflang, and so does a page whose hreflang would clash with another site's (see below).
   `x-default` is left alone.

hreflang values are written in the format of the sites' language settings (`en-GB`), for every alternate link on a
page, rather than SEOmatic's lower case (`en-gb`). They're case-insensitive, so both mean the same. With the
**hreflang Format** setting on **Language only**, they're the language alone (`en`). See
[Settings](settings.md#-hreflang).

## 🚦 When two copies would share an hreflang

Each copy of a page must have its own hreflang, or search engines can't tell which URL is for whom. A copy in
another language can clash with another copy:

- **Sites in the same country**, such as `en-US` and `es-US`, `en-CA` and `fr-CA`, or `de-CH`, `fr-CH` and `it-CH`:
  an `es-US` page still in English would be `en-US`, the same as the `en-US` site's page.
- **Sites without a region**, such as `en` and `fr`: a French page still in English would be `en`.
- **Language only** format: any page in another site's language.

The copy then keeps its site's own hreflang, as if it hadn't been detected. The clash is shown in the page's Content
Language field, in the utility's coverage and its list of pages in another language, and in the notice of **Detect
content language**. Translating the page, or choosing its language in the field, resolves it.

## 🗂️ Which sites are detected on save

Saving a page queues a job for the site being saved, and for each other site whose copy Craft recorded changes to in
that save, such as from a field that isn't translated for each site, or the title, depending on the entry type's title
translation method. Sites the page hasn't been detected on yet, such as one it was just added to, are queued too.
Every site is queued for a new page, and when nested entries such as Matrix blocks change, as they're saved on their
sites by their own rules. Editing only fields that are translated for each site queues just the site being edited.

With Blitz refreshing its cache, Blitz decides instead: each page it refreshes is queued, on the site it was
refreshed for, in one job for the whole refresh. A refresh caused by the plugin saving a page's own detection isn't
detected again. See [Page caches](getting-started.md#-page-caches).

Saves made in the queue or on the command line, such as imports, are queued straight away rather than at the end of
the request, as a queue worker's request doesn't end until the worker stops.

Nothing is queued until an AI provider, API key and model are set.

A queued site whose page text hasn't changed since it was last detected isn't sent to the AI provider.

## 🌐 Why the plugin fetches the page itself

The plugin fetches each page itself and sends the AI provider its text, rather than the page's URL. Through their
APIs, AI models don't open a URL in a prompt: they need a fetch or search tool, and support for one differs by
provider. Anthropic and OpenRouter have a web fetch tool, OpenAI only has web search, where the model decides whether
to open a page, and Langdock has neither. Given only a URL, a model can also guess the language from the URL itself,
such as `fr` for `/fr/contact`. Fetching the page in the plugin works the same with every provider, also reaches local
sites that an AI provider can't, and sends only the page's main text.

## 📄 Why the rendered page rather than fields

The text comes from the live page rather than its element's fields, as an element can have fields its template doesn't
show, such as SEO descriptions or notes for editors, which can be in another language. Only the page's main content is
read, `<main>` unless the **Content Selector** setting names another element, as the navigation, footer and cookie
banner around it are in the site's language, and would bias detection towards it on exactly the pages that are still
in English. Within it, navigation, dialogs, form controls, and elements with `hidden` or `aria-hidden="true"` are left
out too.

The text is sent between `<page_text>` tags, and the default prompt tells the model to ignore any instructions in it,
so text on a page, such as a visitor's comment, can't easily choose the page's language.

## 🚧 Limitations

- Without SEOmatic, languages are still detected, but no hreflang tags are output for you: use
  [`craft.aiHreflang.alternates()`](../README.md#-quick-start) in your templates.
- SEOmatic's sitemaps still list each alternate with its site's language, as SEOmatic offers no way to change them.
- The `<html lang>` attribute isn't changed.
- Without Blitz, a page's text can change without its element being saved, such as when a global or related entry it
  shows changes, or a scheduled entry goes live. Those pages are detected the next time they're saved, or with
  **Re-detect, keep chosen** in the utility. With Blitz, they're detected when Blitz refreshes them.
