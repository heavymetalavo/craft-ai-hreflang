# 🚀 Getting started

[← README](../README.md) · **Getting started** · [How it works](how-it-works.md) · [Settings](settings.md)

How to install AI Hreflang, set it up and detect your first pages. For the overview, see the
[README](../README.md).

## 📋 Requirements

- Craft CMS 5.8 or later
- PHP 8.2 or later
- SEOmatic, which outputs the hreflang tags
- An API key for Anthropic, OpenAI, Langdock or OpenRouter, see [Settings](settings.md#-ai-providers)

## 📥 Installation

Install it with Composer:

```sh
composer require heavymetalavo/craft-ai-hreflang
./craft plugin/install ai-hreflang
```

For local development, use a Composer path repository with `"symlink": true`.

## 🏷️ The Content Language field

Installing the plugin creates the **Content Language** field (`contentLanguage`). In the field layout designer it's
listed under **Custom Fields**, and its field type, **Content Language (hreflang)**, comes up when you search for
"hreflang".

When the plugin is installed by applying the project config, the field comes from the project config instead. Both use
the same UID, so they never conflict.

Add the field to the field layout of each entry type, category group or other element type with public pages, e.g. at
the top of an **SEO** tab. Only elements with the field are detected. Assets, users and globals have no pages, so they
can't be.

The field shows the page's language on the site being edited: the detected language, or blank before it has been
detected. Editors can choose another language from the searchable list, which then isn't detected again. Choosing the
blank option detects it again the next time the page is saved.

## 🎯 The page's content

Only the page's main content is read, so menus and cookie banners in the site's language can't skew the result. By
default that's its `<main>` element. If your templates wrap the content in something else, set its CSS selector, such
as `#content`, in the **Content Selector** setting. See [Settings](settings.md#-the-pages-content).

## 🧊 Page caches

Pages are fetched in the queue, so a page's cache must be cleared before it's read, or the old copy's text is read.

**With Blitz** refreshing its cache (its default), pages are detected once Blitz has refreshed them: Blitz's refresh
job queues a detection job for each page it refreshed that has the Content Language field, after clearing it. So
detection follows Blitz's own rules for which pages and sites a save affects, and also runs when a related entry
changes, a scheduled entry goes live, or a site's or URL's cache is refreshed from Blitz's utility. Clearing, flushing
or generating the cache, and refreshing all of it, don't detect pages, and nor does saving a page without changes,
which Blitz doesn't refresh.

**Without Blitz**, or with its refreshing turned off, a save queues jobs at the end of the request, for the sites the
save changed.

Either way, the jobs have a lower priority (2048, the **Queue Priority** setting) than Blitz's refresh and generate jobs
(10 and 100 by default), so they run after them.

- Keep **Queue Priority** higher than the priorities of any jobs that clear page caches, if they've been changed.
- Blitz's **Expire** refresh modes keep serving a page's expired copy until it's regenerated, so with them a page can
  be read before its new copy exists. **Clear** and **Clear and generate** don't.
- A CDN that keeps serving an old copy after a save would have the same effect.

## 🔍 Detecting your first pages

Pages saved before the plugin was installed haven't been detected yet. Open **Utilities → AI Hreflang**, which
shows each site's coverage (detected pages out of all its pages) and the hreflang values its pages use, with buttons to
detect them for one site or all sites:

- **Detect missing** sends only pages that haven't been detected or whose text has changed.
- **Re-detect all** sends every page again.

Below, a paginated table lists the pages that are in another language on at least one site, with the hreflang each
site gives them. Using the utility needs its permission.

To detect a single page straight away, use **Detect content language** in its action menu (…) on the site being edited.
