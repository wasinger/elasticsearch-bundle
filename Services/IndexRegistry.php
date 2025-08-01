<?php
namespace Wa72\ElasticsearchBundle\Services;

use Elasticsearch\Client;
use Wa72\ESTools\Index;

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

    private $hosts = [];

    public function __construct(Client $es, array $hosts = [])
    {
        $this->es = $es;
        $this->hosts = $hosts;
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

    /**
     * List available index names
     *
     * @return string[]
     */
    public function list()
    {
        return \array_keys($this->indexes);
    }

    public function getHosts()
    {
        return $this->hosts;
    }
}