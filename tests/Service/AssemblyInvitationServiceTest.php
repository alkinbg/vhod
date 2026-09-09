<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssemblyInvitationPosting;
use App\Entity\Document;
use App\Entity\GeneralAssembly;
use App\Entity\Person;
use App\Entity\User;
use App\Enum\AssemblyConveningBasis;
use App\Enum\AssemblyDecisionKind;
use App\Enum\AssemblyVoteDenominator;
use App\Enum\DocumentAccessLevel;
use App\Enum\DocumentCategory;
use App\Enum\MajorityComparison;
use App\Service\AssemblyInvitationService;
use App\Service\DocumentStorage;
use App\Value\AssemblyMajorityRuleSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AssemblyInvitationServiceTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/vhod-generated-documents-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testGeneratedPdfStorageUsesRandomPrivatePdfNameAndRejectsInvalidBytes(): void
    {
        self::assertTrue(method_exists(DocumentStorage::class, 'storeGeneratedPdf'), 'Generated PDF storage API has not been implemented yet.');

        $storage = new DocumentStorage($this->directory);
        $stored = $storage->storeGeneratedPdf('pokana.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        self::assertSame('pokana.pdf', $stored->originalName);
        self::assertSame('application/pdf', $stored->mimeType);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $stored->storageName);
        self::assertFileExists($stored->absolutePath);
        self::assertSame($stored->absolutePath, $storage->pathFor($stored->storageName));

        $this->expectException(InvalidArgumentException::class);
        $storage->storeGeneratedPdf('invalid.pdf', 'not a pdf');
    }

    public function testInvitationRendererProducesPdfWithCyrillicAgenda(): void
    {
        self::assertTrue(class_exists(AssemblyInvitationService::class), 'AssemblyInvitationService has not been implemented yet.');
        $service = self::getContainer()->get(AssemblyInvitationService::class);
        self::assertInstanceOf(AssemblyInvitationService::class, $service);

        $assembly = $this->assembly();
        $assembly->addAgendaItem(
            1,
            'Ремонт на покрива',
            'Обсъждане на оферти.',
            'Да се извърши ремонт на покрива.',
            AssemblyDecisionKind::ORDINARY,
            new AssemblyMajorityRuleSnapshot(
                'ordinary-represented',
                AssemblyVoteDenominator::REPRESENTED_AT_MEETING,
                '50',
                MajorityComparison::GREATER_THAN,
                'ЗУЕС — приложимо мнозинство',
                'effective-through-2026-09-09',
            ),
        );

        $pdf = $service->renderPdf($assembly);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, strlen($pdf));
    }

    public function testPostingEvidenceRequiresGovernanceDocumentAndStoresUtcTimestamp(): void
    {
        self::assertTrue(class_exists(AssemblyInvitationPosting::class), 'AssemblyInvitationPosting has not been implemented yet.');

        $assembly = $this->assembly();
        $actor = $assembly->getCreatedBy();
        $governance = Document::record(
            DocumentCategory::OTHER,
            DocumentAccessLevel::GOVERNANCE,
            'Доказателство за поставяне',
            null,
            'evidence.pdf',
            str_repeat('a', 32).'.pdf',
            'application/pdf',
            128,
            $actor,
            new DateTimeImmutable('2026-09-09T10:00:00Z'),
        );

        $posting = AssemblyInvitationPosting::record(
            $assembly,
            new DateTimeImmutable('2026-09-18T18:30:00+03:00'),
            'До входната врата',
            $actor,
            $governance,
            'Поканата е поставена на видно място.',
        );

        self::assertSame('2026-09-18T15:30:00+00:00', $posting->getPostedAt()->format('Y-m-d\TH:i:sP'));
        self::assertSame('До входната врата', $posting->getPostingPlace());
        self::assertSame($governance, $posting->getEvidenceDocument());

        $resident = Document::record(
            DocumentCategory::MEETING_INVITATION,
            DocumentAccessLevel::RESIDENTS,
            'Покана',
            null,
            'invite.pdf',
            str_repeat('b', 32).'.pdf',
            'application/pdf',
            128,
            $actor,
            new DateTimeImmutable('2026-09-09T10:00:00Z'),
        );

        $this->expectException(InvalidArgumentException::class);
        AssemblyInvitationPosting::record(
            $assembly,
            new DateTimeImmutable('2026-09-18T18:30:00+03:00'),
            'До входната врата',
            $actor,
            $resident,
            null,
        );
    }

    private function assembly(): GeneralAssembly
    {
        $person = new Person('Мария', 'Управител');
        $manager = new User($person, 'assembly-invitation@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);

        return GeneralAssembly::draft(
            'Общо събрание',
            new DateTimeImmutable('2026-09-20T15:00:00Z'),
            'Europe/Sofia',
            new DateTimeImmutable('2026-09-09'),
            'Вход А',
            AssemblyConveningBasis::MANAGER_OR_BOARD,
            'Мария Управител',
            $manager,
            $manager,
            new DateTimeImmutable('2026-09-09T07:00:00Z'),
        );
    }
}
