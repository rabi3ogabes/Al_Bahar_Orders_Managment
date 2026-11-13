<?php

namespace App\Support;

use InvalidArgumentException;

/** A money amount that is not a plain decimal ("1e3", ".5", "abc"). Rendered as a 422 validation error, never a 500. */
class InvalidMoney extends InvalidArgumentException {}
