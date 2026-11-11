<?php

namespace heavymetalavo\aihreflang\controllers;

use Craft;
use craft\web\Controller;
use heavymetalavo\aihreflang\fields\data\ContentLanguageData;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\services\Detector;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Detects the language of a single page, from its element's action menu.
 *
 * @package heavymetalavo\aihreflang\controllers
 */
class ElementController extends Controller
{
    /**
     * Detects the language of a page on one site now, even if its text hasn't changed.
     *
     * Posted params:
     * - `elementId`: the page's element, or a draft of it, whose canonical element is detected
     * - `siteId`: the site
     */
    public function actionDetect(): ?Response
    {
        $this->requirePostRequest();

        $element = Craft::$app->getElements()->getElementById(
            (int)$this->request->getRequiredBodyParam('elementId'),
            null,
            (int)$this->request->getRequiredBodyParam('siteId'),
            ['drafts' => null, 'provisionalDrafts' => null, 'status' => null],
        );

        if ($element === null) {
            throw new NotFoundHttpException('Element not found');
        }

        $element = $element->getCanonical();

        if (!$element->canSave(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('User not permitted to save this element.');
        }

        $plugin = AiHreflang::getInstance();

        try {
            $result = $plugin->detector->detect($element, true);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        if ($result === Detector::RESULT_CHOSEN) {
            return $this->asFailure(Craft::t('ai-hreflang', 'The language was chosen by an editor, so it isn’t detected.'));
        }

        if ($result === Detector::RESULT_TOO_SHORT) {
            return $this->asFailure(Craft::t('ai-hreflang', 'The page has fewer than {count, number} words, so it keeps its site’s hreflang.', [
                'count' => Detector::MINIMUM_WORDS,
            ]));
        }

        if ($result === Detector::RESULT_NO_PAGE) {
            return $this->asFailure(Craft::t('ai-hreflang', 'The page isn’t live, so it can’t be fetched to detect its language.'));
        }

        if ($result === Detector::RESULT_UNDETERMINED) {
            return $this->asFailure(Craft::t('ai-hreflang', 'The language couldn’t be told, so the page keeps its site’s hreflang.'));
        }

        /** @var ContentLanguageData $value */
        $value = $element->getFieldValue(AiHreflang::FIELD_HANDLE);

        return $this->asSuccess(Craft::t('ai-hreflang', 'Detected {language}, so the hreflang is {hreflang}.', [
            'language' => $value->getLanguageName(),
            'hreflang' => $plugin->hreflang->getHreflang($element->getSite(), $value->language),
        ]));
    }
}
