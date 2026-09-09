<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssemblyAttendanceMode;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

#[ORM\Entity]
#[ORM\Table(name: 'assembly_attendance_change')]
#[ORM\Index(name: 'idx_attendance_change_attendance', columns: ['attendance_id'])]
#[ORM\Index(name: 'idx_attendance_change_changed_by', columns: ['changed_by_id'])]
final class AssemblyAttendanceChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'attendance_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_attendance_change_attendance')]
    private AssemblyAttendance $attendance;

    #[ORM\Column(length: 32, enumType: AssemblyAttendanceMode::class)]
    private AssemblyAttendanceMode $oldMode;

    #[ORM\Column(length: 32, enumType: AssemblyAttendanceMode::class)]
    private AssemblyAttendanceMode $newMode;

    #[ORM\Column(type: 'text')]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'changed_by_id', nullable: false, onDelete: 'RESTRICT', foreignKeyName: 'fk_attendance_change_changed_by')]
    private User $changedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $changedAt;

    private function __construct(
        AssemblyAttendance $attendance,
        AssemblyAttendanceMode $oldMode,
        AssemblyAttendanceMode $newMode,
        string $reason,
        User $changedBy,
        DateTimeImmutable $changedAt,
    ) {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new InvalidArgumentException('Attendance correction reason is required.');
        }

        $this->attendance = $attendance;
        $this->oldMode = $oldMode;
        $this->newMode = $newMode;
        $this->reason = $reason;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function record(
        AssemblyAttendance $attendance,
        AssemblyAttendanceMode $oldMode,
        AssemblyAttendanceMode $newMode,
        string $reason,
        User $changedBy,
        DateTimeImmutable $changedAt,
    ): self {
        return new self($attendance, $oldMode, $newMode, $reason, $changedBy, $changedAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getAttendance(): AssemblyAttendance { return $this->attendance; }
    public function getOldMode(): AssemblyAttendanceMode { return $this->oldMode; }
    public function getNewMode(): AssemblyAttendanceMode { return $this->newMode; }
    public function getReason(): string { return $this->reason; }
    public function getChangedBy(): User { return $this->changedBy; }
    public function getChangedAt(): DateTimeImmutable { return $this->changedAt; }
}
