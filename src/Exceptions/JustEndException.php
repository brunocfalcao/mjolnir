<?php

namespace Nidavellir\Mjolnir\Exceptions;

/**
 * Type of exception used on the BaseQueuableJob, that will not call any
 * additional methods, but just end the BaseQueableJob catch() block.
 *
 * Useful on the OrderApiObserver when, for instance, we are creating
 * too much orders of a type, and we don't want to rollback the position.
 */
class JustEndException extends \Exception {}
