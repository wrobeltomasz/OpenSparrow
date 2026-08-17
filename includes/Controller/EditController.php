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
use App\Exception\NotFoundException;
use App\Exception\RedirectException;
use App\Form\FieldTypeRegistry;
use App\Form\RenderContext;
use App\Form\UpdateMapper;
use App\Form\ValidationException;
use App\Http\PhpRequest;
use App\Http\SessionInterface;
use App\Repository\FkOptionsLoader;
use App\Repository\PgFileRepository;
use App\Repository\RecordRepositoryInterface;
use App\Service\AppContext;
use App\Service\AutomationService;
use App\Service\ImageService;
use App\Service\M2MService;
use App\Service\RecordOwnershipService;
use App\Service\RecordSnapshotService;
use App\Support\ByteFormatter;

final class EditController
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private const FILE_ICONS = [
        'image'       => 'assets/icons/image.png',
        'pdf'         => 'assets/icons/picture_as_pdf.png',
        'doc'         => 'assets/icons/docs.png',
        'spreadsheet' => 'assets/icons/grid_on.png',
        'archive'     => 'assets/icons/folder_zip.png',
        'other'       => 'assets/icons/file_present.png',
    ];

    private readonly RecordOwnershipService $ownership;

    private readonly RecordSnapshotService $snapshots;

    private readonly M2MService $m2m;

    private readonly ImageService $images;

    private readonly AutomationService $automations;

    private readonly SessionInterface $session;

    private readonly PhpRequest $request;

    private readonly SessionCsrfTokenManager $csrf;

    private readonly JsonSchemaRepository $schemas;

    private readonly FieldTypeRegistry $fieldRegistry;

    private readonly UpdateMapper $mapper;

    private readonly RecordRepositoryInterface $records;

    private readonly PgFileRepository $files;

    private readonly DbAuditLogger $audit;

    private readonly FkOptionsLoader $fkLoader;

    public function __construct(AppContext $context)
    {
        $this->session       = $context->session();
        $this->request       = $context->request();
        $this->csrf          = $context->csrf();
        $this->schemas       = $context->schemas();
        $this->fieldRegistry = $context->fieldRegistry();
        $this->mapper        = $context->mapper();
        $this->records       = $context->records();
        $this->files         = $context->files();
        $this->audit         = $context->audit();
        $this->fkLoader      = $context->fkLoader();

        $services          = $context->services();
        $this->ownership   = $services->ownership();
        $this->snapshots   = $services->snapshots();
        $this->m2m         = $services->m2m();
        $this->images      = $services->images();
        $this->automations = $services->automations();
    }

    public function handle(array $pageMeta): void
    {
        $cspNonce   = (string) ($pageMeta['nonce'] ?? '');
        $isReadOnly = $this->session->role() !== 'editor';

        if ($isReadOnly && $this->request->isPost()) {
            throw new ForbiddenException('Read-only access');
        }

        $table = os_validated_table_name($this->request->query('table'));
        $id    = os_validated_record_id($this->request->query('id'));

        if (!$this->schemas->hasTable($table)) {
            throw new BadRequestException('Invalid table.');
        }

        os_require_table_access($table);

        $tableConfig   = $this->schemas->table($table);
        $rawSchema  = $this->schemas->raw();
        $m2mConfigs = $rawSchema['tables'][$table]['many_to_many'] ?? [];
        $imagesConfig  = ImageService::config($rawSchema, $table);
        $error      = '';

        $rawTableConfig = $rawSchema['tables'][$table] ?? [];
        $canAccess   = $this->ownership->canAccess(
            $rawTableConfig,
            $table,
            $id,
            $this->session->userId(),
            $this->session->role()
        );
        if (!$canAccess) {
            throw new NotFoundException('Record not found.');
        }

        if ($this->request->isPost()) {
            $error = $this->save($tableConfig, $table, $id, $m2mConfigs, $rawSchema);
        }

        $row = $this->records->find($tableConfig, $id);
        if ($row === null) {
            throw new NotFoundException('Record not found.');
        }

        $subtablesData = array_values(array_filter(
            $this->records->subtables($tableConfig, $id),
            static fn(array $subtableData): bool
                => user_can_access_table((string) ($subtableData['config']['table'] ?? ''))
        ));

        $formFields     = $this->formFields($tableConfig, $rawSchema, $row, $isReadOnly);
        $m2mGroups      = $this->m2mGroups($m2mConfigs, $rawSchema, $id, $isReadOnly);
        $subtablePanels = $this->subtablePanels($subtablesData, $id);
        $imagesPanel    = $this->imagesPanel($imagesConfig, $table, $id, $isReadOnly);
        $filesPanel     = $this->filesPanel($tableConfig->name, $id);
        $historyPanel   = $this->historyPanel();
        $tabs           = $this->tabs($tableConfig, $subtablePanels, $imagesPanel);

        $formHeading   = t('form.edit_record', ['table' => $tableConfig->displayName]);
        $formSaved     = $this->request->query('saved') === '1';
        $formError     = $error;
        $formCsrfToken = $this->csrf->token();
        $formRecordId  = $row[$tableConfig->primaryKey] ?? null;
        $cancelUrl     = 'index.php?table=' . urlencode($table);
        $formLabels    = [
            'saved'    => t('form.saved_ok'),
            'update'   => t('form.update_record'),
            'save'     => t('form.save'),
            'saveExit' => t('form.save_exit'),
            'cancel'   => t('common.cancel'),
            'delete'   => t('common.delete'),
        ];

        $pageTitle = 'OpenSparrow | Edit Record - ' . $tableConfig->displayName;
        ob_start();
        include __DIR__ . '/../../templates/edit.php';
        $pageContent = ob_get_clean();

        $extraScripts = $this->extraScripts($tableConfig, $id, $cspNonce);
        include __DIR__ . '/../../templates/layout.php';
    }

    private function save(
        TableConfig $tableConfig,
        string $table,
        int $id,
        array $m2mConfigs,
        array $rawSchema
    ): string {
        if (!$this->csrf->isValid((string) $this->request->post('csrf_token'))) {
            throw new ForbiddenException('Invalid CSRF token.');
        }

        try {
            $data = $this->mapper->fromPost($tableConfig, $this->request->postAll());

            $oldRecord = $this->automations->captureOldRecord($tableConfig->schema, $tableConfig->name, $id);
            $this->records->update($tableConfig, $id, $data);
            $logId = $this->audit->log($this->session->userId(), 'UPDATE', $tableConfig->name, $id);
            if (RECORD_SNAPSHOTS_ENABLED && $logId !== null) {
                $this->snapshots->capture($tableConfig->schema, $tableConfig->name, $id, $logId);
            }
            $this->automations->evaluate(
                $tableConfig->schema,
                $tableConfig->name,
                $id,
                'update',
                $this->session->userId(),
                $oldRecord
            );
            foreach ($m2mConfigs as $m2mIndex => $m2mConfig) {
                $selected = array_values(array_filter(
                    (array) $this->request->post('m2m_' . $m2mIndex, []),
                    'ctype_digit'
                ));
                $this->m2m->sync($m2mConfig, $id, $selected, $rawSchema);
            }
            throw new RedirectException(
                ($this->request->post('_save_action') ?? 'exit') === 'stay'
                    ? 'edit.php?table=' . urlencode($table) . '&id=' . urlencode((string) $id) . '&saved=1'
                    : 'index.php?table=' . urlencode($table)
            );
        } catch (ValidationException $exception) {
            return $exception->getMessage();
        } catch (\RuntimeException $exception) {
            error_log('[edit.php] ' . $exception->getMessage());
            return 'Database error. Please try again.';
        }
    }

    private function formFields(
        TableConfig $tableConfig,
        array $rawSchema,
        array $row,
        bool $isReadOnly
    ): array {
        $fkOptions = [];
        foreach ($tableConfig->foreignKeys as $columnName => $foreignKeyConfig) {
            $fkOptions[$columnName] = $this->fkLoader->load($foreignKeyConfig, $rawSchema);
        }

        $renderContext = new RenderContext($isReadOnly, $fkOptions);

        $formFields = [];
        foreach ($tableConfig->visibleColumns() as $column) {
            if ($column->name === $tableConfig->primaryKey) {
                continue;
            }
            $isColumnReadOnly = $column->readonly || $isReadOnly;
            $formFields[] = [
                'label'    => $column->displayName,
                'required' => $column->notNull && !$isColumnReadOnly,
                'html'     => $this->fieldRegistry->for($column, $tableConfig->hasForeignKey($column->name))
                    ->render($column, $row[$column->name] ?? '', $renderContext),
            ];
        }

        return $formFields;
    }

    private function m2mGroups(array $m2mConfigs, array $rawSchema, int $id, bool $isReadOnly): array
    {
        $m2mGroups = [];
        foreach ($m2mConfigs as $m2mIndex => $m2mConfig) {
            $m2mGroups[] = os_m2m_group(
                (int) $m2mIndex,
                $m2mConfig,
                $this->m2m->options($m2mConfig, $rawSchema),
                $this->m2m->selected($m2mConfig, $id, $rawSchema),
                $isReadOnly
            );
        }

        return $m2mGroups;
    }

    private function subtablePanels(array $subtablesData, int $id): array
    {
        $subtablePanels = [];
        foreach ($subtablesData as $subtableIndex => $subtableData) {
            $subtableName    = $subtableData['config']['table'];
            $subtableFk      = $subtableData['config']['foreign_key'];
            $subtableColumns = $subtableData['config']['columns_to_show'] ?? ['id'];
            $subtableLabel   = $subtableData['config']['label']
                ?? ($subtableData['schema']->displayName ?? $subtableName);

            $subtableColumnsMap = [];
            foreach ($subtableData['schema']->columns as $subtableColumnName => $subtableColumnConfig) {
                $subtableColumnsMap[$subtableColumnName] = [
                    'display_name' => $subtableColumnConfig->displayName,
                    'type'         => $subtableColumnConfig->type,
                    'enum_colors'  => $subtableColumnConfig->enumColors,
                ];
            }

            $subtableHeaders = [];
            foreach ($subtableColumns as $column) {
                $subtableHeaders[] = $subtableData['schema']->columns[$column]->displayName ?? $column;
            }

            $subtableRows = [];
            foreach ($subtableData['rows'] as $subtableRow) {
                $subtableCells = [];
                foreach ($subtableColumns as $column) {
                    $subtableCells[] = (string) ($subtableRow[$column . '__display'] ?? $subtableRow[$column] ?? '');
                }
                $subtableRows[] = [
                    'json'    => (string) json_encode($subtableRow, self::JSON_FLAGS),
                    'cells'   => $subtableCells,
                    'editUrl' => 'edit.php?table=' . urlencode($subtableName)
                        . '&id=' . urlencode((string) $subtableRow['id']),
                ];
            }

            $subtablePanels[] = [
                'id'           => 'tab-sub-' . (int) $subtableIndex,
                'label'        => $subtableLabel,
                'icon'         => $subtableData['schema']->icon ?? '',
                'addUrl'       => 'create.php?table=' . urlencode($subtableName) . '&' . urlencode($subtableFk)
                    . '=' . urlencode((string) $id),
                'addLabel'     => t('form.add_subtable', ['label' => $subtableLabel]),
                'emptyText'    => t('form.no_records'),
                'actionsLabel' => t('common.actions'),
                'viewLabel'    => t('common.view'),
                'editLabel'    => t('common.edit'),
                'columnsJson'  => (string) json_encode($subtableColumnsMap, self::JSON_FLAGS),
                'headers'      => $subtableHeaders,
                'rows'         => $subtableRows,
            ];
        }

        return $subtablePanels;
    }

    private function imagesPanel(?array $imagesConfig, string $table, int $id, bool $isReadOnly): ?array
    {
        if (!$imagesConfig) {
            return null;
        }

        $galleryImages = $this->images->forRecord($table, $id);
        $imageItems    = [];
        foreach ($galleryImages as $galleryImage) {
            $galleryImageUrl = 'file_download.php?uuid=' . urlencode($galleryImage['uuid']);
            $imageItems[] = [
                'url'      => $galleryImageUrl,
                'thumbUrl' => $galleryImageUrl . '&thumb=1',
                'name'     => $galleryImage['display_name'] ?: $galleryImage['name'],
                'uuid'     => $galleryImage['uuid'],
            ];
        }

        return [
            'label'       => $imagesConfig['label'] ?: t('images.label'),
            'countText'   => t('images.count', [
                'n'   => count($galleryImages),
                'max' => $imagesConfig['max_per_record'],
            ]),
            'items'       => $imageItems,
            'canUpload'   => !$isReadOnly && count($galleryImages) < $imagesConfig['max_per_record'],
            'deleteLabel' => t('images.delete'),
            'uploadLabel' => t('images.upload'),
            'emptyText'   => t('images.empty'),
            'limitText'   => t('images.limit_reached'),
        ];
    }

    private function filesPanel(string $table, int $id): array
    {
        $fileRows = [];
        foreach ($this->files->forRecord($table, $id) as $relatedFile) {
            $rawTags = $relatedFile['tags'] ?? '';
            $tags    = [];
            if ($rawTags && $rawTags !== '{}') {
                foreach (explode(',', str_replace('"', '', trim($rawTags, '{}'))) as $tagItem) {
                    $tags[] = trim($tagItem);
                }
            }
            $fileRows[] = [
                'icon'        => self::FILE_ICONS[$relatedFile['type']] ?? self::FILE_ICONS['other'],
                'type'        => ucfirst($relatedFile['type']),
                'name'        => $relatedFile['display_name'] ?: $relatedFile['name'],
                'tags'        => $tags,
                'size'        => ByteFormatter::humanize((int) $relatedFile['size_bytes']),
                'date'        => date('Y-m-d', strtotime($relatedFile['created_at'])),
                'downloadUrl' => 'file_download.php?uuid=' . urlencode($relatedFile['uuid']),
            ];
        }

        return [
            'title'          => t('form.attached_files'),
            'phDisplayName'  => t('files.ph_display_name'),
            'phTags'         => t('files.ph_tags'),
            'uploadLabel'    => t('form.upload_file'),
            'downloadLabel'  => t('files.download'),
            'emptyText'      => t('form.no_files'),
            'actionsLabel'   => t('common.actions'),
            'tagSuggestions' => ['Invoice', 'Contract', 'Image', 'Report'],
            'columns'        => [
                t('files.col_type'),
                t('files.col_display'),
                t('files.col_tags'),
                t('files.col_size'),
                t('owners.col_date'),
            ],
            'rows'           => $fileRows,
        ];
    }

    private function historyPanel(): array
    {
        return [
            'ownerTitle'       => t('owners.section_owner'),
            'historyTitle'     => t('owners.section_history'),
            'changeOwnerLabel' => t('owners.change_owner'),
            'loadingText'      => t('common.loading'),
        ];
    }

    private function tabs(TableConfig $tableConfig, array $subtablePanels, ?array $imagesPanel): array
    {
        $tabs = [[
            'id'    => 'tab-details',
            'label' => $tableConfig->displayName,
            'icon'  => $tableConfig->icon ?: '',
        ]];
        foreach ($subtablePanels as $panel) {
            $tabs[] = ['id' => $panel['id'], 'label' => $panel['label'], 'icon' => $panel['icon']];
        }
        if ($imagesPanel) {
            $tabs[] = ['id' => 'tab-images', 'label' => $imagesPanel['label'], 'icon' => 'assets/icons/image.png'];
        }
        $tabs[] = ['id' => 'tab-files', 'label' => t('form.tab_files'), 'icon' => 'assets/icons/folder_open.png'];
        $tabs[] = ['id' => 'tab-comments', 'label' => t('form.tab_comments'), 'icon' => ''];
        $tabs[] = ['id' => 'tab-history', 'label' => t('form.tab_history'), 'icon' => ''];

        return $tabs;
    }

    private function extraScripts(TableConfig $tableConfig, int $id, string $cspNonce): string
    {
        $globals = os_inline_globals([
            'CSRF_TOKEN'      => $this->csrf->token(),
            'EDIT_TABLE'      => $tableConfig->name,
            'EDIT_ID'         => $id,
            'CURRENT_USER_ID' => $this->session->userId(),
            'USER_ROLE'       => $this->session->role(),
            'IMAGE_TEXT'      => [
                'select_first'          => t('images.select_first'),
                'confirm_delete'        => t('images.confirm_delete'),
                'confirm_delete_record' => t('common.confirm_delete'),
            ],
        ], $cspNonce);

        $modules = [
            'assets/js/edit/page-actions.js',
            'assets/js/comments.js',
            'assets/js/owners.js',
            'assets/js/edit/form-behaviours.js',
            'assets/js/edit/subtable-tooltip.js',
            'assets/js/edit/m2m-picker.js',
        ];

        $scripts = '';
        foreach ($modules as $module) {
            $scripts .= os_module_script($module, $cspNonce);
        }

        return $globals . "\n" . $scripts;
    }
}
