<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Schema\ColumnConfig;
use App\Domain\Schema\TableConfig;

final readonly class UpdateMapper
{
    public function __construct(private FieldTypeRegistry $registry)
    {
    }

    public function fromPost(TableConfig $cfg, array $postData): RecordData
    {
        $bindings = [];
        foreach ($cfg->writableColumns() as $col) {
            $hasFk      = $cfg->hasForeignKey($col->name);
            $bound      = $this->registry->for($col, $hasFk)->bind($col->name, $postData);
            $this->assertMatchesRegexp($col, $bound->value);
            $bindings[] = ['col' => $col->name, 'bound' => $bound];
        }
        return new RecordData($bindings);
    }

    // Server-side mirror of the client data-pattern check (TextField renders it,
    // assets/js validates it): unanchored match, skipped for NULL/empty values,
    // fail-open on an invalid pattern so a broken regexp in schema.json cannot
    // lock editing. Throws ValidationException with the column's user-facing message.
    private function assertMatchesRegexp(ColumnConfig $col, mixed $value): void
    {
        if ($col->validationRegexp === null || !is_string($value) || $value === '') {
            return;
        }
        // '~' delimiter: not a JS regex metacharacter, so schema patterns written
        // for the client never need it escaped — escaping any literal '~' is enough.
        $result = @preg_match('~' . str_replace('~', '\~', $col->validationRegexp) . '~u', $value);
        if ($result === false) {
            error_log('[UpdateMapper] invalid validation_regexp in schema.json: ' . $col->validationRegexp);
            return;
        }
        if ($result !== 1) {
            throw new ValidationException($col->validationMessage ?? 'Invalid format: ' . $col->name);
        }
    }
}
