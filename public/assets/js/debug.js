// assets/js/debug.js — Debug logging helpers
// isDebugEnabled() (localStorage 'sparrow_debug_mode'); debugLog(msg, obj) writes to the #debug panel (falls back to console), capped at MAX_LOG_LENGTH.

export function isDebugEnabled() {
  return localStorage.getItem('sparrow_debug_mode') === 'true';
}

const MAX_LOG_LENGTH = 10000; 

/**
 * Log a message to the debug panel (and console as fallback).
 */
export function debugLog(msg, obj) {
  let dbg = document.getElementById('debug');

  if (!isDebugEnabled()) {
    if (dbg) dbg.style.display = 'none';
    return;
  }

  if (!dbg) {
    dbg = document.createElement('pre');
    dbg.id = 'debug';
    
    dbg.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      width: 450px;
      background: #f4f4f4;
      border: 1px solid #ccc;
      padding: 10px;
      max-height: 250px;
      overflow-y: auto;
      font-size: 12px;
      font-family: monospace;
      z-index: 9999;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      border-radius: 4px;
    `;
    document.body.appendChild(dbg);
  }
  
  dbg.style.display = 'block';

  let text = `[${new Date().toLocaleTimeString()}] ${msg}`;
  if (obj !== undefined) {
    try {
      text += "\n" + (typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2));
    } catch {
      text += "\n" + String(obj);
    }
  }

  let currentText = dbg.textContent + text + "\n\n";
  if (currentText.length > MAX_LOG_LENGTH) {
    currentText = "..." + currentText.slice(-MAX_LOG_LENGTH);
  }
  
  dbg.textContent = currentText;
  
  dbg.scrollTop = dbg.scrollHeight;

  if (obj !== undefined) {
    console.log(msg, obj);
  } else {
    console.log(msg);
  }
}

/**
 * Clear the debug panel.
 */
export function clearDebug() {
  const dbg = document.getElementById('debug');
  if (dbg) dbg.textContent = '';
}