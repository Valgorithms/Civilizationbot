<?php

/*
 * This file is a part of the Civ13 project.
 *
 * Copyright (c) 2024-present Valithor Obsidion <valithor@valzargaming.com>
 */

namespace Civ13\Exceptions;

/**
 * Thrown when a user attempts to perform an action that they do not have permission on the operating system to do.
 */
class MissingSystemPermissionException extends \Exception
{
}