<?php

namespace heavymetalavo\aihreflang\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\models\Site;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\services\Detector;
use yii\db\Schema;

/**
 * Content Language field.
 *
 * Holds the language a page's content is written in, for each site. It is detected automatically when the page is
 * saved, unless an editor chooses the language. The page's hreflang is built from it.
 *
 * @package heavymetalavo\aihreflang\fields
 */
class ContentLanguage extends Field
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('ai-hreflang', 'Content Language (hreflang)');
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

        // The dropdown shows the detected language, so submitting it unchanged keeps it detected. The blank option
        // clears it, so it's detected again, and any other language is chosen by an editor.
        if ($value['mode'] === '') {
            return new ContentLanguageData(['auto' => true]);
        }

        if ($value['mode'] === ($value['language'] ?? '') && !empty($value['auto'])) {
            return $this->normalizeValue($value, $element);
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
        $hreflang = AiHreflang::getInstance()->hreflang;

        return Craft::$app->getView()->renderTemplate('ai-hreflang/_field/input', [
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $value,
            'languageOptions' => $this->languageOptions($value, $site),
            'hreflang' => $hreflang->getHreflang($site, $value->language),
            'minimumWords' => Detector::MINIMUM_WORDS,
            'sitePublic' => AiHreflang::getInstance()->detector->isSitePublic($site),
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

        return $value->auto ? $label : $label . ' ' . Html::tag('span', Craft::t('ai-hreflang', '(chosen)'), ['class' => 'light']);
    }

    /**
     * The languages an editor can choose: the sites' languages first, then every other language Craft knows, plus the
     * page's current language. Each is labelled with the language for the page's site and the hreflang it gives the
     * page, e.g. "English (Germany) — en-DE".
     *
     * @return array<int, array{optgroup: string}|array{label: string, value: string}> Options for a select
     */
    private function languageOptions(ContentLanguageData $value, Site $site): array
    {
        $hreflang = AiHreflang::getInstance()->hreflang;
        $siteCodes = array_unique(array_map(
            fn(Site $site) => $hreflang->getLanguageCode($site->language),
            Craft::$app->getSites()->getAllSites(),
        ));

        // Locale IDs without a region or script, such as `it`, are the languages
        $otherCodes = array_filter(Craft::$app->getI18n()->getAllLocaleIds(), fn(string $id) => preg_match('/^[a-z]{2,3}$/', $id));

        if ($value->language !== null) {
            $otherCodes[] = $value->language;
        }

        $toOptions = function(array $codes) use ($hreflang, $site): array {
            $labels = [];
            foreach (array_unique($codes) as $code) {
                $labels[$code] = $hreflang->getLabel($site, $code);
            }
            asort($labels);

            return array_map(fn($code, $label) => ['label' => $label, 'value' => $code], array_keys($labels), $labels);
        };

        return [
            ['optgroup' => Craft::t('ai-hreflang', 'Site languages')],
            ...$toOptions($siteCodes),
            ['optgroup' => Craft::t('ai-hreflang', 'Other languages')],
            ...$toOptions(array_diff($otherCodes, $siteCodes)),
        ];
    }
}
