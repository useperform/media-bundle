<?php

namespace Perform\MediaBundle\DependencyInjection;

use Imagine\Gd\Imagine as GdImagine;
use Imagine\Imagick\Imagine as ImagickImagine;
use Imagine\Gmagick\Imagine as GmagickImagine;
use Perform\MediaBundle\Exception\MediaTypeException;
use Perform\MediaBundle\MediaType\AudioType;
use Perform\MediaBundle\MediaType\ImageType;
use Perform\MediaBundle\MediaType\OtherType;
use Perform\MediaBundle\MediaType\PdfType;
use Symfony\Component\DependencyInjection\Definition;
use Perform\MediaBundle\MediaType\YoutubeType;
use Perform\MediaBundle\Event\Events;

/**
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class MediaTypeDefinitionFactory
{
    protected static $imagineEngines = [
        'gd' => GdImagine::class,
        'imagick' => ImagickImagine::class,
        'gmagick' => GmagickImagine::class,
    ];

    public function create(array $config)
    {
        switch ($config['type']) {
        case 'image':
            $definition = new Definition(ImageType::class);
            $definition->setArguments([
                new Definition(static::$imagineEngines[$config['engine']]),
                $config['widths'],
            ]);

            return $definition;
        case 'pdf':
            return new Definition(PdfType::class);
        case 'audio':
            return new Definition(AudioType::class);
        case 'youtube':
            $definition = new Definition(YoutubeType::class);
            $definition->addTag('kernel.event_listener', [
                'event' => Events::IMPORT_URL,
                'method' => 'onUrlImport',
            ]);
            return $definition;
        case 'other':
            return new Definition(OtherType::class);
        default:
            throw new MediaTypeException(sprintf('Unknown media type "%s" requested. Available types are "image", "audio", "pdf", "youtube", and "other".', $config['type']));
        }
    }
}
