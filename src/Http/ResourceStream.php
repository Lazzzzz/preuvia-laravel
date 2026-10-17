<?php

namespace Preuvia\Laravel\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Wraps a caller-owned stream resource without ever closing it, so an upload
 * can be replayed and the caller keeps control of its handle.
 *
 * @internal
 */
final class ResourceStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /** @var StreamInterface */
    private $stream;

    /**
     * @param  resource  $resource
     */
    public function __construct($resource)
    {
        $this->stream = Utils::streamFor($resource);
    }

    public function close(): void
    {
        $this->stream->detach();
    }

    public function __destruct()
    {
        $this->stream->detach();
    }
}
