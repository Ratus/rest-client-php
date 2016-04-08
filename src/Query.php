<?php

namespace Ratus\RestClient;

class Query
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $resource;

    /**
     * @var bool|string
     */
    protected $mapcheck;

    /**
     * @var string
     */
    protected $classname;

    /**
     * @var array
     */
    protected $query = array();

    public function __construct($client = null, $resource = '', $classname = 'array', $mapcheck = '')
    {
        $this->client = $client;
        $this->resource = $resource;
        $this->classname = $classname;
        $this->mapcheck = $mapcheck;
    }

    /**
     * @return mixed
     */
    public function execute()
    {
        return $this->client->get($this->resource, $this->query, $this->classname, $this->mapcheck);
    }

    /**
     * @param array|object $filter
     *
     * @return $this
     */
    public function add($filter = array())
    {
        //convert objects if needed
        if(is_object($filter)) {
            $func = array('toArray','__toArray','toQuery');
            while ($function = array_shift($func)) {
                if(method_exists($filter, $function)) {
                    $filter = call_user_func(array($filter, $function));
                    break;
                }
            }
            if(gettype($filter)!='array' && !count($func)) {
                return null;
            }
        }

        //add the new filter to the query
        $this->query = $this->mergeArrays($this->query, $filter);
        return $this;
    }

    /**
     * @return $this
     */
    public function reset()
    {
        $this->query = array();
        return $this;
    }

    /**
     * @param array $original
     * @param array $new
     *
     * @return array
     */
    protected function mergeArrays( $original = array(), $new = array() )
    {
        //loop through overwriting array
        foreach ($new as $key => $item) {

            //decide to nest or assign
            if(is_array($item)) {
                //nest deeper
                if(!isset($original[$key])) $original[$key] = array();
                $original[$key] = $this->mergeArrays($original[$key], $item);
            } else {
                //assign to output
                $original[$key] = $item;
            }
        }

        //original has been appended to
        return $original;
    }
}