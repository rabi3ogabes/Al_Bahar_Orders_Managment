<?php

namespace App\Domain\Search\Support;

/**
 * One block of the global search: a heading, up to LIMIT results and, when there were more, where to see them all.
 *
 * An item is plain text only (the screen sets it with textContent, never as HTML):
 *   id, title, subtitle, badge and meta (short words such as the order status and total, or null), url (where the result opens: a list page with the side
 *   window of that record, so the person never leaves what they were doing more than needed), icon (an icon name of the dashboard) and
 *   ltr (the title is a number or code and is shown left to right in Arabic too).
 */
final class SearchGroup
{
    public const LIMIT = 5;

    /**
     * @param  list<array{id: string, title: string, subtitle: string, badge: ?string, meta: ?string, url: string, icon: string, ltr: bool}>  $items
     * @param  array{label: string, url: string}|null  $more
     * @param  bool  $lead  the group belongs above the others (a page the person is clearly asking for)
     */
    public function __construct(public readonly string $key, public readonly string $label, public readonly array $items, public readonly ?array $more = null, public readonly bool $lead = false) {}

    /** A phone number, an e-mail or a code inside a line of text, kept left to right whatever the language of the line (Unicode isolates, so "+974..." never turns around in Arabic). */
    public static function ltr(?string $text): ?string
    {
        return $text === null || $text === '' ? $text : "\u{2066}".$text."\u{2069}";
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return array{key: string, label: string, items: list<array<string, mixed>>, more: array{label: string, url: string}|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'items' => $this->items, 'more' => $this->more];
    }
}
