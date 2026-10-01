<?php

namespace Shirahcan\VideoClient\Exceptions;

/** The room lives on a previous Daily domain (or was deleted) and cannot accept a token. Run video:reconcile-rooms; retrying will not help. */
class RoomStranded extends VideoServiceException
{
}
