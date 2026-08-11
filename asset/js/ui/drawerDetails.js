import { DRAWER_RENDERED } from './drawer.js';
import { integrityGroups, INTEGRITY_OK_TEXT } from '../core/integrityModel.js';
import { historyRows, HISTORY_EMPTY_NOTICE } from '../core/historyModel.js';

/**
 * Secciones del drawer que el cliente no puede calcular: integridad e historial
 * (rebanada 3a de TASK-028).
 *
 * Se engancha a `oer:drawer-rendered` en vez de estar cableado dentro de
 * drawer.js, igual que el panel del re-catalogador: así el drawer no depende de
 * estas secciones y se pueden mover sin romperlo.
 *
 * Todo el texto se escribe con textContent: los títulos y los mensajes vienen
 * del catálogo, nunca del código.
 */

function heading(text) {
  const element = document.createElement('h4');
  element.textContent = text;
  return element;
}

function note(text, className) {
  const element = document.createElement('p');
  element.className = className;
  element.textContent = text;
  return element;
}

function renderIntegrity(integrity) {
  const section = document.createElement('section');
  section.className = 'oer-drawer-section oer-drawer-integrity';
  section.appendChild(heading(Omeka.jsTranslate('Integridad')));

  const groups = integrityGroups(integrity);
  if (!groups.length) {
    section.appendChild(note(Omeka.jsTranslate(INTEGRITY_OK_TEXT), 'oer-drawer-empty'));
    return section;
  }

  groups.forEach((group) => {
    const list = document.createElement('ul');
    list.className = `oer-integrity-issues oer-integrity-issues-${group.severity}`;
    group.issues.forEach((issue) => {
      const item = document.createElement('li');
      item.textContent = issue.message;
      list.appendChild(item);
    });
    section.appendChild(list);
  });

  return section;
}

function renderChange(change) {
  const block = document.createElement('div');
  block.className = 'oer-history-change';

  const label = document.createElement('span');
  label.className = 'oer-history-term';
  label.textContent = change.emptied
    ? `${change.label} (${Omeka.jsTranslate('vaciada')})`
    : change.label;
  block.appendChild(label);

  (change.added || []).forEach((title) => {
    const line = document.createElement('span');
    line.className = 'oer-history-added';
    line.textContent = `+ ${title}`;
    block.appendChild(line);
  });

  (change.removed || []).forEach((value) => {
    const line = document.createElement('span');
    line.className = 'oer-history-removed';
    line.textContent = `− ${value.title}`;
    block.appendChild(line);

    // PEND-013: la justificación de la IA se muestra, pero COLAPSADA. Aquí ya
    // está decidido y escrito, así que no ancla la decisión del curador; y
    // regenerarla costaría otra pasada de LLM.
    if (value.reason) {
      const why = document.createElement('details');
      why.className = 'oer-history-why';
      const toggle = document.createElement('summary');
      toggle.textContent = Omeka.jsTranslate('Ver porqué');
      why.appendChild(toggle);
      const reason = document.createElement('p');
      reason.textContent = value.reason;
      why.appendChild(reason);
      block.appendChild(why);
    }
  });

  return block;
}

function renderHistory(history) {
  const section = document.createElement('section');
  section.className = 'oer-drawer-section oer-drawer-history';
  section.appendChild(heading(Omeka.jsTranslate('Historial de curación')));

  const rows = historyRows(history);
  if (!rows.length) {
    section.appendChild(note(Omeka.jsTranslate(HISTORY_EMPTY_NOTICE), 'oer-drawer-empty'));
    return section;
  }

  rows.forEach((row) => {
    const entry = document.createElement('article');
    entry.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';

    const header = document.createElement('p');
    header.className = 'oer-history-header';
    header.textContent = `${row.when} · ${row.contributor}`;
    entry.appendChild(header);

    const summary = document.createElement('p');
    summary.className = 'oer-history-summary';
    summary.textContent = row.summary;
    entry.appendChild(summary);

    row.changes.forEach((change) => entry.appendChild(renderChange(change)));
    section.appendChild(entry);
  });

  return section;
}

export function initDrawerDetails(config) {
  document.addEventListener(DRAWER_RENDERED, (event) => {
    const { itemId, content } = event.detail;
    const url = config.drawerDetailsUrl;
    if (!url) {
      return;
    }

    const placeholder = document.createElement('div');
    placeholder.className = 'oer-drawer-details';
    placeholder.textContent = Omeka.jsTranslate('Cargando detalle…');
    content.appendChild(placeholder);

    fetch(`${url}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
      .then((response) => response.json())
      .then((details) => {
        placeholder.textContent = '';
        placeholder.appendChild(renderIntegrity(details.integrity));
        placeholder.appendChild(renderHistory(details.history));
      })
      .catch(() => {
        placeholder.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle ampliado.');
      });
  });
}
