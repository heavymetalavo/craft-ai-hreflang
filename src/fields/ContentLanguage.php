<?php

namespace extreme\aihreflangdetector\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\models\Site;
use extreme\aihreflangdetector\fields\data\ContentLanguageData;
use extreme\aihreflangdetector\AiHreflangDetector;
use yii\db\Schema;

/**
 * Content Language field.
 *
 * Holds the language a page's content is written in, for each site. It is detected automatically when the page is
 * saved, unless an editor chooses the language. The page's hreflang is built from it.
 *
 * @package extreme\aihreflangdetector\fields
 */
class ContentLanguage extends Field
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('ai-hreflang-detector', 'Content Language');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): string
    {
        return 'language';
    }

    /**
     * @inheritdoc
     */
    public static function phpType(): string
    {
        return ContentLanguageData::class;
    }

    /**
     * The language comes first, so it is the value element queries filter on (e.g. `.contentLanguage('en')`).
     *
     * @inheritdoc
     */
    public static function dbType(): array
    {
        return [
            'language' => Schema::TYPE_STRING,
            'auto' => Schema::TYPE_BOOLEAN,
            'hash' => Schema::TYPE_STRING,
            'detectedAt' => Schema::TYPE_DATETIME,
        ];
    }

    /**
     * Each site's copy of a page can be written in a different language.
     *
     * @inheritdoc
     */
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_SITE];
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof ContentLanguageData) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return new ContentLanguageData();
        }

        return new ContentLanguageData([
            'language' => ($value['language'] ?? null) ?: null,
            'auto' => (bool)($value['auto'] ?? true),
            'hash' => ($value['hash'] ?? null) ?: null,
            'detectedAt' => DateTimeHelper::toDateTime($value['detectedAt'] ?? null) ?: null,
        ]);
    }

    /**
     * The input posts `mode`: blank to detect the language automatically, or the language the editor chose. The last
     * detection is posted alongside it, so switching back to automatic keeps it until the page is detected again.
     *
     * @inheritdoc
     */
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if (!is_array($value) || !array_key_exists('mode', $value)) {
            return $this->normalizeValue($value, $element);
        }

        if ($value['mode'] === '') {
            return $this->normalizeValue([...$value, 'auto' => true], $element);
        }

        return new ContentLanguageData([
            'language' => $value['mode'],
            'auto' => false,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        /** @var ContentLanguageData $value */
        if ($value->language === null && $value->auto && $value->hash === null) {
            return null;
        }

        return [
            'language' => $value->language,
            'auto' => $value->auto,
            'hash' => $value->hash,
            'detectedAt' => $value->detectedAt ? DateTimeHelper::toIso8601($value->detectedAt) : null,
        ];
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof ContentLanguageData || $value->language === null;
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        /** @var ContentLanguageData $value */
        $site = $element?->getSite() ?? Craft::$app->getSites()->getCurrentSite();
        $hreflang = AiHreflangDetector::getInstance()->hreflang;

        return Craft::$app->getView()->renderTemplate('ai-hreflang-detector/_field/input', [
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $value,
            'languageOptions' => $this->languageOptions($value, $site),
            'hreflang' => $hreflang->getHreflang($site, $value->language),
            'hreflangLabel' => $hreflang->getLabel($site, $value->language),
            'minimumWords' => AiHreflangDetector::getInstance()->getSettings()->minimumWords,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        /** @var ContentLanguageData $value */
        if ($value->language === null) {
            return '';
        }

        $label = Html::encode($value->getLanguageName());

        return $value->auto ? $label : $label . ' ' . Html::tag('span', Craft::t('ai-hreflang-detector', '(chosen)'), ['class' => 'light']);
    }

    /**
     * The languages an editor can choose: those of the sites, plus the page's current language. Each is labelled with
     * the language for the page's site and the hreflang it gives the page, e.g. "English (Germany) — en-DE".
     *
     * @return array<string, string> Labels, indexed by language code
     */
    private function languageOptions(ContentLanguageData $value, Site $site): array
    {
        $hreflang = AiHreflangDetector::getInstance()->hreflang;
        $codes = array_map(
            fn(Site $site) => $hreflang->getLanguageCode($site->language),
            Craft::$app->getSites()->getAllSites(),
        );

        if ($value->language !== null) {
            $codes[] = $value->language;
        }

        $options = [];
        foreach (array_unique($codes) as $code) {
            $options[$code] = $hreflang->getLabel($site, $code);
        }
        asort($options);

        return $options;
    }
}
