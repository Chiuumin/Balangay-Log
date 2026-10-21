/* officer-ai.js: AI triage result, downgrade-justification flow, and the "Severity" button
   dialog for the officer dashboard. Load it BEFORE officer-dashboard.js. */
(function () {
  'use strict';
  const origFetch = window.fetch.bind(window);
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const DEFAULT_EVIDENCE = {
    ON_SITE_VERIFICATION: 'Verified on site by a tanod or officer',
    WITNESS_STATEMENT: 'Witness statement',
    COMPLAINANT_RETRACTION: 'Complainant retraction or clarification',
    PHYSICAL_EVIDENCE: 'Physical evidence',
    OTHER: 'Other (always needs Captain approval)'
  };
  const PRIORITY_PILL = { 
    Critical: 'pill-rejected', 
    High: 'pill-pending', 
    Moderate: 'pill-pending', 
    Medium: 'pill-pending', 
    Low: 'pill-active' 
  };

  // DOM helper: text is always set with textContent to avoid XSS
  function el(tag, props, ...kids) {
    const n = document.createElement(tag);
    Object.entries(props || {}).forEach(([k, v]) => {
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else n.setAttribute(k, v);
    });
    kids.flat().forEach((c) => n.append(c));
    return n;
  }

  function openDialog(dlg, onClose) {
    document.body.appendChild(dlg);
    dlg.addEventListener('close', () => { dlg.remove(); if (onClose) onClose(); });
    dlg.showModal();
  }

  // ---------- Justification dialog ----------
  function askOverride(info, errorMsg) {
    return new Promise((resolve) => {
      const ai = info.ai || null;
      const types = info.evidence_types || DEFAULT_EVIDENCE;
      let answer = null;
      const justification = el('textarea', { 
        rows: '4', 
        required: '',
        placeholder: 'e.g., Weapon verified as toy prop by Tanod #12; no physical injury occurred.' 
      });
      const verifiedBy = el('input', { required: '', placeholder: 'e.g., Tanod #12 or full name' });
      const evidence = el('select', { required: '' },
        el('option', { value: '', text: 'Select evidence type' }),
        Object.entries(types).map(([k, label]) => el('option', { value: k, text: label })));
      
      const summary = el('div', { class: 'notice', style: 'margin-bottom:12px;' });
      if (ai && ai.priority) {
        summary.append(el('strong', { 
          text: 'Triage assessed this as ' + ai.priority + (ai.source === 'AI+RULES' ? ' (AI + safety rules)' : ' (safety keyword rules)') + '.' 
        }));
        if (ai.triggers && ai.triggers.length) {
          summary.append(el('div', { text: 'Keywords found: ' + (Array.isArray(ai.triggers) ? ai.triggers.join(', ') : ai.triggers) }));
        }
        if (ai.rationale) {
          summary.append(el('div', { style: 'margin-top:4px;', text: ai.rationale }));
        }
      } else {
        summary.append(el('strong', { text: 'Lowering the severity needs a valid justification.' }));
      }

      const error = el('p', { class: 'empty-message', style: 'color:#d61818;margin:10px 0 0;', text: errorMsg || '' });
      error.hidden = !errorMsg;

      const form = el('form', { method: 'dialog' },
        el('div', { class: 'dialog-heading' },
          el('div', {}, el('p', { class: 'eyebrow', text: 'SEVERITY OVERRIDE' }),
                        el('h2', { text: 'Justify lowering the severity' })),
          el('button', { class: 'icon-button', type: 'button', 'data-cancel': '', 'aria-label': 'Close', text: '×' })),
        summary,
        el('div', { style: 'display:grid;gap:12px;' },
          el('label', {}, 'What did you verify, and what did you find? *', justification),
          el('label', {}, 'Verified by *', verifiedBy),
          el('label', {}, 'Evidence type *', evidence)),
        error,
        el('p', { class: 'empty-message', style: 'margin:10px 0 0;',
          text: 'If this cannot be verified automatically, the Captain approves it and the case keeps its higher severity until then.' }),
        el('div', { class: 'dialog-actions', style: 'margin-top:14px;' },
          el('button', { class: 'button button-quiet', type: 'button', 'data-cancel': '', text: 'Keep higher severity' }),
          el('button', { class: 'button button-primary', type: 'submit', text: 'Submit justification' })));

      const dlg = el('dialog', { class: 'admin-dialog', style: 'width:min(520px, calc(100vw - 32px));' }, form);
      form.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => dlg.close()));
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        answer = {
          overrideJustification: justification.value.trim(),
          overrideVerifiedBy: verifiedBy.value.trim(),
          overrideEvidenceType: evidence.value
        };
        dlg.close();
      });
      openDialog(dlg, () => resolve(answer));
    });
  }

  // ---------- Triage result dialog (after a case is encoded) ----------
  function showTriageResult(data) {
    const ai = data.ai || {};
    const ov = data.override || null;
    const rows = [
      ['Reference', data.reference_number || 'N/A'],
      ['Final priority', data.priority || 'Low'],
      ['Triage', ai.source === 'AI+RULES' ? 'AI + safety rules' : 'Safety keyword rules only']
    ];
    if (typeof ai.confidence === 'number') rows.push(['AI confidence', Math.round(ai.confidence * 100) + '%']);
    if (ai.rationale) rows.push(['Reason', ai.rationale]);
    if (typeof ai.summary === 'string' && ai.summary.trim()) rows.push(['Summary', ai.summary]);
    if (ai.triggers && ai.triggers.length) {
      const trigs = Array.isArray(ai.triggers) ? ai.triggers.join(', ') : ai.triggers;
      rows.push(['Safety keywords', trigs]);
    }
    if (ov) rows.push(['Downgrade request', (ov.verdict || 'Reviewed') + ': ' + (ov.reason || 'No details')]);
    if (ai.notice) rows.push(['Notice', ai.notice]);

    const list = el('div', { style: 'display:grid;grid-template-columns:130px 1fr;gap:8px 12px;font-size:13px;margin-top:12px;' });
    rows.forEach(([k, v]) => {
      list.append(el('strong', { text: k }));
      if (k === 'Final priority') {
        list.append(el('span', {}, el('span', { class: 'pill ' + (PRIORITY_PILL[v] || 'pill-blue'), text: String(v) })));
      } else {
        list.append(el('span', { text: String(v) }));
      }
    });

    // Officer guidance: safety actions from the rules always apply; the AI's steps are extra suggestions to check.
    const strings = (v) => (Array.isArray(v) ? v.filter((x) => typeof x === 'string' && x.trim() !== '') : []);
    const addBlock = (label, items, ordered) => {
      const clean = strings(items);
      if (!clean.length) return;
      list.append(el('strong', { text: label }));
      list.append(el(ordered ? 'ol' : 'ul', { style: 'margin:0;padding-left:18px;' }, clean.map((x) => el('li', { text: x }))));
    };
    const aiSteps = strings(ai.next_steps);
    addBlock(aiSteps.length ? 'Safety steps (rules)' : 'Next steps', (data.recommended_actions || []).slice(0, 4), true);
    addBlock('AI suggestions (check before acting)', aiSteps, true);
    addBlock('Ask the resident', ai.questions, false);

    const dis = ai.disagreement && ai.disagreement.any ? ai.disagreement : null;
    const warn = dis
      ? el('div', { class: 'notice', style: 'margin-top:12px;background:#fef3c7;color:#92400e;font-weight:700;font-size:12.5px;',
          text: '⚠️ The rules and the AI disagree. ' + (dis.message || '') })
      : [];

    const form = el('form', { method: 'dialog' },
      el('div', { class: 'dialog-heading' },
        el('div', {}, el('p', { class: 'eyebrow', text: 'AGENT 1 TRIAGE' }), el('h2', { text: 'Case filed: triage result' })),
        el('button', { class: 'icon-button', type: 'button', 'data-close': '', 'aria-label': 'Close', text: '×' })),
      warn,
      list,
      el('p', { class: 'empty-message', style: 'margin:10px 0 0;font-size:11px;', text: 'Suggestions only. The desk officer makes the final decision.' }),
      el('div', { class: 'dialog-actions', style: 'margin-top:14px;' },
        el('button', { class: 'button button-primary', type: 'submit', text: 'OK' })));

    const dlg = el('dialog', { class: 'admin-dialog', style: 'width:min(520px, calc(100vw - 32px));' }, form);
    form.querySelector('[data-close]').addEventListener('click', () => dlg.close());
    openDialog(dlg);
  }

  // ---------- "Severity" button dialog ----------
  function notify(msg, isError) {
    if (typeof window.showNotice === 'function') window.showNotice(msg, !!isError);
    else alert(msg);
  }

  function openSeverityDialog(ref, current) {
    const choices = ['Low', 'Moderate', 'High', 'Critical'].filter((p) => p.toLowerCase() !== String(current).toLowerCase());
    const select = el('select', { required: '' },
      choices.map((p) => el('option', { value: p, text: p === 'Critical' ? 'Critical (Immediate Dispatch)' : p })));

    const form = el('form', { method: 'dialog' },
      el('div', { class: 'dialog-heading' },
        el('div', {}, el('p', { class: 'eyebrow', text: ref }), el('h2', { text: 'Change severity' })),
        el('button', { class: 'icon-button', type: 'button', 'data-cancel': '', 'aria-label': 'Close', text: '×' })),
      el('p', { class: 'empty-message', style: 'margin:0 0 12px;', text: 'Current severity: ' + (current || 'unknown') }),
      el('label', {}, 'New severity', select),
      el('p', { class: 'empty-message', style: 'margin:10px 0 0;',
        text: 'Raising the severity takes effect immediately. Lowering it requires verification.' }),
      el('div', { class: 'dialog-actions', style: 'margin-top:14px;' },
        el('button', { class: 'button button-quiet', type: 'button', 'data-cancel': '', text: 'Cancel' }),
        el('button', { class: 'button button-primary', type: 'submit', text: 'Continue' })));

    const dlg = el('dialog', { class: 'admin-dialog', style: 'width:min(440px, calc(100vw - 32px));' }, form);
    form.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', () => dlg.close()));

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const newPriority = select.value;
      dlg.close();
      try {
        const res = await window.fetch('api/change_severity.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ reference_number: ref, new_priority: newPriority })
        });
        const json = await res.json();
        notify(json.message || (res.ok ? 'Severity updated.' : 'Could not change severity.'), !res.ok);
        if (res.ok && typeof window.refreshOperationsData === 'function') window.refreshOperationsData();
      } catch (err) {
        notify('Could not change severity: ' + err.message, true);
      }
    });
    openDialog(dlg);
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action="change_severity"]');
    if (btn) openSeverityDialog(btn.dataset.ref, btn.dataset.priority);
  });

  // ---------- fetch wrapper for automatic override intercept ----------
  function targetOf(url, payload) {
    if (url.includes('api/change_severity.php')) return 'severity';
    if (url.includes('api/encode_case.php')) return 'encode';
    if (url.includes('api/officer_console.php') && payload && payload.action === 'encode_case') return 'encode';
    return null;
  }

  window.fetch = async function (input, init) {
    const url = typeof input === 'string' ? input : '';
    if (!url || !init || typeof init.body !== 'string') return origFetch(input, init);
    let payload;
    try { payload = JSON.parse(init.body); } catch (e) { return origFetch(input, init); }
    const kind = targetOf(url, payload);
    if (!kind) return origFetch(input, init);

    const headers = new Headers(init.headers || {});
    if (!headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    if (csrf && !headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', csrf);

    let extra = {};
    let info = null;
    let errorMsg = '';
    let res;

    for (let attempt = 0; attempt < 5; attempt++) {
      res = await origFetch(input, Object.assign({}, init, {
        headers: headers,
        body: JSON.stringify(Object.assign({}, payload, extra))
      }));

      if (res.status !== 409 && res.status !== 422) break;
      const body = await res.clone().json().catch(() => null);
      if (!body) break;

      if (body.requires_override) {
        info = body; 
        errorMsg = '';
      } else if (body.override_rejected) {
        info = info || { ai: body.ai, evidence_types: DEFAULT_EVIDENCE };
        errorMsg = body.message || 'The justification was rejected.';
      } else {
        break;
      }

      const answer = await askOverride(info, errorMsg);
      if (!answer) break; // cancelled
      extra = answer;
    }

    if (res.ok && kind === 'encode') {
      res.clone().json().then((j) => { 
        if (j && j.data && j.data.ai) showTriageResult(j.data); 
      }).catch(() => {});
    }

    return res;
  };
})();