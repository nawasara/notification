<?php

namespace Nawasara\Notification\Exceptions;

/**
 * A send that can never succeed, so retrying it is pointless.
 *
 * SendNotificationJob marks the log failed and stops instead of rethrowing.
 * Without this, a dead push token was tried three times with backoff on every
 * notification, forever, and each attempt was one more call to the provider
 * that was always going to be refused.
 */
class PermanentNotificationFailure extends \RuntimeException
{
}
