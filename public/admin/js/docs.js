// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

// admin/js/docs.js — Admin documentation page renderer; builds HTML from docs-strings.js STRINGS, language switch persisted in localStorage (sparrow_docs_lang). Languages: en/pl only.
import { STRINGS } from './docs-strings.js';

// Configuration constants - documentation is maintained in English and Polish only
const ALL_LANGS = ['en', 'pl'];
const STORAGE_KEY = 'sparrow_docs_lang';

// HTML generators
const _h2 = (t) => `<h2 style="border-bottom:2px solid var(--accent-mid);padding-bottom:10px;margin-top:0;color:var(--text);">${t}</h2>`;
const _h3 = (id, t) => `<h3 id="${id}" style="color:var(--muted);margin-top:30px;">${t}</h3>`;
const _h4 = (t, c = 'var(--accent-mid)') => `<h4 style="color:var(--muted);margin-top:20px;border-left:3px solid ${c};padding-left:15px;">${t}</h4>`;
const _p = (t, style = '') => style ? `<p style="${style}">${t}</p>` : `<p>${t}</p>`;
const _ul = (items) => `<ul style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ul>`;
const _ol = (items) => `<ol style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ol>`;
const _warn = (s, t) => `<p style="background:var(--warn-light);padding:10px 14px;border-left:3px solid var(--warn);border-radius:4px;"><strong>${s}</strong> ${t}</p>`;

export function renderDocumentation(ctx) {
    // Guard against missing context
    if (!ctx || !ctx.workspaceEl) return;

    const { workspaceEl } = ctx;
    workspaceEl.innerHTML = '';

    // Resolve language state
    let lang = localStorage.getItem(STORAGE_KEY) || 'en';
    if (!ALL_LANGS.includes(lang)) lang = 'en';
    const s = STRINGS[lang] || STRINGS.en;

    // Build main wrapper
    const wrapper = document.createElement('div');
    wrapper.appendChild(createLanguageBar(lang, ctx));
    
    const contentArea = createContentArea(s);
    wrapper.appendChild(contentArea);
    
    workspaceEl.appendChild(wrapper);
}

function createLanguageBar(currentLang, ctx) {
    const langBar = document.createElement('div');
    langBar.style.cssText = 'max-width:900px; display:flex; flex-wrap:wrap; justify-content:flex-end; gap:6px; margin-bottom:8px;';

    ALL_LANGS.forEach(l => {
        const btn = document.createElement('button');
        const isActive = currentLang === l;
        
        btn.textContent = l.toUpperCase();
        btn.dataset.lang = l;
        btn.className = 'btn btn-xs ' + (isActive ? 'btn-primary' : 'btn-secondary');
        
        // Handle language switch
        btn.addEventListener('click', () => {
            localStorage.setItem(STORAGE_KEY, l);
            renderDocumentation(ctx);
        });
        
        langBar.appendChild(btn);
    });

    return langBar;
}

function createContentArea(s) {
    const content = document.createElement('div');
    content.style.cssText = 'max-width:900px; padding:30px; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); color:var(--muted); line-height:1.6; margin-bottom:40px;';
    content.innerHTML = buildContent(s);
    return content;
}


function buildContent(s) {
    return `<div>
${_h2(s.title)}
<p style="color:var(--muted);margin-bottom:30px;">${s.subtitle}</p>

${_h3('doc-0', s.s0_head)}
${_warn(s.s0_warn_strong, s.s0_warn_text)}
${_ol([s.s0_step1, s.s0_step2, s.s0_step3, s.s0_step4, s.s0_step5])}
${_p(s.s0_after)}

${_h3('doc-0b', s.s0b_head)}
${_p(s.s0b_desc)}
${_ul([
    `<strong>${s.s0b_data_label}:</strong> ${s.s0b_data}`,
    `<strong>${s.s0b_workflows_label}:</strong> ${s.s0b_workflows}`,
    `<strong>${s.s0b_kb_label}:</strong> ${s.s0b_kb}`,
    `<strong>${s.s0b_system_label}:</strong> ${s.s0b_system}`,
    `<strong>${s.s0b_save_label}:</strong> ${s.s0b_save}`,
    `<strong>${s.s0b_guard_label}:</strong> ${s.s0b_guard}`,
    `<strong>${s.s0b_debug_label}:</strong> ${s.s0b_debug}`,
    `<strong>${s.s0b_docs_label}:</strong> ${s.s0b_docs}`
])}

${_h3('doc-1', s.s1_head)}
${_p(s.s1_desc)}
${_ul([
    `<strong>${s.s1_pk_label}:</strong> ${s.s1_pk}`,
    `<strong>${s.s1_fk_label}:</strong> ${s.s1_fk}`,
    `<strong>${s.s1_enum_label}:</strong> ${s.s1_enum}`,
    `<strong>${s.s1_bool_label}:</strong> ${s.s1_bool}`,
    `<strong>${s.s1_schema_label}:</strong> ${s.s1_schema}`
])}
${_h4(s.s1_systables_head)}
${_p(s.s1_systables_desc)}
${_ul([
    `<code>spw_users</code> — ${s.s1_t_users}`,
    `<code>spw_users_log</code> — ${s.s1_t_users_log}`,
    `<code>spw_users_notifications</code> — ${s.s1_t_notifications}`,
    `<code>spw_users_notifications_log</code> — ${s.s1_t_notifications_log}`,
    `<code>spw_files</code> — ${s.s1_t_files}`,
    `<code>spw_login_attempts</code> — ${s.s1_t_login_attempts}`,
    `<code>spw_comments</code> — ${s.s1_t_comments}`,
    `<code>spw_record_snapshots</code> — ${s.s1_t_snapshots}`,
    `<code>spw_record_owners</code> — ${s.s1_t_owners}`,
    `<code>spw_migrations</code> — ${s.s1_t_migrations}`,
    `<code>spw_release_migrations</code> — ${s.s1_t_release_migrations}`,
    `<code>spw_config</code> — ${s.s1_t_config}`,
    `<code>spw_config_log</code> — ${s.s1_t_config_log}`,
    `<code>spw_notes</code> — ${s.s1_t_notes}`,
    `<code>spw_rag_*</code> — ${s.s1_t_rag}`,
    `<code>spw_automation_*</code> — ${s.s1_t_automations}`,
    `<code>spw_imports</code> — ${s.s1_t_imports}`,
    `<code>spw_etl_*</code> — ${s.s1_t_etl}`,
    `<code>spw_anonymization_*</code> — ${s.s1_t_anon}`,
    `<code>spw_clickstats</code> — ${s.s1_t_clickstats}`
])}
${_warn(s.s1_note_strong, s.s1_note_text)}

${_h3('doc-2', s.s2_head)}
${_p(s.s2_desc)}
${_h4(s.s2_addtable_head)}
${_p(s.s2_addtable_desc)}
${_ul([
    `<strong>${s.s2_name_label}:</strong> ${s.s2_name}`,
    `<strong>${s.s2_display_label}:</strong> ${s.s2_display}`,
    `<strong>${s.s2_presets_label}:</strong> ${s.s2_presets}`,
    `<strong>${s.s2_columns_label}</strong> — ${s.s2_columns}`,
    `<strong>${s.s2_register_label}</strong> ${s.s2_register}`
])}
${_ul([
    `<strong>${s.s2_addcol_label}:</strong> ${s.s2_addcol}`,
    `<strong>${s.s2_synctables_label}:</strong> ${s.s2_synctables}`,
    `<strong>${s.s2_synccols_label}:</strong> ${s.s2_synccols}`,
    `<strong>${s.s2_coldesc_label}:</strong> ${s.s2_coldesc}`,
    `<strong>${s.s2_preview_label}:</strong> ${s.s2_preview}`,
    `<strong>${s.s2_typemap_label}:</strong> ${s.s2_typemap}`,
    `<strong>${s.s2_remove_label}:</strong> ${s.s2_remove}`,
    `<strong>${s.s2_fksearch_label}:</strong> ${s.s2_fksearch}`,
    `<strong>${s.s2_visibility_label}:</strong> ${s.s2_visibility}`,
    `<strong>${s.s2_validation_label}:</strong> ${s.s2_validation}
        <ul style="padding-left:20px;margin-top:5px;">
            <li><strong>Email:</strong> <code>^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$</code></li>
            <li><strong>${s.regex_phone}:</strong> <code>^\\+?[0-9]{9,15}$</code></li>
            <li><strong>${s.regex_postal}:</strong> <code>^[0-9]{2}-[0-9]{3}$</code></li>
            <li><strong>URL (http/https):</strong> <code>^https?:\\/\\/.*$</code></li>
            <li><strong>${s.regex_username}:</strong> <code>^[a-zA-Z0-9_]{3,16}$</code></li>
            <li><strong>${s.regex_price}:</strong> <code>^\\d+(\\.\\d{1,2})?$</code></li>
            <li><strong>${s.regex_date}:</strong> <code>^\\d{4}-\\d{2}-\\d{2}$</code></li>
            <li><strong>${s.regex_password}:</strong> <code>^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d).{8,}$</code></li>
        </ul>`
])}
${_h4(s.s2_subtables_head)}
${_p(s.s2_subtables_desc)}
${_ul([s.s2_sub_open, `<strong>${s.s2_sub_target_label}:</strong> ${s.s2_sub_target}`, `<strong>${s.s2_sub_fkcol_label}:</strong> ${s.s2_sub_fkcol}`])}
${_h4(s.s2_img_head)}
${_p(s.s2_img_desc)}
${_ul([
    `<strong>${s.s2_img_enable_label}:</strong> ${s.s2_img_enable}`,
    `<strong>${s.s2_img_max_label}:</strong> ${s.s2_img_max}`,
    `<strong>${s.s2_img_grid_label}:</strong> ${s.s2_img_grid}`,
    `<strong>${s.s2_img_upload_label}:</strong> ${s.s2_img_upload}`
])}

${_h3('doc-3', s.s3_head)}
${_p(s.s3_desc)}
${_h4(s.s3_types_head)}
${_ul([
    `<strong>${s.s3_stat_label}:</strong> ${s.s3_stat}`,
    `<strong>${s.s3_bar_label}:</strong> ${s.s3_bar}`,
    `<strong>${s.s3_pie_label}:</strong> ${s.s3_pie}`,
    `<strong>${s.s3_line_label}:</strong> ${s.s3_line}`,
    `<strong>${s.s3_list_label}:</strong> ${s.s3_list}`
])}
${_h4(s.s3_props_head)}
${_ul([
    `<strong>${s.s3_width_label}:</strong> <code>1/3</code>, <code>2/3</code>, <code>3/3</code>.`,
    `<strong>${s.s3_height_label}:</strong> ${s.s3_height}`
])}
${_p(s.s3_mobile)}
${_h4(s.s3_filter_head)}
${_p(s.s3_filter_desc)}
${_ul([`${s.s3_filter_ops}: <code>=</code>, <code>!=</code>, <code>&lt;</code>, <code>&gt;</code>, <code>&lt;=</code>, <code>&gt;=</code>, <code>LIKE</code>, <code>ILIKE</code>, <code>IS NULL</code>, <code>IS NOT NULL</code>.`])}
${_h4(s.s3_period_head)}
${_p(s.s3_period_desc)}
${_p(s.s3_export_desc)}
${_h4(s.s3_preview_head)}
${_p(s.s3_preview_desc)}
${_h4(s.s3_global_head)}
${_p(s.s3_global_desc)}

${_h3('doc-4', s.s4_head)}
${_p(s.s4_desc)}
${_ul([
    `<strong>${s.s4_sources_label}:</strong> ${s.s4_sources}`,
    `<strong>${s.s4_color_label}:</strong> ${s.s4_color}`,
    `<strong>${s.s4_context_label}:</strong> ${s.s4_context}`
])}

${_h3('doc-4b', s.s4b_head)}
${_p(s.s4b_desc)}
${_ul([
    `<strong>${s.s4b_multi_label}:</strong> ${s.s4b_multi}`,
    `<strong>${s.s4b_table_label}:</strong> ${s.s4b_table}`,
    `<strong>${s.s4b_status_label}:</strong> ${s.s4b_status}`,
    `<strong>${s.s4b_cards_label}:</strong> ${s.s4b_cards}`,
    `<strong>${s.s4b_dnd_label}:</strong> ${s.s4b_dnd}`
])}

${_h3('doc-5', s.s5_head)}
${_p(s.s5_desc)}
${_ul([
    `<strong>${s.s5_steps_label}:</strong> ${s.s5_steps}`,
    `<strong>${s.s5_link_label}:</strong> ${s.s5_link}`,
    `<strong>${s.s5_multi_label}:</strong> ${s.s5_multi}`
])}
${_h4(s.s5_validation_head)}
${_p(s.s5_validation_desc)}
${_h4(s.s5_proc_head)}
${_p(s.s5_proc_desc)}

${_h3('doc-6', s.s6_head)}
${_p(s.s6_desc)}
${_ul([
    `<strong>${s.s6_roles_label}:</strong>
        <ul style="padding-left:20px;margin-top:5px;">
            <li><strong>Admin</strong> — ${s.s6_admin}</li>
            <li><strong>Editor</strong> — ${s.s6_editor}</li>
            <li><strong>Viewer</strong> — ${s.s6_viewer}</li>
        </ul>`,
    `<strong>${s.s6_pwd_label}:</strong> ${s.s6_pwd}`,
    `<strong>${s.s6_status_label}:</strong> ${s.s6_status}`
])}

${_h3('doc-7', s.s7_head)}
${_p(s.s7_desc)}
${_ul([
    `<strong>${s.s7_schema_label}:</strong> ${s.s7_schema}`,
    `<strong>${s.s7_test_label}:</strong> ${s.s7_test}`,
    `<strong>${s.s7_login_label}:</strong> ${s.s7_login}`
])}

${_h3('doc-8', s.s8_head)}
${_p(s.s8_desc)}
${_ul([
    `<strong>${s.s8_format_label}:</strong> <code>YYYYMMDDHHII_tablename</code>.`,
    `<strong>${s.s8_what_label}:</strong> ${s.s8_what}`
])}

${_h3('doc-9', s.s9_head)}
${_ul([
    `<strong>${s.s9_migrations_label}:</strong> ${s.s9_migrations}`,
    `<strong>${s.s9_diagnostics_label}:</strong> ${s.s9_diagnostics}`,
    `<strong>${s.s9_cron_label}:</strong> ${s.s9_cron}`
])}

${_h3('doc-9b', s.s9b_head)}
${_p(s.s9b_desc)}
${_ul([
    `<strong>${s.s9b_how_label}:</strong> ${s.s9b_how}`,
    `<strong>${s.s9b_toggle_label}:</strong> ${s.s9b_toggle}`,
    `<strong>${s.s9b_storage_label}:</strong> ${s.s9b_storage}`
])}

${_h3('doc-9c', s.s9c_head)}
${_p(s.s9c_desc)}
${_ul([s.s9c_applied, `<strong>${s.s9c_adding_label}:</strong> ${s.s9c_adding}`])}

${_h3('doc-9d', s.s9d_head)}
${_p(s.s9d_desc)}
${_ul([
    `<strong>${s.s9d_auto_label}:</strong> ${s.s9d_auto}`,
    `<strong>${s.s9d_change_label}:</strong> ${s.s9d_change}`,
    `<strong>${s.s9d_history_label}:</strong> ${s.s9d_history}`
])}

${_h3('doc-9o', s.s9o_head)}
${_p(s.s9o_desc)}
${_ul([
    `<strong>${s.s9o_where_label}:</strong> ${s.s9o_where}`,
    `<strong>${s.s9o_empty_label}:</strong> ${s.s9o_empty}`,
    `<strong>${s.s9o_admin_label}:</strong> ${s.s9o_admin}`,
    `<strong>${s.s9o_scope_label}:</strong> ${s.s9o_scope}`,
    `<strong>${s.s9o_hidden_label}:</strong> ${s.s9o_hidden}`,
    `<strong>${s.s9o_bindings_label}:</strong> ${s.s9o_bindings}`,
    `<strong>${s.s9o_views_label}:</strong> ${s.s9o_views}`,
    `<strong>${s.s9o_limits_label}:</strong> ${s.s9o_limits}`
])}

${_h3('doc-9p', s.s9p_head)}
${_p(s.s9p_desc)}
${_ul([
    `<strong>${s.s9p_where_label}:</strong> ${s.s9p_where}`,
    `<strong>${s.s9p_optional_label}:</strong> ${s.s9p_optional}`,
    `<strong>${s.s9p_scope_label}:</strong> ${s.s9p_scope}`
])}

${_h3('doc-9e', s.s9e_head)}
${_ul([
    `<strong>${s.s9e_sort_label}:</strong> ${s.s9e_sort}`,
    `<strong>${s.s9e_limit_label}:</strong> ${s.s9e_limit}`,
    `<strong>${s.s9e_stored_label}:</strong> <code>spw_config.schema</code> ${s.s9e_stored}`
])}

${_h3('doc-9f', s.s9f_head)}
${_p(s.s9f_desc)}

${_h3('doc-9f2', s.s9f2_head)}
${_p(s.s9f2_desc)}
${_ul([
    `<strong>Edit</strong> — ${s.s9f2_edit}`,
    `<strong>Duplicate</strong> — ${s.s9f2_dup}`,
    `<strong>Delete</strong> — ${s.s9f2_delete}`,
    s.s9f2_visible
])}

${_h3('doc-9g', s.s9g_head)}
${_p(s.s9g_desc)}
${_ul([
    `<strong>1. ${s.s9g_li1_label}:</strong> ${s.s9g_li1}`,
    `<strong>2. ${s.s9g_li2_label}:</strong> ${s.s9g_li2}`,
    `<strong>3. ${s.s9g_li3_label}:</strong> ${s.s9g_li3}`,
    `<strong>4. ${s.s9g_li4_label}:</strong> ${s.s9g_li4}`,
    `<strong>5. ${s.s9g_li5_label}:</strong> ${s.s9g_li5}`,
    `<strong>6. ${s.s9g_li6_label}:</strong> ${s.s9g_li6}`
])}

${_h3('doc-9h', s.s9h_head)}
${_p(s.s9h_desc)}
${_ul([
    `<strong>1. ${s.s9h_li1_label}:</strong> ${s.s9h_li1}`,
    `<strong>2. ${s.s9h_li2_label}:</strong> ${s.s9h_li2}`,
    `<strong>3. ${s.s9h_li3_label}:</strong> ${s.s9h_li3}`,
    `<strong>4. ${s.s9h_li4_label}:</strong> ${s.s9h_li4}`,
    `<strong>5. ${s.s9h_li5_label}:</strong> ${s.s9h_li5}`,
    `<strong>6. ${s.s9h_li6_label}:</strong> ${s.s9h_li6}`
])}

${_h3('doc-9i', s.s9i_head)}
${_ul([
    `<strong>${s.s9i_admin_label}:</strong> ${s.s9i_admin}`,
    `<strong>${s.s9i_user_label}:</strong> ${s.s9i_user}`,
    `<strong>${s.s9i_priority_label}:</strong> <code>localStorage</code> → <code>schema.default_page_size</code> → ${s.s9i_fallback} 25.`
])}

${_h3('doc-9j', s.s9j_head)}
${_p(s.s9j_desc)}
${_h4(s.s9j_how_head, 'var(--muted)')}
${_ol([
    `<strong>${s.s9j_how1_label}:</strong> ${s.s9j_how1}`,
    `<strong>${s.s9j_how2_label}:</strong> ${s.s9j_how2}`,
    `<strong>${s.s9j_how3_label}:</strong> ${s.s9j_how3}`
])}
${_h4(s.s9j_config_head, 'var(--muted)')}
${_ul([`<strong>${s.s9j_config_li}</strong>`])}
${_h4(s.s9j_runtime_head, 'var(--muted)')}
${_ul([s.s9j_runtime1, s.s9j_runtime2])}

${_h3('doc-9k', s.s9k_head)}
${_p(s.s9k_desc)}
${_ul([
    `<strong>${s.s9k_types_label}:</strong> ${s.s9k_types}`,
    `<strong>${s.s9k_controls_label}:</strong> ${s.s9k_controls}`
])}

${_h3('doc-9l', s.s9l_head)}
${_p(s.s9l_desc)}
${_ul([
    `<strong>${s.s9l_edit_label}:</strong> ${s.s9l_edit}`,
    `<strong>${s.s9l_dup_label}:</strong> ${s.s9l_dup}`,
    `<strong>${s.s9l_clean_label}:</strong> ${s.s9l_clean}`,
    `<strong>${s.s9l_role_label}:</strong> ${s.s9l_role}`
])}

${_h3('doc-9m', s.s9m_head)}
${_p(s.s9m_desc)}
${_ul([
    `<strong>${s.s9m_tab1_label}:</strong> ${s.s9m_tab1}`,
    `<strong>${s.s9m_tab2_label}:</strong> ${s.s9m_tab2}`,
    `<strong>${s.s9m_tab3_label}:</strong> ${s.s9m_tab3}`,
    `<strong>${s.s9m_tab4_label}:</strong> ${s.s9m_tab4}`,
    `<strong>${s.s9m_tab5_label}:</strong> ${s.s9m_tab5}`
])}

${_h3('doc-9n', s.sLogo_head)}
${_p(s.sLogo_desc)}
${_ul([
    `<strong>${s.sLogo_li1_label}:</strong> ${s.sLogo_li1}`,
    `<strong>${s.sLogo_li2_label}:</strong> ${s.sLogo_li2}`,
    `<strong>${s.sLogo_li3_label}:</strong> ${s.sLogo_li3}`,
    `<strong>${s.sLogo_li4_label}:</strong> ${s.sLogo_li4}`
])}

${_h3('doc-9o', s.s9o_head)}
${_p(s.s9o_desc)}
${_ul([
    `<strong>${s.s9o_tab1_label}:</strong> ${s.s9o_tab1}`,
    `<strong>${s.s9o_tab2_label}:</strong> ${s.s9o_tab2}`,
    `<strong>${s.s9o_off_label}:</strong> ${s.s9o_off}`,
    `<strong>${s.s9o_privacy_label}:</strong> ${s.s9o_privacy}`
])}

${_h3('doc-10', s.s10_head)}
${_p(s.s10_desc)}
${_ul([
    `<strong>${s.s10_browse_label}:</strong> ${s.s10_browse}`,
    `<strong>${s.s10_meta_label}:</strong> ${s.s10_meta}`,
    `<strong>${s.s10_bulk_label}:</strong> ${s.s10_bulk}`,
    `<strong>${s.s10_config_label}:</strong> ${s.s10_config}`,
    `<strong>${s.s10_relations_label}:</strong> ${s.s10_relations}`
])}

${_h3('doc-10b', s.s10b_head)}
${_p(s.s10b_desc)}
${_ul([
    `<strong>${s.s10b_matview_label}:</strong> ${s.s10b_matview}`,
    `<strong>${s.s10b_sync_label}:</strong> ${s.s10b_sync}`,
    `<strong>${s.s10b_schemas_label}:</strong> ${s.s10b_schemas}`,
    `<strong>${s.s10b_display_label}:</strong> ${s.s10b_display}`,
    `<strong>${s.s10b_readonly_label}:</strong> ${s.s10b_readonly}`
])}

${_h3('doc-10c', s.s10c_head)}
${_p(s.s10c_desc)}
${_ul([
    `<strong>${s.s10c_map_label}:</strong> ${s.s10c_map}`,
    `<strong>${s.s10c_global_label}:</strong> ${s.s10c_global}`
])}

${_h3('doc-10d', s.s10d_head)}
${_p(s.s10d_desc)}
${_ul([
    `<strong>${s.s10d_where_label}:</strong> ${s.s10d_where}`,
    `<strong>${s.s10d_perm_label}:</strong> ${s.s10d_perm}`
])}

${_h3('doc-10e', s.s10e_head)}
${_p(s.s10e_desc)}
${_ul([
    `<strong>${s.s10e_private_label}:</strong> ${s.s10e_private}`,
    `<strong>${s.s10e_link_label}:</strong> ${s.s10e_link}`,
    `<strong>${s.s10e_reminder_label}:</strong> ${s.s10e_reminder}`
])}

${_h3('doc-11', s.s11_head)}
${_p(s.s11_desc)}
${_h4(s.s11_dnd_head)}
${_ul([
    `<strong>${s.s11_reorder_label}:</strong> ${s.s11_reorder}`,
    `<strong>${s.s11_nest_label}:</strong> ${s.s11_nest}`,
    `<strong>${s.s11_unnest_label}:</strong> ${s.s11_unnest}`,
    `<strong>${s.s11_autosave_label}:</strong> ${s.s11_autosave}`
])}

${_h3('doc-11b', s.s11b_head)}
${_p(s.s11b_desc)}
${_ul([
    `<strong>${s.s11b_what_label}:</strong> ${s.s11b_what}`,
    `<strong>${s.s11b_safety_label}:</strong> ${s.s11b_safety}`,
    `<strong>${s.s11b_cleanup_label}:</strong> ${s.s11b_cleanup}`
])}
${_h4(s.s11b_demo1_head)}
${_p(s.s11b_demo1_text)}

${_h3('doc-11c', s.s11c_head)}
${_p(s.s11c_desc)}
${_ul([
    `<strong>${s.s11c_step1_label}:</strong> ${s.s11c_step1}`,
    `<strong>${s.s11c_parse_label}:</strong> ${s.s11c_parse}`,
    `<strong>${s.s11c_create_label}:</strong> ${s.s11c_create}`,
    `<strong>${s.s11c_step2_label}:</strong> ${s.s11c_step2}`,
    `<strong>${s.s11c_mode_label}:</strong> ${s.s11c_mode}`,
    `<strong>${s.s11c_upsert_label}:</strong> ${s.s11c_upsert}`,
    `<strong>${s.s11c_types_label}:</strong> ${s.s11c_types}`,
    `<strong>${s.s11c_errors_label}:</strong> ${s.s11c_errors}`,
    `<strong>${s.s11c_tables_label}:</strong> ${s.s11c_tables}`,
    `<strong>${s.s11c_history_label}:</strong> ${s.s11c_history}`
])}

${_h3('doc-11d', s.s11d_head)}
${_p(s.s11d_desc)}
${_ul([
    `<strong>${s.s11d_conn_label}:</strong> ${s.s11d_conn}`,
    `<strong>${s.s11d_jobs_label}:</strong> ${s.s11d_jobs}`,
    `<strong>${s.s11d_modes_label}:</strong> ${s.s11d_modes}`,
    `<strong>${s.s11d_incremental_label}:</strong> ${s.s11d_incremental}`,
    `<strong>${s.s11d_preview_label}:</strong> ${s.s11d_preview}`,
    `<strong>${s.s11d_schedule_label}:</strong> ${s.s11d_schedule}`,
    `<strong>${s.s11d_log_label}:</strong> ${s.s11d_log}`
])}

${_h3('doc-11e', s.s11e_head)}
${_p(s.s11e_desc)}
${_ul([
    `<strong>${s.s11e_run_label}:</strong> ${s.s11e_run}`,
    `<strong>${s.s11e_schedule_label}:</strong> ${s.s11e_schedule}`,
    `<strong>${s.s11e_log_label}:</strong> ${s.s11e_log}`
])}

${_h3('doc-12', s.s12_head)}
${_ul([s.s12_li1, s.s12_li2, s.s12_li3, s.s12_li4])}
${_h4(s.s12_env_head)}
<table class="adm-tbl" style="margin-top:10px;">
    <thead><tr>
        <th class="adm-th">${s.s12_th_var}</th>
        <th class="adm-th">${s.s12_th_default}</th>
        <th class="adm-th">${s.s12_th_desc}</th>
    </tr></thead>
    <tbody>
        <tr><td class="adm-td"><code>APP_ENV</code></td><td class="adm-td"><code>production</code></td><td class="adm-td">${s.env_appenv}</td></tr>
        <tr><td class="adm-td"><code>DB_HOST</code> / <code>PGHOST</code></td><td class="adm-td"><code>localhost</code></td><td class="adm-td">${s.env_dbhost}</td></tr>
        <tr><td class="adm-td"><code>DB_PORT</code> / <code>PGPORT</code></td><td class="adm-td"><code>5432</code></td><td class="adm-td">${s.env_dbport}</td></tr>
        <tr><td class="adm-td"><code>APP_TIMEZONE</code></td><td class="adm-td"><code>Europe/Warsaw</code></td><td class="adm-td">${s.env_timezone}</td></tr>
        <tr><td class="adm-td"><code>SECURE_COOKIES</code></td><td class="adm-td"><code>true</code></td><td class="adm-td">${s.env_cookies}</td></tr>
        <tr><td class="adm-td"><code>SESSION_MAX_LIFETIME</code></td><td class="adm-td"><code>28800</code></td><td class="adm-td">${s.env_session}</td></tr>
        <tr><td class="adm-td"><code>IP_HASH_SALT</code></td><td class="adm-td"><em>${s.env_none}</em></td><td class="adm-td"><strong>${s.env_iphash_req}</strong> ${s.env_iphash}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_MAX_ATTEMPTS_PER_IP</code></td><td class="adm-td"><code>20</code></td><td class="adm-td">${s.env_ip_attempts}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_MAX_ATTEMPTS_PER_USERNAME</code></td><td class="adm-td"><code>5</code></td><td class="adm-td">${s.env_user_attempts}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_LOCKOUT_MINUTES</code></td><td class="adm-td"><code>15</code></td><td class="adm-td">${s.env_lockout}</td></tr>
        <tr><td class="adm-td"><code>DEMO_MODE</code></td><td class="adm-td"><code>false</code></td><td class="adm-td">${s.env_demo}</td></tr>
        <tr><td class="adm-td"><code>FILES_MAX_SIZE_MB</code></td><td class="adm-td"><code>20</code></td><td class="adm-td">${s.env_files}</td></tr>
        <tr><td class="adm-td"><code>RECORD_SNAPSHOTS_ENABLED</code></td><td class="adm-td"><code>false</code></td><td class="adm-td">${s.env_snapshots}</td></tr>
        <tr><td class="adm-td"><code>PGSCHEMA</code></td><td class="adm-td"><code>app</code></td><td class="adm-td">${s.env_pgschema}</td></tr>
        <tr><td class="adm-td"><code>APP_ENCRYPTION_KEY</code></td><td class="adm-td"><em>${s.env_autogen}</em></td><td class="adm-td">${s.env_enckey}</td></tr>
        <tr><td class="adm-td"><code>TRUST_PROXY_HEADERS</code></td><td class="adm-td"><code>true</code></td><td class="adm-td">${s.env_proxy}</td></tr>
        <tr><td class="adm-td"><code>SESSION_SAMESITE</code></td><td class="adm-td"><code>Lax</code></td><td class="adm-td">${s.env_samesite}</td></tr>
        <tr><td class="adm-td"><code>SESSION_SAVE_PATH</code></td><td class="adm-td"><em>${s.env_none}</em></td><td class="adm-td">${s.env_savepath}</td></tr>
        <tr><td class="adm-td"><code>HSTS_MAX_AGE</code></td><td class="adm-td"><code>31536000</code></td><td class="adm-td">${s.env_hsts}</td></tr>
        <tr><td class="adm-td"><code>AUTOMATION_EMAIL_FROM</code></td><td class="adm-td"><em>${s.env_none}</em></td><td class="adm-td">${s.env_mailfrom}</td></tr>
    </tbody>
</table>
${_p(s.s12_env_note)}

${_h3('doc-13', s.s13_head)}
${_p(s.s13_desc)}
${_h4(s.s13_config_head)}
${_ul([s.s13_config1, s.s13_config2])}
${_h4(s.s13_trans_head)}
${_p(s.s13_trans_desc)}
${_ul([s.s13_trans1, s.s13_trans2, s.s13_trans3, `<strong>${s.s13_trans4_label}:</strong> ${s.s13_trans4}`])}
${_h4(s.s13_php_head)}
${_ul([s.s13_php1, s.s13_php2, s.s13_php3, s.s13_php4])}
${_h4(s.s13_js_head)}
${_ul([s.s13_js1, s.s13_js2, s.s13_js3, s.s13_js4, s.s13_js5, s.s13_js6])}
${_h4(s.s13_add_head)}
${_ol([s.s13_add1, s.s13_add2, s.s13_add3, s.s13_add4])}
${_p(`<strong>${s.s13_docslang_label}:</strong> ${s.s13_docslang}`)}

${_h3('doc-14', s.s14_head)}
${_p(s.s14_desc)}
${_h4(s.s14_trigger_label)}
${_p(s.s14_trigger)}
${_h4(s.s14_cond_label)}
${_p(s.s14_cond)}
${_h4(s.s14_actions_label)}
${_p(s.s14_actions)}
${_h4(s.s14_vars_label)}
${_p(s.s14_vars)}
${_h4(s.s14_n8n_label)}
${_p(s.s14_n8n_intro)}
${_ol([s.s14_n8n1, s.s14_n8n2, s.s14_n8n3, s.s14_n8n4, s.s14_n8n5])}
${_p(s.s14_n8n_payload)}
${_warn(s.s14_n8n_warn_label, s.s14_n8n_warn)}
${_h4(s.s14_history_label)}
${_p(s.s14_history)}
${_p(`<strong>${s.s14_note_label}:</strong> ${s.s14_note}`, 'background:var(--warn-light);padding:10px 14px;border-left:3px solid var(--warn);border-radius:4px;')}

${_h3('doc-15', s.sRag_head)}
${_p(s.sRag_desc)}
${_ul([
    `<strong>${s.s13_docs_label}:</strong> ${s.s13_docs}`,
    `<strong>${s.s13_config_label}:</strong> ${s.s13_config}`,
    `<strong>${s.s13_test_label}:</strong> ${s.s13_test}`,
    `<strong>${s.s13_stats_label}:</strong> ${s.s13_stats}`,
    `<strong>${s.s13_multilang_label}:</strong> ${s.s13_multilang}`,
    `<strong>${s.s13_memory_label}:</strong> ${s.s13_memory}`,
    `<strong>${s.sRagAgg_label}:</strong> ${s.sRagAgg_summary}`
])}
${_h4(s.sRagAgg_head)}
${_p(s.sRagAgg_desc)}
${_ul([
    s.sRagAgg_li1, s.sRagAgg_li2, s.sRagAgg_li3, s.sRagAgg_li4, s.sRagAgg_li5, s.sRagAgg_li6, s.sRagAgg_li7, s.sRagAgg_li8
])}
${_h4(s.sRagRollup_head)}
${_p(s.sRagRollup_desc)}
${_ul([s.sRagRollup_li1, s.sRagRollup_li2, s.sRagRollup_li3])}
${_warn(s.sRagAgg_warn_label, s.sRagAgg_warn)}

${_h3('doc-16', s.sPrint_head)}
${_p(s.sPrint_desc)}
${_ul([
    `<strong>${s.sPrint_source_label}:</strong> ${s.sPrint_source}`,
    `<strong>${s.sPrint_layout_label}:</strong> ${s.sPrint_layout}`,
    `<strong>${s.sPrint_pagination_label}:</strong> ${s.sPrint_pagination}`,
    `<strong>${s.sPrint_access_label}:</strong> ${s.sPrint_access}`,
    `<strong>${s.sPrint_config_label}:</strong> ${s.sPrint_config}`
])}

${_h3('doc-17', s.sUpg_head)}
${_p(s.sUpg_desc)}
${_h4(s.sUpg_flow_head)}
${_ol([s.sUpg_step1, s.sUpg_step2, s.sUpg_step3, s.sUpg_step4, s.sUpg_step5])}
${_h4(s.sUpg_what_head)}
${_ul([s.sUpg_what1, s.sUpg_what2, s.sUpg_what3])}
${_h4(s.sUpg_backup_head)}
${_p(s.sUpg_backup)}
${_h4(s.sUpg_add_head)}
${_p(s.sUpg_add)}
<pre style="background:var(--bg);padding:12px;border-radius:4px;overflow-x:auto;">"3.1": {
  "removed_files": ["admin/old_feature.php"],
  "deprecated_files": [],
  "removed_config_keys": [
    { "file": "schema.json", "path": "$.tables[*].legacy_flag" }
  ],
  "notes": "Removed old feature, replaced by new system."
}</pre>
</div>`;
}