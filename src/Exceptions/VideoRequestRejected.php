<?php

namespace Shirahcan\VideoClient\Exceptions;

/** The service or Daily rejected the request on its merits (validation, a refused property). Fix the request; do not retry. */
class VideoRequestRejected extends VideoServiceException
{
}
