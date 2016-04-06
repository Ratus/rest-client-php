<?php

namespace Ratus\RestClient;

interface MapperInterface
{
    /**
     * Standardizes data from the API to data array
     *
     * @param string $data
     * @param string $resource
     * @param bool   $reverseMap
     *
     * @return array|string
     */
    public function standardize($data = '', $resource = '', $reverseMap = false);

    /**
     * @param string $resource
     *
     * @return bool|array
     */
    public function getMap($resource = '');

    /**
     * @param string $resource
     *
     * @return bool|array
     */
    public function getReverseMap($resource = '');
}