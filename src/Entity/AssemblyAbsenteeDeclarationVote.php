<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyVoteChoice;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_absentee_declaration_vote')]
#[ORM\UniqueConstraint(name: 'uniq_absentee_declaration_vote_item', columns: ['declaration_id', 'agenda_item_id'])]
#[ORM\Index(name: 'idx_absentee_declaration_vote_item', columns: ['agenda_item_id'])]
final class AssemblyAbsenteeDeclarationVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'declaration_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_vote_declaration')]
    private AssemblyAbsenteeDeclaration $declaration;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'agenda_item_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_absentee_declaration_vote_item')]
    private AssemblyAgendaItem $agendaItem;

    #[ORM\Column(length: 16, enumType: AssemblyVoteChoice::class)]
    private AssemblyVoteChoice $choice;

    private function __construct(
        AssemblyAbsenteeDeclaration $declaration,
        AssemblyAgendaItem $agendaItem,
        AssemblyVoteChoice $choice,
    ) {
        if (!$declaration->getWindow()->containsAgendaItem($agendaItem)) {
            throw new DomainException('Absentee declaration vote item is not part of the effective window.');
        }

        $this->declaration = $declaration;
        $this->agendaItem = $agendaItem;
        $this->choice = $choice;
    }

    public static function record(
        AssemblyAbsenteeDeclaration $declaration,
        AssemblyAgendaItem $agendaItem,
        AssemblyVoteChoice $choice,
    ): self {
        return new self($declaration, $agendaItem, $choice);
    }

    public function getId(): ?int { return $this->id; }
    public function getDeclaration(): AssemblyAbsenteeDeclaration { return $this->declaration; }
    public function getAgendaItem(): AssemblyAgendaItem { return $this->agendaItem; }
    public function getChoice(): AssemblyVoteChoice { return $this->choice; }
}
