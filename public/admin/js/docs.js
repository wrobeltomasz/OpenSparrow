// This file is part of OpenSparrow - https://opensparrow.org
// SPDX-License-Identifier: LGPL-3.0-or-later
// Copyright (C) 2024-2026 OpenSparrow Contributors
// Licensed under LGPL v3. See COPYING.LESSER file for details.

import { STRINGS } from './docs-strings.js';

const ALL_LANGS = ['en', 'pl'];
const STORAGE_KEY = 'sparrow_docs_lang';

const _h2 = (textContent) => `<h2 style="border-bottom:2px solid var(--accent-mid);padding-bottom:10px;margin-top:0;color:var(--text);">${textContent}</h2>`;
const _h3 = (id, textContent) => `<h3 id="${id}" style="color:var(--muted);margin-top:30px;">${textContent}</h3>`;
const _h4 = (textContent, c = 'var(--accent-mid)') => `<h4 style="color:var(--muted);margin-top:20px;border-left:3px solid ${c};padding-left:15px;">${textContent}</h4>`;
const paragraphHtml = (textContent, style = '') => style ? `<p style="${style}">${textContent}</p>` : `<p>${textContent}</p>`;
const _ul = (items) => `<ul style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ul>`;
const _ol = (items) => `<ol style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ol>`;
const _warn = (strings, textContent) => `<p style="background:var(--warn-light);padding:10px 14px;border-left:3px solid var(--warn);border-radius:4px;"><strong>${strings}</strong> ${textContent}</p>`;

export function renderDocumentation(context) {
    if (!context || !context.workspaceEl) return;

    const { workspaceEl: workspaceElement } = context;
    workspaceElement.innerHTML = '';

    let lang = localStorage.getItem(STORAGE_KEY) || 'en';
    if (!ALL_LANGS.includes(lang)) lang = 'en';
    const strings = STRINGS[lang] || STRINGS.en;

    const wrapper = document.createElement('div');
    wrapper.appendChild(createLanguageBar(lang, context));

    const contentArea = createContentArea(strings);
    wrapper.appendChild(contentArea);

    workspaceElement.appendChild(wrapper);
}

function createLanguageBar(currentLang, context) {
    const langBar = document.createElement('div');
    langBar.style.cssText = 'max-width:900px; display:flex; flex-wrap:wrap; justify-content:flex-end; gap:6px; margin-bottom:8px;';

    ALL_LANGS.forEach(language => {
        const button = document.createElement('button');
        const isActive = currentLang === language;

        button.textContent = language.toUpperCase();
        button.dataset.lang = language;
        button.className = 'btn btn-xs ' + (isActive ? 'btn-primary' : 'btn-secondary');

        button.addEventListener('click', () => {
            localStorage.setItem(STORAGE_KEY, language);
            renderDocumentation(context);
        });

        langBar.appendChild(button);
    });

    return langBar;
}

function createContentArea(strings) {
    const content = document.createElement('div');
    content.style.cssText = 'max-width:900px; padding:30px; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); color:var(--muted); line-height:1.6; margin-bottom:40px;';
    content.innerHTML = buildContent(strings);
    return content;
}

function buildContent(strings) {
    return `<div>
${_h2(strings.title)}
<p style="color:var(--muted);margin-bottom:30px;">${strings.subtitle}</p>

${_h3('doc-0', strings.s0_head)}
${_warn(strings.s0_warn_strong, strings.s0_warn_text)}
${_ol([strings.s0_step1, strings.s0_step2, strings.s0_step3, strings.s0_step4, strings.s0_step5])}
${paragraphHtml(strings.s0_after)}

${_h3('doc-0b', strings.s0b_head)}
${paragraphHtml(strings.s0b_desc)}
${_ul([
    `<strong>${strings.s0b_data_label}:</strong> ${strings.s0b_data}`,
    `<strong>${strings.s0b_workflows_label}:</strong> ${strings.s0b_workflows}`,
    `<strong>${strings.s0b_kb_label}:</strong> ${strings.s0b_kb}`,
    `<strong>${strings.s0b_system_label}:</strong> ${strings.s0b_system}`,
    `<strong>${strings.s0b_save_label}:</strong> ${strings.s0b_save}`,
    `<strong>${strings.s0b_guard_label}:</strong> ${strings.s0b_guard}`,
    `<strong>${strings.s0b_debug_label}:</strong> ${strings.s0b_debug}`,
    `<strong>${strings.s0b_docs_label}:</strong> ${strings.s0b_docs}`
])}

${_h3('doc-1', strings.s1_head)}
${paragraphHtml(strings.s1_desc)}
${_ul([
    `<strong>${strings.s1_pk_label}:</strong> ${strings.s1_pk}`,
    `<strong>${strings.s1_fk_label}:</strong> ${strings.s1_fk}`,
    `<strong>${strings.s1_enum_label}:</strong> ${strings.s1_enum}`,
    `<strong>${strings.s1_bool_label}:</strong> ${strings.s1_bool}`,
    `<strong>${strings.s1_schema_label}:</strong> ${strings.s1_schema}`
])}
${_h4(strings.s1_systables_head)}
${paragraphHtml(strings.s1_systables_desc)}
${_ul([
    `<code>spw_users</code> — ${strings.s1_t_users}`,
    `<code>spw_users_log</code> — ${strings.s1_t_users_log}`,
    `<code>spw_users_notifications</code> — ${strings.s1_t_notifications}`,
    `<code>spw_users_notifications_log</code> — ${strings.s1_t_notifications_log}`,
    `<code>spw_files</code> — ${strings.s1_t_files}`,
    `<code>spw_login_attempts</code> — ${strings.s1_t_login_attempts}`,
    `<code>spw_comments</code> — ${strings.s1_t_comments}`,
    `<code>spw_record_snapshots</code> — ${strings.s1_t_snapshots}`,
    `<code>spw_record_owners</code> — ${strings.s1_t_owners}`,
    `<code>spw_migrations</code> — ${strings.s1_t_migrations}`,
    `<code>spw_release_migrations</code> — ${strings.s1_t_release_migrations}`,
    `<code>spw_config</code> — ${strings.s1_t_config}`,
    `<code>spw_config_log</code> — ${strings.s1_t_config_log}`,
    `<code>spw_notes</code> — ${strings.s1_t_notes}`,
    `<code>spw_rag_*</code> — ${strings.s1_t_rag}`,
    `<code>spw_automation_*</code> — ${strings.s1_t_automations}`,
    `<code>spw_imports</code> — ${strings.s1_t_imports}`,
    `<code>spw_etl_*</code> — ${strings.s1_t_etl}`,
    `<code>spw_anonymization_*</code> — ${strings.s1_t_anon}`,
    `<code>spw_clickstats</code> — ${strings.s1_t_clickstats}`
])}
${_warn(strings.s1_note_strong, strings.s1_note_text)}

${_h3('doc-2', strings.s2_head)}
${paragraphHtml(strings.s2_desc)}
${_h4(strings.s2_addtable_head)}
${paragraphHtml(strings.s2_addtable_desc)}
${_ul([
    `<strong>${strings.s2_name_label}:</strong> ${strings.s2_name}`,
    `<strong>${strings.s2_display_label}:</strong> ${strings.s2_display}`,
    `<strong>${strings.s2_presets_label}:</strong> ${strings.s2_presets}`,
    `<strong>${strings.s2_columns_label}</strong> — ${strings.s2_columns}`,
    `<strong>${strings.s2_register_label}</strong> ${strings.s2_register}`
])}
${_ul([
    `<strong>${strings.s2_addcol_label}:</strong> ${strings.s2_addcol}`,
    `<strong>${strings.s2_synctables_label}:</strong> ${strings.s2_synctables}`,
    `<strong>${strings.s2_synccols_label}:</strong> ${strings.s2_synccols}`,
    `<strong>${strings.s2_coldesc_label}:</strong> ${strings.s2_coldesc}`,
    `<strong>${strings.s2_preview_label}:</strong> ${strings.s2_preview}`,
    `<strong>${strings.s2_typemap_label}:</strong> ${strings.s2_typemap}`,
    `<strong>${strings.s2_remove_label}:</strong> ${strings.s2_remove}`,
    `<strong>${strings.s2_fksearch_label}:</strong> ${strings.s2_fksearch}`,
    `<strong>${strings.s2_visibility_label}:</strong> ${strings.s2_visibility}`,
    `<strong>${strings.s2_validation_label}:</strong> ${strings.s2_validation}
        <ul style="padding-left:20px;margin-top:5px;">
            <li><strong>Email:</strong> <code>^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$</code></li>
            <li><strong>${strings.regex_phone}:</strong> <code>^\\+?[0-9]{9,15}$</code></li>
            <li><strong>${strings.regex_postal}:</strong> <code>^[0-9]{2}-[0-9]{3}$</code></li>
            <li><strong>URL (http/https):</strong> <code>^https?:\\/\\/.*$</code></li>
            <li><strong>${strings.regex_username}:</strong> <code>^[a-zA-Z0-9_]{3,16}$</code></li>
            <li><strong>${strings.regex_price}:</strong> <code>^\\d+(\\.\\d{1,2})?$</code></li>
            <li><strong>${strings.regex_date}:</strong> <code>^\\d{4}-\\d{2}-\\d{2}$</code></li>
            <li><strong>${strings.regex_password}:</strong> <code>^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d).{8,}$</code></li>
        </ul>`
])}
${_h4(strings.s2_subtables_head)}
${paragraphHtml(strings.s2_subtables_desc)}
${_ul([strings.s2_sub_open, `<strong>${strings.s2_sub_target_label}:</strong> ${strings.s2_sub_target}`, `<strong>${strings.s2_sub_fkcol_label}:</strong> ${strings.s2_sub_fkcol}`])}
${_h4(strings.s2_img_head)}
${paragraphHtml(strings.s2_img_desc)}
${_ul([
    `<strong>${strings.s2_img_enable_label}:</strong> ${strings.s2_img_enable}`,
    `<strong>${strings.s2_img_max_label}:</strong> ${strings.s2_img_max}`,
    `<strong>${strings.s2_img_grid_label}:</strong> ${strings.s2_img_grid}`,
    `<strong>${strings.s2_img_upload_label}:</strong> ${strings.s2_img_upload}`
])}

${_h3('doc-3', strings.s3_head)}
${paragraphHtml(strings.s3_desc)}
${_h4(strings.s3_types_head)}
${_ul([
    `<strong>${strings.s3_stat_label}:</strong> ${strings.s3_stat}`,
    `<strong>${strings.s3_bar_label}:</strong> ${strings.s3_bar}`,
    `<strong>${strings.s3_pie_label}:</strong> ${strings.s3_pie}`,
    `<strong>${strings.s3_line_label}:</strong> ${strings.s3_line}`,
    `<strong>${strings.s3_list_label}:</strong> ${strings.s3_list}`
])}
${_h4(strings.s3_props_head)}
${_ul([
    `<strong>${strings.s3_width_label}:</strong> <code>1/3</code>, <code>2/3</code>, <code>3/3</code>.`,
    `<strong>${strings.s3_height_label}:</strong> ${strings.s3_height}`
])}
${paragraphHtml(strings.s3_mobile)}
${_h4(strings.s3_filter_head)}
${paragraphHtml(strings.s3_filter_desc)}
${_ul([`${strings.s3_filter_ops}: <code>=</code>, <code>!=</code>, <code>&lt;</code>, <code>&gt;</code>, <code>&lt;=</code>, <code>&gt;=</code>, <code>LIKE</code>, <code>ILIKE</code>, <code>IS NULL</code>, <code>IS NOT NULL</code>.`])}
${_h4(strings.s3_period_head)}
${paragraphHtml(strings.s3_period_desc)}
${paragraphHtml(strings.s3_export_desc)}
${_h4(strings.s3_preview_head)}
${paragraphHtml(strings.s3_preview_desc)}
${_h4(strings.s3_global_head)}
${paragraphHtml(strings.s3_global_desc)}

${_h3('doc-4', strings.s4_head)}
${paragraphHtml(strings.s4_desc)}
${_ul([
    `<strong>${strings.s4_sources_label}:</strong> ${strings.s4_sources}`,
    `<strong>${strings.s4_color_label}:</strong> ${strings.s4_color}`,
    `<strong>${strings.s4_context_label}:</strong> ${strings.s4_context}`
])}

${_h3('doc-4b', strings.s4b_head)}
${paragraphHtml(strings.s4b_desc)}
${_ul([
    `<strong>${strings.s4b_multi_label}:</strong> ${strings.s4b_multi}`,
    `<strong>${strings.s4b_table_label}:</strong> ${strings.s4b_table}`,
    `<strong>${strings.s4b_status_label}:</strong> ${strings.s4b_status}`,
    `<strong>${strings.s4b_cards_label}:</strong> ${strings.s4b_cards}`,
    `<strong>${strings.s4b_dnd_label}:</strong> ${strings.s4b_dnd}`
])}

${_h3('doc-5', strings.s5_head)}
${paragraphHtml(strings.s5_desc)}
${_ul([
    `<strong>${strings.s5_steps_label}:</strong> ${strings.s5_steps}`,
    `<strong>${strings.s5_link_label}:</strong> ${strings.s5_link}`,
    `<strong>${strings.s5_multi_label}:</strong> ${strings.s5_multi}`
])}
${_h4(strings.s5_validation_head)}
${paragraphHtml(strings.s5_validation_desc)}
${_h4(strings.s5_proc_head)}
${paragraphHtml(strings.s5_proc_desc)}

${_h3('doc-6', strings.s6_head)}
${paragraphHtml(strings.s6_desc)}
${_ul([
    `<strong>${strings.s6_roles_label}:</strong>
        <ul style="padding-left:20px;margin-top:5px;">
            <li><strong>Admin</strong> — ${strings.s6_admin}</li>
            <li><strong>Editor</strong> — ${strings.s6_editor}</li>
            <li><strong>Viewer</strong> — ${strings.s6_viewer}</li>
        </ul>`,
    `<strong>${strings.s6_pwd_label}:</strong> ${strings.s6_pwd}`,
    `<strong>${strings.s6_status_label}:</strong> ${strings.s6_status}`
])}

${_h3('doc-7', strings.s7_head)}
${paragraphHtml(strings.s7_desc)}
${_ul([
    `<strong>${strings.s7_schema_label}:</strong> ${strings.s7_schema}`,
    `<strong>${strings.s7_test_label}:</strong> ${strings.s7_test}`,
    `<strong>${strings.s7_login_label}:</strong> ${strings.s7_login}`
])}

${_h3('doc-8', strings.s8_head)}
${paragraphHtml(strings.s8_desc)}
${_ul([
    `<strong>${strings.s8_format_label}:</strong> <code>YYYYMMDDHHII_tablename</code>.`,
    `<strong>${strings.s8_what_label}:</strong> ${strings.s8_what}`
])}

${_h3('doc-9', strings.s9_head)}
${_ul([
    `<strong>${strings.s9_migrations_label}:</strong> ${strings.s9_migrations}`,
    `<strong>${strings.s9_diagnostics_label}:</strong> ${strings.s9_diagnostics}`,
    `<strong>${strings.s9_cron_label}:</strong> ${strings.s9_cron}`
])}

${_h3('doc-9b', strings.s9b_head)}
${paragraphHtml(strings.s9b_desc)}
${_ul([
    `<strong>${strings.s9b_how_label}:</strong> ${strings.s9b_how}`,
    `<strong>${strings.s9b_toggle_label}:</strong> ${strings.s9b_toggle}`,
    `<strong>${strings.s9b_storage_label}:</strong> ${strings.s9b_storage}`
])}

${_h3('doc-9c', strings.s9c_head)}
${paragraphHtml(strings.s9c_desc)}
${_ul([strings.s9c_applied, `<strong>${strings.s9c_adding_label}:</strong> ${strings.s9c_adding}`])}

${_h3('doc-9d', strings.s9d_head)}
${paragraphHtml(strings.s9d_desc)}
${_ul([
    `<strong>${strings.s9d_auto_label}:</strong> ${strings.s9d_auto}`,
    `<strong>${strings.s9d_change_label}:</strong> ${strings.s9d_change}`,
    `<strong>${strings.s9d_history_label}:</strong> ${strings.s9d_history}`
])}

${_h3('doc-9o', strings.s9o_head)}
${paragraphHtml(strings.s9o_desc)}
${_ul([
    `<strong>${strings.s9o_where_label}:</strong> ${strings.s9o_where}`,
    `<strong>${strings.s9o_empty_label}:</strong> ${strings.s9o_empty}`,
    `<strong>${strings.s9o_admin_label}:</strong> ${strings.s9o_admin}`,
    `<strong>${strings.s9o_scope_label}:</strong> ${strings.s9o_scope}`,
    `<strong>${strings.s9o_hidden_label}:</strong> ${strings.s9o_hidden}`,
    `<strong>${strings.s9o_bindings_label}:</strong> ${strings.s9o_bindings}`,
    `<strong>${strings.s9o_views_label}:</strong> ${strings.s9o_views}`,
    `<strong>${strings.s9o_limits_label}:</strong> ${strings.s9o_limits}`
])}

${_h3('doc-9p', strings.s9p_head)}
${paragraphHtml(strings.s9p_desc)}
${_ul([
    `<strong>${strings.s9p_where_label}:</strong> ${strings.s9p_where}`,
    `<strong>${strings.s9p_optional_label}:</strong> ${strings.s9p_optional}`,
    `<strong>${strings.s9p_scope_label}:</strong> ${strings.s9p_scope}`
])}

${_h3('doc-9e', strings.s9e_head)}
${_ul([
    `<strong>${strings.s9e_sort_label}:</strong> ${strings.s9e_sort}`,
    `<strong>${strings.s9e_limit_label}:</strong> ${strings.s9e_limit}`,
    `<strong>${strings.s9e_stored_label}:</strong> <code>spw_config.schema</code> ${strings.s9e_stored}`
])}

${_h3('doc-9f', strings.s9f_head)}
${paragraphHtml(strings.s9f_desc)}

${_h3('doc-9f2', strings.s9f2_head)}
${paragraphHtml(strings.s9f2_desc)}
${_ul([
    `<strong>Edit</strong> — ${strings.s9f2_edit}`,
    `<strong>Duplicate</strong> — ${strings.s9f2_dup}`,
    `<strong>Delete</strong> — ${strings.s9f2_delete}`,
    strings.s9f2_visible
])}

${_h3('doc-9g', strings.s9g_head)}
${paragraphHtml(strings.s9g_desc)}
${_ul([
    `<strong>1. ${strings.s9g_li1_label}:</strong> ${strings.s9g_li1}`,
    `<strong>2. ${strings.s9g_li2_label}:</strong> ${strings.s9g_li2}`,
    `<strong>3. ${strings.s9g_li3_label}:</strong> ${strings.s9g_li3}`,
    `<strong>4. ${strings.s9g_li4_label}:</strong> ${strings.s9g_li4}`,
    `<strong>5. ${strings.s9g_li5_label}:</strong> ${strings.s9g_li5}`,
    `<strong>6. ${strings.s9g_li6_label}:</strong> ${strings.s9g_li6}`
])}

${_h3('doc-9h', strings.s9h_head)}
${paragraphHtml(strings.s9h_desc)}
${_ul([
    `<strong>1. ${strings.s9h_li1_label}:</strong> ${strings.s9h_li1}`,
    `<strong>2. ${strings.s9h_li2_label}:</strong> ${strings.s9h_li2}`,
    `<strong>3. ${strings.s9h_li3_label}:</strong> ${strings.s9h_li3}`,
    `<strong>4. ${strings.s9h_li4_label}:</strong> ${strings.s9h_li4}`,
    `<strong>5. ${strings.s9h_li5_label}:</strong> ${strings.s9h_li5}`,
    `<strong>6. ${strings.s9h_li6_label}:</strong> ${strings.s9h_li6}`
])}

${_h3('doc-9i', strings.s9i_head)}
${_ul([
    `<strong>${strings.s9i_admin_label}:</strong> ${strings.s9i_admin}`,
    `<strong>${strings.s9i_user_label}:</strong> ${strings.s9i_user}`,
    `<strong>${strings.s9i_priority_label}:</strong> <code>localStorage</code> → <code>schema.default_page_size</code> → ${strings.s9i_fallback} 25.`
])}

${_h3('doc-9j', strings.s9j_head)}
${paragraphHtml(strings.s9j_desc)}
${_h4(strings.s9j_how_head, 'var(--muted)')}
${_ol([
    `<strong>${strings.s9j_how1_label}:</strong> ${strings.s9j_how1}`,
    `<strong>${strings.s9j_how2_label}:</strong> ${strings.s9j_how2}`,
    `<strong>${strings.s9j_how3_label}:</strong> ${strings.s9j_how3}`
])}
${_h4(strings.s9j_config_head, 'var(--muted)')}
${_ul([`<strong>${strings.s9j_config_li}</strong>`])}
${_h4(strings.s9j_runtime_head, 'var(--muted)')}
${_ul([strings.s9j_runtime1, strings.s9j_runtime2])}

${_h3('doc-9k', strings.s9k_head)}
${paragraphHtml(strings.s9k_desc)}
${_ul([
    `<strong>${strings.s9k_types_label}:</strong> ${strings.s9k_types}`,
    `<strong>${strings.s9k_controls_label}:</strong> ${strings.s9k_controls}`
])}

${_h3('doc-9l', strings.s9l_head)}
${paragraphHtml(strings.s9l_desc)}
${_ul([
    `<strong>${strings.s9l_edit_label}:</strong> ${strings.s9l_edit}`,
    `<strong>${strings.s9l_dup_label}:</strong> ${strings.s9l_dup}`,
    `<strong>${strings.s9l_clean_label}:</strong> ${strings.s9l_clean}`,
    `<strong>${strings.s9l_role_label}:</strong> ${strings.s9l_role}`
])}

${_h3('doc-9m', strings.s9m_head)}
${paragraphHtml(strings.s9m_desc)}
${_ul([
    `<strong>${strings.s9m_tab1_label}:</strong> ${strings.s9m_tab1}`,
    `<strong>${strings.s9m_tab2_label}:</strong> ${strings.s9m_tab2}`,
    `<strong>${strings.s9m_tab3_label}:</strong> ${strings.s9m_tab3}`,
    `<strong>${strings.s9m_tab4_label}:</strong> ${strings.s9m_tab4}`,
    `<strong>${strings.s9m_tab5_label}:</strong> ${strings.s9m_tab5}`
])}

${_h3('doc-9n', strings.sLogo_head)}
${paragraphHtml(strings.sLogo_desc)}
${_ul([
    `<strong>${strings.sLogo_li1_label}:</strong> ${strings.sLogo_li1}`,
    `<strong>${strings.sLogo_li2_label}:</strong> ${strings.sLogo_li2}`,
    `<strong>${strings.sLogo_li3_label}:</strong> ${strings.sLogo_li3}`,
    `<strong>${strings.sLogo_li4_label}:</strong> ${strings.sLogo_li4}`
])}

${_h3('doc-9q', strings.s9q_head)}
${paragraphHtml(strings.s9q_desc)}
${_ul([
    `<strong>${strings.s9q_tab1_label}:</strong> ${strings.s9q_tab1}`,
    `<strong>${strings.s9q_retention_label}:</strong> ${strings.s9q_retention}`,
    `<strong>${strings.s9q_tab2_label}:</strong> ${strings.s9q_tab2}`,
    `<strong>${strings.s9q_off_label}:</strong> ${strings.s9q_off}`,
    `<strong>${strings.s9q_privacy_label}:</strong> ${strings.s9q_privacy}`,
    `<strong>${strings.s9q_labels_label}:</strong> ${strings.s9q_labels}`
])}

${_h3('doc-10', strings.s10_head)}
${paragraphHtml(strings.s10_desc)}
${_ul([
    `<strong>${strings.s10_browse_label}:</strong> ${strings.s10_browse}`,
    `<strong>${strings.s10_meta_label}:</strong> ${strings.s10_meta}`,
    `<strong>${strings.s10_bulk_label}:</strong> ${strings.s10_bulk}`,
    `<strong>${strings.s10_config_label}:</strong> ${strings.s10_config}`,
    `<strong>${strings.s10_relations_label}:</strong> ${strings.s10_relations}`
])}

${_h3('doc-10b', strings.s10b_head)}
${paragraphHtml(strings.s10b_desc)}
${_ul([
    `<strong>${strings.s10b_matview_label}:</strong> ${strings.s10b_matview}`,
    `<strong>${strings.s10b_sync_label}:</strong> ${strings.s10b_sync}`,
    `<strong>${strings.s10b_schemas_label}:</strong> ${strings.s10b_schemas}`,
    `<strong>${strings.s10b_display_label}:</strong> ${strings.s10b_display}`,
    `<strong>${strings.s10b_readonly_label}:</strong> ${strings.s10b_readonly}`
])}

${_h3('doc-10c', strings.s10c_head)}
${paragraphHtml(strings.s10c_desc)}
${_ul([
    `<strong>${strings.s10c_map_label}:</strong> ${strings.s10c_map}`,
    `<strong>${strings.s10c_global_label}:</strong> ${strings.s10c_global}`
])}

${_h3('doc-10d', strings.s10d_head)}
${paragraphHtml(strings.s10d_desc)}
${_ul([
    `<strong>${strings.s10d_where_label}:</strong> ${strings.s10d_where}`,
    `<strong>${strings.s10d_perm_label}:</strong> ${strings.s10d_perm}`
])}

${_h3('doc-10e', strings.s10e_head)}
${paragraphHtml(strings.s10e_desc)}
${_ul([
    `<strong>${strings.s10e_private_label}:</strong> ${strings.s10e_private}`,
    `<strong>${strings.s10e_link_label}:</strong> ${strings.s10e_link}`,
    `<strong>${strings.s10e_reminder_label}:</strong> ${strings.s10e_reminder}`
])}

${_h3('doc-11', strings.s11_head)}
${paragraphHtml(strings.s11_desc)}
${_h4(strings.s11_dnd_head)}
${_ul([
    `<strong>${strings.s11_reorder_label}:</strong> ${strings.s11_reorder}`,
    `<strong>${strings.s11_nest_label}:</strong> ${strings.s11_nest}`,
    `<strong>${strings.s11_unnest_label}:</strong> ${strings.s11_unnest}`,
    `<strong>${strings.s11_autosave_label}:</strong> ${strings.s11_autosave}`
])}

${_h3('doc-11b', strings.s11b_head)}
${paragraphHtml(strings.s11b_desc)}
${_ul([
    `<strong>${strings.s11b_what_label}:</strong> ${strings.s11b_what}`,
    `<strong>${strings.s11b_safety_label}:</strong> ${strings.s11b_safety}`,
    `<strong>${strings.s11b_cleanup_label}:</strong> ${strings.s11b_cleanup}`
])}
${_h4(strings.s11b_demo1_head)}
${paragraphHtml(strings.s11b_demo1_text)}

${_h3('doc-11c', strings.s11c_head)}
${paragraphHtml(strings.s11c_desc)}
${_ul([
    `<strong>${strings.s11c_step1_label}:</strong> ${strings.s11c_step1}`,
    `<strong>${strings.s11c_parse_label}:</strong> ${strings.s11c_parse}`,
    `<strong>${strings.s11c_create_label}:</strong> ${strings.s11c_create}`,
    `<strong>${strings.s11c_step2_label}:</strong> ${strings.s11c_step2}`,
    `<strong>${strings.s11c_mode_label}:</strong> ${strings.s11c_mode}`,
    `<strong>${strings.s11c_upsert_label}:</strong> ${strings.s11c_upsert}`,
    `<strong>${strings.s11c_types_label}:</strong> ${strings.s11c_types}`,
    `<strong>${strings.s11c_errors_label}:</strong> ${strings.s11c_errors}`,
    `<strong>${strings.s11c_tables_label}:</strong> ${strings.s11c_tables}`,
    `<strong>${strings.s11c_history_label}:</strong> ${strings.s11c_history}`
])}

${_h3('doc-11d', strings.s11d_head)}
${paragraphHtml(strings.s11d_desc)}
${_ul([
    `<strong>${strings.s11d_conn_label}:</strong> ${strings.s11d_conn}`,
    `<strong>${strings.s11d_jobs_label}:</strong> ${strings.s11d_jobs}`,
    `<strong>${strings.s11d_modes_label}:</strong> ${strings.s11d_modes}`,
    `<strong>${strings.s11d_incremental_label}:</strong> ${strings.s11d_incremental}`,
    `<strong>${strings.s11d_preview_label}:</strong> ${strings.s11d_preview}`,
    `<strong>${strings.s11d_schedule_label}:</strong> ${strings.s11d_schedule}`,
    `<strong>${strings.s11d_log_label}:</strong> ${strings.s11d_log}`
])}

${_h3('doc-11e', strings.s11e_head)}
${paragraphHtml(strings.s11e_desc)}
${_ul([
    `<strong>${strings.s11e_run_label}:</strong> ${strings.s11e_run}`,
    `<strong>${strings.s11e_schedule_label}:</strong> ${strings.s11e_schedule}`,
    `<strong>${strings.s11e_log_label}:</strong> ${strings.s11e_log}`
])}

${_h3('doc-12', strings.s12_head)}
${_ul([strings.s12_li1, strings.s12_li2, strings.s12_li3, strings.s12_li4])}
${_h4(strings.s12_env_head)}
<table class="adm-tbl" style="margin-top:10px;">
    <thead><tr>
        <th class="adm-th">${strings.s12_th_var}</th>
        <th class="adm-th">${strings.s12_th_default}</th>
        <th class="adm-th">${strings.s12_th_desc}</th>
    </tr></thead>
    <tbody>
        <tr><td class="adm-td"><code>APP_ENV</code></td><td class="adm-td"><code>production</code></td><td class="adm-td">${strings.env_appenv}</td></tr>
        <tr><td class="adm-td"><code>DB_HOST</code> / <code>PGHOST</code></td><td class="adm-td"><code>localhost</code></td><td class="adm-td">${strings.env_dbhost}</td></tr>
        <tr><td class="adm-td"><code>DB_PORT</code> / <code>PGPORT</code></td><td class="adm-td"><code>5432</code></td><td class="adm-td">${strings.env_dbport}</td></tr>
        <tr><td class="adm-td"><code>APP_TIMEZONE</code></td><td class="adm-td"><code>Europe/Warsaw</code></td><td class="adm-td">${strings.env_timezone}</td></tr>
        <tr><td class="adm-td"><code>SECURE_COOKIES</code></td><td class="adm-td"><code>true</code></td><td class="adm-td">${strings.env_cookies}</td></tr>
        <tr><td class="adm-td"><code>SESSION_MAX_LIFETIME</code></td><td class="adm-td"><code>28800</code></td><td class="adm-td">${strings.env_session}</td></tr>
        <tr><td class="adm-td"><code>IP_HASH_SALT</code></td><td class="adm-td"><em>${strings.env_none}</em></td><td class="adm-td"><strong>${strings.env_iphash_req}</strong> ${strings.env_iphash}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_MAX_ATTEMPTS_PER_IP</code></td><td class="adm-td"><code>20</code></td><td class="adm-td">${strings.env_ip_attempts}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_MAX_ATTEMPTS_PER_USERNAME</code></td><td class="adm-td"><code>5</code></td><td class="adm-td">${strings.env_user_attempts}</td></tr>
        <tr><td class="adm-td"><code>LOGIN_LOCKOUT_MINUTES</code></td><td class="adm-td"><code>15</code></td><td class="adm-td">${strings.env_lockout}</td></tr>
        <tr><td class="adm-td"><code>DEMO_MODE</code></td><td class="adm-td"><code>false</code></td><td class="adm-td">${strings.env_demo}</td></tr>
        <tr><td class="adm-td"><code>FILES_MAX_SIZE_MB</code></td><td class="adm-td"><code>20</code></td><td class="adm-td">${strings.env_files}</td></tr>
        <tr><td class="adm-td"><code>RECORD_SNAPSHOTS_ENABLED</code></td><td class="adm-td"><code>false</code></td><td class="adm-td">${strings.env_snapshots}</td></tr>
        <tr><td class="adm-td"><code>PGSCHEMA</code></td><td class="adm-td"><code>app</code></td><td class="adm-td">${strings.env_pgschema}</td></tr>
        <tr><td class="adm-td"><code>APP_ENCRYPTION_KEY</code></td><td class="adm-td"><em>${strings.env_autogen}</em></td><td class="adm-td">${strings.env_enckey}</td></tr>
        <tr><td class="adm-td"><code>TRUST_PROXY_HEADERS</code></td><td class="adm-td"><code>true</code></td><td class="adm-td">${strings.env_proxy}</td></tr>
        <tr><td class="adm-td"><code>SESSION_SAMESITE</code></td><td class="adm-td"><code>Lax</code></td><td class="adm-td">${strings.env_samesite}</td></tr>
        <tr><td class="adm-td"><code>SESSION_SAVE_PATH</code></td><td class="adm-td"><em>${strings.env_none}</em></td><td class="adm-td">${strings.env_savepath}</td></tr>
        <tr><td class="adm-td"><code>HSTS_MAX_AGE</code></td><td class="adm-td"><code>31536000</code></td><td class="adm-td">${strings.env_hsts}</td></tr>
        <tr><td class="adm-td"><code>AUTOMATION_EMAIL_FROM</code></td><td class="adm-td"><em>${strings.env_none}</em></td><td class="adm-td">${strings.env_mailfrom}</td></tr>
    </tbody>
</table>
${paragraphHtml(strings.s12_env_note)}

${_h3('doc-13', strings.s13_head)}
${paragraphHtml(strings.s13_desc)}
${_h4(strings.s13_config_head)}
${_ul([strings.s13_config1, strings.s13_config2])}
${_h4(strings.s13_trans_head)}
${paragraphHtml(strings.s13_trans_desc)}
${_ul([strings.s13_trans1, strings.s13_trans2, strings.s13_trans3, `<strong>${strings.s13_trans4_label}:</strong> ${strings.s13_trans4}`])}
${_h4(strings.s13_php_head)}
${_ul([strings.s13_php1, strings.s13_php2, strings.s13_php3, strings.s13_php4])}
${_h4(strings.s13_js_head)}
${_ul([strings.s13_js1, strings.s13_js2, strings.s13_js3, strings.s13_js4, strings.s13_js5, strings.s13_js6])}
${_h4(strings.s13_add_head)}
${_ol([strings.s13_add1, strings.s13_add2, strings.s13_add3, strings.s13_add4])}
${paragraphHtml(`<strong>${strings.s13_docslang_label}:</strong> ${strings.s13_docslang}`)}

${_h3('doc-14', strings.s14_head)}
${paragraphHtml(strings.s14_desc)}
${_h4(strings.s14_trigger_label)}
${paragraphHtml(strings.s14_trigger)}
${_h4(strings.s14_cond_label)}
${paragraphHtml(strings.s14_cond)}
${_h4(strings.s14_actions_label)}
${paragraphHtml(strings.s14_actions)}
${_h4(strings.s14_vars_label)}
${paragraphHtml(strings.s14_vars)}
${_h4(strings.s14_n8n_label)}
${paragraphHtml(strings.s14_n8n_intro)}
${_ol([strings.s14_n8n1, strings.s14_n8n2, strings.s14_n8n3, strings.s14_n8n4, strings.s14_n8n5])}
${paragraphHtml(strings.s14_n8n_payload)}
${_warn(strings.s14_n8n_warn_label, strings.s14_n8n_warn)}
${_h4(strings.s14_history_label)}
${paragraphHtml(strings.s14_history)}
${paragraphHtml(`<strong>${strings.s14_note_label}:</strong> ${strings.s14_note}`, 'background:var(--warn-light);padding:10px 14px;border-left:3px solid var(--warn);border-radius:4px;')}

${_h3('doc-15', strings.sRag_head)}
${paragraphHtml(strings.sRag_desc)}
${_ul([
    `<strong>${strings.s13_docs_label}:</strong> ${strings.s13_docs}`,
    `<strong>${strings.s13_config_label}:</strong> ${strings.s13_config}`,
    `<strong>${strings.s13_test_label}:</strong> ${strings.s13_test}`,
    `<strong>${strings.s13_stats_label}:</strong> ${strings.s13_stats}`,
    `<strong>${strings.s13_multilang_label}:</strong> ${strings.s13_multilang}`,
    `<strong>${strings.s13_memory_label}:</strong> ${strings.s13_memory}`,
    `<strong>${strings.sRagAgg_label}:</strong> ${strings.sRagAgg_summary}`
])}
${_h4(strings.sRagAgg_head)}
${paragraphHtml(strings.sRagAgg_desc)}
${_ul([
    strings.sRagAgg_li1, strings.sRagAgg_li2, strings.sRagAgg_li3, strings.sRagAgg_li4, strings.sRagAgg_li5, strings.sRagAgg_li6, strings.sRagAgg_li7, strings.sRagAgg_li8
])}
${_h4(strings.sRagRollup_head)}
${paragraphHtml(strings.sRagRollup_desc)}
${_ul([strings.sRagRollup_li1, strings.sRagRollup_li2, strings.sRagRollup_li3])}
${_warn(strings.sRagAgg_warn_label, strings.sRagAgg_warn)}

${_h3('doc-16', strings.sPrint_head)}
${paragraphHtml(strings.sPrint_desc)}
${_ul([
    `<strong>${strings.sPrint_source_label}:</strong> ${strings.sPrint_source}`,
    `<strong>${strings.sPrint_layout_label}:</strong> ${strings.sPrint_layout}`,
    `<strong>${strings.sPrint_pagination_label}:</strong> ${strings.sPrint_pagination}`,
    `<strong>${strings.sPrint_access_label}:</strong> ${strings.sPrint_access}`,
    `<strong>${strings.sPrint_config_label}:</strong> ${strings.sPrint_config}`
])}

${_h3('doc-17', strings.sUpg_head)}
${paragraphHtml(strings.sUpg_desc)}
${_h4(strings.sUpg_flow_head)}
${_ol([strings.sUpg_step1, strings.sUpg_step2, strings.sUpg_step3, strings.sUpg_step4, strings.sUpg_step5])}
${_h4(strings.sUpg_what_head)}
${_ul([strings.sUpg_what1, strings.sUpg_what2, strings.sUpg_what3])}
${_h4(strings.sUpg_backup_head)}
${paragraphHtml(strings.sUpg_backup)}
${_h4(strings.sUpg_add_head)}
${paragraphHtml(strings.sUpg_add)}
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
