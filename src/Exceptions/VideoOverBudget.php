<?php

namespace Shirahcan\VideoClient\Exceptions;

/** The product passed its hard monthly video cap. New rooms are refused until a human raises the cap; existing calls are unaffected. */
class VideoOverBudget extends VideoServiceException
{
}
