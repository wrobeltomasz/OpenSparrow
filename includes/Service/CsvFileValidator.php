<?php

// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

declare(strict_types=1);

namespace App\Service;

final class CsvFileValidator
{
    public const int MAX_BYTES = 524288000;

    public static function validate(array $file): void
    {
        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini (currently '
                    . ini_get('upload_max_filesize') . '). Restart the PHP server after editing php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the MAX_FILE_SIZE limit specified in the form.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
            ];
            throw new \AdminApiMessage($uploadMessages[$uploadError] ?? 'Upload error code: ' . $uploadError);
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new \AdminApiMessage('File exceeds ' . (self::MAX_BYTES / 1048576) . ' MB limit.');
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new \AdminApiMessage('Only .csv files are accepted.');
        }
        $finfo  = new \finfo(FILEINFO_MIME_TYPE);
        $mime   = $finfo->file((string) ($file['tmp_name'] ?? ''));
        $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'];
        if (!in_array($mime, $allowed, true)) {
            throw new \AdminApiMessage("Invalid MIME type: {$mime}. Expected a CSV/text file.");
        }
    }
}
