<?php

namespace Admin\MediaBundle\Url;
use Admin\MediaBundle\Entity\File;

/**
 * SimpleFileUrlGenerator
 *
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class SimpleFileUrlGenerator implements FileUrlGeneratorInterface
{
    protected $rootUrl;

    public function __construct($rootUrl)
    {
        $this->rootUrl = rtrim($rootUrl, '/').'/';
    }

    public function getRootUrl()
    {
        return $this->rootUrl;
    }

    public function getUrl(File $file)
    {
        return $this->rootUrl . $file->getFilename();
    }
}
