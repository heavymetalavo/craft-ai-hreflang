<?php

namespace heavymetalavo\aihreflang;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineMenuItemsEvent;
use craft\events\ModelEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use heavymetalavo\aihreflang\fields\ContentLanguage;
use heavymetalavo\aihreflang\models\Settings;
use heavymetalavo\aihreflang\services\Ai;
use heavymetalavo\aihreflang\services\Detector;
use heavymetalavo\aihreflang\services\Hreflang;
use heavymetalavo\aihreflang\utilities\AiHreflangUtility;
use nystudio107\seomatic\events\AddDynamicMetaEvent;
use nystudio107\seomatic\helpers\DynamicMeta;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\services\RefreshCacheService;
use yii\base\Event;

/**
 * AI Hreflang plugin
 *
 * Detects the language each page is written in with AI, and sets its hreflang to match. A page on the French site
 * that is still in English gets `en-FR` rather than `fr-FR`.
 *
 * @method static AiHreflang getInstance()
 * @method Settings getSettings()
 * @property-read Ai $ai
 * @property-read Detector $detector
 * @property-read Hreflang $hreflang
 */
class AiHreflang extends Plugin
{
    /**
     * Handle of the Content Language field the plugin creates.
     */
    public const FIELD_HANDLE = 'contentLanguage';

    /**
     * UID of the Content Language field. It is fixed so the field created on install is the same field as the one in
     * the project config, whichever comes first.
     */
    public const FIELD_UID = '68577c41-2d97-4285-a894-b8f0dd64a93b';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'ai' => Ai::class,
                'detector' => Detector::class,
                'hreflang' => Hreflang::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = ContentLanguage::class;
            }
        );

        // Not in afterInstall(), when the plugin isn't installed yet, so Craft won't save a field of its type
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function(PluginEvent $event) {
                if ($event->plugin === $this) {
                    $this->createField();
                }
            }
        );

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = AiHreflangUtility::class;
            }
        );

        // After propagating, so the element has been saved on all its sites
        Event::on(
            Element::class,
            Element::EVENT_AFTER_PROPAGATE,
            function(ModelEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $this->detector->handleElementSave($element);
            }
        );

        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ACTION_MENU_ITEMS,
            function(DefineMenuItemsEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                $this->detector->addActionMenuItem($element, $event);
            }
        );

        // With Blitz refreshing its cache, pages are detected once Blitz has refreshed them (see Detector::usesBlitz())
        if (class_exists(RefreshCacheService::class)) {
            Event::on(
                RefreshCacheService::class,
                RefreshCacheService::EVENT_AFTER_REFRESH_CACHE,
                function(RefreshCacheEvent $event) {
                    $this->detector->handleBlitzRefresh($event->siteUris);
                }
            );
        }

        if (class_exists(DynamicMeta::class)) {
            Event::on(
                DynamicMeta::class,
                DynamicMeta::EVENT_ADD_DYNAMIC_META,
                function(AddDynamicMetaEvent $event) {
                    $this->hreflang->updateSeomaticAlternates($event);
                }
            );
        }
    }

    /**
     * Creates the Content Language field, unless the project config is being applied, which creates it from the
     * config instead.
     */
    private function createField(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $fields = Craft::$app->getFields();

        if (
            $projectConfig->getIsApplyingExternalChanges()
            || $projectConfig->readOnly
            || $fields->getFieldByUid(self::FIELD_UID)
            || $fields->getFieldByHandle(self::FIELD_HANDLE)
        ) {
            return;
        }

        $field = $fields->createField([
            'type' => ContentLanguage::class,
            'uid' => self::FIELD_UID,
            'name' => 'Content Language',
            'handle' => self::FIELD_HANDLE,
            'instructions' => 'The language this page is written in, which sets its hreflang. It is detected when the page is saved.',
            'searchable' => false,
            'translationMethod' => Field::TRANSLATION_METHOD_SITE,
        ]);

        if (!$fields->saveField($field)) {
            Craft::error('Couldn’t create the Content Language field: ' . implode(', ', $field->getFirstErrors()), __METHOD__);
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('ai-hreflang/_settings', [
            'settings' => $this->getSettings(),
            'ai' => $this->ai,
        ]);
    }
}
