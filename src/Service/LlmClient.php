<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Minimal LLM client supporting OpenAI, Anthropic, and Hugging Face Inference.
 *
 * Used by the IssueToPatchAgent for planning, patching, and reporting.
 */
final class LlmClient
{
    private string $provider;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        if (!empty($_ENV['ANTHROPIC_API_KEY'] ?? '')) {
            $this->provider = 'anthropic';
            $this->apiKey = $_ENV['ANTHROPIC_API_KEY'];
            $this->model = 'claude-sonnet-4-20250514';
        } elseif (!empty($_ENV['OPENAI_API_KEY'] ?? '')) {
            $this->provider = 'openai';
            $this->apiKey = $_ENV['OPENAI_API_KEY'];
            $this->model = 'gpt-4o';
        } elseif (!empty($_ENV['HUGGINGFACE_API_KEY'] ?? '')) {
            $this->provider = 'huggingface';
            $this->apiKey = $_ENV['HUGGINGFACE_API_KEY'];
            $this->model = 'meta-llama/Llama-3.1-8B-Instruct';
        } else {
            $this->provider = 'none';
            $this->apiKey = '';
            $this->model = '';
        }
    }

    public function isConfigured(): bool
    {
        return $this->provider !== 'none' && $this->apiKey !== '';
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Send a chat completion request.
     *
     * @param array<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return string
     */
    public function chat(array $messages, array $options = []): string
    {
        return match ($this->provider) {
            'anthropic' => $this->chatAnthropic($messages, $options),
            'openai' => $this->chatOpenAI($messages, $options),
            'huggingface' => $this->chatHuggingFace($messages, $options),
            default => throw new \RuntimeException('No LLM provider configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or HUGGINGFACE_API_KEY in .env'),
        };
    }

    /** @param array<array{role: string, content: string}> $messages
     *  @param array<string, mixed> $options */
    private function chatAnthropic(array $messages, array $options): string
    {
        $system = '';
        $chatMessages = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system = $m['content'];
            } else {
                $chatMessages[] = $m;
            }
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'messages' => $chatMessages,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Anthropic API error ({$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        return $data['content'][0]['text'] ?? '';
    }

    /** @param array<array{role: string, content: string}> $messages
     *  @param array<string, mixed> $options */
    private function chatOpenAI(array $messages, array $options): string
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 4096,
            'temperature' => $options['temperature'] ?? 0.2,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("OpenAI API error ({$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /** @param array<array{role: string, content: string}> $messages
     *  @param array<string, mixed> $options */
    private function chatHuggingFace(array $messages, array $options): string
    {
        // Convert to HF format (text generation)
        $prompt = '';
        foreach ($messages as $m) {
            $role = $m['role'];
            $content = $m['content'];
            $prompt .= "<|{$role}|>\n{$content}\n";
        }

        $payload = [
            'inputs' => $prompt,
            'parameters' => [
                'max_new_tokens' => $options['max_tokens'] ?? 4096,
                'temperature' => $options['temperature'] ?? 0.2,
            ],
        ];

        $ch = curl_init("https://api-inference.huggingface.co/models/{$this->model}");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("HuggingFace API error ({$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        return $data[0]['generated_text'] ?? '';
    }
}
