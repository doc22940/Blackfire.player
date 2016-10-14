<?php

/*
 * This file is part of the Blackfire Player package.
 *
 * (c) Fabien Potencier <fabien@blackfire.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Blackfire\Player;

use Blackfire\Player\Psr7\CrawlerFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @author Fabien Potencier <fabien@blackfire.io>
 */
class Context
{
    private $name;
    private $valueBag;
    private $extraBag;
    private $generator;
    private $contextStack;
    private $request;
    private $response;
    private $crawler;

    public function __construct($name)
    {
        $this->name = $name;
        $this->valueBag = new ValueBag();
        $this->extraBag = new ValueBag();
    }

    public function getName()
    {
        return $this->name;
    }

    public function getValueBag()
    {
        return $this->valueBag;
    }

    public function getExtraBag()
    {
        return $this->extraBag;
    }

    public function getStepContext()
    {
        return $this->contextStack->top();
    }

    public function setRequestResponse(RequestInterface $request, ResponseInterface $response)
    {
        $this->request = $request;
        $this->response = $response;

        $this->crawler = null;
        if (null !== $request && null !== $response) {
            $this->crawler = CrawlerFactory::create($response, $request->getUri());
        }
    }

    /**
     * @internal
     */
    public function setGenerator(\Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @internal
     */
    public function setContextStack(\SplStack $contextStack)
    {
        $this->contextStack = $contextStack;
    }

    /**
     * @internal
     */
    public function getGenerator()
    {
        return $this->generator;
    }

    /**
     * @internal
     */
    public function getVariableValues($trim = false)
    {
        $values = $this->valueBag->all($trim);

        foreach ($this->contextStack->top()->getVariables() as $key => $value) {
            if (!array_key_exists($key, $values)) {
                $values[$key] = $trim && is_string($value) ? trim($value) : $value;
            }
        }

        $values['_crawler'] = $this->crawler;
        $values['_response'] = $this->response;

        return $values;
    }
}
