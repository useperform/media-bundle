<?php

namespace Perform\MediaBundle\MediaType;

use Perform\MediaBundle\Entity\File;
use Perform\MediaBundle\MediaResource;
use Perform\MediaBundle\Bucket\BucketInterface;

/**
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class PdfType implements MediaTypeInterface
{
    public function supports(File $file, MediaResource $resource)
    {
        return $file->getMimeType() === 'application/pdf';
    }

    public function process(File $file, MediaResource $resource, BucketInterface $bucket)
    {
        //create thumbnail
    }

    public function getSuitableLocation(File $file, array $criteria)
    {
        return $file->getLocation();
    }
}