<?php

namespace Ratus\RestClient;

class Client
{
    protected $baseuri = '';

    protected $key = '';

    protected $curl = null;

    protected $cache = array(
        'server' => '127.0.0.1',
        'port'   => 11211,
        'time'   => 15
    );

    protected $mapperNamespace = '';

    /**
     * @var \JsonMapper
     */
    protected $mapper;

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

    /**
     * @param string $resource
     * @param array  $data
     * @param object|string $classname
     *
     *
     * @return mixed
     */
    protected function get($resource = '', $data = array(), $classname = 'array', $mapcheck = false)
    {
        //generate uri
        $uri = $this->baseuri . '/' . $resource;

        //add optional query
        $query = http_build_query($data);
        if(strlen($query)) {
            $uri .= '?' . $query;
        }

        //set options
        curl_setopt($this->curl, CURLOPT_URL, $uri);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);


        if(
            isset($this->cache['time']) &&
            !!$this->cache['time']
        ) {
            //Setup memcached
            $memcached = new \Memcached();
            $memcached->addServer($this->cache['server'], $this->cache['port']);

            //Load profiles from Memcached when exists else do API call
            $key_location = md5(serialize(func_get_args()));

            if (!$result = $memcached->get($key_location)) {

                //perform API call
                $result = $this->deserialize(curl_exec($this->curl), $classname, $mapcheck, $resource);

                //cache it
                $memcached->set($key_location, $result, $this->cache['time']);
            }
        } else {

            //perform API call
            $result = $this->deserialize(curl_exec($this->curl), $classname, $mapcheck, $resource);
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
     *
     * @return array|null|object
     * @throws \JsonMapper_Exception
     */
    protected function deserialize($data, $classname = null, $mapcheck = false, $resource = '')
    {
        if(!!$mapcheck) {
            switch ($mapcheck) {
                default:

                    //build mapper class name
                    $mapclass = sprintf("%s\\%s", $this->mapperNamespace, ucfirst($mapcheck));

                    //check if it exists
                    try {
                        $mapper = new $mapclass();
                    } catch (\Exception $e) {
                        //nope, don't map
                        break;
                    }

                    $data = $mapper->standardize($data, $resource);

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
}