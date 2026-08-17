#!/bin/sh

# This file is part of OpenSparrow - https://opensparrow.org
# SPDX-License-Identifier: LGPL-3.0-or-later
# Copyright (C) 2024-2026 OpenSparrow Contributors
# Licensed under LGPL v3. See COPYING.LESSER file for details.

set -eu

if [ $# -lt 1 ]; then
    echo "usage: scripts/baseline/export-head.sh <target-dir> [revision]" >&2
    exit 2
fi

TARGET="$1"
REVISION="${2:-HEAD}"
ROOT="$(git rev-parse --show-toplevel)"

rm -rf "$TARGET"
mkdir -p "$TARGET"
git -C "$ROOT" archive "$REVISION" | tar -x -C "$TARGET"

for UNTRACKED in \
    config/database.json \
    includes/.secret_key \
    includes/.secret_salt
do
    if [ -f "$ROOT/$UNTRACKED" ]; then
        cp "$ROOT/$UNTRACKED" "$TARGET/$UNTRACKED"
    else
        echo "warning: $UNTRACKED is missing from the working tree" >&2
    fi
done

echo "exported $(git -C "$ROOT" rev-parse --short "$REVISION") to $TARGET"
