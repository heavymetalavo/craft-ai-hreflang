# 🧠 How it works

[← README](../README.md) · [Getting started](getting-started.md) · **How it works** · [Settings](settings.md)

How pages are read and detected, and how their hreflang values are written.

## 🔄 From save to hreflang

1. After a page's element is saved, a queue job for each site the save could have changed runs once caches such as
   Blitz's have been cleared (see [Page caches](getting-started.md#-page-caches)), fetches its live page there and reads the text of the element the **Content Selector** setting matches, `main` by
   default (or of its `<body>`, without its header and footer, if nothing matches), leaving out navigation, scripts
   and styles. Pages that aren't live, and pages on disabled sites, have no public page to fetch, so they aren't
   detected, and nor can pages behind a password, such as on a protected staging site.
2. If a site's text has at least 10 words and has changed since it was last detected, the first 2,000 characters are
   sent to the chosen AI provider with the prompt, which asks for the language's ISO 639-1 code (e.g. `en`), or `und`
   if it can't be told (the page then keeps its site's own hreflang). The code is saved to the Content Language field
   with a hash of the text, so unchanged text isn't sent again.
3. When a page is rendered, SEOmatic's alternate links are rewritten from each site's Content Language: the detected
   language with the site's region, as a BCP 47 language tag such as `en-FR`. A page that hasn't been detected keeps
   its site's own hreflang. `x-default` is left alone.

hreflang values are written in the format of the sites' language settings (`en-GB`), for every alternate link on a
page, rather than SEOmatic's lower case (`en-gb`). They're case-insensitive, so both mean the same. With the
**hreflang Format** setting on **Language only**, they're the language alone (`en`). See
[Settings](settings.md#-hreflang).

## 🗂️ Which sites are detected on save

Saving a page queues a job for the site being saved, and for each other site whose copy Craft recorded changes to in
that save, such as from a field that isn't translated for each site, or the title, depending on the entry type's title
translation method. Sites the page hasn't been detected on yet, such as one it was just added to, are queued too.
Every site is queued for a new page, and when nested entries such as Matrix blocks change, as they're saved on their
sites by their own rules. Editing only fields that are translated for each site queues just the site being edited.

With Blitz refreshing its cache, Blitz decides instead: each page it refreshes is queued, on the site it was
refreshed for. See [Page caches](getting-started.md#-page-caches).

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
in English.

## 🚧 Limitations

- Without SEOmatic, languages are still detected, but no hreflang tags are changed.
- SEOmatic's sitemaps still list each alternate with its site's language, as SEOmatic offers no way to change them.
- The `<html lang>` attribute isn't changed.
- A page's text can change without its element being saved, such as when a global or related entry it shows changes,
  or a scheduled entry goes live. Those pages are detected the next time they're saved, or with **Detect missing** in
  the utility.
