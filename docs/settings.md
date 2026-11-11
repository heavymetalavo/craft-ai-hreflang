# ⚙️ Settings

[← README](../README.md) · [Getting started](getting-started.md) · [How it works](how-it-works.md) · **Settings**

After installation, configure the plugin at **Settings → Plugins → AI Hreflang**.

## 📊 Settings overview

| Setting | Description |
| --- | --- |
| AI Provider | Anthropic, OpenAI, Langdock or OpenRouter. Only the chosen provider's settings are shown |
| API Key | The chosen provider's key, as an environment variable such as `$OPENROUTER_API_KEY`. A key typed in is saved in plain text in the project config, and the setting warns about it |
| Model | A small model is enough, e.g. `claude-haiku-5-5`, `gpt-6-luna` or `openai/gpt-6-luna` |
| Langdock Model Author | Anthropic or OpenAI, which picks the Langdock API requests go to |
| Reasoning Effort | OpenAI, Langdock (OpenAI models) and OpenRouter only. Only sent to OpenAI reasoning models (`low` by default). **Don't send** sends none to any model |
| Prompt | The instructions sent with each page's text, see [The prompt](#-the-prompt) |
| Detect when a page is saved | On by default |
| Maximum Characters | How much of a page's text is sent (2,000 by default) |
| Queue Priority | The priority of the plugin's queue jobs (2048 by default), higher than the jobs that clear page caches, see [Page caches](getting-started.md#-page-caches) |
| Content Selector | The CSS selector of the element whose text is read, `main` by default, see [The page's content](#-the-pages-content) |
| hreflang Format | Match each site's language setting (the default) or language only, see [hreflang](#-hreflang) |

## 🤖 AI providers

The providers are the same as the AI Alt Text plugin's, and requests are made the same way, but with this plugin's own
settings:

| Provider | API |
| --- | --- |
| Anthropic | Messages API, `https://api.anthropic.com/v1/messages` |
| OpenAI | Responses API, `https://api.openai.com/v1/responses`, with `store: false` |
| Langdock | Anthropic models through its Messages API, OpenAI models through its Responses API, in the EU region |
| OpenRouter | Responses API, `https://openrouter.ai/api/v1/responses` |

## 💬 The prompt

The prompt is sent with each page's text. Leave it blank to use the default, which is shown as the field's placeholder,
or use **Reset to default** to put the default in and edit it. The reply must be an ISO 639-1 language code such as
`en`, or `und` when the language can't be told, which leaves the page on its site's own hreflang. Any other reply
fails.

The page's text is sent between `<page_text>` tags. The default prompt tells the model to ignore instructions inside
them. Keep that in a prompt of your own.

Changing the provider, model or prompt makes every page count as changed, so the next save or **Detect missing**
sends it again.

## 📄 The page's content

**Content Selector** is the CSS selector of the element whose text is read from each page, `main` by default. Any CSS
selector works, such as `#content`, `.page-body` or `article.post`, and the first element it matches is read. Pick the
element that holds the page's own content, without the menus, footer or cookie banner around it, which are in the
site's language and would bias detection towards it.

A page where nothing matches, or a blank selector, is read from its `<body>`, without its header and footer.

## 🏷️ hreflang

**hreflang Format** sets how each page's hreflang is written:

- **Match each site's language setting** (the default) keeps the region if the site's language has one, so English
  on a `fr-FR` site is `en-FR`, and on a `fr` site is `en`.
- **Language only** always drops the region, so English on any site is `en`.

With **Language only**, sites in the same language, such as `en-GB` and `en-US`, give the same hreflang, which search
engines can't tell apart, so it suits sites with one site per language.

In either format, a page whose hreflang would be the same as another site's copy of it keeps its site's own hreflang.
See [When two copies would share an hreflang](how-it-works.md#-when-two-copies-would-share-an-hreflang).
