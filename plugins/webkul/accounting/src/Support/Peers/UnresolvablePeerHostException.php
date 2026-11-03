<?php

namespace Webkul\Accounting\Support\Peers;

use InvalidArgumentException;

/**
 * PeerEndpointGuard could not resolve the peer's host right now. Still a
 * refusal (nothing is sent to a host whose addresses were not checked), but
 * unlike a private or loopback address it may be transient, so a queued send
 * retries it with backoff instead of failing at once.
 */
final class UnresolvablePeerHostException extends InvalidArgumentException {}
