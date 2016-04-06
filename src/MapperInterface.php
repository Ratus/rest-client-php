<?php

namespace Ratus\RestClient;

interface MapperInterface
{
    /**
     * Returns an array map of how to transform the data
     *
     * @param string $resource
     *
     * @return array
     */
    public function getMap($resource = '');
}