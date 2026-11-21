<?php

namespace App\Domain\Orders\Printing;

/** The two paper documents of a branch part (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md). */
enum PrintKind: string
{
    /** For the person who gathers the goods in the branch. */
    case Picking = 'picking_list';

    /** Goes with the goods in the driver's hands. */
    case Note = 'delivery_note';

    /** The audit-log action written for every printed part. */
    public function auditAction(): string
    {
        return 'order.'.$this->value.'_printed';
    }

    /** The audit actions of both documents. @return list<string> */
    public static function auditActions(): array
    {
        return array_map(fn (self $k) => $k->auditAction(), self::cases());
    }

    public function fileStem(): string
    {
        return match ($this) {
            self::Picking => 'picking-list',
            self::Note => 'delivery-note',
        };
    }

    public function view(): string
    {
        return 'documents.'.$this->fileStem();
    }

    public static function fromAuditAction(string $action): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->auditAction() === $action) {
                return $kind;
            }
        }

        return null;
    }
}
