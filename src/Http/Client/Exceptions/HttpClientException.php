<?php

declare(strict_types=1);

namespace Atria\Http\Client\Exceptions;

use RuntimeException;

/**
 * The request never got a response: DNS, connection, TLS or timeout failure.
 * The code is the curl error number (CURLE_*). HTTP error statuses are not
 * exceptions; check HttpResponse::ok() instead.
 */
class HttpClientException extends RuntimeException {}
