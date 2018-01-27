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

/**
 * Add files to the media library.
 *
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class FileImporter
{
    protected $bucketRegistry;
    protected $entityManager;
    protected $repository;
    protected $dispatcher;
    protected $mimes;

    public function __construct(BucketRegistryInterface $bucketRegistry, EntityManagerInterface $entityManager, EventDispatcherInterface $dispatcher)
    {
        $this->bucketRegistry = $bucketRegistry;
        $this->entityManager = $entityManager;
        $this->repository = $entityManager->getRepository('PerformMediaBundle:File');
        $this->dispatcher = $dispatcher;
        $this->mimes = new MimeTypes();
    }

    /**
     * Import a file or directory into the media library.
     *
     * @param string      $pathname   The location of the file or directory
     * @param string|null $bucketName The name of the bucket to store the imported files
     * @param User        $user       The optional owner of the files
     */
    public function import($pathname, $bucketName = null, User $owner = null)
    {
        return is_dir($pathname) ?
            $this->importDirectory($pathname, $bucketName, $owner) :
            $this->importFile($pathname, $bucketName, $owner);
    }

    /**
     * Import a file into the media library.
     *
     * @param string      $pathname   The location of the file
     * @param string|null $name       Optionally, the name to give the file
     * @param string|null $bucketName The name of the bucket to store the imported file
     * @param User|null   $user       The optional owner of the file
     */
    public function importFile($pathname, $name = null, $bucketName = null, User $owner = null)
    {
        if (!file_exists($pathname)) {
            throw new \InvalidArgumentException("$pathname does not exist.");
        }
        $bucket = $bucketName ?
                $this->bucketRegistry->get($bucketName) :
                $this->bucketRegistry->getDefault();
        $this->validateFileSize($bucket, $pathname);

        $file = new File();
        $file->setName($name ?: basename($pathname));
        $this->entityManager->beginTransaction();
        try {
            $file->setBucketName($bucket->getName());

            // set guid manually to have it available for creating a file path before insert
            $file->setId($this->generateUuid());
            $extension = pathinfo($pathname, PATHINFO_EXTENSION);

            list($mimeType, $charset) = $this->getContentType($pathname, $extension);
            $file->setMimeType($mimeType);
            $file->setCharset($charset);

            $file->setLocation(Location::file(sprintf('%s.%s', sha1($file->getId()), $this->getSuitableExtension($mimeType, $extension))));
            if ($owner) {
                $file->setOwner($owner);
            }

            $this->dispatcher->dispatch(FileEvent::CREATE, new FileEvent($file));
            $bucket->save($file->getLocation(), fopen($pathname, 'r'));
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
     * Import a directory of files into the media library.
     *
     * @param string      $pathname   The location of the directory
     * @param array       $extensions Only import the files with the given extensions
     * @param string|null $bucketName The name of the bucket to store the imported files
     * @param User        $user       The optional owner of the files
     */
    public function importDirectory($pathname, array $extensions = [], $bucketName = null, User $owner = null)
    {
        $finder = Finder::create()
                ->files()
                ->in($pathname);
        foreach ($extensions as $ext) {
            $finder->name(sprintf('/\\.%s$/i', trim($ext, '.')));
        }
        $files = [];

        foreach ($finder as $file) {
            $files[] = $this->importFile($file->getPathname(), null, $bucketName, $owner);
        }

        return $files;
    }

    /**
     * Import the URL of a file into the media library.
     *
     * @param string      $url        The URL of the file
     * @param string|null $name       The name to give the file. If null, use the filename.
     * @param string|null $bucketName The name of the bucket to store the imported files
     * @param User        $user       The optional owner of the file
     */
    public function importUrl($url, $name = null, $bucketName = null, User $owner = null)
    {
        $local = tempnam(sys_get_temp_dir(), 'perform-media');
        copy($url, $local);
        if (!$name) {
            $name = basename(parse_url($url, PHP_URL_PATH));
        }
        $this->importFile($local, $name, $bucketName, $owner);
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
}
