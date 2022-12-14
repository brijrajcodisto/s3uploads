<?php

namespace BaghelSoft\S3Uploads\Aws;

/**
 * AWS command object.
 */
class Command implements \BaghelSoft\S3Uploads\Aws\CommandInterface
{
    use HasDataTrait;
    /** @var string */
    private $name;
    /** @var HandlerList */
    private $handlerList;
    /**
     * Accepts an associative array of command options, including:
     *
     * - @http: (array) Associative array of transfer options.
     *
     * @param string      $name           Name of the command
     * @param array       $args           Arguments to pass to the command
     * @param HandlerList $list           Handler list
     */
    public function __construct($name, array $args = [], \BaghelSoft\S3Uploads\Aws\HandlerList $list = null)
    {
        $this->name = $name;
        $this->data = $args;
        $this->handlerList = $list ?: new \BaghelSoft\S3Uploads\Aws\HandlerList();
        if (!isset($this->data['@http'])) {
            $this->data['@http'] = [];
        }
        if (!isset($this->data['@context'])) {
            $this->data['@context'] = [];
        }
    }
    public function __clone()
    {
        $this->handlerList = clone $this->handlerList;
    }
    public function getName()
    {
        return $this->name;
    }
    public function hasParam($name)
    {
        return array_key_exists($name, $this->data);
    }
    public function getHandlerList()
    {
        return $this->handlerList;
    }
    /** @deprecated */
    public function get($name)
    {
        return $this[$name];
    }
}
