<?php
namespace Bullet;

use Pimple\Container;

class App extends Container
{
    protected $rootCallbacks;
    protected $currentCallbacks;
    protected $exceptionHandler;

    public function __construct()
    {
        $this->rootCallbacks = [];
        $this->currentCallbacks = &$this->rootCallbacks;
    }

    protected function executeCallback(\Closure $c, array $params = [])
    {
        $c = \Closure::bind($c, $this);
        $response = call_user_func_array($c, $params);

        if ($response === null || $response instanceOf Response) {
            return $response;
        }

        return Response::make($response);
    }

    /**
     * Run app with given Request
     *
     * run() ALWAYS return a Response. Returning any other type or
     * throwing an exception is a bug.
     *
     * Internally run() calls run_() which MIGHT throw exceptions. These
     * exceptions are caught, and handled by respondToE(Exception $e).
     *
     * @param \Bullet\Request \Bullet\Request object
     * @return \Bullet\Response
     */
    public function run(Request $request)
    {
        try {
            return $this->run_($request);
        } catch (\Exception $e) {
            return $this->respondToE($e);
        }
    }

    /**
     * Looks for and executes a callback for an URL part if there's any
     */
    protected function matchCallbacks($request, $part)
    {
        // Try to find a param match
        if (array_key_exists('param', $this->currentCallbacks)) {
            // This needs a linear search trhough the param filters
            $c = null;
            foreach ($this->currentCallbacks['param'] as $filterCallbackTuple) {
                if ($filterCallbackTuple[0]($part)) {
                    $c = $filterCallbackTuple[1];
                    break;
                }
            }
            if ($c instanceOf \Closure) {
                $this->currentCallbacks = [];
                return $this->executeCallback($c, [$request, $part]);
            } else {
                return new Response(null, 404); // WARNING! The last $part might match a format.
            }
        } else {
            return new Response(null, 404); // Same WARNING as above.
        }
    }

    /**
     * Run app with given Request
     *
     * run_() either returns a Response, or throws an exception.
     *
     * It may be called manually from url handlers, or even in
     * index.php when the default exception handling method (respondToE)
     * is not suitable.
     *
     * The suggested method of calling the Bullet app from itself IS to
     * call run_() manually, since any exception thrown will
     * short-circuit the application and will end up being caught by
     * respondToE() in run() or by a user-defined try-catch in
     * index.php.
     *
     * The preferred method for custom exception-responses is NOT to
     * call run_() manually, but to call run(), and overwrite the
     * Response's content based on it's status().
     *
     * Internally run() calls run_() which MIGHT throw exceptions. These
     * exceptions are caught, and handled by respondToE(Exception $e).
     *
     * @param \Bullet\Request \Bullet\Request object
     * @return \Bullet\Response
     */
    public function run_(Request $request)
    {
        // Save the app's URL parser state (e.g. the current callback map)
        $currentCallbacks = $this->currentCallbacks;
        $this->currentCallbacks = $this->rootCallbacks;

        $response = null;
        try {
            // Remove empty path elements
            $uri = $request->path();
            $parts = [''];
            foreach (explode('/', $uri) as $part) {
                if ($part != '') {
                    $parts[] = $part;
                }
            }

            // Walk through the URI and execute path callbacks
            $pc = count($parts);
            $i = 0;
            foreach ($parts as $part) {
                ++$i;
                $response = $this->matchCallbacks($request, $part);
                if ($response instanceof Response && ($response->status() != 404 || $i != $pc)) {
                    // This is not the last part, or maybe it is, but at least not a 404, so we can return it.
                    return $response;
                }
            }

            // If we have a 404 response, we must try to match the last URL part
            // against the name.extension pattern, and re-try the URL/param callbacks
            // for the name part, and then the format callbacks for the extension part.
            $format_part = null;
            $format_ext = null;
            if ($response instanceof Response && $response->status() == 404) {
                $_ = explode('.', $part);

                if (count($_) <= 1) {
                    return $response;
                }
                $format_part = $_[0];
                $format_ext = $_[1];

                $response = $this->matchCallbacks($request, $format_part);
                if ($response instanceof Response) {
                    // It's either a Response returned by the callback
                    // or it's a 404 returned by matchCallbacks()
                    return $response;
                }
            }

            $method = $request->method();

            // The URI has been processed. Call the appropriate method callback
            if (!array_key_exists($method, $this->currentCallbacks)) {
                // Nope, we can't serve this URI, 405 Not Allowed
                return new Response(null, 405, ['Allow' => implode(',', array_keys($this->currentCallbacks))]);
            }

            // There indeed is a method callback, so let's call it!
            $response = $this->executeCallback($this->currentCallbacks[$method], [$request]);

            // If there's a response, we can return it
            if ($response instanceOf Response) {
                return $response;
            }

            // The method handlers could still have installed format handlers,
            // so we'll have to check those here.
            // Try the specific, then the catch-all ('') format handler
            if ($format_ext != null) {
                $c = null;
                if (array_key_exists($format_ext, $this->currentCallbacks['format'])) {
                    $c = $this->currentCallbacks['format'][$format_ext];
                } elseif (array_key_exists('', $this->currentCallbacks['format'])) {
                    $c = $this->currentCallbacks['format'][''];
                } else {
                    return new Response(null, 406); // Not acceptable format
                }
                $this->currentCallbacks = [];
                $response = $this->executeCallback($c, [$request]);
                if ($response instanceof Response) {
                    return $response;
                }
            } else {
                // Ok, no file extension, so try the formats decyphered from the "Accept" header
                if (!array_key_exists('format', $this->currentCallbacks)) {
                    // No format handlers are specified, can't do anything right now.
                    return new Response(null, 404);
                }

                $c = null;
                foreach ($request->formats() as $format) {
                    if (array_key_exists($format, $this->currentCallbacks['format'])) {
                        $c = $this->currentCallbacks['format'][$format];
                        break;
                    }
                }
                if ($c == null && array_key_exists('', $this->currentCallbacks['format'])) {
                    $c = $this->currentCallbacks['format'][''];
                }
                if ($c != null) {
                    $this->currentCallbacks = [];
                    $response = $this->executeCallback($c, [$request]);
                    if ($response instanceof Response) {
                        return $response;
                    }
                } else {
                    return new Response(null, 404); // This is not an URL with an extension, or an appropriate Accept header
                }
            }

            return new Response(null, 501); // Got no error, but got no response either. This is "Not Implemented".
        } finally {
            $this->currentCallbacks = &$currentCallbacks;
        }
    }

    /**
     * Creates a Response from any exception
     *
     * If the exception is an instance of \Bullet\Response\Exception,
     * then the exception code is used as the status, and the message
     * (if not null) is used as the content.
     *
     * The response will contain the exception either way.
     */
    public function respondToE(\Exception $e)
    {
        if ($e instanceOf Response\Exception) {
            return (new \Bullet\Response($e->getMessage(), $e->getCode()))->exception($e);
        } else {
            return (new \Bullet\Response(null, 500))->exception($e);
        }
    }

    /**
     * An alias to path()
     *
     * @see path()
     */
    public function resource(string $part, \Closure $callback)
    {
        $this->path($part, $callback);
    }

    /**
     * A path() callback is really just a param() callback
     * with the filter function being the exactly matching string
     */
    public function path($part, \Closure $callback)
    {
        $this->param(function ($_part) use ($part) {
            return $_part == $part;
        }, $callback);
    }

    /**
     * Param callbacks are tested in the order they are defiend.
     */
    public function param(\Closure $filter, \Closure $callback)
    {
        $this->currentCallbacks['param'][] = [$filter, $callback];
    }

    /**
     * Handle HTTP content type as output format
     *
     * @param string $format extension to handle
     * @param \Closure $callback Closure to execute to handle specified format
     */
    public function format($format, \Closure $callback)
    {
        // TODO: Install a param handle that can handle formats
        $this->currentCallbacks['format'][$format] = $callback;
        return $this;
    }

    /**
     * Method handlers are called after the path has been fully consumed
     * less the extension.
     */
    public function method(string $method, \Closure $callback)
    {
        $this->currentCallbacks[$method] = $callback;
    }

    public function get(\Closure $callback)
    {
        $this->method('GET', $callback);
    }

    public function head(\Closure $callback)
    {
        $this->method('HEAD', $callback);
    }

    public function post(\Closure $callback)
    {
        $this->method('POST', $callback);
    }

    public function put(\Closure $callback)
    {
        $this->method('PUT', $callback);
    }

    public function delete(\Closure $callback)
    {
        $this->method('DELETE', $callback);
    }

    public function patch(\Closure $callback)
    {
        $this->method('PATCH', $callback);
    }

    public function options(\Closure $callback)
    {
        $this->method('OPTIONS', $callback);
    }

    public function domain()
    {
    }

    public function subdomain()
    {
    }

    public function helper($name, $className = null)
    {
        if($className === null) {
            // Ensure helper exists
            if(!isset($this->_helpers[$name])) {
                throw new \InvalidArgumentException("Requested helper '" . $name ."' not registered.");
            }

            // Instantiate helper if not done already
            if(!is_object($this->_helpers[$name])) {
                $this->_helpers[$name] = new $this->_helpers[$name];
            }

            return $this->_helpers[$name];
        } else {
            $this->_helpers[$name] = $className;
        }
    }

    public function registerResponseHandler()
    {
    }

    public function removeResponseHandler()
    {
    }

    public static function paramInt()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_INT);
        };
    }

    public static function paramFloat()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_FLOAT);
        };
    }

    /**
     *
     * True = "1", "true", "on", "yes"
     * False = "0", "false", "off", "no"
     */
    public static function paramBoolean()
    {
        return function($value) {
            $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return (!empty($filtered) && $filtered !== null);
        };
    }

    public static function paramSlug()
    {
        return function($value) {
            return (preg_match("/[a-zA-Z0-9-_]/", $value) > 0);
        };
    }

    public static function paramEmail()
    {
        return function($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL);
        };
    }
}
