<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Controller;

use App\Audit\DbAuditLogger;
use App\Csrf\SessionCsrfTokenManager;
use App\Domain\Schema\JsonSchemaRepository;
use App\Domain\Schema\TableConfig;
use App\Exception\BadRequestException;
use App\Exception\ForbiddenException;
use App\Exception\RedirectException;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use App\Form\UpdateMapper;
use App\Form\ValidationException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Repository\FkOptionsLoader;
use App\Repository\RecordRepositoryInterface;
use App\Service\AutomationService;
use App\Service\ImageService;
use App\Service\M2MService;
use App\Service\RecordOwnershipService;
use App\Service\RecordSnapshotService;
use App\Service\ServiceContainer;

final class CreateController
{
    private readonly RecordOwnershipService $ownership;

    private readonly RecordSnapshotService $snapshots;

    private readonly M2MService $m2m;

    private readonly AutomationService $automations;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly PhpRequest $request,
        private readonly SessionCsrfTokenManager $csrf,
        private readonly JsonSchemaRepository $schemas,
        private readonly FieldTypeRegistry $fieldRegistry,
        private readonly UpdateMapper $mapper,
        private readonly RecordRepositoryInterface $records,
        private readonly DbAuditLogger $audit,
        private readonly FkOptionsLoader $fkLoader,
        private readonly ServiceContainer $services,
    ) {
        $this->ownership   = $services->ownership();
        $this->snapshots   = $services->snapshots();
        $this->m2m         = $services->m2m();
        $this->automations = $services->automations();
    }

    public function handle(PhpRequest $request, array $pageMeta): void
    {
        $cspNonce   = (string) ($pageMeta['nonce'] ?? '');
        $isReadOnly = $this->session->role() !== 'editor';

        if ($isReadOnly && $request->isPost()) {
            throw new ForbiddenException('Read-only access');
        }

        $table = os_validated_table_name($request->query('table'));

        if (!$this->schemas->hasTable($table)) {
            throw new BadRequestException('Invalid table.');
        }
        os_require_table_access($table);

        $tableCfg   = $this->schemas->table($table);
        $rawSchema  = $this->schemas->raw();
        $m2mConfigs = $rawSchema['tables'][$table]['many_to_many'] ?? [];
        $error      = '';

        if ($request->isPost()) {
            $error = $this->save($request, $tableCfg, $table, $m2mConfigs, $rawSchema);
        }

        [$prefilled, $locked] = $this->prefilledValues($request, $tableCfg);

        $formFields = $this->formFields($tableCfg, $rawSchema, $prefilled, $locked, $isReadOnly);
        $m2mGroups  = $this->m2mGroups($m2mConfigs, $rawSchema, $isReadOnly);

        $formHeading   = t('form.add_new_record', ['table' => $tableCfg->displayName]);
        $formError     = $error;
        $formCsrfToken = $this->csrf->token();
        $cancelUrl     = 'index.php?table=' . urlencode($table);
        $formLabels    = [
            'add'    => t('form.add_record'),
            'cancel' => t('common.cancel'),
        ];

        $pageTitle = 'OpenSparrow | Add Record - ' . $tableCfg->displayName;
        ob_start();
        include __DIR__ . '/../../templates/create.php';
        $pageContent = ob_get_clean();

        $extraScripts = os_module_script('assets/js/edit/form-behaviours.js', $cspNonce)
            . os_module_script('assets/js/edit/m2m-picker.js', $cspNonce);
        include __DIR__ . '/../../templates/layout.php';
    }

    private function save(
        PhpRequest $request,
        TableConfig $tableCfg,
        string $table,
        array $m2mConfigs,
        array $rawSchema
    ): string {
        if (!$this->csrf->isValid((string) $request->post('csrf_token'))) {
            throw new ForbiddenException('Invalid CSRF token.');
        }

        try {
            $data   = $this->mapper->fromPost($tableCfg, $request->postAll());
            $newId  = $this->records->insert($tableCfg, $data);
            $userId = $this->session->userId();
            $logId  = $this->audit->log($userId, 'INSERT', $tableCfg->name, (int) $newId);
            if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
                $this->snapshots->capture($tableCfg->schema, $tableCfg->name, (int) $newId, $logId);
            }
            $this->ownership->assign($tableCfg->name, (int) $newId, $userId, $userId);
            $this->automations->evaluate($tableCfg->schema, $tableCfg->name, (int) $newId, 'create', $userId);
            foreach ($m2mConfigs as $m2mIndex => $m2mConfig) {
                $selected = array_values(array_filter(
                    (array) $request->post('m2m_' . $m2mIndex, []),
                    'ctype_digit'
                ));
                $this->m2m->sync($m2mConfig, (int) $newId, $selected, $rawSchema);
            }
            $fragment = ImageService::config($rawSchema, $table) ? '#tab-images' : '#tab-files';
            throw new RedirectException('edit.php?table=' . urlencode($table) . '&id=' . $newId . $fragment);
        } catch (ValidationException $exception) {
            return $exception->getMessage();
        } catch (\RuntimeException $exception) {
            error_log('[create.php] ' . $exception->getMessage());
            return 'Database error. Please try again.';
        }
    }

    private function prefilledValues(PhpRequest $request, TableConfig $tableCfg): array
    {
        $queryValues = $request->queryAll();
        $prefilled   = [];
        $locked      = [];
        foreach ($tableCfg->writableColumns() as $column) {
            if (isset($queryValues[$column->name])) {
                $prefilled[$column->name] = (string) $queryValues[$column->name];
                $locked[$column->name]    = true;
            }
        }

        return [$prefilled, $locked];
    }

    private function formFields(
        TableConfig $tableCfg,
        array $rawSchema,
        array $prefilled,
        array $locked,
        bool $isReadOnly
    ): array {
        $fkOptions = [];
        foreach ($tableCfg->foreignKeys as $colName => $foreignKeyConfig) {
            $fkOptions[$colName] = $this->fkLoader->load($foreignKeyConfig, $rawSchema);
        }

        $renderContext = new RenderContext($isReadOnly, $fkOptions, $prefilled, $locked);

        $formFields = [];
        foreach ($tableCfg->visibleColumns() as $column) {
            if ($column->name === $tableCfg->primaryKey || $column->readonly) {
                continue;
            }
            $isColumnReadOnly = $isReadOnly || ($locked[$column->name] ?? false);
            $formFields[] = [
                'label'    => $column->displayName,
                'required' => $column->notNull && !$isColumnReadOnly,
                'html'     => $this->fieldRegistry->for($column, $tableCfg->hasForeignKey($column->name))
                    ->render($column, null, $renderContext),
            ];
        }

        return $formFields;
    }

    private function m2mGroups(array $m2mConfigs, array $rawSchema, bool $isReadOnly): array
    {
        $m2mGroups = [];
        foreach ($m2mConfigs as $m2mIndex => $m2mConfig) {
            $m2mGroups[] = os_m2m_group(
                (int) $m2mIndex,
                $m2mConfig,
                $this->m2m->options($m2mConfig, $rawSchema),
                [],
                $isReadOnly
            );
        }

        return $m2mGroups;
    }
}
