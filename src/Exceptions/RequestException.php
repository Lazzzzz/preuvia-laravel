<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * Any other non-2xx answer, or a 2xx envelope with success: false.
 */
class RequestException extends PreuviaException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public static function unsuccessful(string $method, string $endpoint, int $status, array $response): self
    {
        $method = strtoupper($method);
        $code = $response['meta']['code'] ?? null;
        $apiMessage = is_string($response['message'] ?? null) && $response['message'] !== '' ? $response['message'] : null;

        return new self(
            sprintf(
                'Preuvia API %s %s failed (%s)%s',
                $method,
                $endpoint,
                trim($status.' '.(is_string($code) ? $code : 'success: false')),
                $apiMessage === null ? '.' : ': '.$apiMessage,
            ),
            $method,
            $endpoint,
            $status,
            $response,
        );
    }
}
