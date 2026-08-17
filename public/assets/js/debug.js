// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

export function isDebugEnabled() {
  return localStorage.getItem('sparrow_debug_mode') === 'true';
}

const MAX_LOG_LENGTH = 10000;

export function debugLog(message, obj) {
  let debugElement = document.getElementById('debug');

  if (!isDebugEnabled()) {
    if (debugElement) debugElement.style.display = 'none';
    return;
  }

  if (!debugElement) {
    debugElement = document.createElement('pre');
    debugElement.id = 'debug';

    debugElement.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 450px;
      background: var(--border-light);
      border: 1px solid var(--border);
      padding: 10px;
      max-height: 250px;
      overflow-y: auto;
      font-size: 12px;
      font-family:var(--font-mono);
      z-index: 9999;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      border-radius: 4px;
    `;
    document.body.appendChild(debugElement);
  }

  debugElement.style.display = 'block';

  let text = `[${new Date().toLocaleTimeString()}] ${message}`;
  if (obj !== undefined) {
    try {
      text += "\n" + (typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2));
    } catch {
      text += "\n" + String(obj);
    }
  }

  let currentText = debugElement.textContent + text + "\n\n";
  if (currentText.length > MAX_LOG_LENGTH) {
    currentText = "..." + currentText.slice(-MAX_LOG_LENGTH);
  }

  debugElement.textContent = currentText;

  debugElement.scrollTop = debugElement.scrollHeight;

  if (obj !== undefined) {
    console.log(message, obj);
  } else {
    console.log(message);
  }
}

export function clearDebug() {
  const debugElement = document.getElementById('debug');
  if (debugElement) debugElement.textContent = '';
}
