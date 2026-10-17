<?php

namespace Preuvia\Laravel\Facades;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Preuvia\Laravel\Testing\PreuviaFake;

/**
 * @method static \Preuvia\Laravel\Preuvia withApiKey(string $apiKey)
 * @method static \Preuvia\Laravel\Preuvia withBaseUrl(string $baseUrl)
 * @method static \Preuvia\Laravel\Preuvia withTimeout(int $seconds)
 * @method static \Preuvia\Laravel\Preuvia withRetries(int $times)
 * @method static \Preuvia\Laravel\Preuvia withoutRetries()
 * @method static string baseUrl()
 * @method static \Preuvia\Laravel\Http\PreuviaResponse health()
 * @method static \Preuvia\Laravel\Http\PreuviaResponse me()
 * @method static \Preuvia\Laravel\Resources\Templates templates()
 * @method static \Preuvia\Laravel\Resources\CustomTemplates customTemplates()
 * @method static \Preuvia\Laravel\Resources\Dossiers dossiers()
 * @method static \Preuvia\Laravel\Resources\Documents documents()
 * @method static \Preuvia\Laravel\Resources\Certifications certifications()
 * @method static \Preuvia\Laravel\Resources\Users users()
 * @method static \Preuvia\Laravel\Resources\Tokens tokens()
 * @method static \Preuvia\Laravel\Http\PreuviaResponse get(string $endpoint, array $query = [])
 * @method static \Preuvia\Laravel\Http\PreuviaResponse post(string $endpoint, array $payload = [])
 * @method static \Preuvia\Laravel\Http\PreuviaResponse patch(string $endpoint, array $payload = [])
 * @method static \Preuvia\Laravel\Http\PreuviaResponse delete(string $endpoint, array $payload = [])
 *
 * @see \Preuvia\Laravel\Preuvia
 */
class Preuvia extends Facade
{
    /**
     * Fakes the HTTP layer through Http::fake(). Relative keys ('dossiers/*') are prefixed with the
     * configured base URL; absolute URLs and patterns starting with '*' are kept as they are.
     * Without stubs, every request gets an empty successful envelope.
     *
     * @param  array<string, mixed>  $stubs
     */
    public static function fake(array $stubs = []): HttpFactory
    {
        if ($stubs === []) {
            return Http::fake(fn () => PreuviaFake::ok());
        }

        $baseUrl = rtrim(static::getFacadeRoot()->baseUrl(), '/');
        $prefixed = [];

        foreach ($stubs as $pattern => $stub) {
            $pattern = (string) $pattern;

            if (! Str::startsWith($pattern, ['*', 'http://', 'https://'])) {
                $pattern = $baseUrl.'/'.ltrim($pattern, '/');
            }

            $prefixed[$pattern] = $stub;
        }

        return Http::fake($prefixed);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'preuvia';
    }
}
