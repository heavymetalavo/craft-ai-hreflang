<?php

namespace heavymetalavo\aihreflang\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\models\Site;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\services\Detector;
use yii\db\Schema;

/**
 * Content Language field.
 *
 * Holds the language a page's content is written in, for each site. It is detected automatically when the page is
 * saved, unless an editor chooses the language. The page's hreflang is built from it.
 *
 * Editors only choose the mode: automatic, or a language. The detection itself (language, hash and date) is only
 * ever written by detection, never posted from the edit form, so a form or draft opened before a detection can't
 * write an older one back.
 *
 * @package heavymetalavo\aihreflang\fields
 */
class ContentLanguage extends Field
{
    /**
     * Language options for each site, built once per request.
     *
     * @var array<int, array>
     */
    private static array $languageOptions = [];

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
     * The value the input posts for Automatic. Blank (`''`) clears the field.
     */
    public const MODE_AUTOMATIC = 'auto';

    /**
     * The input posts `mode`, one of:
     *
     * - blank: clears the field, so the page counts as not detected yet and is detected again
     * - `auto` (MODE_AUTOMATIC): automatic, keeping the current detection, or after a chosen language, going back to
     *   automatic so the page is detected again
     * - a language: chosen by an editor, unless it's the detected language submitted unchanged, which stays detected
     *
     * @inheritdoc
     */
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if (!is_array($value) || !array_key_exists('mode', $value)) {
            return $this->normalizeValue($value, $element);
        }

        $mode = (string)$value['mode'];
        $current = $element ? $this->currentValue($element) : null;

        if ($mode === '') {
            return new ContentLanguageData();
        }

        if ($mode === self::MODE_AUTOMATIC) {
            return $current !== null && $current->auto ? $current : new ContentLanguageData();
        }

        // The detected language submitted unchanged stays detected, so it isn't sent again
        if ($current !== null && $current->auto && $mode === $current->language) {
            return $current;
        }

        return new ContentLanguageData([
            'language' => $mode,
            'auto' => false,
        ]);
    }

    /**
     * What the input shows for a value: its language, Automatic when it was detected without one (it couldn't be
     * detected, or was too short), or blank when it hasn't been detected.
     */
    public static function inputMode(ContentLanguageData $value): string
    {
        if ($value->language !== null) {
            return $value->language;
        }

        return $value->auto && $value->hash !== null ? self::MODE_AUTOMATIC : '';
    }

    /**
     * Applying a draft would copy the detection the draft was created with onto the page, on every site, as Craft
     * doesn't merge detections into drafts (they're saved as resaves, which aren't tracked). The page's current
     * detection is kept instead, unless the draft changed the field on that site, such as an editor choosing a
     * language, Automatic or blank, which is kept. `duplicateOf` is the draft's copy on the site being saved.
     *
     * @inheritdoc
     */
    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        if ($element->updatingFromDerivative && !$isNew && $element->id) {
            /** @var ContentLanguageData $value */
            $value = $element->getFieldValue($this->handle);
            $draftChangedIt = $element->duplicateOf?->isFieldModified($this->handle) ?? false;

            if ($value->auto && !$draftChangedIt) {
                $page = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->one();
                $pageValue = $page ? AiHreflang::getInstance()->detector->getValue($page) : null;

                if ($pageValue !== null && $pageValue->auto) {
                    $element->setFieldValue($this->handle, $pageValue);
                }
            }
        }

        return parent::beforeElementSave($element, $isNew);
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
        $plugin = AiHreflang::getInstance();

        if ($element !== null) {
            $value = $this->currentValue($element) ?? $value;
        }

        // The hreflang the page has on this site, with any clash with another site's hreflang
        $alternate = $element !== null ? ($plugin->hreflang->getAlternates($element->getCanonical())[$site->id] ?? null) : null;

        return Craft::$app->getView()->renderTemplate('ai-hreflang/_field/input', [
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'value' => $value,
            'mode' => self::inputMode($value),
            'automatic' => self::MODE_AUTOMATIC,
            'languageOptions' => $this->languageOptions($value, $site),
            'hreflang' => $alternate['hreflang'] ?? $plugin->hreflang->getHreflang($site, $value->language),
            'clash' => $alternate['clash'] ?? null,
            'clashSites' => $alternate['clashSites'] ?? [],
            'minimumWords' => Detector::MINIMUM_WORDS,
            'sitePublic' => $plugin->pageReader->isSitePublic($site),
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
     * The element's value, or for a draft or revision in automatic mode, its page's current detection, which may be
     * newer than the one the draft was created with.
     */
    private function currentValue(ElementInterface $element): ?ContentLanguageData
    {
        $value = $element->getFieldValue($this->handle);

        if (!$value instanceof ContentLanguageData) {
            return null;
        }

        if ($value->auto && !$element->getIsCanonical()) {
            $canonicalValue = AiHreflang::getInstance()->detector->getValue($element->getCanonical());

            if ($canonicalValue !== null && $canonicalValue->auto) {
                return $canonicalValue;
            }
        }

        return $value;
    }

    /**
     * The languages an editor can choose: the sites' languages first, then every other language with an ISO 639-1
     * code, which hreflang requires, plus the page's current language. Each is labelled with the language for the
     * page's site and the hreflang it gives the page, e.g. "English (Germany) — en-DE".
     *
     * @return array<int, array{optgroup: string}|array{label: string, value: string}> Options for a select
     */
    private function languageOptions(ContentLanguageData $value, Site $site): array
    {
        $hreflang = AiHreflang::getInstance()->hreflang;

        if (!isset(self::$languageOptions[$site->id])) {
            $siteCodes = array_unique(array_map(
                fn(Site $site) => $hreflang->getLanguageCode($site->language),
                Craft::$app->getSites()->getAllSites(),
            ));

            $otherCodes = array_diff($hreflang->getLanguageCodes(), $siteCodes);

            self::$languageOptions[$site->id] = [
                ['optgroup' => Craft::t('ai-hreflang', 'Site languages')],
                ...$this->toOptions($siteCodes, $site),
                ['optgroup' => Craft::t('ai-hreflang', 'Other languages')],
                ...$this->toOptions($otherCodes, $site),
            ];
        }

        $options = self::$languageOptions[$site->id];

        // A language that isn't listed, such as one chosen before it was left out, is kept
        if ($value->language !== null && !in_array($value->language, array_column($options, 'value'), true)) {
            $options = [...$options, ...$this->toOptions([$value->language], $site)];
        }

        return $options;
    }

    /**
     * @param string[] $codes
     * @return array<int, array{label: string, value: string}>
     */
    private function toOptions(array $codes, Site $site): array
    {
        $hreflang = AiHreflang::getInstance()->hreflang;
        $labels = [];

        foreach (array_unique($codes) as $code) {
            $labels[$code] = $hreflang->getLabel($site, $code);
        }

        asort($labels);

        return array_map(fn($code, $label) => ['label' => $label, 'value' => $code], array_keys($labels), $labels);
    }
}
