<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OfficialAnnouncement;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

final readonly class AnnouncementPdfService
{
    public function __construct(private Environment $twig) {}

    public function render(OfficialAnnouncement $announcement): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(
            $this->twig->render('announcements/pdf.html.twig', ['announcement' => $announcement]),
            'UTF-8',
        );
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
