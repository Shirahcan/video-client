<?php

namespace Shirahcan\VideoClient\Exceptions;

/** The service, or Daily behind it, is unreachable or failing. Retryable; the call itself is not wrong. */
class VideoServiceUnavailable extends VideoServiceException
{
}
