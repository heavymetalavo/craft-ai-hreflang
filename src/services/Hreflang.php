<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\AiHreflang;
use nystudio107\seomatic\events\AddDynamicMetaEvent;
use nystudio107\seomatic\helpers\DynamicMeta;
use nystudio107\seomatic\models\MetaLink;
use nystudio107\seomatic\Seomatic;

/**
 * Builds hreflang values from each site's Content Language, and puts them in SEOmatic's alternate links.
 *
 * @package heavymetalavo\aihreflang\services
 */
class Hreflang extends Component
{
    /**
     * The language part of a locale, e.g. `fr` for `fr-FR`.
     */
    public function getLanguageCode(string $locale): string
    {
        return strtolower(explode('-', str_replace('_', '-', $locale))[0]);
    }

    /**
     * The hreflang for a page on a site, written in the given language, in the format of the site's language setting
     * (e.g. `fr-FR`). The site's region is kept, so English content on the French site is `en-FR`, unless the
     * `hreflangFormat` setting is `language`, which gives the language alone (`en`).
     *
     * @param string|null $contentLanguage BCP 47 language subtag, e.g. `en`, or null to use the site's language
     */
    public function getHreflang(Site $site, ?string $contentLanguage): string
    {
        $siteHreflang = str_replace('_', '-', $site->language);
        $parts = explode('-', $siteHreflang);

        if (AiHreflang::getInstance()->getSettings()->hreflangFormat === 'language') {
            return $contentLanguage ?? strtolower($parts[0]);
        }

        if ($contentLanguage === null || $contentLanguage === strtolower($parts[0])) {
            return $siteHreflang;
        }

        return count($parts) > 1 ? $contentLanguage . '-' . end($parts) : $contentLanguage;
    }

    /**
     * A label for the hreflang of a page on a site, naming the language for the site's region, e.g.
     * "English (Germany) — en-DE".
     *
     * @param string|null $contentLanguage BCP 47 language subtag, e.g. `en`, or null to use the site's language
     */
    public function getLabel(Site $site, ?string $contentLanguage): string
    {
        $hreflang = $this->getHreflang($site, $contentLanguage);
        $name = Craft::$app->getI18n()->getLocaleById($hreflang)->getDisplayName(Craft::$app->language);

        return sprintf('%s — %s', $name, $hreflang);
    }

    /**
     * Rewrites the hreflang values of SEOmatic's alternate links in the format of the sites' language settings
     * (`en-GB` rather than SEOmatic's `en-gb`). For a page, each site's value comes from the Content Language of its
     * element's copy on that site. SEOmatic caches the result with the rest of the page's meta, and clears that cache
     * when the element is saved.
     */
    public function updateSeomaticAlternates(AddDynamicMetaEvent $event): void
    {
        /** @var MetaLink|null $alternate */
        $alternate = Seomatic::$plugin->link->get('alternate');

        if ($alternate === null) {
            return;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();
        $languages = $element instanceof ElementInterface ? $this->getContentLanguages($element) : [];

        // The alternate links were built from these localized URLs, in this order, with an `x-default` added after
        // the primary site's.
        $localizedUrls = DynamicMeta::getLocalizedUrls($event->uri, $event->siteId);
        $sites = Craft::$app->getSites();
        $i = 0;

        foreach ($alternate->hreflang as $index => $hreflang) {
            if ($hreflang === 'x-default') {
                continue;
            }

            $site = isset($localizedUrls[$i]) ? $sites->getSiteById($localizedUrls[$i]['id']) : null;
            $i++;

            if ($site !== null) {
                $alternate->hreflang[$index] = $this->getHreflang($site, $languages[$site->id] ?? null);
            }
        }
    }

    /**
     * The Content Language of each site's copy of an element.
     *
     * @return array<int, string|null> Language codes, indexed by site ID
     */
    public function getContentLanguages(ElementInterface $element): array
    {
        $handle = AiHreflang::FIELD_HANDLE;
        $languages = [];

        $siteElements = $element::find()
            ->id($element->getCanonicalId())
            ->site('*')
            ->unique(false)
            ->status(null)
            ->all();

        foreach ($siteElements as $siteElement) {
            if ($siteElement->getFieldLayout()?->getFieldByHandle($handle) === null) {
                continue;
            }

            /** @var ContentLanguageData $value */
            $value = $siteElement->getFieldValue($handle);
            $languages[$siteElement->siteId] = $value->language;
        }

        return $languages;
    }
}
