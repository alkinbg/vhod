<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\OfficialAnnouncement;
use App\Entity\Person;
use App\Entity\User;
use App\Service\AnnouncementPdfService;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class AnnouncementPdfServiceTest extends KernelTestCase
{
    public function testRendersCyrillicAnnouncementAsPdfAndEscapesHtmlTemplate(): void
    {
        self::bootKernel();
        $person = new Person('Мария', 'Петрова', email: 'manager-pdf@example.com');
        $manager = new User($person, 'manager-pdf@example.com', 'hash');
        $manager->setRoles(['ROLE_MANAGER']);
        $announcement = OfficialAnnouncement::draft(
            'Важно съобщение <script>alert(1)</script>',
            'Проверка на асансьора & безопасност.',
            $manager,
            new DateTimeImmutable('2026-09-09 09:00:00+00:00'),
        );
        $announcement->publish($manager, new DateTimeImmutable('2026-09-09 09:05:00+00:00'));

        $service = self::getContainer()->get(AnnouncementPdfService::class);
        self::assertInstanceOf(AnnouncementPdfService::class, $service);
        $pdf = $service->render($announcement);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(500, strlen($pdf));

        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('announcements/pdf.html.twig', ['announcement' => $announcement]);
        self::assertStringContainsString('Важно съобщение', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Проверка на асансьора &amp; безопасност.', $html);
    }
}
