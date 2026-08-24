<?php

declare(strict_types=1);

namespace Atria\Modules\Mercure;

use Atria\Modules\Mercure\Exceptions\MercureTransportException;

class MercurePublisher
{
    /** @var callable(array<int, string>|string, string, bool, ?string, ?string, ?int): string */
    private $publisher;

    public function __construct(MercureConfig $config, ?callable $publisher = null)
    {
        $config->assertEnabled();
        $this->publisher = $publisher ?? $this->defaultPublisher(...);
    }

    /**
     * @param array<int, string>|string $topics
     */
    public function publish(
        array|string $topics,
        string $data = '',
        bool $private = false,
        ?string $id = null,
        ?string $type = null,
        ?int $retry = null,
    ): void {
        try {
            ($this->publisher)(
                $topics,
                $data,
                $private,
                $id,
                $type,
                $retry,
            );
        } catch (MercureTransportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new MercureTransportException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<int, string>|string $topics
     */
    private function defaultPublisher(
        array|string $topics,
        string $data,
        bool $private,
        ?string $id,
        ?string $type,
        ?int $retry,
    ): string {
        if (!function_exists('mercure_publish')) {
            throw new MercureTransportException(
                'mercure_publish() is not available. Make sure Mercure publishing runs inside FrankenPHP.',
            );
        }

        return mercure_publish($topics, $data, $private, $id, $type, $retry);
    }
}
