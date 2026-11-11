<?php

namespace heavymetalavo\aihreflang\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\services\Detector;
use heavymetalavo\aihreflang\web\assets\utility\UtilityAsset;

/**
 * Shows how many pages have had their language detected, and queues detection for pages saved before the plugin was
 * installed.
 *
 * @package heavymetalavo\aihreflang\utilities
 */
class AiHreflangUtility extends Utility
{
    /**
     * How many pages in another language are listed on each page of the utility.
     */
    private const PAGE_SIZE = 50;

    /**
     * Colours for the languages in the hreflang bars, given out in alphabetical order of language, so a language is
     * the same colour on every site.
     */
    private const LANGUAGE_COLORS = [
        'var(--blue-500, #3b82f6)',
        'var(--violet-500, #8b5cf6)',
        'var(--pink-500, #ec4899)',
        'var(--teal-500, #14b8a6)',
        'var(--indigo-500, #6366f1)',
        'var(--lime-500, #84cc16)',
        'var(--cyan-500, #06b6d4)',
        'var(--fuchsia-500, #d946ef)',
    ];

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('ai-hreflang', 'AI Hreflang');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'ai-hreflang';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'language';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $plugin = AiHreflang::getInstance();
        $page = max(1, (int)Craft::$app->getRequest()->getQueryParam('page', 1));
        Craft::$app->getView()->registerAssetBundle(UtilityAsset::class);
        $otherLanguagePages = $plugin->report->getOtherLanguagePages(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $total = $otherLanguagePages['total'];
        $stats = $plugin->report->getStats();
        $pageUrl = fn(int $page) => UrlHelper::cpUrl('utilities/' . self::id(), ['page' => $page]);

        return Craft::$app->getView()->renderTemplate('ai-hreflang/_utility', [
            'stats' => $stats,
            'languageColors' => self::languageColors($stats),
            'otherLanguagePages' => $otherLanguagePages['rows'],
            'pageInfo' => [
                'first' => min($total, ($page - 1) * self::PAGE_SIZE + 1),
                'last' => min($total, $page * self::PAGE_SIZE),
                'total' => $total,
                'prevUrl' => $page > 1 ? $pageUrl($page - 1) : null,
                'nextUrl' => $page * self::PAGE_SIZE < $total ? $pageUrl($page + 1) : null,
            ],
            'sites' => Craft::$app->getSites()->getAllSites(false),
            'maxCharacters' => $plugin->getSettings()->maxCharacters,
            'contentSelector' => $plugin->getSettings()->contentSelector,
            'minimumWords' => Detector::MINIMUM_WORDS,
            'configurationError' => $plugin->ai->getConfigurationError(),
            'provider' => $plugin->ai->getProviderLabel(),
            'model' => $plugin->ai->getModel(),
            'hasField' => !empty($plugin->detector->getElementTypes()),
        ]);
    }

    /**
     * A colour for each language in the stats' hreflang values. Languages after the palette runs out use the
     * stylesheet's colour for other languages.
     *
     * @return array<string, string>
     */
    private static function languageColors(array $stats): array
    {
        $languages = [];

        foreach ($stats as $row) {
            foreach (array_keys($row['hreflangs']) as $code) {
                $languages[] = strtolower(explode('-', (string)$code)[0]);
            }
        }

        $languages = array_values(array_unique($languages));
        sort($languages);
        $languages = array_slice($languages, 0, count(self::LANGUAGE_COLORS));

        return array_combine($languages, array_slice(self::LANGUAGE_COLORS, 0, count($languages)));
    }
}
