#!/usr/bin/env python3
"""
COPYING.LESSER (LGPL v3) Verification & Comparison Tool

Compares the current COPYING.LESSER against a candidate COPYING.LESSER.new
and writes three reports. All comparison values are derived from the actual
file contents - nothing is hardcoded.

OpenSparrow uses the FSF two-file layout:
  COPYING         -> GNU GPL v3 (verbatim)
  COPYING.LESSER  -> GNU LGPL v3 (verbatim, additional permissions on the GPL)
"""

import os
import re
import sys
from datetime import datetime
from difflib import unified_diff

# Paths - resolved relative to this script's location.
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
current_path = os.path.join(BASE_DIR, 'COPYING.LESSER')
new_path = os.path.join(BASE_DIR, 'COPYING.LESSER.new')
output_dir = BASE_DIR

print("=" * 60)
print("COPYING.LESSER VERIFICATION & COMPARISON TOOL")
print("=" * 60)
print()

# Check files exist
if not os.path.exists(current_path):
    print(f"[ERROR] Current COPYING.LESSER not found: {current_path}")
    sys.exit(1)

if not os.path.exists(new_path):
    print(f"[ERROR] Candidate COPYING.LESSER.new not found: {new_path}")
    sys.exit(1)

# Read files
with open(current_path, 'r', encoding='utf-8') as f:
    current_content = f.read()
    current_lines = current_content.split('\n')

with open(new_path, 'r', encoding='utf-8') as f:
    new_content = f.read()
    new_lines = new_content.split('\n')

print(f"[OK] Current  loaded: {len(current_content)} bytes, {len(current_lines)} lines")
print(f"[OK] Candidate loaded: {len(new_content)} bytes, {len(new_lines)} lines")
print()

# === BUILD REPORT ===
report = []
report.append("COPYING.LESSER VERIFICATION REPORT")
report.append("=" * 60)
report.append(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
report.append("")

# 1. FILE SIZE
report.append("1. FILE SIZE COMPARISON")
report.append("-" * 60)
report.append(f"Current:   {len(current_content)} bytes ({len(current_lines)} lines)")
report.append(f"Candidate: {len(new_content)} bytes ({len(new_lines)} lines)")
report.append(f"Difference:      {len(new_content) - len(current_content):+d} bytes")
report.append(f"Line difference: {len(new_lines) - len(current_lines):+d} lines")
report.append("")


# 2. EXTRACT KEY INFO
def extract_info(content):
    info = {
        'license_type': '',
        'version': '',
        'copyright': '',
        'has_additional_terms': False,
        'has_linking_exception': False,
        'has_disclaimer': False,
        'has_limitation': False,
    }

    # License type. Check LESSER first - the LGPL text contains the GPL name too.
    if 'GNU LESSER GENERAL PUBLIC LICENSE' in content:
        info['license_type'] = 'LGPL'
    elif 'GNU GENERAL PUBLIC LICENSE' in content:
        info['license_type'] = 'GPL'

    version_match = re.search(r'Version\s+(\d+)', content)
    if version_match:
        info['version'] = version_match.group(1)

    copyright_match = re.search(r'Copyright[^\n]+', content)
    if copyright_match:
        info['copyright'] = copyright_match.group(0)

    info['has_additional_terms'] = 'ADDITIONAL TERMS' in content
    info['has_linking_exception'] = 'LINKING EXCEPTION' in content
    info['has_disclaimer'] = 'Disclaimer of Warranty' in content
    info['has_limitation'] = 'Limitation of Liability' in content

    return info


current_info = extract_info(current_content)
new_info = extract_info(new_content)


def yn(flag):
    return 'YES' if flag else 'NO'


report.append("2. EXTRACTED INFORMATION")
report.append("-" * 60)
report.append("")
for label, info in (("CURRENT:", current_info), ("CANDIDATE:", new_info)):
    report.append(label)
    report.append(f"  License Type:            {info['license_type']} v{info['version']}")
    report.append(f"  Copyright:               {info['copyright']}")
    report.append(f"  Additional Terms:        {yn(info['has_additional_terms'])}")
    report.append(f"  Linking Exception:       {yn(info['has_linking_exception'])}")
    report.append(f"  Disclaimer of Warranty:  {yn(info['has_disclaimer'])}")
    report.append(f"  Limitation of Liability: {yn(info['has_limitation'])}")
    report.append("")

# 3. COMPATIBILITY / INTEGRITY CHECK
report.append("3. COMPATIBILITY CHECK")
report.append("-" * 60)

is_compatible = True

if current_info['license_type'] == new_info['license_type']:
    report.append(f"[OK] License Type: Both are {current_info['license_type'] or 'UNKNOWN'}")
else:
    report.append(f"[ERROR] License Type Mismatch: {current_info['license_type']} vs {new_info['license_type']}")
    is_compatible = False

if current_info['version'] == new_info['version']:
    report.append(f"[OK] License Version: Same (v{current_info['version']})")
else:
    report.append(f"[WARN] License Version: v{current_info['version']} -> v{new_info['version']}")

if current_info['copyright'] != new_info['copyright']:
    report.append("[WARN] Copyright line differs - the LGPL text is meant to be verbatim;")
    report.append("       the project copyright belongs in README / source headers instead.")

# Custom sections added on top of the verbatim licence are a red flag.
for section, flag in (('ADDITIONAL TERMS', 'has_additional_terms'),
                      ('LINKING EXCEPTION', 'has_linking_exception')):
    if new_info[flag] and not current_info[flag]:
        report.append(f"[WARN] Candidate adds a '{section}' section not in the current file.")
        report.append("       Adding restrictions to verbatim LGPL v3 is legally risky")
        report.append("       (GPL v3 section 7 lets downstream recipients strip them).")

report.append("")
if is_compatible:
    report.append("[OK] COMPATIBILITY RESULT: COMPATIBLE")
else:
    report.append("[ERROR] COMPATIBILITY RESULT: INCOMPATIBLE")
report.append("")

# 4. LINE COMPARISON
report.append("4. DETAILED LINE COMPARISON (first 15 lines)")
report.append("-" * 60)
report.append("")
for i in range(min(15, max(len(current_lines), len(new_lines)))):
    curr = current_lines[i] if i < len(current_lines) else "[EOF]"
    new = new_lines[i] if i < len(new_lines) else "[EOF]"

    if curr == new:
        report.append(f"  [OK] L{i+1:3d}: SAME")
    else:
        report.append(f"  [WARN] L{i+1:3d}: DIFFERENT")
        report.append(f"       CUR: {curr[:70]}")
        report.append(f"       NEW: {new[:70]}")
report.append("")

# 5. SECTION CHANGES
report.append("5. SECTION CHANGES")
report.append("-" * 60)
report.append("")
tracked = [
    ('ADDITIONAL TERMS', 'has_additional_terms'),
    ('LINKING EXCEPTION', 'has_linking_exception'),
    ('Disclaimer of Warranty', 'has_disclaimer'),
    ('Limitation of Liability', 'has_limitation'),
]
any_change = False
for name, flag in tracked:
    if current_info[flag] != new_info[flag]:
        any_change = True
        verb = 'ADDED' if new_info[flag] else 'REMOVED'
        report.append(f"[{verb}] {name}")
if not any_change:
    report.append("[OK] No tracked sections were added or removed.")
report.append("")

# 6. SUMMARY TABLE (all values derived from file content)
report.append("6. COMPARISON SUMMARY TABLE")
report.append("-" * 60)
report.append("")


def changed(a, b):
    return "Same" if a == b else "CHANGED"


table_data = [
    ["Aspect", "Current", "Candidate", "Change?"],
    ["-" * 22, "-" * 12, "-" * 12, "-" * 8],
    ["License Type", current_info['license_type'], new_info['license_type'],
     changed(current_info['license_type'], new_info['license_type'])],
    ["Version", f"v{current_info['version']}", f"v{new_info['version']}",
     changed(current_info['version'], new_info['version'])],
    ["Copyright line", "see report", "see report",
     changed(current_info['copyright'], new_info['copyright'])],
    ["Additional Terms", yn(current_info['has_additional_terms']),
     yn(new_info['has_additional_terms']),
     changed(current_info['has_additional_terms'], new_info['has_additional_terms'])],
    ["Linking Exception", yn(current_info['has_linking_exception']),
     yn(new_info['has_linking_exception']),
     changed(current_info['has_linking_exception'], new_info['has_linking_exception'])],
    ["Disclaimer of Warranty", yn(current_info['has_disclaimer']),
     yn(new_info['has_disclaimer']),
     changed(current_info['has_disclaimer'], new_info['has_disclaimer'])],
    ["Limitation of Liability", yn(current_info['has_limitation']),
     yn(new_info['has_limitation']),
     changed(current_info['has_limitation'], new_info['has_limitation'])],
    ["Total Bytes", str(len(current_content)), str(len(new_content)),
     f"{len(new_content) - len(current_content):+d}"],
    ["Total Lines", str(len(current_lines)), str(len(new_lines)),
     f"{len(new_lines) - len(current_lines):+d}"],
]
for row in table_data:
    report.append(f"  {row[0]:<22} | {row[1]:<12} | {row[2]:<12} | {row[3]:<8}")
report.append("")

# 7. RECOMMENDATIONS
report.append("7. RECOMMENDATIONS")
report.append("-" * 60)
report.append("")
if current_content == new_content:
    report.append("[OK] Files are identical - no action needed.")
else:
    report.append("- Keep COPYING.LESSER byte-for-byte verbatim LGPL v3 (sections 0-6).")
    report.append("- Put the project copyright/notice in README and source headers,")
    report.append("  not inside the licence text.")
    if not is_compatible:
        report.append("- [ERROR] License type changed - review before adopting the candidate.")
    if new_info['has_disclaimer'] or new_info['has_limitation']:
        report.append("- Sections 15/16 belong to the GPL (in COPYING); they should not be")
        report.append("  appended to the LGPL file.")
report.append("")

report.append("=" * 60)
report.append(f"Report generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
report.append("=" * 60)

# Print and save
report_text = '\n'.join(report)
print(report_text)

output_file = os.path.join(output_dir, 'COPYING_LESSER_VERIFICATION_REPORT.txt')
with open(output_file, 'w', encoding='utf-8') as f:
    f.write(report_text)
print(f"\n[OK] Report saved: {output_file}")

# === DETAILED DIFF ===
print("\n" + "=" * 60)
print("CREATING DETAILED DIFF FILE")
print("=" * 60)
print()

diff_file = os.path.join(output_dir, 'COPYING_LESSER_DIFF.txt')
with open(diff_file, 'w', encoding='utf-8') as f:
    f.write("DETAILED DIFF: current -> candidate\n")
    f.write("=" * 60 + "\n\n")
    for line in unified_diff(current_lines, new_lines,
                             fromfile='COPYING.LESSER (current)',
                             tofile='COPYING.LESSER (candidate)',
                             lineterm='', n=2):
        f.write(line + '\n')
print(f"[OK] Diff file saved: {diff_file}")

# === MARKDOWN COMPARISON ===
print("\n" + "=" * 60)
print("CREATING MARKDOWN COMPARISON")
print("=" * 60)
print()

md_file = os.path.join(output_dir, 'COPYING_LESSER_COMPARISON_DETAILED.md')
with open(md_file, 'w', encoding='utf-8') as f:
    f.write("# COPYING.LESSER Comparison: current vs candidate\n\n")
    f.write(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n")
    f.write("## Quick Facts\n\n")
    f.write("| Metric | Current | Candidate | Change |\n")
    f.write("|--------|---------|-----------|--------|\n")
    f.write(f"| License Type | {current_info['license_type']} | {new_info['license_type']} | {changed(current_info['license_type'], new_info['license_type'])} |\n")
    f.write(f"| Version | {current_info['version']} | {new_info['version']} | {changed(current_info['version'], new_info['version'])} |\n")
    f.write(f"| Size (bytes) | {len(current_content)} | {len(new_content)} | {len(new_content) - len(current_content):+d} |\n")
    f.write(f"| Lines | {len(current_lines)} | {len(new_lines)} | {len(new_lines) - len(current_lines):+d} |\n")
    f.write(f"| Additional Terms | {yn(current_info['has_additional_terms'])} | {yn(new_info['has_additional_terms'])} | {changed(current_info['has_additional_terms'], new_info['has_additional_terms'])} |\n")
    f.write(f"| Linking Exception | {yn(current_info['has_linking_exception'])} | {yn(new_info['has_linking_exception'])} | {changed(current_info['has_linking_exception'], new_info['has_linking_exception'])} |\n\n")

    if current_content == new_content:
        f.write("## Status: IDENTICAL\n\nNo differences.\n")
    else:
        f.write("## Status: DIFFERENCES FOUND\n\n")
        f.write("See `COPYING_LESSER_DIFF.txt` for the full line-by-line diff.\n")

print(f"[OK] Markdown comparison saved: {md_file}")

print("\n" + "=" * 60)
print("[OK] ALL VERIFICATION COMPLETE")
print("=" * 60)
print("\nGenerated files:")
print(f"  1. {output_file}")
print(f"  2. {diff_file}")
print(f"  3. {md_file}")
