<?php

namespace App\Tenancy\Http\Middleware;

use Exception;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control-flow signal internal to the request transaction middleware: the
 * routing pipeline has already rendered a downstream exception into a
 * response, so throwing again is the only way to roll the wrapping
 * transaction back; this exception carries the rendered response across
 * the rollback so the client still receives it.
 */
class RenderedErrorRollback extends Exception
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Rolling back the request transaction behind an already-rendered error response.');
    }
}
