<?php

namespace Nidavellir\Mjolnir\Exceptions;

/**
 * Type of exception used on the BaseQueuableJob, so when it's raised
 * it will not run the ignoreException cycles, and just call the
 * resolveException cycle.
 */
class JustResolveException extends \Exception {}
