<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Config;

final class AIClientFactory
{
    public static function create(): OpenRouterClient
    {
        return self::providerName() === 'gemini'
            ? new GeminiClient()
            : new OpenRouterClient();
    }

    public static function providerName(): string
    {
        $provider = strtolower(trim((string)Config::get('AI_PROVIDER', 'openrouter')));
        return in_array($provider, ['gemini', 'google', 'google_gemini'], true)
            ? 'gemini'
            : 'openrouter';
    }
}
