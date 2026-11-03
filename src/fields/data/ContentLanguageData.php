<?php

namespace extreme\aihreflangdetector\fields\data;

use Craft;
use DateTime;
use yii\base\BaseObject;

/**
 * The value of a Content Language field: the language a page is written in, and how it was decided.
 *
 * @package extreme\aihreflangdetector\fields\data
 */
class ContentLanguageData extends BaseObject
{
    /**
     * BCP 47 language subtag (e.g. `en`), or null if it hasn't been detected yet.
     *
     * @var string|null
     */
    public ?string $language = null;

    /**
     * Whether the language is detected automatically. False when an editor chose it.
     *
     * @var bool
     */
    public bool $auto = true;

    /**
     * Hash of the text the language was detected from, so unchanged text isn't sent to the AI provider again.
     *
     * @var string|null
     */
    public ?string $hash = null;

    /**
     * When the language was last detected.
     *
     * @var DateTime|null
     */
    public ?DateTime $detectedAt = null;

    /**
     * The language's name in the CP's language, e.g. "English".
     */
    public function getLanguageName(): ?string
    {
        if ($this->language === null) {
            return null;
        }

        return Craft::$app->getI18n()->getLocaleById($this->language)->getDisplayName(Craft::$app->language);
    }

    public function __toString(): string
    {
        return (string)$this->language;
    }
}
