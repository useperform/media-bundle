<?php

namespace Perform\MediaBundle\Importer;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Perform\MediaBundle\Entity\File;
use Perform\UserBundle\Entity\User;
use Perform\MediaBundle\Event\FileEvent;
use Mimey\MimeTypes;
use Symfony\Component\Finder\Finder;
use Perform\MediaBundle\Bucket\BucketRegistryInterface;
use Perform\MediaBundle\Location\Location;
use Perform\MediaBundle\Exception\InvalidFileSizeException;
use Perform\MediaBundle\Bucket\BucketInterface;
use Perform\MediaBundle\MediaResource;
use Perform\MediaBundle\MediaType\MediaTypeRegistry;

/**
 * Add files to the media library.
 *
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class FileImporter
{
    protected $bucketRegistry;
    protected $entityManager;
    protected $mediaTypeRegistry;
    protected $dispatcher;
    protected $mimes;

    public function __construct(BucketRegistryInterface $bucketRegistry, EntityManagerInterface $entityManager, MediaTypeRegistry $mediaTypeRegistry, EventDispatcherInterface $dispatcher)
    {
        $this->bucketRegistry = $bucketRegistry;
        $this->entityManager = $entityManager;
        $this->mediaTypeRegistry = $mediaTypeRegistry;
        $this->dispatcher = $dispatcher;
        $this->mimes = new MimeTypes();
    }

    /**
     * Import a new resource into the media library.
     *
     * @param MediaResource $resource
     * @param string|null   $bucketName The name of the bucket to store the imported file
     */
    public function import(MediaResource $resource, $bucketName = null)
    {
        $bucket = $bucketName ?
                $this->bucketRegistry->get($bucketName) :
                $this->bucketRegistry->getDefault();

        $file = File::fromResource($resource);
        $this->entityManager->beginTransaction();
        try {
            $file->setBucketName($bucket->getName());

            // set guid manually so a location can be created before saving to the database
            $file->setId($this->generateUuid());

            if ($resource->isFile()) {
                $pathname = $resource->getPath();
                $this->validateFileSize($bucket, $pathname);
                $extension = pathinfo($pathname, PATHINFO_EXTENSION);

                list($mimeType, $charset) = $this->getContentType($pathname, $extension);
                $file->setMimeType($mimeType);
                $file->setCharset($charset);

                $file->setLocation(Location::file(sprintf('%s.%s', sha1($file->getId()), $this->getSuitableExtension($mimeType, $extension))));
            } else {
                $file->setMimeType('');
                $file->setCharset('');
                $file->setLocation(Location::url($resource->getPath()));
            }

            $file->setType($this->findType($bucket, $file, $resource));
            $this->dispatcher->dispatch(FileEvent::CREATE, new FileEvent($file));
            if ($resource->isFile()) {
                $bucket->save($file->getLocation(), fopen($resource->getPath(), 'r'));
            }
            $this->dispatcher->dispatch(FileEvent::PROCESS, new FileEvent($file));
            $this->entityManager->persist($file);
            $this->entityManager->flush();

            $this->entityManager->commit();

            return $file;
        } catch (\Exception $e) {
            $location = $file->getLocation();
            if ($location instanceof Location && $bucket->has($location)) {
                $bucket->delete($location);
            }

            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Import a file into the media library.
     *
     * @param string      $pathname   The location of the file or directory
     * @param string|null $name       Optionally, the name to give the media
     * @param User|null   $owner      The optional owner of the files
     * @param string|null $bucketName The name of the bucket to store the imported files
     */
    public function importFile($pathname, $name = null, User $owner = null, $bucketName = null)
    {
        return $this->import(new MediaResource($pathname, $name, $owner), $bucketName);
    }

    /**
     * Import a directory of files into the media library.
     *
     * @param string      $pathname   The location of the directory
     * @param User|null   $owner      The optional owner of the files
     * @param string|null $bucketName The name of the bucket to store the imported files
     * @param array       $extensions Only import the files with the given extensions
     */
    public function importDirectory($pathname, User $owner = null, $bucketName = null, array $extensions = [])
    {
        $finder = Finder::create()
                ->files()
                ->in($pathname);
        foreach ($extensions as $ext) {
            $finder->name(sprintf('/\\.%s$/i', trim($ext, '.')));
        }
        $files = [];

        foreach ($finder as $file) {
            $files[] = $this->import(new MediaResource($file->getPathname(), null, $owner), $bucketName);
        }

        return $files;
    }

    /**
     * Import the URL of a file into the media library.
     *
     * @param string      $url        The URL of the file
     * @param string|null $name       The name to give the file. If null, use the filename.
     * @param User|null   $owner      The optional owner of the file
     * @param string|null $bucketName The name of the bucket to store the imported files
     */
    public function importUrl($url, $name = null, User $owner = null, $bucketName = null)
    {
        $local = tempnam(sys_get_temp_dir(), 'perform-media');
        copy($url, $local);
        if (!$name) {
            $name = basename(parse_url($url, PHP_URL_PATH));
        }
        $this->import(new MediaResource($local, $name, $owner), $bucketName);
        unlink($local);
    }

    /**
     * Remove a file from the database and delete it from its bucket.
     *
     * @param File $file
     */
    public function delete(File $file)
    {
        $bucket = $this->bucketRegistry->getForFile($file);
        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->remove($file);
            $this->entityManager->flush();
            $this->dispatcher->dispatch(FileEvent::DELETE, new FileEvent($file));
            $bucket->delete($file->getLocation());
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    /**
     * Get the mimetype and charset for a file.
     *
     * @param string $filename
     * @param string $extension
     */
    protected function getContentType($filename, $extension)
    {
        $finfo = new \Finfo(FILEINFO_MIME);
        $guess = explode('; charset=', @$finfo->file($filename));

        if (count($guess) === 2) {
            return $guess;
        }

        // best effort detection of mimetype and charset
        $mime = $extension ? $this->mimes->getMimeType($extension) : null;
        // getMimeType can return null, default to application/octet-stream
        if (!$mime) {
            $mime = 'application/octet-stream';
        }

        return [
            $mime,
            $this->defaultCharset($mime),
        ];
    }

    /**
     * Get a suitable extension for a file with the given mime type.
     *
     * @param string $mimeType  The mime type of the supplied file
     * @param string $extension The extension of the supplied file
     */
    public function getSuitableExtension($mimeType, $extension)
    {
        $validExtensions = $this->mimes->getAllExtensions($mimeType);

        if (in_array($extension, $validExtensions) || !isset($validExtensions[0])) {
            return $extension;
        }

        return $validExtensions[0];
    }

    /**
     * Can't use UuidGenerator, since it depends on EntityManager, not EntityManagerInterface.
     *
     * The UuidGenerator can replace this method when upgrading to doctrine 3.
     *
     * See https://github.com/doctrine/doctrine2/pull/6599
     */
    protected function generateUuid()
    {
        $connection = $this->entityManager->getConnection();
        $sql = 'SELECT '.$connection->getDatabasePlatform()->getGuidExpression();

        return $connection->query($sql)->fetchColumn(0);
    }

    protected function defaultCharset($mimeType)
    {
        if (substr($mimeType, 0, 5) === 'text/') {
            return 'us-ascii';
        }

        return 'binary';
    }

    protected function validateFileSize(BucketInterface $bucket, $pathname)
    {
        $filesize = filesize($pathname);
        if ($filesize < $bucket->getMinSize() || $filesize > $bucket->getMaxSize()) {
            throw new InvalidFileSizeException(sprintf(
                'Files added to the "%s" bucket must be between %s and %s bytes, the supplied file is %s bytes.',
                $bucket->getName(),
                $bucket->getMinSize(),
                $bucket->getMaxSize(),
                $filesize));
        }
    }

    protected function findType(BucketInterface $bucket, File $file, MediaResource $resource)
    {
        foreach ($bucket->getMediaTypes() as $name) {
            $type = $this->mediaTypeRegistry->get($name);
            if ($type->supports($file, $resource)) {
                return $name;
            }
        }
    }
}
