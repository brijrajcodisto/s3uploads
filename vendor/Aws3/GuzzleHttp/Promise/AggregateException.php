<?php

namespace BaghelSoft\S3Uploads\GuzzleHttp\Promise;

/**
 * Exception thrown when too many errors occur in the some() or any() methods.
 */
class AggregateException extends \BaghelSoft\S3Uploads\GuzzleHttp\Promise\RejectionException
{
    public function __construct($msg, array $reasons)
    {
        parent::__construct($reasons, sprintf('%s; %d rejected promises', $msg, count($reasons)));
    }
}
