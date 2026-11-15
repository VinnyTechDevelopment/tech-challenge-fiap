<?php

namespace App\Application\Exceptions;

/**
 * Dependência síncrona indisponível (renderizada como HTTP 503).
 */
class ServiceUnavailableException extends \RuntimeException {}
