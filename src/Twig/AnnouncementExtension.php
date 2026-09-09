<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\AnnouncementReceiptService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AnnouncementExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly AnnouncementReceiptService $receiptService,
    ) {}

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('announcement_unread_count', [$this, 'unreadCount']),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->receiptService->countUnread($user);
    }
}
