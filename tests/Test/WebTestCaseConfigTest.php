<?php

declare(strict_types=1);

/*
 * This file is part of the Liip/TestFixturesBundle
 *
 * (c) Lukas Kahwe Smith <smith@pooteeweet.org>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Liip\Acme\Tests\Test;

use Doctrine\Common\Annotations\Annotation\IgnoreAnnotation;
use Doctrine\ORM\EntityManager;
use Liip\Acme\Tests\AppConfig\AppConfigKernel;
use Liip\TestFixturesBundle\Annotations\DisableDatabaseCache;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Zalas\Injector\PHPUnit\Symfony\TestCase\SymfonyTestContainer;
use Zalas\Injector\PHPUnit\TestCase\ServiceContainerTestCase;

/**
 * Tests that configuration has been loaded and users can be logged in.
 *
 * Use Tests/AppConfig/AppConfigKernel.php instead of
 * Tests/App/AppKernel.php.
 * So it must be loaded in a separate process.
 *
 * @preserveGlobalState disabled
 *
 * Avoid conflict with PHPUnit annotation when reading QueryCount
 * annotation:
 *
 * @IgnoreAnnotation("expectedException")
 */
class WebTestCaseConfigTest extends KernelTestCase implements ServiceContainerTestCase
{
    use SymfonyTestContainer;

    /**
     * @var EntityManager
     * @inject doctrine
     */
    private $entityManager;

    /**
     * @var ContainerInterface
     * @inject
     */
    private $containerTest;

    /**
     * @var DatabaseToolCollection
     * @inject liip_test_fixtures.services.database_tool_collection
     */
    private $databaseToolCollection;

    /**
     * @var AbstractDatabaseTool
     */
    private $databaseTool;

    protected static function getKernelClass(): string
    {
        return AppConfigKernel::class;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->assertInstanceOf(DatabaseToolCollection::class, $this->databaseToolCollection);

        $this->databaseTool = $this->databaseToolCollection->get();
    }

    /**
     * Load Data Fixtures with custom loader defined in configuration.
     */
    public function testLoadFixturesFilesWithCustomProvider(): void
    {
        // Load default Data Fixtures.
        $fixtures = $this->databaseTool->loadAliceFixture([
            '@AcmeBundle/DataFixtures/ORM/user.yml',
        ]);

        $this->assertIsArray($fixtures);

        // 10 users are loaded
        $this->assertCount(
            10,
            $fixtures
        );

        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user = $fixtures['id1'];

        // The custom provider has not been used successfully.
        $this->assertStringStartsNotWith(
            'foo',
            $user->getName()
        );

        // Load Data Fixtures with custom loader defined in configuration.
        $fixtures = $this->databaseTool->loadAliceFixture([
            '@AcmeBundle/DataFixtures/ORM/user_with_custom_provider.yml',
        ]);

        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user = $fixtures['id1'];

        // The custom provider "foo" has been loaded and used successfully.
        $this->assertSame(
            'fooa string',
            $user->getName()
        );
    }

    /**
     * @DisableDatabaseCache()
     */
    public function testCacheCanBeDisabled(): void
    {
        $fixtures = [
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadDependentUserData',
        ];

        $this->databaseTool->loadFixtures($fixtures);

        // Load data from database
        /** @var \Liip\Acme\Tests\App\Entity\User $user1 */
        $user1 = $this->entityManager->getRepository('LiipAcme:User')->findOneBy(['id' => 1]);

        // Store random data, in order to check it after reloading fixtures.
        $user1Salt = $user1->getSalt();

        sleep(2);

        // Reload the fixtures.
        $this->databaseTool->loadFixtures($fixtures);

        /** @var \Liip\Acme\Tests\App\Entity\User $user1 */
        $user1 = $this->entityManager->getRepository('LiipAcme:User')->findOneBy(['id' => 1]);

        //The salt are not the same because cache were not used
        $this->assertNotSame($user1Salt, $user1->getSalt());
    }

    /**
     * Update a fixture file and check that the cache will be refreshed.
     */
    public function testBackupIsRefreshed(): void
    {
        // MD5 hash corresponding to these fixtures files.
        $md5 = '779547fe76503b90075f8d15c74a28be';

        $fixtures = [
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadDependentUserData',
        ];

        $this->databaseTool->loadFixtures($fixtures);

        // Load data from database
        /** @var \Liip\Acme\Tests\App\Entity\User $user1 */
        $user1 = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy(['id' => 1]);

        // Store random data, in order to check it after reloading fixtures.
        $user1Salt = $user1->getSalt();

        $dependentFixtureFilePath = static::$kernel->locateResource(
            '@AcmeBundle/DataFixtures/ORM/LoadUserData.php'
        );

        $dependentFixtureFilemtime = filemtime($dependentFixtureFilePath);

        $databaseFilePath = $this->containerTest->getParameter('kernel.cache_dir').'/test_sqlite_'.$md5.'.db';

        if (!is_file($databaseFilePath)) {
            $this->markTestSkipped($databaseFilePath.' is not a file.');
        }

        $databaseFilemtime = filemtime($databaseFilePath);

        sleep(2);

        // Reload the fixtures.
        $this->databaseTool->loadFixtures($fixtures);

        // The mtime of the file has not changed.
        $this->assertSame(
            $dependentFixtureFilemtime,
            filemtime($dependentFixtureFilePath),
            'File modification time of the fixture has been updated.'
        );

        // The backup has not been updated.
        $this->assertSame(
            $databaseFilemtime,
            filemtime($databaseFilePath),
            'File modification time of the backup has been updated.'
        );

        $user1 = $this->entityManager->getRepository('LiipAcme:User')->findOneBy(['id' => 1]);

        // Check that random data has not been changed, to ensure that backup was created and loaded successfully.
        $this->assertSame($user1Salt, $user1->getSalt());

        sleep(2);

        // Update the filemtime of the fixture file used as a dependency.
        touch($dependentFixtureFilePath);

        $this->databaseTool->loadFixtures($fixtures);

        // The mtime of the fixture file has been updated.
        $this->assertGreaterThan(
            $dependentFixtureFilemtime,
            filemtime($dependentFixtureFilePath),
            'File modification time of the fixture has not been updated.'
        );

        // The backup has been refreshed: mtime is greater.
        $this->assertGreaterThan(
            $databaseFilemtime,
            filemtime($databaseFilePath),
            'File modification time of the backup has not been updated.'
        );

        $user1 = $this->entityManager->getRepository('LiipAcme:User')->findOneBy(['id' => 1]);

        // Check that random data has been changed, to ensure that backup was not used.
        $this->assertNotSame($user1Salt, $user1->getSalt());
    }
}
