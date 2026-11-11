<?php

namespace heavymetalavo\aihreflang\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\services\Detector;

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
        $otherLanguagePages = $plugin->detector->getOtherLanguagePages(($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $total = $otherLanguagePages['total'];
        $pageUrl = fn(int $page) => UrlHelper::cpUrl('utilities/' . self::id(), ['page' => $page]);

        return Craft::$app->getView()->renderTemplate('ai-hreflang/_utility', [
            'stats' => $plugin->detector->getStats(),
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
}
