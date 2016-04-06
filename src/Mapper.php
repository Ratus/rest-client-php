<?php

namespace Ratus\RestClient;

class Mapper implements MapperInterface
{
    /**
     * @var array
     */
    protected $fullmap = array();

    /**
     * Standardizes data from the API to data array
     *
     * @param string $data
     * @param string $resource
     * @return array
     */
    public function standardize($data = '', $resource = '')
    {
        if(!($map = $this->getMap($resource))) {
            //don't do anything if we can't map
            return $data;
        }
        
        //Fetch flat array of the data
        $result = $this->runMap($data, $map);

        //return a deepened array
        return self::addDepth($result);
    }
    
    /**
     * @param string $data
     * @param array  $map
     * @param array  $index
     *
     * @return array
     */
    protected function runMap($data = '', $map = array(), $index = array())
    {
        //first, make the data useful to us
        $data = self::deserialize($data);

        //the array we'll build to
        $result = array();

        //run through the map
        foreach($map as $key => $value) {
            //based on the first char, because we might have multiple loops
            switch(substr($key, 0, 1)) {
                case '#':
                    //loop
                    foreach($data as $dkey => $dvalue) {

                        //save the key, we'll probably need it
                        $index[$key] = $dkey;

                        //fetch the data
                        $result = array_merge(
                            $result,
                            $this->runMap($data[$dkey], $value, $index)
                        );
                    }

                    break;
                default:

                    //skip if the key does not exist
                    if(!isset($data[$key])) {
                        continue;
                    }

                    //see if we need translation
                    if($value instanceof \Closure) {
                        $result = array_merge(
                            call_user_func_array($value, array(
                                $data[$key],
                                $index
                            )),
                            $result
                        );
                    }

                    //fetch what we'll do
                    switch(gettype($value)) {
                        case 'array':
                            //we're not done yet

                            $result = array_merge(
                                $this->runMap($data[$key], $value, $index),
                                $result
                            );

                            break;
                        case 'string':
                            //yay, we know where the value belongs

                            //replace any indexes
                            $value = str_replace(array_keys($index), array_values($index), $value);

                            //store the data
                            $result[$value] = $data[$key];

                            break;
                        default:
                            //sorry, don't know this type
                            break;
                    }
                    break;
            }
        }

        return $result;
    }

    protected static function deserialize($data)
    {
        //prevent to run stuff that's already an array
        if(is_array($data)) return $data;

        //try json
        try {
            return json_decode($data, true);
        } catch (\Exception $e) {
            //dammit
        }

        //we tried, we failed
        return null;
    }

    /**
     * @param array $input
     *
     * @return array
     */
    protected static function addDepth($input = array())
    {
        $output = array();

        //loop through array elements
        array_walk($input, function($value, $path) use (&$output) {

            //split path
            $parts = explode('|', $path);
            $target = &$output;

            //iterate down
            foreach($parts as $part) {
                $target = &$target[$part];
            }

            //save value
            $target = $value;
        });

        return $output;
    }

    /**
     * @param string $resource
     *
     * @return bool
     */
    public function getMap($resource = '')
    {
        $map = $this->fullmap;
        $path = explode('/', $resource);

        while(count($path)) {
            try {
                $map = $map[array_shift($path)];
            } catch (\Exception $e) {
                return false;
            }
        }

        return $map;
    }
}