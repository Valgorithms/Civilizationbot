<?php

/*
 * This file is a part of the Civilizationbot project.
 *
 * Copyright (c) 2021-present Valithor Obsidion <valithor@civ13.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Civ13\Exceptions;

/**
 * Thrown when a user attempts to perform an action that they do not have permission on the operating system to do.
 */
class MissingSystemPermissionException extends \Exception
{
}
