<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommunityPostType;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'community_poll_option')]
#[ORM\UniqueConstraint(name: 'uniq_community_poll_option_position', columns: ['post_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_community_poll_option_label', columns: ['post_id', 'label'])]
class CommunityPollOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'post_id', nullable: false, onDelete: 'RESTRICT')]
    private CommunityPost $poll;

    #[ORM\Column(length: 255)]
    private string $label;

    #[ORM\Column]
    private int $position;

    private function __construct(CommunityPost $poll, string $label, int $position)
    {
        if (CommunityPostType::POLL !== $poll->getType()) {
            throw new InvalidArgumentException('Poll options may belong only to poll posts.');
        }
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > 255) {
            throw new InvalidArgumentException('Poll option label must contain between 1 and 255 characters.');
        }
        if ($position <= 0) {
            throw new InvalidArgumentException('Poll option position must be positive.');
        }
        $this->poll = $poll;
        $this->label = $label;
        $this->position = $position;
    }

    public static function create(CommunityPost $poll, string $label, int $position): self
    {
        return new self($poll, $label, $position);
    }

    public function getId(): ?int { return $this->id; }
    public function getPoll(): CommunityPost { return $this->poll; }
    public function getLabel(): string { return $this->label; }
    public function getPosition(): int { return $this->position; }
}
