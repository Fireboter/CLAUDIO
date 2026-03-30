/**
 * SEO Dashboard - Rechtecheck
 * Interactive Management Dashboard
 */

// ── Model Switcher ────────────────────────────────────────────────────────────

let activeModel = null;

async function loadModel() {
    try {
        const res  = await fetch('api/model.php');
        const data = await res.json();
        activeModel = data.active_model;
        renderModelSwitcher(data.active_model, data.available_models);
    } catch (e) {
        console.error('Failed to load model', e);
    }
}

function renderModelSwitcher(activeModelId, availableModels) {
    const label = document.getElementById('model-label');
    const menu  = document.getElementById('model-dropdown-menu');
    if (!label || !menu) return;

    label.textContent = availableModels[activeModelId] || activeModelId;
    menu.innerHTML = '';

    for (const [id, name] of Object.entries(availableModels)) {
        const li = document.createElement('li');
        li.innerHTML = `<i class="fas fa-check check-icon"></i> ${name}`;
        if (id === activeModelId) li.classList.add('active');
        li.onclick = () => switchModel(id, name, availableModels);
        menu.appendChild(li);
    }
}

function toggleModelDropdown() {
    document.getElementById('model-dropdown-menu').classList.toggle('open');
}

async function switchModel(modelId, modelName, availableModels) {
    document.getElementById('model-dropdown-menu').classList.remove('open');
    if (modelId === activeModel) return;

    try {
        const res  = await fetch('api/model.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ model: modelId }),
        });
        const data = await res.json();
        if (data.status === 'success') {
            activeModel = modelId;
            renderModelSwitcher(modelId, availableModels);
            showToast(`Model switched to ${modelName}`, 'success');
        } else {
            showToast('Failed to switch model: ' + data.message, 'error');
        }
    } catch (e) {
        showToast('Network error switching model', 'error');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.model-switcher')) {
        document.getElementById('model-dropdown-menu')?.classList.remove('open');
    }
});

const API_BASE = 'api/';

// Track loaded state for lazy-loading children
const loadedRF  = new Set();  // rechtsgebiet IDs whose RF rows are loaded
const loadedVar = new Set();  // rechtsfrage IDs whose variation rows are loaded
const loadedVT  = new Set();  // rechtsgebiet IDs whose variation-type detail rows are loaded

// ============================================
// Data Loading
// ============================================

/**
 * Load all Rechtsgebiete and populate the table.
 */
async function loadRechtsgebiete(sortBy = 'alphabet') {
    const spinner = document.getElementById('loading-spinner');
    const tableWrapper = document.getElementById('table-wrapper');
    const emptyState = document.getElementById('empty-state');
    const tableBody = document.getElementById('table-body');

    spinner.classList.remove('hidden');
    tableWrapper.style.display = 'none';
    emptyState.style.display = 'none';

    try {
        const response = await fetch(`${API_BASE}rechtsgebiete.php?sort_by=${encodeURIComponent(sortBy)}`);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        const rechtsgebiete = data.rechtsgebiete || data || [];

        // Update total count in header
        const totalCount = data.total_pages || rechtsgebiete.length;
        document.getElementById('total-pages-count').textContent = totalCount;

        // Clear previous rows and loaded state
        tableBody.innerHTML = '';
        loadedRF.clear();
        loadedVar.clear();

        if (rechtsgebiete.length === 0) {
            emptyState.style.display = 'block';
            spinner.classList.add('hidden');
            return;
        }

        rechtsgebiete.forEach(rg => {
            const row = createRGRow(rg);
            tableBody.appendChild(row);
        });

        tableWrapper.style.display = 'block';
    } catch (error) {
        console.error('Failed to load Rechtsgebiete:', error);
        showToast('Fehler beim Laden der Rechtsgebiete: ' + error.message, 'error');
        emptyState.style.display = 'block';
    } finally {
        spinner.classList.add('hidden');
    }
}

// ============================================
// Row Creation
// ============================================

/**
 * Create a Rechtsgebiet table row.
 */
function createRGRow(rg) {
    const tr = document.createElement('tr');
    tr.className = 'rg-row';
    tr.dataset.rgId   = rg.id;
    tr.dataset.rgName = rg.name;
    tr.onclick = function(e) {
        // Don't toggle when clicking action buttons
        if (e.target.closest('.row-actions')) return;
        toggleRG(rg.id);
    };

    const statusClass = getStatusClass(rg.status);
    const statusIcon = getStatusIcon(rg.status);
    const scoreClass = getScoreClass(rg.performance_score);
    const pageExists = rg.page_exists || rg.generation_status === 'published' || rg.generation_status === 'generated';

    tr.innerHTML = `
        <td><span class="expand-icon" id="expand-rg-${rg.id}"><i class="fas fa-chevron-right"></i></span></td>
        <td><span class="standard-badge" title="Standard-Eintrag">S</span><span class="row-name">${escapeHtml(rg.name)}</span></td>
        <td><span class="status-badge ${statusClass}"><i class="fas ${statusIcon}"></i> ${escapeHtml(rg.status || 'draft')}</span></td>
        <td><span class="score-value ${scoreClass}">${rg.performance_score || 0}</span></td>
        <td><span class="metric-value">${formatNumber(rg.total_clicks)}</span></td>
        <td><span class="metric-value">${formatNumber(rg.total_impressions)}</span></td>
        <td><span class="metric-value">${rg.avg_position ? rg.avg_position.toFixed(1) : '-'}</span></td>
        <td>
            <div class="row-actions">
                <button class="btn-row btn-tags" onclick="event.stopPropagation(); toggleRGVariations(${rg.id})" title="Variation Sets"><i class="fas fa-tags"></i> Variationen</button>
                <button class="btn-row btn-preview" onclick="event.stopPropagation(); previewPage('rechtsgebiet', '${escapeAttr(rg.slug)}')" title="Vorschau"><i class="fas fa-eye"></i> Preview</button>
            </div>
        </td>
    `;

    return tr;
}

/**
 * Create a Rechtsfrage table row.
 */
function createRFRow(rf, rgId) {
    const tr = document.createElement('tr');
    tr.className = 'rf-row visible';
    tr.dataset.rfId = rf.id;
    tr.dataset.parentRg = rgId;
    tr.onclick = function(e) {
        if (e.target.closest('.row-actions')) return;
        toggleRF(rf.id);
    };

    const statusClass = getStatusClass(rf.status);
    const statusIcon = getStatusIcon(rf.status);
    const scoreClass = getScoreClass(rf.performance_score);
    const pageExists = rf.page_exists || rf.generation_status === 'published' || rf.generation_status === 'generated';

    tr.innerHTML = `
        <td><span class="expand-icon" id="expand-rf-${rf.id}"><i class="fas fa-chevron-right"></i></span></td>
        <td><span class="standard-badge" title="Standard-Eintrag">S</span><span class="row-name">${escapeHtml(rf.name)}</span></td>
        <td><span class="status-badge ${statusClass}"><i class="fas ${statusIcon}"></i> ${escapeHtml(rf.status || 'draft')}</span></td>
        <td><span class="score-value ${scoreClass}">${rf.performance_score || 0}</span></td>
        <td><span class="metric-value">${formatNumber(rf.total_clicks)}</span></td>
        <td><span class="metric-value">${formatNumber(rf.total_impressions)}</span></td>
        <td><span class="metric-value">${rf.avg_position ? rf.avg_position.toFixed(1) : '-'}</span></td>
        <td>
            <div class="row-actions">
                ${!pageExists ? `<button class="btn-row btn-generate" onclick="event.stopPropagation(); generateContent('rechtsfrage', ${rf.id})" title="Seite generieren"><i class="fas fa-wand-magic-sparkles"></i> Generate</button>` : ''}
                <button class="btn-row btn-preview" onclick="event.stopPropagation(); previewPage('rechtsfrage', '${escapeAttr(rf.slug)}')" title="Vorschau"><i class="fas fa-eye"></i> Preview</button>
            </div>
        </td>
    `;

    return tr;
}

/**
 * Create a Variation table row showing type + value + intro status.
 * Variations now render on-the-fly via smart substitution, so no generate button.
 */
function createVarRow(variation, rfId) {
    const tr = document.createElement('tr');
    tr.className = 'var-row visible';
    tr.dataset.varId    = variation.id;
    tr.dataset.parentRf = rfId;
    tr.dataset.rfSlug   = variation.rf_slug || '';
    tr.dataset.varSlug  = variation.slug || '';

    const typeLabel   = escapeHtml(variation.variation_type_name || 'Städte');
    const varValue    = escapeHtml(variation.value || '');
    const rfSlug      = variation.rf_slug || '';
    const varSlug     = variation.slug || '';
    const pageStatus  = variation.page_status || 'pending';
    const statusClass = getStatusClass(pageStatus);
    const statusIcon  = getStatusIcon(pageStatus);
    const isGenerated = pageStatus === 'generated';

    tr.innerHTML = `
        <td></td>
        <td>
            <span class="row-name">
                <span style="color:var(--text-muted);font-size:0.78em;margin-right:0.3rem">[${typeLabel}]</span>${varValue}
            </span>
        </td>
        <td><span class="status-badge ${statusClass}"><i class="fas ${statusIcon}"></i> ${escapeHtml(pageStatus)}</span></td>
        <td><span class="score-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td><span class="metric-value">-</span></td>
        <td>
            <div class="row-actions">
                ${!isGenerated ? `<button class="btn-row btn-generate" onclick="event.stopPropagation(); generateVariation(${rfId}, ${variation.id})" title="Seite generieren"><i class="fas fa-wand-magic-sparkles"></i> Generate</button>` : ''}
                ${isGenerated && rfSlug && varSlug ? `<button class="btn-row btn-preview" onclick="event.stopPropagation(); previewPage('variation', '${escapeAttr(rfSlug)}-${escapeAttr(varSlug)}')" title="Vorschau"><i class="fas fa-eye"></i> Preview</button>` : ''}
            </div>
        </td>
    `;

    return tr;
}

// ============================================
// Toggle Expand/Collapse
// ============================================

/**
 * Toggle expand/collapse for a Rechtsgebiet row.
 * Lazy-loads Rechtsfragen on first expansion.
 */
async function toggleRG(rgId) {
    const expandIcon = document.getElementById(`expand-rg-${rgId}`);
    const isExpanded = expandIcon.classList.contains('expanded');

    if (isExpanded) {
        // Collapse: hide all child RF and Var rows
        expandIcon.classList.remove('expanded');
        const rfRows = document.querySelectorAll(`.rf-row[data-parent-rg="${rgId}"]`);
        rfRows.forEach(row => {
            row.classList.remove('visible');
            // Also collapse any expanded RF children
            const rfId = row.dataset.rfId;
            const rfExpand = document.getElementById(`expand-rf-${rfId}`);
            if (rfExpand) rfExpand.classList.remove('expanded');
            const varRows = document.querySelectorAll(`.var-row[data-parent-rf="${rfId}"]`);
            varRows.forEach(v => v.classList.remove('visible'));
        });
        return;
    }

    // Expand
    expandIcon.classList.add('expanded');

    if (loadedRF.has(rgId)) {
        // Already loaded, just show them
        const rfRows = document.querySelectorAll(`.rf-row[data-parent-rg="${rgId}"]`);
        rfRows.forEach(row => row.classList.add('visible'));
        return;
    }

    // Fetch Rechtsfragen for this Rechtsgebiet
    try {
        const response = await fetch(`${API_BASE}rechtsfragen.php?rechtsgebiet_id=${rgId}`);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        const rechtsfragen = data.rechtsfragen || data || [];

        const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
        let insertAfter = rgRow;

        rechtsfragen.forEach(rf => {
            const rfRow = createRFRow(rf, rgId);
            insertAfter.after(rfRow);
            insertAfter = rfRow;
        });

        loadedRF.add(rgId);

        if (rechtsfragen.length === 0) {
            showToast('Keine Rechtsfragen gefunden.', 'info');
        }
    } catch (error) {
        console.error('Failed to load Rechtsfragen:', error);
        showToast('Fehler beim Laden der Rechtsfragen: ' + error.message, 'error');
        expandIcon.classList.remove('expanded');
    }
}

/**
 * Toggle expand/collapse for a Rechtsfrage row.
 * Lazy-loads variations on first expansion.
 */
async function toggleRF(rfId) {
    const expandIcon = document.getElementById(`expand-rf-${rfId}`);
    const isExpanded = expandIcon.classList.contains('expanded');

    if (isExpanded) {
        expandIcon.classList.remove('expanded');
        const varRows = document.querySelectorAll(`.var-row[data-parent-rf="${rfId}"]`);
        varRows.forEach(row => row.classList.remove('visible'));
        return;
    }

    expandIcon.classList.add('expanded');

    if (loadedVar.has(rfId)) {
        const varRows = document.querySelectorAll(`.var-row[data-parent-rf="${rfId}"]`);
        varRows.forEach(row => row.classList.add('visible'));
        return;
    }

    // Fetch variations for this Rechtsfrage
    try {
        const response = await fetch(`${API_BASE}variations.php?rechtsfrage_id=${rfId}`);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        const variations = data.variations || data || [];

        const rfRow = document.querySelector(`.rf-row[data-rf-id="${rfId}"]`);
        let insertAfter = rfRow;

        variations.forEach(variation => {
            const varRow = createVarRow(variation, rfId);
            insertAfter.after(varRow);
            insertAfter = varRow;
        });

        loadedVar.add(rfId);

        if (variations.length === 0) {
            showToast('Keine Variationen gefunden.', 'info');
        }
    } catch (error) {
        console.error('Failed to load variations:', error);
        showToast('Fehler beim Laden der Variationen: ' + error.message, 'error');
        expandIcon.classList.remove('expanded');
    }
}

/**
 * Toggle the variation-types detail row on a Rechtsgebiet row.
 * Shows a collapsible panel with type pills (name + value count).
 */
// ── localStorage helpers ──────────────────────────────────────────────────────

function getVState(rgId) {
    try { return JSON.parse(localStorage.getItem(`vstate_${rgId}`)) || null; }
    catch { return null; }
}
function setVState(rgId, state) {
    localStorage.setItem(`vstate_${rgId}`, JSON.stringify(state));
}
function clearVState(rgId) {
    localStorage.removeItem(`vstate_${rgId}`);
}

// ── Panel entry point ─────────────────────────────────────────────────────────

async function toggleRGVariations(rgId) {
    const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
    const rgName = rgRow?.dataset.rgName || '';
    const detailId = `vt-detail-${rgId}`;
    const existing = document.getElementById(detailId);

    if (existing) {
        const isHidden = existing.classList.contains('vt-detail-hidden');
        existing.classList.toggle('vt-detail-hidden', !isHidden);
        const btn = rgRow?.querySelector('.btn-tags i');
        if (btn) btn.className = isHidden ? 'fas fa-times' : 'fas fa-tags';
        return;
    }

    // Create panel shell
    const tr = document.createElement('tr');
    tr.id = detailId;
    tr.className = 'vt-detail-row';
    tr.innerHTML = `<td colspan="8">
        <div class="vt-detail-panel" id="vt-panel-${rgId}">
            <div class="vt-detail-header">
                <span><i class="fas fa-tags"></i> Variations-Sets — ${escapeHtml(rgName)}</span>
                <button class="btn-danger-sm" onclick="resetVariations(${rgId})">
                    <i class="fas fa-trash"></i> Reset
                </button>
            </div>
            <div class="vt-panel-body" id="vt-body-${rgId}">
                <div class="vt-loading"><div class="spinner-ring-sm"></div> Lade...</div>
            </div>
        </div>
    </td>`;
    rgRow.after(tr);

    const btn = rgRow?.querySelector('.btn-tags i');
    if (btn) btn.className = 'fas fa-times';

    await initVPanel(rgId, rgName);
}

// Determines which view to show based on DB state and localStorage draft
async function initVPanel(rgId, rgName) {
    try {
        const res   = await fetch(`${API_BASE}variation_types_by_rg.php?rechtsgebiet_id=${rgId}`);
        const types = await res.json();
        if (Array.isArray(types) && types.length > 0) {
            renderReadView(rgId, rgName, types);
            return;
        }
    } catch(e) { console.warn('initVPanel fetch failed:', e); }

    const draft = getVState(rgId);
    if (draft?.phase === 2 && draft.sets?.length) {
        renderPhase2(rgId, rgName);
        return;
    }
    renderPhase1(rgId, rgName);
}

// ── Read view (finalized sets in DB) ──────────────────────────────────────────

function renderReadView(rgId, rgName, types) {
    const body = document.getElementById(`vt-body-${rgId}`);
    const groupsHtml = types.map(t => {
        const uid      = `vt-values-${rgId}-${t.id}`;
        const valPills = (t.values || []).map(v =>
            `<span class="vt-value-pill">${escapeHtml(v.value)}</span>`
        ).join('');
        return `<div class="vt-group">
            <button class="vt-group-header" onclick="toggleVTGroup('${uid}', this)">
                <i class="fas fa-chevron-right vt-group-chevron"></i>
                <span class="vt-group-name">${escapeHtml(t.name)}</span>
                <span class="vt-group-count">${t.value_count}</span>
            </button>
            <div class="vt-group-values" id="${uid}">${valPills}</div>
        </div>`;
    }).join('');
    body.innerHTML = `<div class="vt-groups">${groupsHtml || '<span style="color:var(--text-muted);padding:1rem;display:block">Keine Sets gefunden.</span>'}</div>`;
}

function toggleVTGroup(uid, btn) {
    const panel   = document.getElementById(uid);
    const chevron = btn.querySelector('.vt-group-chevron');
    const isOpen  = panel.classList.toggle('open');
    if (chevron) chevron.style.transform = isOpen ? 'rotate(90deg)' : '';
}

// ── Phase 1: Type selection ────────────────────────────────────────────────────

function renderPhase1(rgId, rgName) {
    const body       = document.getElementById(`vt-body-${rgId}`);
    const draft      = getVState(rgId);
    const candidates = draft?.types || [];
    const selectedCount = candidates.filter(t => t.selected).length;

    body.innerHTML = `
        <div class="vt-p1-panel">
            <div class="vt-p1-actions">
                <button class="btn-action btn-blue btn-sm"
                    onclick="generateTypes(${rgId}, '${escapeAttr(rgName)}')">
                    <i class="fas fa-wand-magic-sparkles"></i> Generate Sets
                </button>
                <span class="vt-p1-hint">${
                    candidates.length
                        ? `${selectedCount}/6 ausgewählt — klicke Karten an oder editiere Namen`
                        : 'Klicke "Generate Sets" um 10 Kandidaten zu erstellen'
                }</span>
            </div>
            <div class="vt-candidates" id="vt-candidates-${rgId}">
                ${renderTypeCards(candidates, rgId)}
            </div>
            <div class="vt-p1-footer">
                <button class="btn-action btn-green btn-sm"
                    id="vt-proceed-${rgId}"
                    onclick="proceedToPhase2(${rgId}, '${escapeAttr(rgName)}')"
                    ${selectedCount === 6 ? '' : 'disabled'}>
                    Weiter: Sets befüllen →
                </button>
            </div>
        </div>`;
}

function renderTypeCards(types, rgId) {
    if (!types.length) return '';
    const selectedCount = types.filter(t => t.selected).length;
    const limitReached  = selectedCount >= 6;

    return types.map((t, i) => {
        const isSelected = t.selected;
        const isLocked   = t.locked;
        const isDisabled = !isSelected && limitReached;
        const classes    = ['vt-type-card',
            isSelected ? 'selected' : '',
            isLocked   ? 'locked'   : '',
            isDisabled ? 'disabled' : '',
        ].filter(Boolean).join(' ');

        return `<div class="${classes}">
            <label class="vt-card-check">
                <input type="checkbox"
                    ${isSelected ? 'checked' : ''}
                    ${isLocked || isDisabled ? 'disabled' : ''}
                    onchange="toggleTypeCard(${rgId}, ${i}, this.checked)">
            </label>
            <div class="vt-card-body">
                <div class="vt-card-name"
                    ${isLocked ? '' : `contenteditable="true" onblur="editTypeName(${rgId}, ${i}, this.textContent)"`}
                >${escapeHtml(t.name)}</div>
                <div class="vt-card-desc">${escapeHtml(t.description || '')}</div>
            </div>
            ${isLocked ? '<span class="vt-card-lock"><i class="fas fa-lock"></i></span>' : ''}
        </div>`;
    }).join('');
}

async function generateTypes(rgId, rgName) {
    const candidatesEl = document.getElementById(`vt-candidates-${rgId}`);
    if (candidatesEl) {
        candidatesEl.innerHTML = '<div class="vt-loading"><div class="spinner-ring-sm"></div> Generiere Sets...</div>';
    }

    try {
        const res  = await fetch(`${API_BASE}variation_generate_types.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId, model: activeModel }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message || 'Generation failed');

        // Always keep Generelle Informationen as locked first entry
        const gi   = { name: 'Generelle Informationen', description: 'Allgemeine Informationen zum Rechtsgebiet', selected: true, locked: true };
        const draft = getVState(rgId) || {};

        // Preserve previously selected non-locked types
        const prevSelected = (draft.types || []).filter(t => t.selected && !t.locked);
        const prevNames    = new Set(prevSelected.map(t => t.name.toLowerCase()));

        // Filter new candidates: exclude GI and already-selected
        const newCandidates = data.types
            .filter(t => t.name.toLowerCase() !== 'generelle informationen')
            .filter(t => !prevNames.has(t.name.toLowerCase()))
            .map(t => ({ ...t, selected: false, locked: false }));

        const allTypes = [gi, ...prevSelected, ...newCandidates].slice(0, 11);
        setVState(rgId, { phase: 1, types: allTypes, sets: [] });
        renderPhase1(rgId, rgName);

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
        renderPhase1(rgId, rgName);
    }
}

function toggleTypeCard(rgId, idx, checked) {
    const state = getVState(rgId);
    if (!state?.types) return;
    state.types[idx].selected = checked;
    setVState(rgId, state);
    const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
    renderPhase1(rgId, rgRow?.dataset.rgName || '');
}

function editTypeName(rgId, idx, newName) {
    const state = getVState(rgId);
    if (!state?.types?.[idx]) return;
    state.types[idx].name = newName.trim();
    setVState(rgId, state);
}

async function proceedToPhase2(rgId, rgName) {
    const state = getVState(rgId);
    const selectedTypes = (state?.types || []).filter(t => t.selected).map(t => t.name);
    if (selectedTypes.length !== 6) {
        showToast('Bitte genau 6 Sets auswählen.', 'error');
        return;
    }

    const body = document.getElementById(`vt-body-${rgId}`);
    body.innerHTML = '<div class="vt-loading"><div class="spinner-ring-sm"></div> Generiere Werte (30–60 Sek.)...</div>';

    try {
        const res  = await fetch(`${API_BASE}variation_generate_values.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                rechtsgebiet_id:   rgId,
                rechtsgebiet_name: rgName,
                types:             selectedTypes,
                model:             activeModel,
            }),
        });
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message || 'Generation failed');

        const sets = data.sets.map(s => ({
            type:   s.type,
            values: s.values.map(v => ({ text: v, approved: true })),
        }));

        setVState(rgId, { ...state, phase: 2, sets });
        renderPhase2(rgId, rgName);

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
        renderPhase1(rgId, rgName);
    }
}

// ── Phase 2: Value review & finalize ──────────────────────────────────────────

function countApproved(sets) {
    let approved = 0, total = 0;
    (sets || []).forEach(s => (s.values || []).forEach(v => {
        total++;
        if (v.approved) approved++;
    }));
    return { approved, total };
}

function renderPhase2(rgId, rgName) {
    const body  = document.getElementById(`vt-body-${rgId}`);
    const state = getVState(rgId);
    if (!state?.sets?.length) { renderPhase1(rgId, rgName); return; }

    const { approved } = countApproved(state.sets);
    const canFinalize   = approved >= 200;
    const pct           = Math.min(100, (approved / 300) * 100).toFixed(1);

    const setsHtml = state.sets.map((s, si) => {
        const approvedCount = s.values.filter(v => v.approved).length;
        const pillsHtml     = s.values.map((v, vi) =>
            `<label class="vt-val-pill ${v.approved ? '' : 'rejected'}">
                <input type="checkbox" ${v.approved ? 'checked' : ''}
                    onchange="toggleValue(${rgId}, ${si}, ${vi}, this.checked)">
                <span contenteditable="true"
                    onblur="editValue(${rgId}, ${si}, ${vi}, this.textContent)"
                >${escapeHtml(v.text)}</span>
            </label>`
        ).join('');

        return `<div class="vt-set-card">
            <button class="vt-set-header" onclick="toggleSetCard(this)">
                <i class="fas fa-chevron-right vt-set-chevron"></i>
                <span class="vt-set-name">${escapeHtml(s.type)}</span>
                <span class="vt-set-count" id="vt-setcount-${rgId}-${si}">${approvedCount}/${s.values.length}</span>
            </button>
            <div class="vt-set-body open">${pillsHtml}</div>
        </div>`;
    }).join('');

    body.innerHTML = `
        <div class="vt-p2-panel">
            <div class="vt-p2-toolbar">
                <button class="btn-row" onclick="goBackToPhase1(${rgId}, '${escapeAttr(rgName)}')">← Zurück</button>
                <div class="vt-progress-wrap">
                    <div class="vt-progress-bar">
                        <div class="vt-progress-fill" id="vt-prog-fill-${rgId}" style="width:${pct}%"></div>
                    </div>
                    <span class="vt-progress-label" id="vt-prog-label-${rgId}">${approved} / 300 approved</span>
                </div>
                <button class="btn-action btn-orange btn-sm"
                    onclick="regenerateUnselected(${rgId}, '${escapeAttr(rgName)}')">
                    <i class="fas fa-sync-alt"></i> Regenerate unselected
                </button>
                <button class="btn-action btn-green btn-sm"
                    id="vt-finalize-${rgId}"
                    onclick="finalizeVariations(${rgId})"
                    ${canFinalize ? '' : 'disabled'}>
                    <i class="fas fa-check"></i> Finalisieren
                </button>
            </div>
            <div class="vt-sets-grid">${setsHtml}</div>
        </div>`;
}

function toggleSetCard(btn) {
    const body    = btn.nextElementSibling;
    const chevron = btn.querySelector('.vt-set-chevron');
    const isOpen  = body.classList.toggle('open');
    if (chevron) chevron.style.transform = isOpen ? 'rotate(90deg)' : '';
}

function toggleValue(rgId, setIdx, valIdx, checked) {
    const state = getVState(rgId);
    if (!state?.sets) return;
    state.sets[setIdx].values[valIdx].approved = checked;
    setVState(rgId, state);

    // Toggle .rejected class on the pill
    const body = document.getElementById(`vt-body-${rgId}`);
    if (body) {
        const setCards = body.querySelectorAll('.vt-set-card');
        const card = setCards[setIdx];
        if (card) {
            const pills = card.querySelectorAll('.vt-val-pill');
            const pill = pills[valIdx];
            if (pill) pill.classList.toggle('rejected', !checked);
        }
    }

    // Live-update progress bar
    const { approved } = countApproved(state.sets);
    const fill  = document.getElementById(`vt-prog-fill-${rgId}`);
    const label = document.getElementById(`vt-prog-label-${rgId}`);
    if (fill)  fill.style.width   = Math.min(100, (approved / 300) * 100).toFixed(1) + '%';
    if (label) label.textContent  = `${approved} / 300 approved`;

    // Live-update set count badge
    const setCount = document.getElementById(`vt-setcount-${rgId}-${setIdx}`);
    if (setCount) {
        const s = state.sets[setIdx];
        setCount.textContent = `${s.values.filter(v => v.approved).length}/${s.values.length}`;
    }

    // Enable/disable Finalisieren
    const finalizeBtn = document.getElementById(`vt-finalize-${rgId}`);
    if (finalizeBtn) finalizeBtn.disabled = approved < 200;
}

function editValue(rgId, setIdx, valIdx, newText) {
    const state = getVState(rgId);
    if (!state?.sets?.[setIdx]?.values?.[valIdx]) return;
    state.sets[setIdx].values[valIdx].text = newText.trim();
    setVState(rgId, state);
}

function goBackToPhase1(rgId, rgName) {
    const state = getVState(rgId);
    if (state) { state.phase = 1; setVState(rgId, state); }
    renderPhase1(rgId, rgName);
}

async function regenerateUnselected(rgId, rgName) {
    const state = getVState(rgId);
    if (!state?.sets) return;

    const setsPayload = state.sets
        .map(s => ({
            type:     s.type,
            rejected: s.values.filter(v => !v.approved).map(v => v.text),
            approved: s.values.filter(v =>  v.approved).map(v => v.text),
        }))
        .filter(s => s.rejected.length > 0);

    if (!setsPayload.length) {
        showToast('Keine abgelehnten Werte vorhanden.', 'info');
        return;
    }

    // Dim the toolbar while loading
    const toolbar = document.querySelector(`#vt-body-${rgId} .vt-p2-toolbar`);
    if (toolbar) toolbar.style.opacity = '0.5';

    try {
        const res  = await fetch(`${API_BASE}variation_regenerate.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                rechtsgebiet_id:   rgId,
                rechtsgebiet_name: rgName,
                model:             activeModel,
                sets:              setsPayload,
            }),
        });
        if (!res.ok) throw new Error(`Server error (${res.status})`);
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        // Replace rejected values with returned replacements
        data.sets.forEach(newSet => {
            const local = state.sets.find(s => s.type === newSet.type);
            if (!local) return;
            let ri = 0;
            local.values = local.values.map(v => {
                if (!v.approved && ri < newSet.values.length) {
                    return { text: newSet.values[ri++], approved: true };
                }
                return v;
            });
        });

        setVState(rgId, state);
        renderPhase2(rgId, rgName);
        showToast('Werte erfolgreich regeneriert!', 'success');

    } catch(e) {
        if (toolbar) toolbar.style.opacity = '1';
        showToast('Fehler: ' + e.message, 'error');
    }
}

async function finalizeVariations(rgId) {
    const state = getVState(rgId);
    if (!state?.sets) return;

    const { approved } = countApproved(state.sets);
    if (approved < 200) {
        showToast('Mindestens 200 Werte müssen genehmigt sein.', 'error');
        return;
    }

    const sets = state.sets.map(s => ({
        type:   s.type,
        values: s.values.filter(v => v.approved).map(v => v.text),
    }));

    try {
        const res  = await fetch(`${API_BASE}variation_finalize.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId, sets }),
        });
        if (!res.ok) throw new Error(`Server error (${res.status})`);
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        clearVState(rgId);
        showToast(`${data.saved} Werte gespeichert!`, 'success');

        // Remove the panel and re-open to show read view
        const detail = document.getElementById(`vt-detail-${rgId}`);
        if (detail) detail.remove();
        loadedVT.delete(rgId);

        await toggleRGVariations(rgId);

    } catch(e) {
        showToast('Fehler beim Speichern: ' + e.message, 'error');
    }
}

async function resetVariations(rgId) {
    if (!confirm('Alle Variation-Sets für dieses Rechtsgebiet löschen? Dies kann nicht rückgängig gemacht werden.')) return;

    try {
        const res  = await fetch(`${API_BASE}variation_reset.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ rechtsgebiet_id: rgId }),
        });
        if (!res.ok) throw new Error(`Server error (${res.status})`);
        const data = await res.json();
        if (data.status !== 'ok') throw new Error(data.message);

        clearVState(rgId);
        showToast('Variationen zurückgesetzt.', 'success');

        const rgRow = document.querySelector(`.rg-row[data-rg-id="${rgId}"]`);
        renderPhase1(rgId, rgRow?.dataset.rgName || '');

    } catch(e) {
        showToast('Fehler: ' + e.message, 'error');
    }
}

// ============================================
// Actions
// ============================================

/**
 * Trigger a bulk action (generate_all, publish_all, sync_gsc, etc.).
 */
async function handleAction(action) {
    const buttonMap = {
        'generate_all': 'btn-generate-all',
        'publish_all': 'btn-publish-all',
        'sync_gsc': 'btn-sync-gsc',
        'run_analyzer': 'btn-run-analyzer',
        'generate_sitemap': 'btn-generate-sitemap'
    };

    const btnId = buttonMap[action];
    const btn = document.getElementById(btnId);
    if (!btn) return;

    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Running...`;

    try {
        const response = await fetch(`${API_BASE}actions.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action })
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();

        if (result.status === 'success') {
            showToast(result.message || `Aktion "${action}" erfolgreich ausgefuehrt.`, 'success');
            // Reload table data to reflect changes
            const sortSelect = document.getElementById('sort-select');
            loadRechtsgebiete(sortSelect.value);
        } else {
            showToast(result.message || `Fehler bei Aktion "${action}".`, 'error');
        }
    } catch (error) {
        console.error(`Action "${action}" failed:`, error);
        showToast(`Fehler: ${error.message}`, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

/**
 * Generate content for a single item.
 */
async function generateContent(type, id) {
    const btn = event.target.closest('.btn-generate');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i>`;
    }

    try {
        const response = await fetch(`${API_BASE}content.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: type, id: id })
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const result = await response.json();

        if (result.status === 'success') {
            showToast(result.message || `${type} erfolgreich generiert.`, 'success');
            // Update the badge in the row
            updateRowStatus(type, id, 'generated');
            // Remove the generate button
            if (btn) btn.remove();
        } else {
            showToast(result.message || `Generierung fehlgeschlagen.`, 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> Generate`;
            }
        }
    } catch (error) {
        console.error('Generate failed:', error);
        showToast(`Fehler: ${error.message}`, 'error');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> Generate`;
        }
    }
}

/**
 * Generate content for a single variation value.
 */
async function generateVariation(rfId, variationValueId) {
    const btn = event.target.closest('.btn-generate');
    const row = btn ? btn.closest('tr') : null;
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fas fa-spinner fa-spin"></i>`;
    }

    try {
        const response = await fetch(`${API_BASE}content.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ type: 'variation', id: rfId, variation_value_id: variationValueId }),
        });

        if (!response.ok) {
            const errBody = await response.json().catch(() => ({}));
            throw new Error(errBody.message || `HTTP ${response.status}`);
        }
        const result = await response.json();

        if (result.status === 'success') {
            showToast(result.message || 'Variation erfolgreich generiert.', 'success');

            if (row) {
                const badge = row.querySelector('.status-badge');
                if (badge) {
                    badge.className = `status-badge ${getStatusClass('generated')}`;
                    badge.innerHTML = `<i class="fas ${getStatusIcon('generated')}"></i> generated`;
                }

                const rfSlug  = row.dataset.rfSlug;
                const varSlug = row.dataset.varSlug;
                if (btn && rfSlug && varSlug) {
                    const previewBtn = document.createElement('button');
                    previewBtn.className = 'btn-row btn-preview';
                    previewBtn.title = 'Vorschau';
                    previewBtn.innerHTML = `<i class="fas fa-eye"></i> Preview`;
                    previewBtn.onclick = (e) => {
                        e.stopPropagation();
                        previewPage('variation', `${rfSlug}-${varSlug}`);
                    };
                    btn.replaceWith(previewBtn);
                }
            }
        } else {
            showToast(result.message || 'Generierung fehlgeschlagen.', 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> Generate`;
            }
        }
    } catch (error) {
        console.error('Generate variation failed:', error);
        showToast(`Fehler: ${error.message}`, 'error');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> Generate`;
        }
    }
}

/**
 * Update a row's status badge after an action.
 */
function updateRowStatus(type, id, newStatus) {
    let selector;
    if (type === 'rechtsgebiet') {
        selector = `.rg-row[data-rg-id="${id}"]`;
    } else if (type === 'rechtsfrage') {
        selector = `.rf-row[data-rf-id="${id}"]`;
    } else {
        selector = `.var-row[data-var-id="${id}"]`;
    }

    const row = document.querySelector(selector);
    if (!row) return;

    const badge = row.querySelector('.status-badge');
    if (!badge) return;

    const statusClass = getStatusClass(newStatus);
    const statusIcon = getStatusIcon(newStatus);
    badge.className = `status-badge ${statusClass}`;
    badge.innerHTML = `<i class="fas ${statusIcon}"></i> ${escapeHtml(newStatus)}`;
}

/**
 * Preview a page by opening its public URL in a new tab.
 */
function previewPage(type, slug) {
    let path;
    if (type === 'rechtsgebiet') {
        path = `/experten-service/${slug}`;
    } else {
        path = `/${slug}`;
    }
    window.open('http://localhost:8000' + path, '_blank');
}

// ============================================
// Tab Switching
// ============================================

/**
 * Switch between Management and Analytics tabs.
 */
function switchTab(tabName) {
    // Deactivate all tabs and content
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(panel => panel.classList.remove('active'));

    // Activate the selected tab
    const tabBtn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
    const tabPanel = document.getElementById(`tab-${tabName}`);

    if (tabBtn) tabBtn.classList.add('active');
    if (tabPanel) tabPanel.classList.add('active');

    // Load analytics data when switching to analytics tab
    if (tabName === 'analytics') {
        loadAnalytics();
    }
}

// ============================================
// Sort Handler
// ============================================

/**
 * Handle sort dropdown change.
 */
function handleSort(sortBy) {
    loadRechtsgebiete(sortBy);
}

/**
 * Add a new Rechtsgebiet via the inline input form.
 */
async function addRechtsgebiet(event) {
    event.preventDefault();
    const input = document.getElementById('add-rg-input');
    const btn   = event.target.querySelector('.add-rg-btn');
    const name  = input.value.trim();
    if (!name) return;

    btn.disabled = true;
    try {
        const res = await fetch(`${API_BASE}rechtsgebiete.php`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ name }),
        });
        const data = await res.json();
        if (!res.ok) {
            showToast(data.error || 'Fehler beim Hinzufügen.', 'error');
            return;
        }
        input.value = '';
        showToast(`"${name}" wurde hinzugefügt.`, 'success');
        const sortBy = document.getElementById('sort-select').value;
        loadRechtsgebiete(sortBy);
    } catch (e) {
        showToast('Netzwerkfehler.', 'error');
    } finally {
        btn.disabled = false;
    }
}

// ============================================
// Toast Notifications
// ============================================

/**
 * Show a toast notification.
 * @param {string} message - The message to display.
 * @param {string} type - 'success', 'error', or 'info'.
 * @param {number} duration - Auto-hide duration in ms (default 4000).
 */
function showToast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const iconMap = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle'
    };

    toast.innerHTML = `
        <i class="fas ${iconMap[type] || iconMap.info}"></i>
        <span class="toast-message">${escapeHtml(message)}</span>
    `;

    container.appendChild(toast);

    // Auto-remove after duration
    setTimeout(() => {
        toast.classList.add('toast-out');
        toast.addEventListener('animationend', () => toast.remove());
    }, duration);
}

// ============================================
// Utility Functions
// ============================================

/**
 * Escape HTML to prevent XSS.
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const str = String(text);
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/**
 * Escape a value for use in HTML attributes.
 */
function escapeAttr(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Format a number with locale formatting, handling null/undefined.
 */
function formatNumber(value) {
    if (value === null || value === undefined || value === '') return '0';
    return Number(value).toLocaleString('de-DE');
}

/**
 * Get CSS class for a status badge.
 */
function getStatusClass(status) {
    const map = {
        'draft': 'status-draft',
        'published': 'status-published',
        'unpublished': 'status-unpublished',
        'pending': 'status-pending',
        'generating': 'status-generating',
        'generated': 'status-generated',
        'failed': 'status-failed'
    };
    return map[status] || 'status-draft';
}

/**
 * Get Font Awesome icon for a status.
 */
function getStatusIcon(status) {
    const map = {
        'draft': 'fa-circle',
        'published': 'fa-circle-check',
        'unpublished': 'fa-circle-xmark',
        'pending': 'fa-clock',
        'generating': 'fa-spinner fa-spin',
        'generated': 'fa-circle-check',
        'failed': 'fa-triangle-exclamation'
    };
    return map[status] || 'fa-circle';
}

/**
 * Get score color class based on value.
 */
function getScoreClass(score) {
    if (score === null || score === undefined) return '';
    score = Number(score);
    if (score >= 70) return 'score-high';
    if (score >= 40) return 'score-mid';
    return 'score-low';
}

// ============================================
// Analytics
// ============================================

let trendChart = null;

async function loadAnalytics() {
    loadAnalyticsSummary();
    loadTrendChart(30);
    loadRecommendations();
    loadTopPerformers();
    loadApiCosts();
}

async function loadAnalyticsSummary() {
    try {
        const res = await fetch(API_BASE + 'analytics.php?action=summary');
        const data = await res.json();
        document.getElementById('stat-total-pages').textContent = formatNumber(data.total_pages);
        document.getElementById('stat-published').textContent = formatNumber(data.published_pages);
        document.getElementById('stat-clicks').textContent = formatNumber(data.total_clicks_30d);
        document.getElementById('stat-impressions').textContent = formatNumber(data.total_impressions_30d);
        document.getElementById('stat-ctr').textContent = (data.avg_ctr * 100).toFixed(1) + '%';
        document.getElementById('stat-position').textContent = data.avg_position ? data.avg_position.toFixed(1) : '-';
    } catch (e) {
        console.error('Failed to load analytics summary:', e);
    }
}

async function loadTrendChart(days) {
    // Update active button
    document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
    if (event && event.target && event.target.classList) {
        event.target.classList.add('active');
    } else {
        // Default: activate the button matching the days value
        document.querySelectorAll('.period-btn').forEach(b => {
            if (b.textContent.trim().startsWith(days.toString())) {
                b.classList.add('active');
            }
        });
    }

    try {
        const res = await fetch(API_BASE + 'analytics.php?action=trends&days=' + days);
        const data = await res.json();

        const labels = data.map(d => d.date);
        const clicks = data.map(d => d.clicks);
        const impressions = data.map(d => d.impressions);

        if (trendChart) trendChart.destroy();

        const ctx = document.getElementById('trendChart').getContext('2d');
        trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Klicks',
                        data: clicks,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                    {
                        label: 'Impressionen',
                        data: impressions,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.1)',
                        fill: true,
                        tension: 0.3,
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { labels: { color: '#94a3b8' } } },
                scales: {
                    x: { ticks: { color: '#64748b' }, grid: { color: '#334155' } },
                    y: { ticks: { color: '#64748b' }, grid: { color: '#334155' } }
                }
            }
        });
    } catch (e) {
        console.error('Failed to load trend chart:', e);
    }
}

async function loadRecommendations() {
    try {
        const res = await fetch(API_BASE + 'analytics.php?action=recommendations');
        const data = await res.json();
        const tbody = document.querySelector('#recommendations-table tbody');

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Keine Empfehlungen vorhanden</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(d => `
            <tr>
                <td>${escapeHtml(d.page_name || 'Unknown')}</td>
                <td><span class="action-badge action-${d.action}">${escapeHtml((d.action || '').toUpperCase())}</span></td>
                <td>${escapeHtml(d.reason)}</td>
                <td>${d.priority_score}</td>
                <td>${d.executed_at ? '<i class="fas fa-check" style="color:#22c55e"></i>' : '<i class="fas fa-clock" style="color:#f59e0b"></i>'}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load recommendations:', e);
    }
}

async function loadTopPerformers() {
    try {
        const res = await fetch(API_BASE + 'analytics.php?action=top');
        const data = await res.json();
        const tbody = document.querySelector('#top-performers-table tbody');

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Noch keine Daten</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(d => `
            <tr>
                <td>${escapeHtml(d.page_name || d.url)}</td>
                <td>${formatNumber(d.total_clicks)}</td>
                <td>${formatNumber(d.total_impressions)}</td>
                <td>${(d.avg_ctr * 100).toFixed(1)}%</td>
                <td>${d.avg_position ? parseFloat(d.avg_position).toFixed(1) : '-'}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load top performers:', e);
    }
}

async function loadApiCosts() {
    try {
        const res = await fetch(API_BASE + 'analytics.php?action=api_costs&days=30');
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        const tbody = document.querySelector('#api-costs-table tbody');

        // Update summary cards
        const totalCostEur = ((data.totals?.total_cost_cents || 0) / 100).toFixed(2);
        const totalCalls = data.totals?.total_calls || 0;
        document.getElementById('stat-api-cost').textContent = totalCostEur + ' €';
        document.getElementById('stat-api-calls').textContent = formatNumber(totalCalls);

        if (!data.rows || data.rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted)">Noch keine API-Daten</td></tr>';
            return;
        }

        tbody.innerHTML = data.rows.map(r => `
            <tr>
                <td>${escapeHtml(r.date)}</td>
                <td>${escapeHtml(r.api_name)}</td>
                <td>${formatNumber(r.calls_count)}</td>
                <td>${formatNumber(r.tokens_used)}</td>
                <td>${(r.cost_cents != null ? (r.cost_cents / 100).toFixed(4) : '0.0000')} €</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Failed to load API costs:', e);
    }
}

// ============================================
// Initialization
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    loadModel();
    loadRechtsgebiete();
});
