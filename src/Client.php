<?php

namespace Ratus\RestClient;

use Finwo\Cache\Cache;
use Finwo\Cache\CacheInterface;
use Finwo\PropertyAccessor\PropertyAccessor;

class Client
{
    protected $baseuri = '';

    protected $key = '';

    protected $curl = null;

    protected $cache = array(
        'server' => '127.0.0.1',
        'port'   => 11211,
        'time'   => 30
    );

    // Settings for automatic pagination handling
    protected $paginationType  = 'none';
    protected $offsetType      = 'offset';
    protected $offsetLocalKey  = 'offset';
    protected $offsetRemoteKey = 'offset';
    protected $pageLocalKey    = 'page';
    protected $pageRemoteKey   = 'page';
    protected $limitLocalKey   = 'limit';
    protected $limitRemoteKey  = 'limit';

    protected $mapperNamespace = '';

    /**
     * @var \JsonMapper
     */
    protected $mapper;

    /**
     * @var PropertyAccessor
     */
    protected $accessor;

    /**
     * @var CacheInterface
     */
    protected $cacheObject;

    /**
     * @return \JsonMapper
     */
    protected function getMapper()
    {
        if(is_null($this->mapper)) {
            $this->mapper = new \JsonMapper();
        }

        return $this->mapper;
    }

    /**
     * @return PropertyAccessor
     */
    protected function getPropertyAccessor()
    {
        if(is_null($this->accessor)) {
            $this->accessor = new PropertyAccessor(true);
        }

        return $this->accessor;
    }

    public function __construct($baseuri = '')
    {
        //set the base URI from param
        if(strlen($baseuri)) $this->baseuri = $baseuri;
        $this->curl = curl_init();

        //handle cache before
        if(
            isset($this->cache['time']) &&
            !!$this->cache['time']
        ) {
            $this->cache['port'] = isset( $this->cache['port'] ) ? $this->cache['port'] : 11211 ;
            $this->cache['server'] = isset( $this->cache['server'] ) ? $this->cache['server'] : '127.0.0.1' ;
        }
    }

    protected function cacheData($key, $newValue = null, $time = null)
    {
        // use default time of 30 or the object's
        if(is_null($time)) {
            $time = isset($this->cache['time']) ? intval($this->cache['time']) : 30 ;
        }

        // create cache object if we don't have it yet
        if(is_null($this->cacheObject)) {

            // let the cache object detect what to use
            $this->cacheObject = Cache::init('detect', $this->cache);
        }

        //insert if needed
        if(!is_null($newValue)) {
            return $this->cacheObject->store($key, $newValue, $time);
        }

        //return the value at the key location
        return $this->cacheObject->fetch($key, $time);
    }

    public function cget($resource = '', $data = array(), $classname = 'array', $mapcheck = false)
    {
        // pre-build accessor, we'll probably need it
        $accessor = $this->getPropertyAccessor();

        // Pre-parse data
        $data = Mapper::deserialize($data);

        // Default limit
        $limit = 60;

        // Fetch if we are able to return directly
        $direct = (
            is_null($offset = $accessor->get($data, $this->offsetLocalKey)) &&
            is_null($page   = $accessor->get($data, $this->pageLocalKey))
        );

        // Return directly if possible, saves us some work
        if ($direct) {

            return $this->get($resource, json_encode($data), $classname, $mapcheck);
        }

        //handle first page of pagination
        switch ($this->paginationType) {
            case 'offset':
                // Act as offset
                switch ($this->offsetType) {
                    case 'offset':
                        // It's a match, just rename the variable
                        $accessor->remove($data, $this->offsetLocalKey);
                        $accessor->set($data, $this->offsetRemoteKey);
                        $data = json_encode($data);
                        return $this->get($resource, $data, $classname, $mapcheck);
                        break;
                    case 'page':
                        // Pagination needs simulation
                        $accessor->remove($data, $this->offsetLocalKey);
                        $limit = intval($accessor->get($data, $this->limitLocalKey));

                        // Generate page numbers
                        $pageLow  = floor($offset/$limit)+1;
                        $pageHigh = ceil($offset/$limit)+1;

                        // Fetch low data
                        $accessor->set($data, $this->pageRemoteKey, $pageLow);
                        $dataLow  = $this->get($resource, json_encode($data), $classname, $mapcheck);

                        // Fetch high data
                        $accessor->set($data, $this->pageRemoteKey, $pageHigh);
                        $dataHigh = $this->get($resource, json_encode($data), $classname, $mapcheck);

                        // Start building result
                        $result = array_merge($dataLow, $dataHigh);

                        // Match offset & limit to the request, return that data
                        $remove = $offset-($limit*($pageLow-1));

                        return array_filter($result, function($item) use (&$limit, &$remove) {
                            // Remove first X results
                            if (0<$remove--) {
                                return false;
                            }

                            if (0>=$limit--) {
                                return false;
                            }

                            return true;
                        });
                }
                break;
            case 'page':
                // Act as paging
                switch ($this->offsetType) {
                    case 'offset':
                        // Pagination needs simulation
                        break;
                    case 'page':
                        // It's a match, just rename the variable
                        break;
                }
                break;
            default:
                // Don't handle pagination
                break;
        }

        // We failed
        return null;
    }

    /**
     * @param string $resource
     * @param array  $data
     * @param object|string $classname
     *
     *
     * @return mixed
     */
    public function get($resource = '', $data = array(), $classname = 'array', $mapcheck = false)
    {
        //use cache if we we're asked to
        $useCache = isset($this->cache['time']) && !!$this->cache['time'] ;
        if ($useCache) {
            //use cache
            $key = md5(serialize(func_get_args()));
            if($result = $this->cacheData($key)) {
                return $result;
            }
        }

        //generate uri
        $uri = $this->baseuri . '/' . $resource;

        //reverse map the data if needed
        if(!!$mapcheck) {
            $data = $this->deserialize($data, null, $mapcheck, $resource, true);
        }

        //remove empty values
        $data = $this->array_filter_recursive($data);

        //add optional query
        if(count($data)) {
            $uri .= '?' . http_build_query($data);
        }

        //set options
        curl_setopt($this->curl, CURLOPT_URL, $uri);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

        //run request
        $result = curl_exec($this->curl);

        //break on error
        if ($result === false) {
            return false;
        }

        //deserialize what we got
        $result = $this->deserialize($result, $classname, $mapcheck, $resource);

        //cache the result if we were asked to
        if ($useCache) {
            $this->cacheData($key, $result);
        }

        return $result;
    }

    /**
     * deserialize()
     *
     * Handles mapping API calls to defaults or classes
     *
     * @param        $data
     * @param null   $classname
     * @param bool   $mapcheck
     * @param string $resource
     * @param bool   $reverse
     *
     * @return array|null|object|string
     * @throws \JsonMapper_Exception
     */
    protected function deserialize($data, $classname = null, $mapcheck = false, $resource = '', $reverse = false)
    {
        if(!!$mapcheck) {
            switch ($mapcheck) {
                default:

                    //build mapper class name
                    $mapclass = sprintf("%s\\%s", $this->mapperNamespace, ucfirst($mapcheck));

                    //check if it exists
                    try {
                        /** @var MapperInterface $mapper */
                        $mapper = new $mapclass();
                    } catch (\Exception $e) {
                        //nope, don't map
                        break;
                    }

                    $data = $mapper->standardize($data, $resource, $reverse);

                    break;
            }

            //we didn't catch with a map, so don't try again on recursion
            $mapcheck = false;
        }

        //catch error
        if(is_null($classname)) {
            $classname = 'array';
        }

        //handle classname
        if(is_string($classname)) {
            switch($classname) {
                case 'array':
                    //do nothing, yet
                    break;
                case 'raw':
                    //well, you'll get it raw
                    return $data;
                default:
                    $classname = new $classname();
                    break;
            }
        }

        //handle strings
        if( is_string($data) || is_array($data) ) {
            $data = $this->destring($data);
        }

        //handle some REST uses
        if(isset($data->result)) {
            $data = $data->result;
        }

        //handle arrays
        if(is_array($data)) {
            foreach($data as $key => $value) {

                //to not overwrite data
                $tmp = $classname;
                if(is_object($classname)) $tmp = get_class($classname);

                //handle the data
                $data[$key] = $this->deserialize($value, $tmp, $mapcheck);
            }
            return $data;
        }

        //so you wanted an array?
        if($classname === 'array') {
            return json_decode(json_encode($data), true);
        }

        //catch an error waiting to occur
        if(is_null($data)) return null;

        return $this->getMapper()->map($data, $classname);
    }

    protected function array_filter_recursive($input)
    {
        foreach ($input as &$value)
        {
            if (is_array($value))
            {
                $value = $this->array_filter_recursive($value);
            }
        }

        return array_filter($input);
    }

    /**
     * @param $data
     *
     * @return null
     */
    protected function destring($data)
    {
        if(is_array($data)) {
            $data = json_encode($data);
        }

        //try json
        try {
            return json_decode($data);
        } catch(\Exception $e) {
            //do nothing
        }

        //we tried, we failed
        return null;
    }

    /**
     * Returns a fresh query object for the current client
     *
     * @param string $resource
     * @param string $classname
     * @param bool   $mapcheck
     *
     * @return Query
     */
    protected function createQuery($resource = '', $classname = 'array', $mapcheck = false)
    {
        return new Query($this, $resource, $classname, $mapcheck);
    }
}
