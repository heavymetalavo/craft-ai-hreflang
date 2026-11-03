<?php

namespace extreme\aihreflangdetector\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin Settings Model.
 *
 * The AI providers and their settings match the AI Alt Text plugin's: Anthropic, OpenAI, Langdock and OpenRouter.
 *
 * @package extreme\aihreflangdetector\models
 */
class Settings extends Model
{
    /**
     * The AI providers, by the value the `aiProvider` setting takes.
     */
    public const PROVIDERS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'langdock' => 'Langdock',
        'openrouter' => 'OpenRouter',
    ];

    /**
     * Reasoning effort values, blank to send none.
     */
    public const REASONING_EFFORTS = ['', 'none', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    /**
     * The AI provider: `anthropic`, `openai`, `langdock` or `openrouter`.
     *
     * @var string
     */
    public string $aiProvider = '';

    /**
     * Anthropic API key.
     *
     * @var string
     */
    public string $anthropicApiKey = '';

    /**
     * Anthropic model, e.g. `claude-haiku-5-5`.
     *
     * @var string
     */
    public string $anthropicModel = '';

    /**
     * OpenAI API key.
     *
     * @var string
     */
    public string $openAiApiKey = '';

    /**
     * OpenAI model, e.g. `gpt-5-nano`.
     *
     * @var string
     */
    public string $openAiModel = '';

    /**
     * Reasoning effort sent to OpenAI reasoning models, or blank to send none.
     *
     * @var string
     */
    public string $openAiReasoningEffort = 'low';

    /**
     * Langdock API key.
     *
     * @var string
     */
    public string $langdockApiKey = '';

    /**
     * The author of the Langdock model, which picks the API requests go to: `anthropic` for models such as Claude, or
     * `openai` for models such as GPT.
     *
     * @var string
     */
    public string $langdockModelAuthor = 'anthropic';

    /**
     * Langdock model, as Langdock names it.
     *
     * @var string
     */
    public string $langdockModel = '';

    /**
     * Reasoning effort sent to OpenAI reasoning models through Langdock, or blank to send none.
     *
     * @var string
     */
    public string $langdockReasoningEffort = 'low';

    /**
     * OpenRouter API key.
     *
     * @var string
     */
    public string $openRouterApiKey = '';

    /**
     * OpenRouter model, as OpenRouter names it (e.g. `openai/gpt-5-nano`).
     *
     * @var string
     */
    public string $openRouterModel = '';

    /**
     * Reasoning effort sent to OpenAI reasoning models through OpenRouter, or blank to send none.
     *
     * @var string
     */
    public string $openRouterReasoningEffort = 'low';

    /**
     * The instructions sent with each page's text. The reply must be a BCP 47 language subtag (e.g. `en`), or `und` when the
     * language can't be told.
     *
     * @var string
     */
    public string $prompt = 'Identify the language the text is written in. Reply with only its BCP 47 language subtag, without a region (for example "en", "fr" or "de"), and nothing else. If the text mixes languages, give the language most of it is written in. If there isn’t enough text to tell, reply "und".';

    /**
     * How many words a page needs before it is sent. A page with fewer keeps its site's own hreflang.
     *
     * @var int
     */
    public int $minimumWords = 50;

    /**
     * Whether to detect a page's language when it is saved.
     *
     * @var bool
     */
    public bool $detectOnSave = true;

    /**
     * How many characters of a page's text are sent to the AI provider. Language detection needs little text, so
     * this keeps the cost of long pages down.
     *
     * @var int
     */
    public int $maxCharacters = 2000;

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['aiProvider', 'prompt'], 'required'],
            [['aiProvider'], 'validateOneOf', 'params' => ['allowed' => array_keys(self::PROVIDERS), 'message' => 'Invalid AI Provider configured.']],
            [['anthropicApiKey', 'anthropicModel', 'openAiApiKey', 'openAiModel', 'langdockApiKey', 'langdockModel', 'openRouterApiKey', 'openRouterModel', 'prompt'], 'string'],
            [['langdockModelAuthor'], 'validateOneOf', 'params' => ['allowed' => ['anthropic', 'openai'], 'message' => 'Invalid Langdock Model Author configured.']],
            [['openAiReasoningEffort'], 'validateOneOf', 'params' => ['allowed' => self::REASONING_EFFORTS, 'message' => 'Invalid OpenAI Reasoning Effort configured.']],
            [['langdockReasoningEffort'], 'validateOneOf', 'params' => ['allowed' => self::REASONING_EFFORTS, 'message' => 'Invalid Langdock Reasoning Effort configured.']],
            [['openRouterReasoningEffort'], 'validateOneOf', 'params' => ['allowed' => self::REASONING_EFFORTS, 'message' => 'Invalid OpenRouter Reasoning Effort configured.']],
            ['minimumWords', 'integer', 'min' => 1, 'max' => 1000],
            ['detectOnSave', 'boolean'],
            ['maxCharacters', 'integer', 'min' => 100, 'max' => 20000],
        ];
    }

    /**
     * Inline validator: the attribute, once any environment variable is resolved, must be one of `params['allowed']`.
     */
    public function validateOneOf(string $attribute, array $params): void
    {
        if (!in_array(App::parseEnv($this->$attribute), $params['allowed'], true)) {
            $this->addError($attribute, $params['message']);
        }
    }
}
