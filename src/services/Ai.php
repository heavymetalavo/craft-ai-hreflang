<?php

namespace heavymetalavo\aihreflang\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use heavymetalavo\aihreflang\AiHreflang;
use heavymetalavo\aihreflang\errors\AiException;
use heavymetalavo\aihreflang\errors\ConfigurationException;
use heavymetalavo\aihreflang\helpers\HttpClient;
use heavymetalavo\aihreflang\models\Settings;

/**
 * Asks the chosen AI provider which language a text is written in.
 *
 * The providers are the AI Alt Text plugin's, and requests are made the same way, with this plugin's own settings:
 *
 * - OpenAI, OpenRouter, and Langdock's OpenAI models through the Responses API (`POST {baseUrl}/responses`)
 * - Anthropic, and Langdock's Anthropic models through the Messages API (`POST {baseUrl}/messages`)
 *
 * @package heavymetalavo\aihreflang\services
 */
class Ai extends Component
{
    /**
     * The most tokens the Messages API may reply with. A language code needs a few, but a model that thinks first,
     * such as `claude-haiku-5-5` through Langdock, where thinking isn't turned off, needs room to finish. Only the tokens
     * used are billed.
     */
    private const MAX_TOKENS = 1024;

    private ?Client $client = null;

    /**
     * The chosen provider, e.g. `openrouter`, or blank if none is chosen.
     */
    public function getProvider(): string
    {
        return $this->setting('aiProvider');
    }

    /**
     * The chosen provider's name, e.g. "OpenRouter".
     */
    public function getProviderLabel(): string
    {
        return Settings::PROVIDERS[$this->getProvider()] ?? $this->getProvider();
    }

    /**
     * The chosen provider's API key.
     */
    public function getApiKey(): string
    {
        return match ($this->getProvider()) {
            'anthropic' => $this->setting('anthropicApiKey'),
            'openai' => $this->setting('openAiApiKey'),
            'langdock' => $this->setting('langdockApiKey'),
            'openrouter' => $this->setting('openRouterApiKey'),
            default => '',
        };
    }

    /**
     * The chosen provider's model.
     */
    public function getModel(): string
    {
        return match ($this->getProvider()) {
            'anthropic' => $this->setting('anthropicModel'),
            'openai' => $this->setting('openAiModel'),
            'langdock' => $this->setting('langdockModel'),
            'openrouter' => $this->setting('openRouterModel'),
            default => '',
        };
    }

    /**
     * Why requests can't be sent, or null if they can.
     */
    public function getConfigurationError(): ?string
    {
        $provider = $this->getProvider();

        if ($provider === '') {
            return Craft::t('ai-hreflang', 'No AI provider is chosen.');
        }

        if (!isset(Settings::PROVIDERS[$provider])) {
            return Craft::t('ai-hreflang', '“{provider}” isn’t a supported AI provider. Choose one of: {providers}.', [
                'provider' => $provider,
                'providers' => implode(', ', Settings::PROVIDERS),
            ]);
        }

        if ($this->getApiKey() === '') {
            return Craft::t('ai-hreflang', 'No {provider} API key is set.', ['provider' => $this->getProviderLabel()]);
        }

        if ($this->getModel() === '') {
            return Craft::t('ai-hreflang', 'No {provider} model is set.', ['provider' => $this->getProviderLabel()]);
        }

        if ($provider === 'langdock' && !isset(Settings::LANGDOCK_MODEL_AUTHORS[$this->setting('langdockModelAuthor')])) {
            return Craft::t('ai-hreflang', 'The Langdock Model Author must be one of: {authors}.', [
                'authors' => implode(', ', Settings::LANGDOCK_MODEL_AUTHORS),
            ]);
        }

        return null;
    }

    /**
     * The instructions sent with each page's text: the `prompt` setting, or the default.
     */
    public function getInstructions(): string
    {
        return App::parseEnv(AiHreflang::getInstance()->getSettings()->prompt) ?: Settings::DEFAULT_PROMPT;
    }

    /**
     * What a detection depends on besides the page's text: the provider, model and instructions. It's part of each
     * page's hash, so changing any of them makes every page count as changed.
     */
    public function getFingerprint(): string
    {
        return implode("\n", [$this->getProvider(), $this->getModel(), $this->getInstructions()]);
    }

    /**
     * Detects the language a text is written in.
     *
     * @return string|null ISO 639-1 language code, e.g. `en`, or null if the model replied `und` (undetermined), or
     * with a language that has no ISO 639-1 code, which hreflang can't express
     * @throws ConfigurationException If the provider settings are incomplete
     * @throws AiException If the request fails or the model's reply isn't a language code
     */
    public function detectLanguage(string $text): ?string
    {
        if ($error = $this->getConfigurationError()) {
            throw new ConfigurationException($error);
        }

        $instructions = $this->getInstructions();
        $apiKey = $this->getApiKey();

        // The page's text is marked out, so text in it that reads like an instruction is less likely to be followed
        $text = "<page_text>\n$text\n</page_text>";

        $reply = match ($this->getProvider()) {
            // Thinking is turned off, as a language code needs none: left on, it can use up the reply's tokens
            'anthropic' => $this->sendMessagesRequest('https://api.anthropic.com/v1', [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ], $instructions, $text, ['thinking' => ['type' => 'disabled']]),
            // OpenAI keeps requests for at least 30 days unless told not to
            'openai' => $this->sendResponsesRequest('https://api.openai.com/v1', $this->setting('openAiReasoningEffort'), $instructions, $text, ['store' => false]),
            // Langdock hosts its models in the EU region
            'langdock' => $this->setting('langdockModelAuthor') === 'openai'
                ? $this->sendResponsesRequest('https://api.langdock.com/openai/eu/v1', $this->setting('langdockReasoningEffort'), $instructions, $text)
                : $this->sendMessagesRequest('https://api.langdock.com/anthropic/eu/v1', [
                    'Authorization' => 'Bearer ' . $apiKey,
                ], $instructions, $text),
            'openrouter' => $this->sendResponsesRequest('https://openrouter.ai/api/v1', $this->setting('openRouterReasoningEffort'), $instructions, $text),
            // getConfigurationError() has already rejected any other provider
            default => throw new ConfigurationException(sprintf('“%s” isn’t a supported AI provider.', $this->getProvider())),
        };

        return $this->parseReply($reply);
    }

    /**
     * The language code in a model's reply. A known ISO 639-1 code is the language, and `und`, or a known language
     * with only a 3-letter code (such as `haw`), is undetermined, as hreflang needs an ISO 639-1 code. Anything else,
     * such as `yes` or `the`, means the model didn't answer as asked, and fails.
     *
     * @throws AiException If the reply isn't a language code
     */
    public function parseReply(string $reply): ?string
    {
        $code = strtolower(trim($reply, " \t\n\r\"'`."));
        $code = preg_split('/[-_]/', $code)[0];
        $hreflang = AiHreflang::getInstance()->hreflang;

        if (in_array($code, $hreflang->getLanguageCodes(), true)) {
            return $code;
        }

        if ($code === 'und' || (strlen($code) === 3 && in_array($code, Craft::$app->getI18n()->getAllLocaleIds(), true))) {
            return null;
        }

        throw new AiException(sprintf('%s replied with "%s", which isn’t an ISO 639-1 language code. Check the Prompt setting asks for one.', $this->getProviderLabel(), mb_strimwidth($reply, 0, 100, '…')));
    }

    /**
     * Sends a request to the Responses API and returns the model's reply.
     *
     * @param array $extra Extra request parameters, such as `store`
     * @throws AiException
     */
    private function sendResponsesRequest(string $baseUrl, string $reasoningEffort, string $instructions, string $text, array $extra = []): string
    {
        $model = $this->getModel();
        $payload = [
            'model' => $model,
            'instructions' => $instructions,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $text],
                    ],
                ],
            ],
            ...$extra,
        ];

        if ($reasoningEffort !== '' && $this->isReasoningModel($model)) {
            $payload['reasoning']['effort'] = $reasoningEffort;
        }

        $data = $this->post($baseUrl . '/responses', [
            'Authorization' => 'Bearer ' . $this->getApiKey(),
            ...$this->openRouterHeaders(),
        ], $payload);

        if (($data['status'] ?? null) === 'incomplete') {
            throw new AiException(sprintf('%s returned an incomplete response (reason: %s).', $this->getProviderLabel(), $data['incomplete_details']['reason'] ?? 'unknown'));
        }

        foreach ($data['output'] ?? [] as $item) {
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'output_text' && isset($content['text'])) {
                    return $content['text'];
                }
            }
        }

        throw new AiException(sprintf('%s returned no text.', $this->getProviderLabel()));
    }

    /**
     * Sends a request to the Messages API and returns the model's reply.
     *
     * @param array<string, string> $headers The authentication headers
     * @param array $extra Extra request parameters, such as `thinking`
     * @throws AiException
     */
    private function sendMessagesRequest(string $baseUrl, array $headers, string $instructions, string $text, array $extra = []): string
    {
        $data = $this->post($baseUrl . '/messages', $headers, [
            'model' => $this->getModel(),
            'max_tokens' => self::MAX_TOKENS,
            'system' => $instructions,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ],
            ...$extra,
        ]);

        foreach ($data['content'] ?? [] as $content) {
            if (($content['type'] ?? null) === 'text' && isset($content['text'])) {
                return $content['text'];
            }
        }

        throw new AiException(sprintf('%s returned no text (stop reason: %s).', $this->getProviderLabel(), $data['stop_reason'] ?? 'unknown'));
    }

    /**
     * Posts JSON to the provider and returns the decoded response.
     *
     * @param array<string, string> $headers
     * @throws AiException If the request fails or the response is an error
     */
    private function post(string $url, array $headers, array $payload): array
    {
        $label = $this->getProviderLabel();

        try {
            $response = $this->getClient()->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    ...$headers,
                ],
                'json' => $payload,
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            Craft::error($label . ' API error: ' . trim($body ?: $e->getMessage()), __METHOD__);
            $data = json_decode($body, true);

            throw new AiException($label . ' API error: ' . ((is_array($data) ? $this->errorMessage($data) : null) ?? $e->getMessage()), 0, $e);
        } catch (GuzzleException $e) {
            throw new AiException($label . ' API request failed: ' . $e->getMessage(), 0, $e);
        }

        // OpenRouter pads a slow response with leading whitespace while the model works
        $data = Json::decodeIfJson(trim((string)$response->getBody()));

        if (!is_array($data)) {
            throw new AiException(sprintf('%s returned a response that isn’t JSON.', $label));
        }

        if (isset($data['error'])) {
            throw new AiException($label . ' API error: ' . ($this->errorMessage($data) ?? Json::encode($data['error'])));
        }

        return $data;
    }

    /**
     * The message of an error response. Langdock reports some errors as a flat `{"message": "..."}`, and OpenRouter
     * reports a failure at the model's own provider as "Provider returned error", with the cause in its metadata.
     */
    private function errorMessage(array $data): ?string
    {
        $message = $data['error']['message'] ?? $data['message'] ?? null;

        if (!is_string($message)) {
            return null;
        }

        $metadata = $data['error']['metadata'] ?? null;
        $raw = $metadata['raw'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $cause = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? $raw) : $raw;
            $provider = $metadata['provider_name'] ?? null;
            $message .= ': ' . mb_strimwidth((string)$cause, 0, 300, '…') . ($provider ? " ($provider)" : '');
        }

        return $message;
    }

    /**
     * Names the app in OpenRouter's logs and keeps it out of OpenRouter's public rankings, as the AI Alt Text plugin
     * does. Other providers, and a primary site URL that isn't absolute, such as `@web` in a queue job, send none.
     *
     * @return array<string, string>
     */
    private function openRouterHeaders(): array
    {
        if ($this->getProvider() !== 'openrouter') {
            return [];
        }

        $url = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();

        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            return [];
        }

        return [
            'HTTP-Referer' => $url,
            'X-OpenRouter-Title' => 'heavymetalavo/craft-ai-hreflang',
            'X-OpenRouter-App-Visibility' => 'hidden',
        ];
    }

    /**
     * Whether a model takes a `reasoning` parameter: OpenAI's o-series and gpt-5 onwards, but not their chat variants.
     */
    private function isReasoningModel(string $model): bool
    {
        return !str_contains($model, '-chat') && preg_match('/^(openai\/)?(o\d|gpt-([5-9]|\d{2,}))/', $model);
    }

    /**
     * The HTTP client for requests to AI providers.
     */
    private function getClient(): Client
    {
        return $this->client ??= HttpClient::create();
    }

    private function setting(string $name): string
    {
        return (string)App::parseEnv(AiHreflang::getInstance()->getSettings()->$name);
    }
}
