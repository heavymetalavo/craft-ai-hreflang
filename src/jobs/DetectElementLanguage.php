<?php

namespace extreme\aihreflangdetector\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use extreme\aihreflangdetector\AiHreflangDetector;
use extreme\aihreflangdetector\services\Detector;
use Throwable;

/**
 * Detects the language of an entry on each of its sites, after it was saved.
 *
 * Every site is checked because content that isn't translated per site changes on them all. Sites whose text hasn't
 * changed aren't sent to the AI provider.
 *
 * @package extreme\aihreflangdetector\jobs
 */
class DetectEntryLanguage extends BaseJob
{
    public int $entryId;

    /**
     * @inheritdoc
     * @throws \Exception If the language couldn't be detected on a site
     */
    public function execute($queue): void
    {
        $detector = AiHreflangDetector::getInstance()->detector;
        $errors = [];

        $entries = Entry::find()
            ->id($this->entryId)
            ->site('*')
            ->unique(false)
            ->status(Detector::STATUSES)
            ->all();

        foreach ($entries as $entry) {
            if (!$detector->hasField($entry)) {
                continue;
            }

            try {
                $detector->detect($entry);
            } catch (Throwable $e) {
                Craft::error(sprintf('Couldn’t detect the language of entry %d on site %d: %s', $entry->id, $entry->siteId, $e->getMessage()), __METHOD__);
                $errors[] = $entry->getSite()->getName() . ': ' . $e->getMessage();
            }
        }

        if ($errors) {
            throw new \Exception(implode("\n", $errors));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('ai-hreflang-detector', 'Detecting the language of entry {id}', ['id' => $this->entryId]);
    }
}
