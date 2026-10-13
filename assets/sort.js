(() => {
  'use strict';
  const config = window.etchHubSortConfig;
  if (!config?.endpoint || !config?.nonce) return;
  const { __, sprintf } = window.wp.i18n;

  const columns = [
    ['id', __('ID', 'etch-content-hub-sort')], ['title', __('Title', 'etch-content-hub-sort')], ['slug', __('Slug', 'etch-content-hub-sort')],
    ['status', __('Status', 'etch-content-hub-sort')], ['created', __('Created', 'etch-content-hub-sort')], ['modified', __('Last Edited', 'etch-content-hub-sort')],
  ];
  const states = new Map();
  let view = null;
  let scheduled = false;
  const sortIcons = {
    asc: 'arrow-up-03-stroke-sharp.svg',
    desc: 'arrow-down-03-stroke-sharp.svg',
    none: 'arrow-data-transfer-vertical-stroke-sharp.svg',
  };

  const element = (tag, className = '', text) => {
    const node = document.createElement(tag);
    node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };
  const button = (label, callback, className = '') => {
    const node = element('button', `etch-hub-sort-button ${className}`, label);
    node.type = 'button';
    node.addEventListener('click', callback);
    return node;
  };
  const selectedType = () => {
    const selected = document.querySelector('.content-hub__sidebar .content-hub-list__item--selected .content-hub-list__item-button');
    return config.types[selected?.textContent?.trim()] || null;
  };
  const stateFor = type => {
    if (!states.has(type)) states.set(type, {
      search: '', sort: 'created', order: 'desc', page: 1,
      perPage: 25, native: false, requestId: 0,
      hierarchy: (config.hierarchicalTypes || []).includes(type),
    });
    return states.get(type);
  };
  const formatDate = value => {
    if (!value || value.startsWith('0000-00-00')) return '-';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
  };

  function syncMode(current) {
    current.root.classList.toggle('etch-hub-sort-native', current.state.native);
    current.table.hidden = !current.state.native;
    current.results.hidden = current.state.native;
    current.controls.hidden = current.state.native;
    if (current.hierarchyTools) current.hierarchyTools.hidden = current.state.native;
    current.switchButton.textContent = current.state.native ? __('Browse all', 'etch-content-hub-sort') : __('Etch table', 'etch-content-hub-sort');
    current.switchButton.title = current.state.native
      ? __('Search, sort, and page through all items', 'etch-content-hub-sort')
      : __('Use Etch’s original table for inline editing and creating items', 'etch-content-hub-sort');
  }

  function renderRows(current, data) {
    const { state, results } = current;
    current.treeActionId = (current.treeActionId || 0) + 1;
    current.bulkOpening = false;
    results.replaceChildren();
    const start = data.total ? (data.page - 1) * data.per_page + 1 : 0;
    const end = Math.min(data.page * data.per_page, data.total);
    current.message.textContent = data.hierarchy
      ? sprintf(__('Showing %1$d–%2$d of %3$d top-level items', 'etch-content-hub-sort'), start, end, data.total)
      : sprintf(__('Showing %1$d–%2$d of %3$d', 'etch-content-hub-sort'), start, end, data.total);
    results.setAttribute('aria-busy', 'false');

    const scroller = element('div', 'etch-hub-sort-scroller');
    const table = element('table', 'etch-hub-sort-table');
    const hierarchy = !!data.hierarchy;
    const thead = element('thead');
    const headings = element('tr');
    const splitActions = config.actionsLayout === 'split';
    const openStart = config.actionsLayout === 'open_start';
    const separateActions = splitActions || openStart;
    const actionsFirst = config.actionsLayout === 'start';
    const actionHeading = (label, single = false) => {
      const heading = element('th', `etch-hub-sort-actions-cell${single ? ' etch-hub-sort-action-single' : ''}`, label);
      heading.scope = 'col';
      return heading;
    };
    if (actionsFirst) headings.appendChild(actionHeading(__('Actions', 'etch-content-hub-sort')));
    if (openStart) headings.appendChild(actionHeading(__('Open', 'etch-content-hub-sort'), true));
    for (const [key, label] of columns) {
      const th = element('th');
      th.scope = 'col';
      const active = state.sort === key;
      th.setAttribute('aria-sort', active ? (state.order === 'asc' ? 'ascending' : 'descending') : 'none');
      const heading = button(label, () => {
        state.order = state.sort === key && state.order === 'asc' ? 'desc' : 'asc';
        state.sort = key;
        state.page = 1;
        current.focusSort = key;
        load(current);
      }, 'etch-hub-sort-heading');
      heading.dataset.sort = key;
      heading.setAttribute('aria-label', active
        ? sprintf(__('Sort by %1$s, %2$s', 'etch-content-hub-sort'), label, state.order === 'asc' ? __('ascending', 'etch-content-hub-sort') : __('descending', 'etch-content-hub-sort'))
        : sprintf(__('Sort by %s', 'etch-content-hub-sort'), label));
      const icon = element('img', 'etch-hub-sort-icon');
      icon.src = `${config.assetUrl}${sortIcons[active ? state.order : 'none']}`;
      icon.alt = '';
      heading.appendChild(icon);
      th.appendChild(heading);
      headings.appendChild(th);
      if (splitActions && key === 'title') headings.appendChild(actionHeading(__('Open', 'etch-content-hub-sort'), true));
    }
    if (separateActions) headings.appendChild(actionHeading(__('Trash', 'etch-content-hub-sort'), true));
    else if (!actionsFirst) headings.appendChild(actionHeading(__('Actions', 'etch-content-hub-sort')));
    thead.appendChild(headings);
    table.appendChild(thead);

    const tbody = element('tbody');
    const columnCount = columns.length + (separateActions ? 2 : 1);
    const updateBulkButton = () => {
      if (!current.bulkButton) return;
      const canExpand = !!tbody.querySelector('.etch-hub-sort-expand[aria-expanded="false"], .etch-hub-sort-load-more');
      const hasExpanded = !!tbody.querySelector('.etch-hub-sort-expand[aria-expanded="true"]');
      const close = current.bulkOpening || (!canExpand && hasExpanded);
      current.bulkMode = close ? 'close' : 'open';
      current.bulkButton.textContent = close ? __('Close all', 'etch-content-hub-sort') : __('Open all', 'etch-content-hub-sort');
      current.bulkButton.title = close
        ? __('Collapse every branch on this page', 'etch-content-hub-sort')
        : __('Expand every branch on this page', 'etch-content-hub-sort');
      current.bulkButton.disabled = !canExpand && !hasExpanded;
    };
    const loadChildren = async (parentRow, parentItem, page, depth, nextPageRow = null) => {
      const marker = element('tr', 'etch-hub-sort-child-message');
      marker.dataset.depth = String(depth);
      const markerCell = element('td', '', __('Loading children…', 'etch-content-hub-sort'));
      markerCell.colSpan = columnCount;
      marker.appendChild(markerCell);
      parentRow.parentNode.insertBefore(marker, nextPageRow || parentRow.nextSibling);
      try {
        const url = new URL(config.endpoint);
        for (const [key, value] of Object.entries({
          type: current.type, search: '', sort: state.sort, order: state.order,
          page, per_page: state.perPage, hierarchy: true, parent: parentItem.id,
        })) url.searchParams.set(key, value);
        const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
        const children = await response.json();
        if (!response.ok) throw new Error(children.message || __('Could not load children.', 'etch-content-hub-sort'));
        if (view !== current || current.data !== data || !marker.isConnected || parentRow.querySelector('.etch-hub-sort-expand')?.getAttribute('aria-expanded') !== 'true') return false;
        let last = marker;
        for (const child of children.items) {
          const childRow = createRow(child, depth);
          last.after(childRow);
          last = childRow;
        }
        if (page < children.pages) {
          const moreRow = element('tr', 'etch-hub-sort-child-more');
          moreRow.dataset.depth = String(depth);
          const cell = element('td');
          cell.colSpan = columnCount;
          cell.style.setProperty('--etch-hub-depth', depth);
          cell.appendChild(button(__('Load more children', 'etch-content-hub-sort'), () => {
            moreRow.etchHubChildrenPromise = loadChildren(parentRow, parentItem, page + 1, depth, moreRow);
          }, 'etch-hub-sort-load-more'));
          moreRow.appendChild(cell);
          last.after(moreRow);
        }
        nextPageRow?.remove();
        return true;
      } catch (error) {
        if (marker.isConnected && view === current) {
          current.message.textContent = error.message || __('Could not load children.', 'etch-content-hub-sort');
          if (!nextPageRow) {
            const expand = parentRow.querySelector('.etch-hub-sort-expand');
            expand?.setAttribute('aria-expanded', 'false');
            if (expand) {
              expand.textContent = '▸';
              expand.setAttribute('aria-label', sprintf(__('Show children of %s', 'etch-content-hub-sort'), parentItem.title || `#${parentItem.id}`));
            }
          }
        }
        return false;
      } finally {
        marker.remove();
        updateBulkButton();
      }
    };
    const createRow = (item, depth = 0) => {
      const row = element('tr');
      row.dataset.depth = String(depth);
      if (hierarchy) {
        row.classList.add('etch-hub-sort-tree-row');
        if (depth) row.classList.add('etch-hub-sort-tree-child');
        if (item.has_children) row.classList.add('etch-hub-sort-tree-parent');
      }
      for (const [key] of columns) {
        const value = key === 'id' ? `#${item.id}`
          : key === 'created' || key === 'modified' ? formatDate(item[key])
          : item[key] || '';
        const cell = element('td', `etch-hub-sort-cell-${key}`);
        if (key === 'status') cell.appendChild(element('span', `etch-hub-sort-status etch-hub-sort-status-${item.status}`, value));
        else if (key === 'id' && hierarchy) {
          const id = element('div', 'etch-hub-sort-tree-id');
          id.style.setProperty('--etch-hub-depth', depth);
          if (item.has_children) {
            const expand = button('▸', () => {
              const opened = expand.getAttribute('aria-expanded') === 'true';
              expand.setAttribute('aria-expanded', String(!opened));
              expand.textContent = opened ? '▸' : '▾';
              expand.setAttribute('aria-label', sprintf(opened
                ? __('Show children of %s', 'etch-content-hub-sort')
                : __('Hide children of %s', 'etch-content-hub-sort'), item.title || `#${item.id}`));
              if (opened) {
                let next = row.nextSibling;
                while (next && Number(next.dataset.depth) > depth) {
                  const following = next.nextSibling;
                  next.remove();
                  next = following;
                }
                updateBulkButton();
              } else row.etchHubChildrenPromise = loadChildren(row, item, 1, depth + 1);
            }, 'etch-hub-sort-expand');
            expand.setAttribute('aria-expanded', 'false');
            expand.setAttribute('aria-label', sprintf(__('Show children of %s', 'etch-content-hub-sort'), item.title || `#${item.id}`));
            id.appendChild(expand);
          } else id.appendChild(element('span', 'etch-hub-sort-tree-spacer'));
          id.appendChild(element('span', 'etch-hub-sort-sr-only', sprintf(__('Level %d: ', 'etch-content-hub-sort'), depth + 1)));
          id.appendChild(element('span', '', value));
          cell.appendChild(id);
          cell.title = value;
        } else if (key === 'title' && !hierarchy && item.parent) {
          cell.classList.add('etch-hub-sort-child-title');
          cell.appendChild(element('div', '', value));
          cell.appendChild(element('div', 'etch-hub-sort-parent-label', sprintf(__('Child of %s', 'etch-content-hub-sort'), item.parent_title || `#${item.parent}`)));
          cell.title = sprintf(__('%1$s — child of %2$s', 'etch-content-hub-sort'), value, item.parent_title || `#${item.parent}`);
        } else { cell.textContent = value; cell.title = value; }
        row.appendChild(cell);
      }
      const actions = element('td', `etch-hub-sort-actions-cell${separateActions ? ' etch-hub-sort-action-single' : ''}`);
      const actionButtons = element('div', 'etch-hub-sort-action-buttons');
      const trashActions = separateActions ? element('td', 'etch-hub-sort-actions-cell etch-hub-sort-action-single') : null;
      const trashButtons = separateActions ? element('div', 'etch-hub-sort-action-buttons') : null;
      const open = button('↗', async () => {
        open.disabled = true;
        try {
          if (!window.etch?.navigation?.openPostAsync) throw new Error(__('Etch navigation is unavailable.', 'etch-content-hub-sort'));
          await window.etch.navigation.openPostAsync(item.id);
        } catch (error) {
          current.message.textContent = error.message || __('Could not open this item.', 'etch-content-hub-sort');
          open.disabled = false;
        }
      }, 'etch-hub-sort-open');
      open.setAttribute('aria-label', sprintf(__('Open %s in Builder', 'etch-content-hub-sort'), item.title || `#${item.id}`));
      open.title = __('Open in Builder', 'etch-content-hub-sort');
      actionButtons.appendChild(open);
      if (item.can_delete) {
        const trash = button('', async () => {
          const name = item.title || `#${item.id}`;
          if (!window.confirm(sprintf(__('Move “%s” to WordPress Trash?', 'etch-content-hub-sort'), name))) return;
          trash.disabled = true;
          current.message.textContent = __('Moving to Trash…', 'etch-content-hub-sort');
          try {
            const url = new URL(`${config.endpoint}/${item.id}`);
            url.searchParams.set('type', current.type);
            const response = await fetch(url, {
              method: 'DELETE',
              credentials: 'same-origin',
              headers: { 'X-WP-Nonce': config.nonce },
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || __('Could not move this item to Trash.', 'etch-content-hub-sort'));
            if (view !== current) return;
            if (depth === 0 && data.items?.length === 1 && state.page > 1) state.page--;
            current.focusSearch = true;
            load(current);
          } catch (error) {
            trash.disabled = false;
            current.message.textContent = error.message || __('Could not move this item to Trash.', 'etch-content-hub-sort');
          }
        }, 'etch-hub-sort-trash');
        const trashIcon = element('img', 'etch-hub-sort-trash-icon');
        trashIcon.src = `${config.assetUrl}etch-trash.svg`;
        trashIcon.alt = '';
        trash.appendChild(trashIcon);
        trash.setAttribute('aria-label', sprintf(__('Move %s to Trash', 'etch-content-hub-sort'), item.title || `#${item.id}`));
        trash.title = __('Move to WordPress Trash', 'etch-content-hub-sort');
        (trashButtons || actionButtons).appendChild(trash);
      }
      actions.appendChild(actionButtons);
      if (separateActions) {
        trashActions.appendChild(trashButtons);
        if (openStart) row.prepend(actions);
        else row.querySelector('.etch-hub-sort-cell-title').after(actions);
        row.appendChild(trashActions);
      } else if (actionsFirst) row.prepend(actions);
      else row.appendChild(actions);
      return row;
    };
    for (const item of data.items) tbody.appendChild(createRow(item));
    current.openAll = async () => {
      if (!hierarchy || current.bulkOpening) return;
      const actionId = ++current.treeActionId;
      const countMessage = current.message.textContent;
      current.bulkOpening = true;
      current.message.textContent = __('Opening all branches on this page…', 'etch-content-hub-sort');
      updateBulkButton();
      try {
        let target;
        while (actionId === current.treeActionId && view === current && current.data === data
          && (target = tbody.querySelector('.etch-hub-sort-expand[aria-expanded="false"], .etch-hub-sort-load-more'))) {
          const row = target.closest('tr');
          target.click();
          if (false === await row.etchHubChildrenPromise) return;
        }
        if (actionId === current.treeActionId) current.message.textContent = countMessage;
      } finally {
        if (actionId === current.treeActionId) {
          current.bulkOpening = false;
          updateBulkButton();
        }
      }
    };
    current.closeAll = () => {
      if (!hierarchy) return;
      ++current.treeActionId;
      current.bulkOpening = false;
      for (const row of tbody.querySelectorAll('tr[data-depth="0"]')) {
        const expand = row.querySelector('.etch-hub-sort-expand[aria-expanded="true"]');
        expand?.click();
      }
      current.message.textContent = sprintf(__('Showing %1$d–%2$d of %3$d top-level items', 'etch-content-hub-sort'), start, end, data.total);
      updateBulkButton();
    };
    updateBulkButton();
    if (!data.items.length) {
      const row = element('tr');
      const cell = element('td', 'etch-hub-sort-empty', __('No matching items.', 'etch-content-hub-sort'));
      cell.colSpan = columnCount;
      row.appendChild(cell);
      tbody.appendChild(row);
    }
    table.appendChild(tbody);
    scroller.appendChild(table);
    results.appendChild(scroller);

    const paging = element('div', 'etch-hub-sort-paging');
    const previous = button(__('Previous', 'etch-content-hub-sort'), () => { state.page--; load(current); });
    previous.disabled = data.page <= 1;
    const next = button(__('Next', 'etch-content-hub-sort'), () => { state.page++; load(current); });
    next.disabled = data.page >= data.pages;
    paging.append(previous, element('span', '', sprintf(__('Page %1$d of %2$d', 'etch-content-hub-sort'), data.page, Math.max(1, data.pages))), next);
    results.appendChild(paging);
    current.data = data;
    if (current.focusSort) {
      results.querySelector(`[data-sort="${current.focusSort}"]`)?.focus();
      current.focusSort = null;
    } else if (current.focusSearch) {
      current.search.focus();
      current.focusSearch = false;
    }
  }

  async function load(current) {
    if (current.state.native) return;
    const { state } = current;
    const requestId = ++state.requestId;
    const url = new URL(config.endpoint);
    for (const [key, value] of Object.entries({
      type: current.type, search: state.search, sort: state.sort,
      order: state.order, page: state.page, per_page: state.perPage,
      hierarchy: state.hierarchy && !state.search.trim(), parent: 0,
    })) url.searchParams.set(key, value);
    current.message.textContent = __('Loading…', 'etch-content-hub-sort');
    current.results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
      const data = await response.json();
      if (requestId !== state.requestId || view !== current) return;
      if (!response.ok) throw new Error(data.message || __('Could not load items.', 'etch-content-hub-sort'));
      renderRows(current, data);
    } catch (error) {
      if (requestId !== state.requestId || view !== current) return;
      current.message.textContent = error.message || __('Could not load items.', 'etch-content-hub-sort');
      current.results.setAttribute('aria-busy', 'false');
    }
  }

  function mount(table, type) {
    const state = stateFor(type);
    const root = element('section', 'etch-hub-sort');
    root.setAttribute('aria-label', __('Content Hub search and sorting', 'etch-content-hub-sort'));
    const bar = element('div', 'etch-hub-sort-bar');
    const controls = element('div', 'etch-hub-sort-controls');
    const search = element('input', 'etch-hub-sort-search');
    search.type = 'search';
    search.placeholder = __('Search items…', 'etch-content-hub-sort');
    search.setAttribute('aria-label', __('Search this content type', 'etch-content-hub-sort'));
    search.value = state.search;
    const size = element('select', 'etch-hub-sort-size');
    size.setAttribute('aria-label', __('Items per page', 'etch-content-hub-sort'));
    for (const amount of [25, 50, 100]) {
      const option = element('option', '', sprintf(__('%d per page', 'etch-content-hub-sort'), amount));
      option.value = String(amount);
      size.appendChild(option);
    }
    size.value = String(state.perPage);
    const message = element('span', 'etch-hub-sort-message');
    message.setAttribute('role', 'status');
    controls.append(search, message, size);
    const supportsHierarchy = (config.hierarchicalTypes || []).includes(type);
    const hierarchyTools = supportsHierarchy ? element('div', 'etch-hub-sort-hierarchy-tools') : null;
    const hierarchyButton = supportsHierarchy ? button(__('Hierarchy', 'etch-content-hub-sort'), () => {
      state.hierarchy = !state.hierarchy;
      state.page = 1;
      syncHierarchy();
      load(current);
    }, 'etch-hub-sort-hierarchy') : null;
    const bulkButton = supportsHierarchy ? button(__('Open all', 'etch-content-hub-sort'), () => {
      if (current.bulkMode === 'close') current.closeAll?.();
      else current.openAll?.();
    }, 'etch-hub-sort-bulk') : null;
    const syncHierarchy = () => {
      if (!hierarchyButton) return;
      hierarchyButton.setAttribute('aria-pressed', String(state.hierarchy));
      hierarchyButton.disabled = !!state.search.trim();
      hierarchyButton.title = state.search.trim()
        ? __('Search results appear as a flat list. Clear search to browse the hierarchy.', 'etch-content-hub-sort')
        : __('Show parent and child items in a tree', 'etch-content-hub-sort');
      const showBulk = state.hierarchy && !state.search.trim();
      bulkButton.hidden = !showBulk;
      if (!showBulk) bulkButton.disabled = true;
    };
    syncHierarchy();
    const switchButton = button('', () => {
      state.native = !state.native;
      syncMode(current);
      if (!state.native) load(current);
    }, 'etch-hub-sort-switch');
    bar.append(controls);
    if (hierarchyTools) {
      bulkButton.title = __('Expand every branch on this page', 'etch-content-hub-sort');
      bulkButton.disabled = true;
      hierarchyTools.append(hierarchyButton, bulkButton);
      bar.appendChild(hierarchyTools);
    }
    bar.appendChild(switchButton);
    const results = element('div', 'etch-hub-sort-results');
    root.append(bar, results);
    table.before(root);
    const current = { table, type, state, root, controls, hierarchyTools, bulkButton, switchButton, message, results, search };
    view = current;
    syncMode(current);
    if (!state.native) load(current);

    let searchTimer;
    search.addEventListener('input', () => {
      state.search = search.value;
      state.page = 1;
      syncHierarchy();
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => load(current), 250);
    });
    size.addEventListener('change', () => {
      state.perPage = Number(size.value);
      state.page = 1;
      load(current);
    });
  }

  function refresh() {
    mountSettingsTab();
    const type = selectedType();
    const table = document.querySelector('.content-hub__content .datagrid-wrapper');
    if (!config.enabled || !table || !type) {
      if (view) {
        view.table.hidden = false;
        view.root.remove();
        view = null;
      }
      return;
    }
    const headers = Array.from(table.querySelectorAll('.datagrid-header > .datagrid-cell'));
    if (headers.length !== 7) return;
    if (view?.table === table && view.type === type) return;
    if (view) {
      view.table.hidden = false;
      view.root.remove();
      view = null;
    }
    mount(table, type);
  }

  function mountSettingsTab() {
    if (!config.canManage) return;
    const tabs = document.querySelector('.etch-settings-tabs');
    const settingsPanel = document.querySelector('.etch-settings-panel');
    const nativeContent = settingsPanel?.querySelector('.etch-settings-content.svelte-wgfud9');
    const nativeButtons = settingsPanel?.querySelector('.etch-settings-buttons.svelte-wgfud9');
    if (!tabs || !settingsPanel || !nativeContent || !nativeButtons || tabs.querySelector('.etch-hub-sort-settings-tab')) return;

    const tab = element('button', 'etch-settings-tab svelte-wgfud9 etch-hub-sort-settings-tab');
    tab.type = 'button';
    const tabIcon = element('img', 'etch-hub-sort-settings-icon');
    tabIcon.src = `${config.assetUrl}${sortIcons.none}`;
    tabIcon.alt = '';
    tab.append(tabIcon, element('span', 'etch-hub-sort-settings-label', __('Hub search and sort', 'etch-content-hub-sort')));

    const content = element('div', 'etch-settings-content etch-hub-sort-settings-panel');
    content.style.display = 'none';

    const addSetting = ({ label, ariaLabel, description, key, configKey }) => {
      const row = element('div', 'settings-toggle svelte-2pz811 etch-hub-sort-setting');
      const wrapper = element('div', 'settings-toggle-input-wrapper svelte-2pz811');
      const name = element('span', 'settings-toggle-name svelte-2pz811', label);
      const toggle = element('button', 'etch-builder-switch__root');
      toggle.type = 'button';
      toggle.setAttribute('role', 'switch');
      toggle.setAttribute('aria-label', ariaLabel);
      const thumb = element('span', 'etch-builder-switch__thumb');
      toggle.appendChild(thumb);
      const help = element('p', 'settings-toggle-description svelte-2pz811', description);
      const status = element('span', 'etch-hub-sort-setting-status');
      status.setAttribute('role', 'status');
      const paint = () => {
        const state = config[configKey] ? 'checked' : 'unchecked';
        toggle.dataset.state = state;
        toggle.setAttribute('aria-checked', String(config[configKey]));
        thumb.dataset.state = state;
      };
      paint();
      toggle.addEventListener('click', async () => {
        toggle.disabled = true;
        status.textContent = __('Saving…', 'etch-content-hub-sort');
        try {
          const response = await fetch(config.settingsEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
            body: JSON.stringify({ [key]: !config[configKey] }),
          });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || __('Could not save setting.', 'etch-content-hub-sort'));
          config.enabled = !!data.enabled;
          config.actionsLayout = data.actions_layout;
          paint();
          status.textContent = __('Saved', 'etch-content-hub-sort');
          if (key === 'enabled') refresh();
          else if (view?.data && !view.state.native) renderRows(view, view.data);
        } catch (error) {
          status.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
        } finally {
          toggle.disabled = false;
        }
      });
      wrapper.append(name, toggle);
      row.append(wrapper, help, status);
      content.appendChild(row);
    };
    addSetting({
      label: __('Enable search and sorting', 'etch-content-hub-sort'),
      ariaLabel: __('Enable Content Hub search and sorting', 'etch-content-hub-sort'),
      description: __('Search, sort, and page through all content. Changes save immediately.', 'etch-content-hub-sort'),
      key: 'enabled', configKey: 'enabled',
    });
    const placement = element('div', 'settings-toggle svelte-2pz811 etch-hub-sort-setting');
    const placementWrapper = element('div', 'settings-toggle-input-wrapper svelte-2pz811');
    const placementLabel = element('span', 'settings-toggle-name svelte-2pz811', __('Actions placement', 'etch-content-hub-sort'));
    const placementSelect = element('select', 'etch-hub-sort-placement-select');
    placementSelect.setAttribute('aria-label', __('Actions placement', 'etch-content-hub-sort'));
    for (const [value, label] of [
      ['end', __('Both at end', 'etch-content-hub-sort')],
      ['start', __('Both at start', 'etch-content-hub-sort')],
      ['split', __('Open after Title, Trash last', 'etch-content-hub-sort')],
      ['open_start', __('Open first, Trash last', 'etch-content-hub-sort')],
    ]) {
      const option = element('option', '', label);
      option.value = value;
      placementSelect.appendChild(option);
    }
    placementSelect.value = config.actionsLayout;
    const placementHelp = element('p', 'settings-toggle-description svelte-2pz811', __('Choose where the Open and Trash buttons appear in the search and sort table.', 'etch-content-hub-sort'));
    const placementStatus = element('span', 'etch-hub-sort-setting-status');
    placementStatus.setAttribute('role', 'status');
    placementSelect.addEventListener('change', async () => {
      placementSelect.disabled = true;
      placementStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const response = await fetch(config.settingsEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify({ actions_layout: placementSelect.value }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || __('Could not save setting.', 'etch-content-hub-sort'));
        config.actionsLayout = data.actions_layout;
        placementSelect.value = config.actionsLayout;
        placementStatus.textContent = __('Saved', 'etch-content-hub-sort');
        if (view?.data && !view.state.native) renderRows(view, view.data);
      } catch (error) {
        placementSelect.value = config.actionsLayout;
        placementStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      } finally {
        placementSelect.disabled = false;
      }
    });
    placementWrapper.append(placementLabel, placementSelect);
    placement.append(placementWrapper, placementHelp, placementStatus);
    content.appendChild(placement);

    const footer = element('div', 'etch-settings-buttons svelte-wgfud9 etch-hub-sort-settings-buttons');
    footer.style.display = 'none';
    const close = element('button', 'etch-builder-button etch-builder-button--icon-placement-before etch-builder-button--variant-ghost', __('Close', 'etch-content-hub-sort'));
    close.type = 'button';
    close.addEventListener('click', () => nativeButtons.querySelector('button')?.click());
    footer.appendChild(close);

    const deactivate = selectedTab => {
      settingsPanel.classList.remove('etch-hub-sort-settings-active');
      tab.classList.remove('etch-settings-tab--active');
      content.style.display = 'none';
      footer.style.display = 'none';
      nativeButtons.style.display = '';
      nativeContent.style.display = '';
      selectedTab.classList.add('etch-settings-tab--active');
    };
    tabs.addEventListener('click', event => {
      const selectedTab = event.target.closest('.etch-settings-tab');
      if (selectedTab && selectedTab !== tab) deactivate(selectedTab);
    }, true);
    tab.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      settingsPanel.classList.add('etch-hub-sort-settings-active');
      for (const item of tabs.querySelectorAll('.etch-settings-tab')) item.classList.remove('etch-settings-tab--active');
      tab.classList.add('etch-settings-tab--active');
      for (const item of settingsPanel.querySelectorAll('.etch-settings-content')) item.style.display = 'none';
      content.style.display = '';
      nativeButtons.style.display = 'none';
      footer.style.display = '';
    });
    tabs.appendChild(tab);
    settingsPanel.insertBefore(content, nativeButtons);
    settingsPanel.appendChild(footer);
  }

  function scheduleRefresh() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(() => { scheduled = false; refresh(); }, 60);
  }
  function start() {
    refresh();
    new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
