<?php

namespace Perform\MediaBundle\Tests\Bucket;

use PHPUnit\Framework\TestCase;
use Perform\MediaBundle\Bucket\BucketRegistry;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Perform\MediaBundle\Entity\File;
use Perform\MediaBundle\Exception\BucketNotFoundException;
use Perform\MediaBundle\Bucket\BucketInterface;

/**
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class BucketRegistryTest extends TestCase
{
    protected $locator;

    public function setUp()
    {
        $this->locator = $this->getMockBuilder(ServiceLocator::class)
                       ->disableOriginalConstructor()
                       ->getMock();
        $this->registry = new BucketRegistry($this->locator, 'default');
    }

    private function mockBucket($name)
    {
        $bucket = $this->createMock(BucketInterface::class);
        $this->locator->expects($this->any())
            ->method('has')
            ->with($name)
            ->will($this->returnValue(true));
        $this->locator->expects($this->any())
            ->method('get')
            ->with($name)
            ->will($this->returnValue($bucket));

        return $bucket;
    }

    public function testGet()
    {
        $bucket = $this->mockBucket('images');
        $this->assertSame($bucket, $this->registry->get('images'));
    }

    public function testGetUnknown()
    {
        $this->expectException(BucketNotFoundException::class);
        $this->registry->get('government_docs');
    }

    public function testGetDefault()
    {
        $bucket = $this->mockBucket('default');
        $this->assertSame($bucket, $this->registry->getDefault());
    }

    public function testGetForFile()
    {
        $file = new File();
        $file->setBucketName('office_docs');
        $bucket = $this->mockBucket('office_docs');

        $this->assertSame($bucket, $this->registry->getForFile($file));
    }
}
