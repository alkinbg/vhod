<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyAttendanceMode;
use App\Repository\AssemblyAttendanceRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity(repositoryClass: AssemblyAttendanceRepository::class)]
#[ORM\Table(name: 'assembly_attendance')]
#[ORM\UniqueConstraint(name: 'uniq_assembly_attendance_principal', columns: ['assembly_id', 'electorate_entry_id'])]
#[ORM\Index(name: 'idx_assembly_attendance_assembly', columns: ['assembly_id'])]
#[ORM\Index(name: 'idx_assembly_attendance_entry', columns: ['electorate_entry_id'])]
#[ORM\Index(name: 'idx_assembly_attendance_representative_person', columns: ['representative_person_id'])]
#[ORM\Index(name: 'idx_assembly_attendance_registered_by', columns: ['registered_by_id'])]
final class AssemblyAttendance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'assembly_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_attendance_assembly')]
    private GeneralAssembly $assembly;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'electorate_entry_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_attendance_entry')]
    private AssemblyElectorateEntry $electorateEntry;

    #[ORM\Column(length: 32, enumType: AssemblyAttendanceMode::class)]
    private AssemblyAttendanceMode $mode;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'representative_person_id', nullable: true, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_attendance_representative_person')]
    private ?Person $representativePerson;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $representativeName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $authorityNote;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'registered_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_assembly_attendance_registered_by')]
    private User $registeredBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $registeredAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $leftAt = null;

    private function __construct(
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $electorateEntry,
        AssemblyAttendanceMode $mode,
        User $registeredBy,
        DateTimeImmutable $registeredAt,
        ?Person $representativePerson,
        ?string $representativeName,
        ?string $authorityNote,
    ) {
        if ($electorateEntry->getAssembly() !== $assembly) {
            throw new InvalidArgumentException('Attendance electorate entry must belong to the same General Assembly.');
        }

        $representativeName = self::nullableTrim($representativeName);
        $authorityNote = self::nullableTrim($authorityNote);
        self::assertModeMetadata($mode, $representativePerson, $representativeName, $authorityNote);

        $this->assembly = $assembly;
        $this->electorateEntry = $electorateEntry;
        $this->mode = $mode;
        $this->representativePerson = $representativePerson;
        $this->representativeName = $representativeName;
        $this->authorityNote = $authorityNote;
        $this->registeredBy = $registeredBy;
        $this->registeredAt = $registeredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function register(
        GeneralAssembly $assembly,
        AssemblyElectorateEntry $electorateEntry,
        AssemblyAttendanceMode $mode,
        User $registeredBy,
        DateTimeImmutable $registeredAt,
        ?Person $representativePerson = null,
        ?string $representativeName = null,
        ?string $authorityNote = null,
    ): self {
        return new self($assembly, $electorateEntry, $mode, $registeredBy, $registeredAt, $representativePerson, $representativeName, $authorityNote);
    }

    public function changeMode(
        AssemblyAttendanceMode $mode,
        ?Person $representativePerson = null,
        ?string $representativeName = null,
        ?string $authorityNote = null,
    ): void {
        $representativeName = self::nullableTrim($representativeName);
        $authorityNote = self::nullableTrim($authorityNote);
        self::assertModeMetadata($mode, $representativePerson, $representativeName, $authorityNote);

        $this->mode = $mode;
        $this->representativePerson = $representativePerson;
        $this->representativeName = $representativeName;
        $this->authorityNote = $authorityNote;
    }

    public function getId(): ?int { return $this->id; }
    public function getAssembly(): GeneralAssembly { return $this->assembly; }
    public function getElectorateEntry(): AssemblyElectorateEntry { return $this->electorateEntry; }
    public function getMode(): AssemblyAttendanceMode { return $this->mode; }
    public function getRepresentativePerson(): ?Person { return $this->representativePerson; }
    public function getRepresentativeName(): ?string { return $this->representativeName; }
    public function getAuthorityNote(): ?string { return $this->authorityNote; }
    public function getRegisteredBy(): User { return $this->registeredBy; }
    public function getRegisteredAt(): DateTimeImmutable { return $this->registeredAt; }
    public function getLeftAt(): ?DateTimeImmutable { return $this->leftAt; }

    private static function assertModeMetadata(
        AssemblyAttendanceMode $mode,
        ?Person $representativePerson,
        ?string $representativeName,
        ?string $authorityNote,
    ): void {
        if (AssemblyAttendanceMode::BY_PROXY === $mode && null === $representativeName) {
            throw new InvalidArgumentException('Proxy attendance requires representative details.');
        }
        if (AssemblyAttendanceMode::STATUTORY_USER_AUTHORITY === $mode && null === $authorityNote) {
            throw new InvalidArgumentException('Statutory user authority requires an explicit authority note.');
        }
        if (AssemblyAttendanceMode::BY_PROXY !== $mode && null !== $representativePerson) {
            throw new InvalidArgumentException('Representative person is allowed only for proxy attendance.');
        }
    }

    private static function nullableTrim(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
