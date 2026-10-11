<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Storage Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Storage\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Enum\TeamRoleEnum;
use Uhifadhi\Storage\Access\StorageConcerns;
use Uhifadhi\Storage\Controller\FilesController;
use Uhifadhi\Storage\Tests\Integration\Fixtures\Area\HostArea;

/**
 * WHERE THE ORGANIZATION'S FILES GO IS THE WHOLE ORGANIZATION'S SETTING.
 * Somebody holding storage.configure at one area reads the settings and does
 * not change where every area's files go: the switch and the move are asked
 * of the whole organization, and the card offers no form to anybody they
 * would refuse.
 */
final class StorageTargetReachTest extends FilesTestCase
{
    public function testSomebodyConfiguringStorageAtOneAreaReadsTheSettingsAndChangesNothing(): void
    {
        $client = static::createClient();
        $kilimani = new HostArea()->setName('Kilimani');
        self::entities()->persist($kilimani);
        $client->loginUser($this->holder('area.warden@example.test', new Placement()->inAreas([$kilimani])));

        $crawler = $client->request('GET', '/files/settings');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action^="/files/settings/target"]'), 'no form to change where files go');

        $client->request('POST', '/files/settings/target', ['place' => 'archive', '_token' => 'whatever']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSomebodyConfiguringStorageAcrossTheOrganizationIsOfferedTheSwitch(): void
    {
        $client = static::createClient();
        $client->loginUser($this->holder('org.planner@example.test', new Placement()->acrossTheOrganization()));

        $crawler = $client->request('GET', '/files/settings');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('form[action="/files/settings/target"]')->count());
    }

    private static function entities(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function holder(string $email, Placement $placement): User
    {
        $entityManager = self::entities();
        $records = new Department()->setName('Records '.$email);
        $position = new Position()->setName('Storage keeper '.$email);
        $pairs = [StorageConcerns::STORAGE_READ, FilesController::SETTINGS_PAIR];
        $position->setGrantValues($pairs, $pairs);
        $person = new User()->setEmail($email)->setFirstName('Asha')->setLastName('Kweka')
            ->setPassword('a hash, never a password')->setTeamRole(TeamRoleEnum::Staff)->setVerified(true)
            ->setPosition($position)->setPlacement($placement->inDepartment($records));
        foreach ([$records, $position, $placement, $person] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return $person;
    }
}
