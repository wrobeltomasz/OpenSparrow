// admin/js/docs.js — Admin documentation page renderer; builds HTML from docs-strings.js STRINGS, language switch persisted in localStorage (sparrow_docs_lang). Languages: en/pl/de/fr/it/es.
import { STRINGS } from './docs-strings.js';

// Configuration constants - limited to required languages
const ALL_LANGS = ['en', 'pl', 'de', 'fr', 'it', 'es'];
const STORAGE_KEY = 'sparrow_docs_lang';

// HTML generators
const _h2 = (t) => `<h2 style="border-bottom:2px solid #DDEAF4;padding-bottom:10px;margin-top:0;color:#1E293B;">${t}</h2>`;
const _h3 = (id, t) => `<h3 id="${id}" style="color:#64748B;margin-top:30px;">${t}</h3>`;
const _h4 = (t, c = '#DDEAF4') => `<h4 style="color:#64748B;margin-top:20px;border-left:3px solid ${c};padding-left:15px;">${t}</h4>`;
const _p = (t, style = '') => style ? `<p style="${style}">${t}</p>` : `<p>${t}</p>`;
const _ul = (items) => `<ul style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ul>`;
const _ol = (items) => `<ol style="padding-left:20px;">${items.map(i => `<li>${i}</li>`).join('')}</ol>`;
const _warn = (s, t) => `<p style="background:rgba(255,195,0,0.12);padding:10px 14px;border-left:3px solid #ffc300;border-radius:4px;font-size:14px;"><strong>${s}</strong> ${t}</p>`;

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
        btn.style.cssText = `padding:3px 10px; border-radius:4px; border:1px solid #CBD5E1; cursor:pointer; font-size:12px; font-weight:600; background:${isActive ? '#64748B' : '#DDEAF4'}; color:${isActive ? '#fff' : '#64748B'};`;
        
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
    content.style.cssText = 'max-width:900px; padding:30px; background:white; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); color:#64748B; line-height:1.6; margin-bottom:40px;';
    content.innerHTML = buildContent(s);
    return content;
}


function buildContent(s) {
    return `<div>
${_h2(s.title)}
<p style="font-size:15px;color:#64748B;margin-bottom:30px;">${s.subtitle}</p>

${_h3('doc-0', s.s0_head)}
${_warn(s.s0_warn_strong, s.s0_warn_text)}
${_ol([s.s0_step1, s.s0_step2, s.s0_step3, s.s0_step4, s.s0_step5])}
${_p(s.s0_after)}

${_h3('doc-0b', s.s0b_head)}
${_p(s.s0b_desc)}
${_ul([
    `<strong>${s.s0b_data_label}:</strong> ${s.s0b_data}`,
    `<strong>${s.s0b_workflows_label}:</strong> ${s.s0b_workflows}`,
    `<strong>${s.s0b_system_label}:</strong> ${s.s0b_system}`,
    `<strong>${s.s0b_config_label}:</strong> ${s.s0b_config}`,
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
    `<code>spw_migrations</code> — ${s.s1_t_migrations}`
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

${_h3('doc-3', s.s3_head)}
${_p(s.s3_desc)}
${_h4(s.s3_types_head)}
${_ul([
    `<strong>${s.s3_stat_label}:</strong> ${s.s3_stat}`,
    `<strong>${s.s3_kpi_label}:</strong> ${s.s3_kpi}`,
    `<strong>${s.s3_bar_label}:</strong> ${s.s3_bar}`,
    `<strong>${s.s3_pie_label}:</strong> ${s.s3_pie}`,
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
    `<strong>${s.s9_cron_label}:</strong> ${s.s9_cron}`,
    `<strong>${s.s9_exportimport_label}:</strong> ${s.s9_exportimport}`
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

${_h3('doc-9e', s.s9e_head)}
${_ul([
    `<strong>${s.s9e_sort_label}:</strong> ${s.s9e_sort}`,
    `<strong>${s.s9e_limit_label}:</strong> ${s.s9e_limit}`,
    `<strong>${s.s9e_stored_label}:</strong> <code>config/schema.json</code> ${s.s9e_stored}`
])}

${_h3('doc-9f', s.s9f_head)}
${_p(s.s9f_desc)}

${_h3('doc-9f2', s.s9f2_head)}
${_ul([
    `<strong>Edit</strong> — ${s.s9f2_edit}`,
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
    `<strong>5. ${s.s9h_li5_label}:</strong> ${s.s9h_li5}`
])}

${_h3('doc-9i', s.s9i_head)}
${_ul([
    `<strong>${s.s9i_admin_label}:</strong> ${s.s9i_admin}`,
    `<strong>${s.s9i_user_label}:</strong> ${s.s9i_user}`,
    `<strong>${s.s9i_priority_label}:</strong> <code>localStorage</code> → <code>schema.default_page_size</code> → ${s.s9i_fallback} 25.`
])}

${_h3('doc-9j', s.s9j_head)}
${_p(s.s9j_desc)}
${_h4(s.s9j_how_head, '#64748B')}
${_ol([
    `<strong>${s.s9j_how1_label}:</strong> ${s.s9j_how1}`,
    `<strong>${s.s9j_how2_label}:</strong> ${s.s9j_how2}`,
    `<strong>${s.s9j_how3_label}:</strong> ${s.s9j_how3}`
])}
${_h4(s.s9j_config_head, '#64748B')}
${_ul([`<strong>${s.s9j_config_li}</strong>`])}
${_h4(s.s9j_runtime_head, '#64748B')}
${_ul([s.s9j_runtime1, s.s9j_runtime2])}

${_h3('doc-9k', s.s9k_head)}
${_p(s.s9k_desc)}
${_ul([
    `<strong>${s.s9k_types_label}:</strong> ${s.s9k_types}`,
    `<strong>${s.s9k_controls_label}:</strong> ${s.s9k_controls}`
])}

${_h3('doc-9l', s.s9l_head)}
${_warn(s.s9l_warn_strong, s.s9l_warn_text)}
${_p(s.s9l_desc)}
${_h4(s.s9l_conn_head)}
${_p(s.s9l_conn)}
${_h4(s.s9l_tables_head)}
${_ul([s.s9l_tables, s.s9l_remove])}
${_h4(s.s9l_meta_head)}
${_p(s.s9l_meta)}
${_h4(s.s9l_sync_head)}
${_p(s.s9l_sync)}
${_h4(s.s9l_seps_head)}
${_ul([s.s9l_seps_tabs, s.s9l_seps_sync, s.s9l_seps_inline])}
${_p(s.s9l_crud)}
${_h4(s.s9l_views_head)}
${_p(s.s9l_views_desc)}
${_ul([s.s9l_views_tabs, s.s9l_views_sync])}

${_h3('doc-10', s.s10_head)}
${_p(s.s10_desc)}
${_ul([
    `<strong>${s.s10_config_label}:</strong> ${s.s10_config}`,
    `<strong>${s.s10_relations_label}:</strong> ${s.s10_relations}`
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
${_h4(s.s11b_demo2_head)}
${_p(s.s11b_demo2_text)}
${_h4(s.s11b_demo3_head)}
${_p(s.s11b_demo3_text)}

${_h3('doc-11c', s.s11c_head)}
${_p(s.s11c_desc)}
${_ul([
    `<strong>${s.s11c_step1_label}:</strong> ${s.s11c_step1}`,
    `<strong>${s.s11c_step2_label}:</strong> ${s.s11c_step2}`,
    `<strong>${s.s11c_upsert_label}:</strong> ${s.s11c_upsert}`,
    `<strong>${s.s11c_types_label}:</strong> ${s.s11c_types}`,
    `<strong>${s.s11c_errors_label}:</strong> ${s.s11c_errors}`,
    `<strong>${s.s11c_tables_label}:</strong> ${s.s11c_tables}`,
    `<strong>${s.s11c_history_label}:</strong> ${s.s11c_history}`
])}

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

${_h3('doc-13-rag', s.sRag_head)}
${_p(s.sRag_desc)}
${_ul([
    `<strong>${s.s13_docs_label}:</strong> ${s.s13_docs}`,
    `<strong>${s.s13_config_label}:</strong> ${s.s13_config}`,
    `<strong>${s.s13_test_label}:</strong> ${s.s13_test}`,
    `<strong>${s.s13_stats_label}:</strong> ${s.s13_stats}`,
    `<strong>${s.s13_multilang_label}:</strong> ${s.s13_multilang}`
])}

${_h3('doc-14-aut', s.s14_head)}
${_p(s.s14_desc)}
${_h4(s.s14_trigger_label)}
${_p(s.s14_trigger)}
${_h4(s.s14_cond_label)}
${_p(s.s14_cond)}
${_h4(s.s14_actions_label)}
${_p(s.s14_actions)}
${_h4(s.s14_vars_label)}
${_p(s.s14_vars)}
${_h4(s.s14_history_label)}
${_p(s.s14_history)}
${_p(`<strong>${s.s14_note_label}:</strong> ${s.s14_note}`, 'background:rgba(255,195,0,0.12);padding:10px 14px;border-left:3px solid #ffc300;border-radius:4px;font-size:14px;')}

${_h3('doc-14', 'Upgrading OpenSparrow')}
${_p('After running <code>git pull</code> to a new version, use <strong>System → Migrations</strong> to check for pending release migrations. The admin header displays a yellow upgrade notice when action is required.')}
${_h4('Release workflow')}
${_ol([
    'Pull the new release: <code>git pull</code>',
    'Open Admin → <strong>System → Migrations</strong>.',
    'Run <strong>Apply Pending Migrations</strong> first (database schema changes).',
    'Scroll to the <strong>Release Migrations</strong> section. Select the actions you want to apply and click <strong>Apply selected</strong>.',
    'Verify the upgrade notice in the header has disappeared.',
])}
${_h4('What Release Migrations do')}
${_ul([
    '<strong>Remove file</strong> — moves an obsolete file to <code>storage/migrations_backup/&lt;version&gt;/</code> then deletes it from the working tree.',
    '<strong>Remove config key</strong> — removes a deprecated field from a <code>config/*.json</code> file; original saved to backup first.',
    '<strong>Deprecated (info only)</strong> — no action taken; the file is still present but generates a warning in <code>storage/logs/deprecations.log</code> if accessed.',
])}
${_h4('Backups')}
${_p('Every file and config snapshot is saved to <code>storage/migrations_backup/&lt;version&gt;/</code> before any change. The applied history in the Migrations tab shows the backup path for each action.')}
${_h4('Adding a migration entry (for contributors)')}
${_p('Every pull request that removes a file or a <code>config/*.json</code> key must add an entry to <code>config/migrations.json</code> in the same PR. Example:')}
<pre style="background:#F4F7F9;padding:12px;border-radius:4px;font-size:12px;overflow-x:auto;">"2.5.0": {
  "removed_files": ["admin/old_feature.php"],
  "deprecated_files": [],
  "removed_config_keys": [
    { "file": "schema.json", "path": "$.tables[*].legacy_flag" }
  ],
  "notes": "Removed old feature, replaced by new system."
}</pre>

${_h3('doc-12', s.s12_head)}
${_ul([s.s12_li1, s.s12_li2, s.s12_li3, s.s12_li4])}
${_h4(s.s12_env_head)}
<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;">
    <thead><tr style="background:#F4F7F9;">
        <th style="text-align:left;padding:6px 10px;border:1px solid #CBD5E1;">${s.s12_th_var}</th>
        <th style="text-align:left;padding:6px 10px;border:1px solid #CBD5E1;">${s.s12_th_default}</th>
        <th style="text-align:left;padding:6px 10px;border:1px solid #CBD5E1;">${s.s12_th_desc}</th>
    </tr></thead>
    <tbody>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>APP_ENV</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>production</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_appenv}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>DB_HOST</code> / <code>PGHOST</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>localhost</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_dbhost}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>DB_PORT</code> / <code>PGPORT</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>5432</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_dbport}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>APP_TIMEZONE</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>Europe/Warsaw</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_timezone}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>SECURE_COOKIES</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>true</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_cookies}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>SESSION_MAX_LIFETIME</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>28800</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_session}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>IP_HASH_SALT</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><em>${s.env_none}</em></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><strong>${s.env_iphash_req}</strong> ${s.env_iphash}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>LOGIN_MAX_ATTEMPTS_PER_IP</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>20</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_ip_attempts}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>LOGIN_MAX_ATTEMPTS_PER_USERNAME</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>5</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_user_attempts}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>LOGIN_LOCKOUT_MINUTES</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>15</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_lockout}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>DEMO_MODE</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>false</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_demo}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>FILES_MAX_SIZE_MB</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>20</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_files}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>RECORD_SNAPSHOTS_ENABLED</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>false</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_snapshots}</td></tr>
        <tr><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>PGSCHEMA</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;"><code>app</code></td><td style="padding:5px 10px;border:1px solid #CBD5E1;">${s.env_pgschema}</td></tr>
    </tbody>
</table>
</div>`;
}