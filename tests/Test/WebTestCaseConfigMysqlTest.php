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
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Liip\Acme\Tests\AppConfigMysql\AppConfigMysqlKernel;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zalas\Injector\PHPUnit\Symfony\TestCase\SymfonyTestContainer;
use Zalas\Injector\PHPUnit\TestCase\ServiceContainerTestCase;

/**
 * Test MySQL database.
 *
 * The following tests require a connection to a MySQL database,
 * they are disabled by default (see phpunit.xml.dist).
 *
 * In order to run them, you have to set the MySQL connection
 * parameters in the Tests/AppConfigMysql/config.yml file and
 * add “--exclude-group ""” when running PHPUnit.
 *
 * Use Tests/AppConfigMysql/AppConfigMysqlKernel.php instead of
 * Tests/App/AppKernel.php.
 * So it must be loaded in a separate process.
 *
 * @preserveGlobalState disabled
 * @IgnoreAnnotation("group")
 */
class WebTestCaseConfigMysqlTest extends KernelTestCase implements ServiceContainerTestCase
{
    use SymfonyTestContainer;

    /**
     * @var EntityManager
     * @inject doctrine
     */
    protected $entityManager;

    /**
     * @var DatabaseToolCollection
     * @inject liip_test_fixtures.services.database_tool_collection
     */
    private $databaseToolCollection;

    /**
     * @var AbstractDatabaseTool
     */
    protected $databaseTool;

    public function setUp(): void
    {
        parent::setUp();

        $this->assertInstanceOf(DatabaseToolCollection::class, $this->databaseToolCollection);

        $this->databaseTool = $this->databaseToolCollection->get();
    }

    protected static function getKernelClass(): string
    {
        return AppConfigMysqlKernel::class;
    }

    /**
     * Data fixtures.
     *
     * @group mysql
     */
    public function testLoadEmptyFixtures(): void
    {
        $fixtures = $this->databaseTool->loadFixtures([]);

        $this->assertInstanceOf(
            'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            $fixtures
        );
    }

    /**
     * @group mysql
     */
    public function testLoadFixtures(): void
    {
        $fixtures = $this->databaseTool->loadFixtures([
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadUserData',
        ]);

        $this->assertInstanceOf(
            'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            $fixtures
        );

        $repository = $fixtures->getReferenceRepository();

        $this->assertInstanceOf(
            'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            $fixtures
        );

        $user1 = $repository->getReference('user');

        $this->assertSame(1, $user1->getId());
        $this->assertSame('foo bar', $user1->getName());
        $this->assertSame('foo@bar.com', $user1->getEmail());
        $this->assertTrue($user1->getEnabled());

        // Load data from database
        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy([
                'id' => 1,
            ]);

        $this->assertSame(
            'foo@bar.com',
            $user->getEmail()
        );

        $this->assertTrue(
            $user->getEnabled()
        );
    }

    /**
     * @group mysql
     */
    public function testAppendFixtures(): void
    {
        $this->databaseTool->loadFixtures([
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadUserData',
        ]);

        $this->databaseTool->loadFixtures(
            ['Liip\Acme\Tests\App\DataFixtures\ORM\LoadSecondUserData'],
            true
        );

        // Load data from database
        $users = $this->entityManager->getRepository('LiipAcme:User')
            ->findAll();

        // Check that there are 3 users.
        $this->assertCount(
            3,
            $users
        );

        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user1 = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy([
                'id' => 1,
            ]);

        $this->assertNotNull($user1);

        $this->assertSame(
            'foo@bar.com',
            $user1->getEmail()
        );

        $this->assertTrue(
            $user1->getEnabled()
        );

        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user3 = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy([
                'id' => 3,
            ]);

        $this->assertNotNull($user3);

        $this->assertSame(
            'bar@foo.com',
            $user3->getEmail()
        );

        $this->assertTrue(
            $user3->getEnabled()
        );
    }

    /**
     * Data fixtures and purge.
     *
     * Purge modes are defined in
     * Doctrine\Common\DataFixtures\Purger\ORMPurger.
     *
     * @group mysql
     */
    public function testLoadFixturesAndExcludeFromPurge(): void
    {
        $fixtures = $this->databaseTool->loadFixtures([
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadUserData',
        ]);

        $this->assertInstanceOf(
            'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            $fixtures
        );

        // Check that there are 2 users.
        $this->assertSame(
            2,
            count($this->entityManager->getRepository('LiipAcme:User')
                ->findAll())
        );

        $this->databaseTool->setExcludedDoctrineTables(['liip_user']);
        $this->databaseTool->loadFixtures([], false, null, 'doctrine', ORMPurger::PURGE_MODE_TRUNCATE);

        // The exclusion from purge worked, the user table is still alive and well.
        $this->assertSame(
            2,
            count($this->entityManager->getRepository('LiipAcme:User')
                ->findAll())
        );
    }

    /**
     * Data fixtures and purge.
     *
     * Purge modes are defined in
     * Doctrine\Common\DataFixtures\Purger\ORMPurger.
     *
     * @group mysql
     */
    public function testLoadFixturesAndPurge(): void
    {
        $fixtures = $this->databaseTool->loadFixtures([
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadUserData',
        ]);

        $this->assertInstanceOf(
            'Doctrine\Common\DataFixtures\Executor\ORMExecutor',
            $fixtures
        );

        $users = $this->entityManager->getRepository('LiipAcme:User')
            ->findAll();

        // Check that there are 2 users.
        $this->assertCount(
            2,
            $users
        );

        $this->databaseTool->loadFixtures([], false, null, 'doctrine', ORMPurger::PURGE_MODE_DELETE);

        // The purge worked: there is no user.
        $users = $this->entityManager->getRepository('LiipAcme:User')
            ->findAll();

        $this->assertCount(
            0,
            $users
        );

        // Reload fixtures
        $this->databaseTool->loadFixtures([
            'Liip\Acme\Tests\App\DataFixtures\ORM\LoadUserData',
        ]);

        $users = $this->entityManager->getRepository('LiipAcme:User')
            ->findAll();

        // Check that there are 2 users.
        $this->assertCount(
            2,
            $users
        );

        $this->databaseTool->loadFixtures([], false, null, 'doctrine', ORMPurger::PURGE_MODE_TRUNCATE);

        // The purge worked: there is no user.
        $this->assertSame(
            0,
            count($this->entityManager->getRepository('LiipAcme:User')
                ->findAll())
        );
    }

    /**
     * Use nelmio/alice.
     *
     * @group mysql
     */
    public function testLoadFixturesFiles(): void
    {
        $fixtures = $this->databaseTool->loadAliceFixture([
            '@AcmeBundle/DataFixtures/ORM/user.yml',
        ]);

        $this->assertIsArray($fixtures);

        // 10 users are loaded
        $this->assertCount(
            10,
            $fixtures
        );

        $users = $this->entityManager->getRepository('LiipAcme:User')
            ->findAll();

        $this->assertSame(
            10,
            count($users)
        );

        /** @var \Liip\Acme\Tests\App\Entity\User $user */
        $user = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy([
                'id' => 1,
            ]);

        $this->assertTrue(
            $user->getEnabled()
        );

        $user = $this->entityManager->getRepository('LiipAcme:User')
            ->findOneBy([
                'id' => 10,
            ]);

        $this->assertTrue(
            $user->getEnabled()
        );
    }
}
