# 🌍 🤖 AI Hreflang

**README** · [Getting started](docs/getting-started.md) · [How it works](docs/how-it-works.md) · [Settings](docs/settings.md)

![AI Hreflang, for multi-site Craft websites where pages aren't always in their site's language, with Anthropic, OpenAI, Langdock and OpenRouter, on Craft 5. A page on the French site that is still in English, with its hreflang corrected from fr-FR to en-FR.](docs/images/banner.png)

**For multi-site Craft websites where pages aren't always in their site's language.**

Some pages are waiting on a translator. Others stay in English on purpose: because most of their audience speaks it as
a second language and translating every page isn't worth the budget, or because they're legal or regulatory text that
must keep its original wording. Either way, AI Hreflang detects the language each page is really written in, and sets
its hreflang to match.

[GitHub Repository](https://github.com/heavymetalavo/craft-ai-hreflang)

## 🤔 The problem

Multi-site launches rarely translate everything at once. Until a page is translated, the French site shows the English
copy, while SEOmatic tells search engines it's `fr-FR`. Google sees a French page in English, and French searchers get
the wrong result. AI Hreflang finds those pages and announces them as `en-FR`, so each page is listed for the
language it's actually in. Translated pages keep their site's hreflang, and nobody has to keep track by hand.

## ✨ What you get

- **Untranslated pages caught automatically.** A page is detected after it's saved, on each site the save changed,
  once caches such as Blitz's are cleared. As soon as it's translated, the next save puts its hreflang back.
- **Reach new audiences before everything's translated.** Copy content across your sites, with Craft's propagation or
  a plugin such as Site Copy X, and go live. Each page's hreflang stays truthful while translation catches up.
- **English on purpose, labelled honestly.** In the Netherlands, 93% of people can hold a conversation in English; in
  Sweden it's 90%, and in Denmark 87% ([Special Eurobarometer 540](https://europa.eu/eurobarometer/surveys/detail/2979),
  2023). Pages you keep in English for those audiences are labelled `en-NL`, `en-SE` or `en-DK`, so search engines know
  exactly who they're for.
- **Reads what your visitors read.** The plugin fetches the live page and reads its main content, `<main>` or any
  element you choose, so hidden fields, notes for editors, menus and cookie banners can't skew the result.
- **Works with any AI provider.** Use Anthropic or OpenAI directly, Langdock (hosted in the EU), or OpenRouter. The
  plugin sends the page's text itself, rather than its URL, so it works the same with every provider, and on local and
  staging sites the provider can't reach. See [why](docs/how-it-works.md#-why-the-plugin-fetches-the-page-itself).
- **Costs next to nothing.** A small model is all it takes, only the first 2,000 characters of a page are sent, and a
  page whose text hasn't changed is never sent again.
- **Entries, categories and more.** Any element with public pages is supported. Add the field to a field layout and
  its pages are detected.
- **Automatic, one click, or in bulk.** On save, from an entry's or category's action menu, or for a whole site from
  the utility. Everything runs through Craft's queue.
- **Editors stay in control.** Any page's language can be chosen from a searchable list of every language, and a
  chosen language is never overwritten.
- **SEOmatic's hreflang tags, fixed.** Alternate links are rewritten for every site, in your sites' own format, such as
  `en-GB`, or as the language alone, such as `en`.
- **See every site's coverage, and a free translation to-do list.** The utility shows how many pages each site has
  detected, the hreflang values in use, and every page that's still in another language, ready to hand to translators.

![How it works, in four steps. 1, saved: a page is saved, and once caches such as Blitz's are cleared, a queue job fetches it on each site the save changed. 2, read: the text of its main content is taken, without menus, scripts or styles. 3, detected: the text is sent to Anthropic, OpenAI, Langdock or OpenRouter, which replies with its language. 4, announced: SEOmatic's hreflang for that site becomes the detected language with the site's region, such as en-FR.](docs/images/how-it-works.png)

## 🚀 Quick start

Craft CMS 5.8 or later, PHP 8.2 or later, SEOmatic, and an API key for Anthropic, OpenAI, Langdock or OpenRouter.

```sh
composer require heavymetalavo/craft-ai-hreflang
./craft plugin/install ai-hreflang
```

1. Choose a provider and add its API key under **Settings → AI Hreflang**.
2. Add the **Content Language** field, created when the plugin is installed, to the field layouts of your entry types
   and category groups with public pages.
3. Detect your existing pages from **Utilities → AI Hreflang → Detect missing**. New and changed pages are
   detected when they're saved.

## 📚 Documentation

- [Getting started](docs/getting-started.md) - requirements, installation, the Content Language field, page caches and
  detecting your first pages
- [How it works](docs/how-it-works.md) - how pages are read and detected, how hreflang values are written, and
  limitations
- [Settings](docs/settings.md) - every setting, the AI providers, the prompt and the hreflang format

## ⚠️ Disclaimer

Each detection is a request to your AI provider, billed to your account. The defaults keep that cost down, with a small
model, 2,000 characters per page and unchanged pages skipped, but we take no responsibility for API usage that results
from mistakes, bugs or security vulnerabilities in this plugin, so use it at your own risk.

## 🙋 Support

If you have any issues or questions, please submit them on
[GitHub](https://github.com/heavymetalavo/craft-ai-hreflang/issues).
# craft-ai-hreflang
