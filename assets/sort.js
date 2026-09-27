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
    current.switchButton.textContent = current.state.native ? __('Browse all', 'etch-content-hub-sort') : __('Etch table', 'etch-content-hub-sort');
    current.switchButton.title = current.state.native
      ? __('Search, sort, and page through all items', 'etch-content-hub-sort')
      : __('Use Etch’s original table for inline editing and creating items', 'etch-content-hub-sort');
  }

  function renderRows(current, data) {
    const { state, results } = current;
    results.replaceChildren();
    const start = data.total ? (data.page - 1) * data.per_page + 1 : 0;
    const end = Math.min(data.page * data.per_page, data.total);
    current.message.textContent = sprintf(__('Showing %1$d–%2$d of %3$d', 'etch-content-hub-sort'), start, end, data.total);
    results.setAttribute('aria-busy', 'false');

    const scroller = element('div', 'etch-hub-sort-scroller');
    const table = element('table', 'etch-hub-sort-table');
    const thead = element('thead');
    const headings = element('tr');
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
    }
    const actionsHeading = element('th', '', __('Actions', 'etch-content-hub-sort'));
    actionsHeading.scope = 'col';
    headings.appendChild(actionsHeading);
    thead.appendChild(headings);
    table.appendChild(thead);

    const tbody = element('tbody');
    for (const item of data.items) {
      const row = element('tr');
      for (const [key] of columns) {
        const value = key === 'id' ? `#${item.id}`
          : key === 'created' || key === 'modified' ? formatDate(item[key])
          : item[key] || '';
        const cell = element('td', `etch-hub-sort-cell-${key}`);
        if (key === 'status') cell.appendChild(element('span', `etch-hub-sort-status etch-hub-sort-status-${item.status}`, value));
        else { cell.textContent = value; cell.title = value; }
        row.appendChild(cell);
      }
      const actions = element('td');
      const actionButtons = element('div', 'etch-hub-sort-action-buttons');
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
            if (data.items?.length === 1 && state.page > 1) state.page--;
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
        actionButtons.appendChild(trash);
      }
      actions.appendChild(actionButtons);
      row.appendChild(actions);
      tbody.appendChild(row);
    }
    if (!data.items.length) {
      const row = element('tr');
      const cell = element('td', 'etch-hub-sort-empty', __('No matching items.', 'etch-content-hub-sort'));
      cell.colSpan = columns.length + 1;
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
    const switchButton = button('', () => {
      state.native = !state.native;
      syncMode(current);
      if (!state.native) load(current);
    }, 'etch-hub-sort-switch');
    bar.append(controls, switchButton);
    const results = element('div', 'etch-hub-sort-results');
    root.append(bar, results);
    table.before(root);
    const current = { table, type, state, root, controls, switchButton, message, results, search };
    view = current;
    syncMode(current);
    if (!state.native) load(current);

    let searchTimer;
    search.addEventListener('input', () => {
      state.search = search.value;
      state.page = 1;
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
    tab.append(tabIcon, document.createTextNode(__('Hub search and sort', 'etch-content-hub-sort')));

    const content = element('div', 'etch-settings-content etch-hub-sort-settings-panel');
    content.style.display = 'none';

    const row = element('div', 'settings-toggle svelte-2pz811 etch-hub-sort-setting');
    const wrapper = element('div', 'settings-toggle-input-wrapper svelte-2pz811');
    const name = element('span', 'settings-toggle-name svelte-2pz811', __('Enable search and sorting', 'etch-content-hub-sort'));
    const toggle = element('button', 'etch-builder-switch__root');
    toggle.type = 'button';
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('aria-label', __('Enable Content Hub search and sorting', 'etch-content-hub-sort'));
    const thumb = element('span', 'etch-builder-switch__thumb');
    toggle.appendChild(thumb);
    const description = element('p', 'settings-toggle-description svelte-2pz811', __('Search, sort, and page through all content. Changes save immediately.', 'etch-content-hub-sort'));
    const status = element('span', 'etch-hub-sort-setting-status');
    status.setAttribute('role', 'status');
    const paint = () => {
      const state = config.enabled ? 'checked' : 'unchecked';
      toggle.dataset.state = state;
      toggle.setAttribute('aria-checked', String(config.enabled));
      thumb.dataset.state = state;
    };
    paint();
    toggle.addEventListener('click', async () => {
      const enabled = !config.enabled;
      toggle.disabled = true;
      status.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const response = await fetch(config.settingsEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify({ enabled }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || __('Could not save setting.', 'etch-content-hub-sort'));
        config.enabled = !!data.enabled;
        paint();
        status.textContent = __('Saved', 'etch-content-hub-sort');
        refresh();
      } catch (error) {
        status.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      } finally {
        toggle.disabled = false;
      }
    });
    wrapper.append(name, toggle);
    row.append(wrapper, description, status);
    content.appendChild(row);

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
