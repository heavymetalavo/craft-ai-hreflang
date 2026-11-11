<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * Detects the language of a page on one of its element's sites, after the element was saved.
 *
 * A job is queued for each site, because content that isn't translated per site changes on them all, and so a failure
 * on one site can be retried without sending the others again. A site whose text hasn't changed isn't sent to the AI
 * provider.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
class DetectElementLanguage extends BaseJob
{
    public int $elementId;

    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType;

    public int $siteId;

    /**
     * @inheritdoc
     * @throws \Throwable If the language couldn't be detected
     */
    public function execute($queue): void
    {
        $detector = AiHreflang::getInstance()->detector;

        $element = $this->elementType::find()
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->status($detector->getLiveStatus($this->elementType))
            ->one();

        if ($element !== null && $detector->hasField($element)) {
            $detector->detect($element);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('ai-hreflang', 'Detecting the language of {type} {id} on {site}', [
            'type' => $this->elementType::lowerDisplayName(),
            'id' => $this->elementId,
            'site' => Craft::$app->getSites()->getSiteById($this->siteId, true)?->getName() ?? $this->siteId,
        ]);
    }
}
