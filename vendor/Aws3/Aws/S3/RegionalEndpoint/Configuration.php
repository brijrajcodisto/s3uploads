<?php

namespace BaghelSoft\S3Uploads\Aws\S3\RegionalEndpoint;

class Configuration implements \BaghelSoft\S3Uploads\Aws\S3\RegionalEndpoint\ConfigurationInterface
{
    private $endpointsType;
    public function __construct($endpointsType)
    {
        $this->endpointsType = strtolower($endpointsType);
        if (!in_array($this->endpointsType, ['legacy', 'regional'])) {
            throw new \InvalidArgumentException("Configuration parameter must either be 'legacy' or 'regional'.");
        }
    }
    /**
     * {@inheritdoc}
     */
    public function getEndpointsType()
    {
        return $this->endpointsType;
    }
    /**
     * {@inheritdoc}
     */
    public function toArray()
    {
        return ['endpoints_type' => $this->getEndpointsType()];
    }
}
