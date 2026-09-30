<?php

declare(strict_types=1);

namespace Atria\Http\Client;

use Atria\Async\EventLoop;
use Atria\Async\Suspension;
use CurlHandle;
use CurlMultiHandle;
use CurlShareHandle;

/**
 * Runs curl transfers on one multi handle per worker.
 *
 * The multi handle keeps connections (keep-alive, TLS sessions) and, through
 * the share handle, DNS results across requests. Outside Async::run() tasks a
 * transfer blocks on curl_multi_select(); inside a task it suspends, and a
 * 1 ms timer drives every pending transfer, resuming tasks as they finish.
 *
 * @internal Used by HttpClient.
 */
final class CurlDriver
{
    private readonly CurlMultiHandle $multi;
    private readonly CurlShareHandle $share;

    /** @var array<int, array{handle: CurlHandle, done: bool, result: int, suspension: ?Suspension}> By handle id. */
    private array $transfers = [];

    private ?string $timer = null;

    public function __construct(
        private readonly EventLoop $loop,
        private readonly float $interval = 0.001,
    ) {
        $this->multi = curl_multi_init();
        $this->share = curl_share_init();
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
    }

    /**
     * Runs the transfer to completion and returns its curl result (CURLE_OK on success).
     */
    public function perform(CurlHandle $handle): int
    {
        $id = spl_object_id($handle);

        curl_setopt($handle, CURLOPT_SHARE, $this->share);
        curl_multi_add_handle($this->multi, $handle);
        $this->transfers[$id] = ['handle' => $handle, 'done' => false, 'result' => CURLE_OK, 'suspension' => null];

        try {
            if ($this->loop->inAsyncTask()) {
                $suspension = $this->loop->getSuspension();
                $this->transfers[$id]['suspension'] = $suspension;
                $this->schedule();
                $suspension->suspend();
            } else {
                $this->block($id);
            }

            return $this->transfers[$id]['result'] ?? CURLE_OK;
        } finally {
            curl_multi_remove_handle($this->multi, $handle);
            unset($this->transfers[$id]);
        }
    }

    /**
     * Drops transfers left by tasks that ended with the request; keeps the
     * multi handle so its open connections are reused.
     */
    public function reset(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
        }

        $this->timer = null;

        foreach ($this->transfers as $transfer) {
            curl_multi_remove_handle($this->multi, $transfer['handle']);
        }

        $this->transfers = [];
    }

    private function block(int $id): void
    {
        while (true) {
            $this->drive();

            if ($this->transfers[$id]['done'] ?? true) {
                return;
            }

            // -1: nothing to wait on yet (e.g. resolving DNS); avoid spinning.
            if (curl_multi_select($this->multi, 0.05) === -1) {
                usleep(1_000);
            }
        }
    }

    private function schedule(): void
    {
        $this->timer ??= $this->loop->delay($this->interval, fn() => $this->tick());
    }

    private function tick(): void
    {
        $this->timer = null;
        $this->drive();

        foreach ($this->transfers as $transfer) {
            if (!$transfer['done'] && $transfer['suspension'] !== null) {
                $this->schedule();
                return;
            }
        }
    }

    /**
     * Advances every transfer and marks the finished ones, resuming their tasks.
     */
    private function drive(): void
    {
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'] ?? null;

            if ($info['msg'] !== CURLMSG_DONE || !$handle instanceof CurlHandle) {
                continue;
            }

            $id = spl_object_id($handle);

            if (!isset($this->transfers[$id])) {
                continue;
            }

            $this->transfers[$id]['done'] = true;
            $this->transfers[$id]['result'] = is_int($info['result'] ?? null) ? $info['result'] : CURLE_OK;
            $this->transfers[$id]['suspension']?->resume();
        }
    }
}
