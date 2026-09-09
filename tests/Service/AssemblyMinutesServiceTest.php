<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyAbsenteeWindow;
use App\Entity\AssemblyMinutesCorrection;
use App\Entity\AssemblyQuorumCheck;
use App\Entity\AssemblyResolution;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyLegalResult;
use App\Enum\AssemblyQuorumCheckKind;
use App\Enum\AssemblyResolutionResult;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\GeneralAssemblyStatus;
use App\Enum\MajorityComparison;
use App\Service\AssemblyMinutesService;
use App\Value\AssemblyMajorityRuleSnapshot;
use App\Value\AssemblyQuorumCalculation;
use App\Value\AssemblyResolutionCalculation;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyMinutesServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AssemblyMinutesService $service;
    private User $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        self::assertTrue(class_exists(AssemblyMinutesService::class), 'Task 10 requires AssemblyMinutesService.');

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $service = self::getContainer()->get(AssemblyMinutesService::class);
        self::assertInstanceOf(AssemblyMinutesService::class, $service);
        $this->service = $service;

        $person = new Person('Мария', 'Управител', email: 'manager-minutes@example.com');
        $this->manager = new User($person, 'manager-minutes@example.com', 'hash');
        $this->manager->setRoles(['ROLE_MANAGER']);
        $em->persist($person);
        $em->persist($this->manager);
        $em->flush();
    }

    public function testViewModelAndPdfContainPersistedFormalMeetingFacts(): void
    {
        [$assembly, $item] = $this->closedResolvedAssembly();

        $quorum = AssemblyQuorumCheck::record(
            $assembly,
            AssemblyQuorumCheckKind::FIRST_CALL,
            new DateTimeImmutable('2026-09-10T17:02:00Z'),
            new AssemblyQuorumCalculation(
                '60',
                '51',
                'zues-test',
                AssemblyLegalResult::REVIEW_REQUIRED,
                'Кворумът изисква правен преглед.',
            ),
            $this->manager,
        );
        $this->em->persist($quorum);
        $this->em->flush();

        $model = $this->service->buildViewModel($assembly);

        self::assertSame($assembly, $model['assembly']);
        self::assertSame($quorum, $model['latestQuorumCheck']);
        self::assertSame('60.00000000', $model['representedIdealPartsPercent']);
        self::assertCount(1, $model['quorumChecks']);
        self::assertCount(1, $model['agenda']);
        self::assertSame($item, $model['agenda'][0]['item']);
        self::assertSame(AssemblyResolutionResult::ACCEPTED, $model['agenda'][0]['resolution']->getResult());
        self::assertNotEmpty($model['warnings']);
        self::assertStringContainsString('правен преглед', mb_strtolower(implode(' ', $model['warnings'])));

        $pdf = $this->service->renderPdf($assembly);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, strlen($pdf));
    }

    public function testFinalizationRequiresClosedMeetingAndCompleteMinutesMetadata(): void
    {
        $assembly = $this->draftAssembly();

        $this->expectException(DomainException::class);
        $this->service->finalize($this->manager, $assembly, new DateTimeImmutable('2026-09-10T19:00:00Z'));
    }

    public function testFinalizationIsBlockedByOpenAbsenteeWindow(): void
    {
        [$assembly] = $this->closedResolvedAssembly();
        $assembly->setMinutesMetadata('Иван Председател', 'Елена Протоколчик', 'Формални бележки.');

        $window = AssemblyAbsenteeWindow::open(
            $assembly,
            $this->manager,
            new DateTimeImmutable('2026-09-10T18:05:00Z'),
            new DateTimeImmutable('2026-09-11T18:05:00Z'),
            'Правно основание за неприсъствено гласуване.',
        );
        $this->em->persist($window);
        $this->em->flush();

        $this->expectException(DomainException::class);
        $this->service->finalize($this->manager, $assembly, new DateTimeImmutable('2026-09-11T18:10:00Z'));
    }

    public function testFinalizationIsBlockedWhileAgendaItemHasNoResolution(): void
    {
        $assembly = $this->draftAssembly();
        $item = $assembly->addAgendaItem(1, 'Нерешена точка', null, 'Проект за решение.', AssemblyDecisionKind::ORDINARY, $this->ordinaryRule());
        $this->em->persist($assembly);
        $this->em->persist($item);
        $this->em->flush();

        $assembly->convene($this->manager, new DateTimeImmutable('2026-09-10T16:00:00Z'));
        $assembly->start($this->manager, new DateTimeImmutable('2026-09-10T17:00:00Z'));
        $item->open('Финален текст без резултат.', new DateTimeImmutable('2026-09-10T17:05:00Z'));
        $assembly->close($this->manager, new DateTimeImmutable('2026-09-10T18:00:00Z'));
        $assembly->setMinutesMetadata('Иван Председател', 'Елена Протоколчик');
        $this->em->flush();

        $this->expectException(DomainException::class);
        $this->service->finalize($this->manager, $assembly, new DateTimeImmutable('2026-09-10T19:00:00Z'));
    }

    public function testFinalizationStoresOneResidentMinutesDocumentAndIsIdempotent(): void
    {
        [$assembly] = $this->closedResolvedAssembly();
        $assembly->setMinutesMetadata('Иван Председател', 'Елена Протоколчик', 'Протоколът е проверен.');
        $this->em->flush();

        $document = $this->service->finalize(
            $this->manager,
            $assembly,
            new DateTimeImmutable('2026-09-10T19:00:00Z'),
        );

        self::assertSame(DocumentCategory::MEETING_MINUTES, $document->getCategory());
        self::assertSame(DocumentAccessLevel::RESIDENTS, $document->getAccessLevel());
        self::assertSame(GeneralAssemblyStatus::MINUTES_FINALIZED, $assembly->getStatus());
        self::assertSame($document, $assembly->getMinutesDocument());
        self::assertSame('2026-09-17', $assembly->getMinutesDueOn()?->format('Y-m-d'));
        self::assertSame('Иван Председател', $assembly->getChairpersonNameSnapshot());
        self::assertSame('Елена Протоколчик', $assembly->getSecretaryNameSnapshot());
        self::assertSame($this->manager, $assembly->getMinutesFinalizedBy());
        self::assertSame('2026-09-10T19:00:00+00:00', $assembly->getMinutesFinalizedAt()?->format(DATE_ATOM));

        $sameDocument = $this->service->finalize(
            $this->manager,
            $assembly,
            new DateTimeImmutable('2026-09-10T19:01:00Z'),
        );

        self::assertSame($document->getId(), $sameDocument->getId());
        self::assertCount(1, $this->em->getRepository(Document::class)->findBy(['category' => DocumentCategory::MEETING_MINUTES]));

        $this->expectException(DomainException::class);
        $assembly->setMinutesMetadata('Друг председател', 'Друг протоколчик');
    }

    public function testCorrectionIsAppendOnlyAndDoesNotRewriteFinalizedMinutes(): void
    {
        [$assembly] = $this->closedResolvedAssembly();
        $assembly->setMinutesMetadata('Иван Председател', 'Елена Протоколчик');
        $this->em->flush();
        $original = $this->service->finalize($this->manager, $assembly, new DateTimeImmutable('2026-09-10T19:00:00Z'));

        $addendum = Document::record(
            DocumentCategory::MEETING_MINUTES,
            DocumentAccessLevel::RESIDENTS,
            'Добавка към протокол',
            'Корекция без промяна на историческия резултат.',
            'minutes-addendum.pdf',
            'dddddddddddddddddddddddddddddddd.pdf',
            'application/pdf',
            256,
            $this->manager,
            new DateTimeImmutable('2026-09-11T09:00:00Z'),
        );
        $this->em->persist($addendum);
        $this->em->flush();

        $correction = $this->service->addCorrection(
            $this->manager,
            $assembly,
            'Поправка на техническа грешка в текста.',
            $addendum,
            new DateTimeImmutable('2026-09-11T09:05:00Z'),
        );

        self::assertInstanceOf(AssemblyMinutesCorrection::class, $correction);
        self::assertSame($assembly, $correction->getAssembly());
        self::assertSame($addendum, $correction->getDocument());
        self::assertSame($this->manager, $correction->getRecordedBy());
        self::assertSame($original, $assembly->getMinutesDocument());
        self::assertCount(1, $this->em->getRepository(AssemblyMinutesCorrection::class)->findAll());
    }

    /** @return array{GeneralAssembly, \App\Entity\AssemblyAgendaItem} */
    private function closedResolvedAssembly(): array
    {
        $assembly = $this->draftAssembly();
        $item = $assembly->addAgendaItem(1, 'Избор на изпълнител', null, 'Общото събрание избира изпълнител.', AssemblyDecisionKind::ORDINARY, $this->ordinaryRule());
        $this->em->persist($assembly);
        $this->em->persist($item);
        $this->em->flush();

        $assembly->convene($this->manager, new DateTimeImmutable('2026-09-10T16:00:00Z'));
        $assembly->start($this->manager, new DateTimeImmutable('2026-09-10T17:00:00Z'));
        $item->open('Общото събрание избира изпълнител А.', new DateTimeImmutable('2026-09-10T17:05:00Z'));
        $resolution = AssemblyResolution::record(
            $item,
            new AssemblyResolutionCalculation(
                '60',
                '10',
                '5',
                '75',
                '37.5',
                AssemblyResolutionResult::ACCEPTED,
                'Решението е прието с изискуемото мнозинство.',
            ),
            $this->manager,
            new DateTimeImmutable('2026-09-10T17:45:00Z'),
        );
        $item->resolve(new DateTimeImmutable('2026-09-10T17:45:00Z'));
        $assembly->close($this->manager, new DateTimeImmutable('2026-09-10T18:00:00Z'));
        $this->em->persist($resolution);
        $this->em->flush();

        return [$assembly, $item];
    }

    private function draftAssembly(): GeneralAssembly
    {
        return GeneralAssembly::draft(
            'Общо събрание — протокол',
            new DateTimeImmutable('2026-09-10T17:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-10'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $this->manager,
            $this->manager,
            new DateTimeImmutable('2026-09-10T08:00:00Z'),
        );
    }

    private function ordinaryRule(): AssemblyMajorityRuleSnapshot
    {
        return new AssemblyMajorityRuleSnapshot(
            'ordinary-minutes-test',
            AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
            '50',
            MajorityComparison::GREATER_THAN,
            'ЗУЕС — тестово правно основание',
            '2026-09-10',
        );
    }
}
