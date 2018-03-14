<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace GraphJS\Controllers;

use CapMousse\ReactRestify\Http\Request;
use CapMousse\ReactRestify\Http\Response;
use CapMousse\ReactRestify\Http\Session;
use Pho\Kernel\Kernel;
use Valitron\Validator;
use PhoNetworksAutogenerated\User;


/**
 * An abstract controller that includes common operations in GraphJS
 * 
 * @author Emre Sokullu <emre@phonetworks.org>
 */
abstract class AbstractController extends   \Pho\Server\Rest\Controllers\AbstractController 
{
    protected function succeed(Response $response, array $data = []): void
    {
        $method = $this->getWriteMethod();
        $response->addHeader("Access-Control-Allow-Credentials", "true")->$method(
            array_merge(
                ["success"=>true], 
                $data
            )
        )->end();
    }

    /**
     * Makes sure the method is dependent on session availability
     *
     * @param Request $request
     * @param Response $response
     * @param Session $session
     * @param variadic $ignore
     * 
     * @return int 0 if session does not exists, user ID otherwise.
     */
    protected function dependOnSession(Request $request, Response $response, Session $session, ...$ignore): ?string
    {
        $id = $session->get($request, "id");
        if(is_null($id)) {
            $this->addHeader("Access-Control-Allow-Credentials", "true")->fail($response, "No active session");
            return null;
        }
        return $id;
    }
}
