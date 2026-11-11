<?php

namespace heavymetalavo\aihreflang\jobs;

use Craft;
use craft\base\Batchable;
use craft\base\ElementInterface;
use craft\db\QueryBatcher;
use heavymetalavo\aihreflang\AiHreflang;

/**
 * Detects the language of every page of one element type on a site, for pages saved before the plugin was installed,
 * or from the utility.
 *
 * @package heavymetalavo\aihreflang\jobs
 */
class DetectLanguages extends BaseDetectJob
{
    public int $siteId;

    /**
     * @var class-string<ElementInterface>
     */
    public string $elementType;

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        $query = AiHreflang::getInstance()->detector
            ->pagesQuery($this->elementType, $this->siteId)
            ->orderBy(['elements.id' => SORT_ASC]);

        return new QueryBatcher($query);
    }

    /**
     * @inheritdoc
     */
    protected function element(mixed $item): ?ElementInterface
    {
        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return self::describe($this->siteId, $this->elementType, $this->force, $this->includeChosen);
    }

    /**
     * The description of the job for an element type's pages on a site, which tells pending jobs for the same pages
     * apart from others.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public static function describe(int $siteId, string $elementType, bool $force, bool $includeChosen = false): string
    {
        $params = [
            'type' => $elementType::pluralLowerDisplayName(),
            'site' => Craft::$app->getSites()->getSiteById($siteId, true)?->getName() ?? $siteId,
        ];

        if ($includeChosen) {
            return Craft::t('ai-hreflang', 'Re-detecting the languages of all {type} on {site}, including chosen', $params);
        }

        return $force
            ? Craft::t('ai-hreflang', 'Re-detecting the languages of {type} on {site}, keeping chosen', $params)
            : Craft::t('ai-hreflang', 'Detecting the languages of {type} on {site}', $params);
    }
}
