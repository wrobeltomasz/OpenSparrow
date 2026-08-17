<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

use App\Audit\DbAuditLogger;
use App\Csrf\SessionCsrfTokenManager;
use App\Domain\Schema\JsonSchemaRepository;
use App\Form\FieldTypeRegistry;
use App\Form\Type\BooleanField;
use App\Form\Type\DateField;
use App\Form\Type\EnumField;
use App\Form\Type\ForeignKeyField;
use App\Form\Type\TextField;
use App\Form\Type\TimestampField;
use App\Form\UpdateMapper;
use App\Http\PhpRequest;
use App\Http\PhpSession;
use App\Http\SessionInterface;
use App\Persistence\PgConnection;
use App\Repository\FkOptionsLoader;
use App\Repository\PgFileRepository;
use App\Repository\PgRecordRepository;
use App\Repository\RecordRepositoryInterface;
use PgSql\Connection;

final class AppContext
{
    private ?SessionInterface $session = null;

    private ?PhpRequest $request = null;

    private ?SessionCsrfTokenManager $csrf = null;

    private ?Connection $conn = null;

    private ?PgConnection $database = null;

    private ?ServiceContainer $services = null;

    private ?JsonSchemaRepository $schemas = null;

    private ?FkOptionsLoader $fkLoader = null;

    private ?FieldTypeRegistry $fieldRegistry = null;

    private ?UpdateMapper $mapper = null;

    private ?RecordRepositoryInterface $records = null;

    private ?PgFileRepository $files = null;

    private ?DbAuditLogger $audit = null;

    public function session(): SessionInterface
    {
        return $this->session ??= new PhpSession();
    }

    public function request(): PhpRequest
    {
        return $this->request ??= os_request();
    }

    public function csrf(): SessionCsrfTokenManager
    {
        return $this->csrf ??= new SessionCsrfTokenManager($this->session());
    }

    public function connection(): Connection
    {
        return $this->conn ??= db_connect();
    }

    public function database(): PgConnection
    {
        return $this->database ??= new PgConnection($this->connection());
    }

    public function services(): ServiceContainer
    {
        return $this->services ??= new ServiceContainer($this->connection());
    }

    public function schemas(): JsonSchemaRepository
    {
        return $this->schemas ??= new JsonSchemaRepository(config_get('schema') ?? ['tables' => []]);
    }

    public function fkLoader(): FkOptionsLoader
    {
        return $this->fkLoader ??= new FkOptionsLoader($this->database());
    }

    public function fieldRegistry(): FieldTypeRegistry
    {
        return $this->fieldRegistry ??= new FieldTypeRegistry([
            new ForeignKeyField(),
            new BooleanField(),
            new EnumField(),
            new TimestampField(),
            new DateField(),
            new TextField(),
        ]);
    }

    public function mapper(): UpdateMapper
    {
        return $this->mapper ??= new UpdateMapper($this->fieldRegistry());
    }

    public function records(): RecordRepositoryInterface
    {
        return $this->records ??= new PgRecordRepository(
            $this->database(),
            $this->schemas(),
            $this->fkLoader()
        );
    }

    public function files(): PgFileRepository
    {
        return $this->files ??= new PgFileRepository($this->database());
    }

    public function audit(): DbAuditLogger
    {
        return $this->audit ??= new DbAuditLogger($this->database());
    }
}
