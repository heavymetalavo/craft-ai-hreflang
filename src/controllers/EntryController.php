<?php

namespace extreme\aihreflangdetector\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use extreme\aihreflangdetector\fields\data\ContentLanguageData;
use extreme\aihreflangdetector\AiHreflangDetector;
use extreme\aihreflangdetector\services\Detector;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Detects the language of a single entry, from its action menu.
 *
 * @package extreme\aihreflangdetector\controllers
 */
class EntryController extends Controller
{
    /**
     * Detects the language of an entry on one site now, even if its text hasn't changed.
     *
     * Posted params:
     * - `entryId`: the entry, or a draft of it, whose canonical entry is detected
     * - `siteId`: the site
     */
    public function actionDetect(): ?Response
    {
        $this->requirePostRequest();

        $entry = Entry::find()
            ->id($this->request->getRequiredBodyParam('entryId'))
            ->siteId($this->request->getRequiredBodyParam('siteId'))
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();

        if ($entry === null) {
            throw new NotFoundHttpException('Entry not found');
        }

        $entry = $entry->getCanonical();

        if (!$entry->canSave(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('User not permitted to save this entry.');
        }

        $plugin = AiHreflangDetector::getInstance();

        try {
            $result = $plugin->detector->detect($entry, true);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        if ($result === Detector::RESULT_CHOSEN) {
            return $this->asFailure(Craft::t('ai-hreflang-detector', 'The language was chosen by an editor, so it isn’t detected.'));
        }

        if ($result === Detector::RESULT_TOO_SHORT) {
            return $this->asFailure(Craft::t('ai-hreflang-detector', 'The page has fewer than {count, number} words, so it keeps its site’s hreflang.', [
                'count' => $plugin->getSettings()->minimumWords,
            ]));
        }

        if ($result === Detector::RESULT_UNDETERMINED) {
            return $this->asFailure(Craft::t('ai-hreflang-detector', 'The language couldn’t be told, so the page keeps its site’s hreflang.'));
        }

        /** @var ContentLanguageData $value */
        $value = $entry->getFieldValue(AiHreflangDetector::FIELD_HANDLE);

        return $this->asSuccess(Craft::t('ai-hreflang-detector', 'Detected {language}, so the hreflang is {hreflang}.', [
            'language' => $value->getLanguageName(),
            'hreflang' => $plugin->hreflang->getHreflang($entry->getSite(), $value->language),
        ]));
    }
}
