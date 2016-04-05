<?php

namespace Finwo\RestClient;

interface MapperInterface
{
    /**
     * Returns an array map of how to transform the data
     *
     * @param string $resource
     *
     * @return array
     */
    public static function getMap($resource = '');
}