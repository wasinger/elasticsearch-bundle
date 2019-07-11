<?php
namespace Wa72\ElasticsearchBundle\Services;

use Elasticsearch\Client;

class IndexRegistry
{
    /**
     * @var Client
     */
    private $es;
    /**
     * @var Index[]
     */
    private $indexes = [];

    public function __construct(Client $es)
    {
        $this->es = $es;
    }

    public function addIndex(Index $index)
    {
        $this->indexes[$index->getName()] = $index;
    }

    public function getIndex($name)
    {
        if (isset($this->indexes[$name])) {
            return $this->indexes[$name];
        }
        return null;
    }

    public function list()
    {
        return \array_keys($this->indexes);
    }
}