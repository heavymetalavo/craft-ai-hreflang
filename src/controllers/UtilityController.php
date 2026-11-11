<?php

namespace heavymetalavo\aihreflang\controllers;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\queue\QueueInterface;
use craft\web\Controller;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\jobs\DetectLanguages;
use heavymetalavo\aihreflang\utilities\AiHreflangUtility;
use yii\web\Response;

/**
 * Actions of the AI Hreflang utility.
 *
 * @package heavymetalavo\aihreflang\controllers
 */
class UtilityController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('utility:' . AiHreflangUtility::id());

        return parent::beforeAction($action);
    }

    /**
     * Queues detection of the pages on one site, or on all sites.
     *
     * Posted params:
     * - `siteId`: the site, or blank for all sites
     * - `force`: whether to go through every page, including those already detected, rather than only the pages that
     *   haven't been detected
     * - `includeChosen`: whether to replace languages editors chose too (implies `force`)
     */
    public function actionDetect(): ?Response
    {
        $this->requirePostRequest();

        $error = AiHreflang::getInstance()->ai->getConfigurationError();
        if ($error !== null) {
            return $this->asFailure($error);
        }

        $siteId = $this->request->getBodyParam('siteId');
        $sites = Craft::$app->getSites();
        $siteIds = $siteId ? [(int)$siteId] : $sites->getAllSiteIds(false);
        $includeChosen = (bool)$this->request->getBodyParam('includeChosen');
        $force = $includeChosen || $this->request->getBodyParam('force');

        foreach ($siteIds as $id) {
            if (!$sites->getSiteById($id)?->getEnabled()) {
                return $this->asFailure(Craft::t('ai-hreflang', 'Site {id} doesn’t exist or is disabled.', ['id' => $id]));
            }
        }

        $pending = $this->pendingJobDescriptions();
        $priority = AiHreflang::getInstance()->getSettings()->queuePriority;
        $queued = 0;

        foreach ($siteIds as $id) {
            foreach (array_keys(AiHreflang::getInstance()->detector->getElementTypes()) as $elementType) {
                // A job for the same pages that's still waiting or running would send them all again. Its later
                // batches add "(batch 2 of 3)" to the description.
                $description = DetectLanguages::describe($id, $elementType, $force, $includeChosen);
                foreach ($pending as $pendingDescription) {
                    if ($pendingDescription === $description || str_starts_with($pendingDescription, "$description (")) {
                        continue 2;
                    }
                }

                QueueHelper::push(new DetectLanguages(['siteId' => $id, 'elementType' => $elementType, 'force' => $force, 'includeChosen' => $includeChosen]), $priority);
                $queued++;
            }
        }

        if ($queued === 0) {
            return $this->asSuccess(Craft::t('ai-hreflang', 'Detection is already queued for these pages.'));
        }

        return $this->asSuccess(Craft::t('ai-hreflang', 'Detection queued for {count, plural, =1{one site} other{# sites}}.', [
            'count' => count($siteIds),
        ]));
    }

    /**
     * The descriptions of the queue's jobs that are waiting or running.
     *
     * @return string[]
     */
    private function pendingJobDescriptions(): array
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof QueueInterface) {
            return [];
        }

        $descriptions = [];

        foreach ($queue->getJobInfo() as $job) {
            if (in_array($job['status'], [\yii\queue\Queue::STATUS_WAITING, \yii\queue\Queue::STATUS_RESERVED], true)) {
                $descriptions[] = (string)$job['description'];
            }
        }

        return $descriptions;
    }
}
