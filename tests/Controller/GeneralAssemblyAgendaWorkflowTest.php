<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AssemblyAgendaItem;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GeneralAssemblyAgendaWorkflowTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $managerId;
    private int $residentId;
    private int $assemblyId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->entityManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->managerId = $this->persistUser($em, 'agenda-manager@example.com', ['ROLE_MANAGER']);
        $this->residentId = $this->persistUser($em, 'agenda-resident@example.com');
        $manager = $em->find(User::class, $this->managerId);
        self::assertInstanceOf(User::class, $manager);

        $assembly = GeneralAssembly::draft(
            'ОС за тест на дневен ред',
            new DateTimeImmutable('+10 days 18:00 Europe/Sofia'),
            'Europe/Sofia',
            new DateTimeImmutable('today Europe/Sofia'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Управител',
            $manager,
            $manager,
            new DateTimeImmutable('now'),
        );
        $em->persist($assembly);
        $em->flush();
        self::assertNotNull($assembly->getId());
        $this->assemblyId = $assembly->getId();
    }

    public function testManagerCanAddEditAndRemoveDraftAgendaItemFromUi(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$this->assemblyId.'/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('assembly_agenda_add')->form([
            'position' => '1',
            'agenda_title' => 'Избор на изпълнител',
            'agenda_description' => 'Обсъждане на получените оферти.',
            'draft_resolution_text' => 'Общото събрание избира изпълнител.',
            'decision_kind' => AssemblyDecisionKind::ORDINARY->value,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$this->assemblyId.'/edit');

        $em = $this->entityManager();
        $items = $em->getRepository(AssemblyAgendaItem::class)->findAll();
        self::assertCount(1, $items);
        self::assertSame('Избор на изпълнител', $items[0]->getTitle());
        self::assertSame('ordinary-represented-majority', $items[0]->getMajorityRule()->getRuleCode());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$this->assemblyId.'/edit');
        $form = $crawler->selectButton('assembly_agenda_edit_'.$items[0]->getId())->form([
            'agenda_title' => 'Избор на изпълнител за покрива',
            'agenda_description' => '',
            'draft_resolution_text' => 'Общото събрание избира изпълнителя по оферта № 1.',
            'decision_kind' => AssemblyDecisionKind::ORDINARY->value,
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$this->assemblyId.'/edit');

        $em = $this->entityManager();
        $item = $em->find(AssemblyAgendaItem::class, $items[0]->getId());
        self::assertInstanceOf(AssemblyAgendaItem::class, $item);
        self::assertSame('Избор на изпълнител за покрива', $item->getTitle());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->managerId));
        $crawler = $this->client->request('GET', '/management/assembly/'.$this->assemblyId.'/edit');
        $form = $crawler->selectButton('assembly_agenda_remove_'.$item->getId())->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/management/assembly/'.$this->assemblyId.'/edit');
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyAgendaItem::class)->findAll());
    }

    public function testAgendaMutationsAreCsrfProtectedAndResidentCannotManageThem(): void
    {
        $this->client->loginUser($this->user($this->managerId));
        $this->client->request('POST', '/management/assembly/'.$this->assemblyId.'/agenda/add', [
            '_token' => 'invalid',
            'position' => '1',
            'agenda_title' => 'Невалидна точка',
            'draft_resolution_text' => 'Няма да се запише.',
            'decision_kind' => AssemblyDecisionKind::ORDINARY->value,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyAgendaItem::class)->findAll());

        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->loginUser($this->user($this->residentId));
        $this->client->request('POST', '/management/assembly/'.$this->assemblyId.'/agenda/add', [
            '_token' => 'anything',
            'position' => '1',
            'agenda_title' => 'Забранена точка',
            'draft_resolution_text' => 'Няма да се запише.',
            'decision_kind' => AssemblyDecisionKind::ORDINARY->value,
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(AssemblyAgendaItem::class)->findAll());
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param list<string> $roles */
    private function persistUser(EntityManagerInterface $em, string $email, array $roles = []): int
    {
        $person = new Person('Тест', 'Потребител', email: $email);
        $user = new User($person, $email, 'hash');
        $user->setRoles($roles);
        $em->persist($person);
        $em->persist($user);
        $em->flush();
        self::assertNotNull($user->getId());

        return $user->getId();
    }

    private function user(int $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
