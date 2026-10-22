(() => {
  'use strict';
  const config = window.etchHubSortConfig;
  if (!config?.endpoint || !config?.nonce) return;
  const { __, sprintf } = window.wp.i18n;
  const statusLabelFor = status => config.statusLabels?.[status] ?? status ?? '';
  const isCustomStatus = status => !(config.nativeStatuses || ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit']).includes(status);
  const applyStatusColors = () => {
    for (const [group, style] of Object.entries(config.statusStyles || {})) {
      for (const [property, value] of Object.entries({ bg: style.background, text: style.text, 'picker-text': style.picker_text })) {
        document.documentElement.style.setProperty(`--etch-status-${group}-${property}`, value);
      }
    }
  };
  applyStatusColors();

  const columns = [
    ['id', __('ID', 'etch-content-hub-sort')], ['title', __('Title', 'etch-content-hub-sort')], ['slug', __('Slug', 'etch-content-hub-sort')],
    ['status', __('Status', 'etch-content-hub-sort')], ['created', __('Created', 'etch-content-hub-sort')], ['modified', __('Last Edited', 'etch-content-hub-sort')],
  ];
  const termColumns = [
    ['id', __('ID', 'etch-content-hub-sort')], ['title', __('Name', 'etch-content-hub-sort')],
    ['slug', __('Slug', 'etch-content-hub-sort')], ['description', __('Description', 'etch-content-hub-sort')],
    ['count', __('Count', 'etch-content-hub-sort')],
  ];
  const states = new Map();
  let sessionNative = config.defaultView === 'etch';
  let view = null;
  let settingsManager = null;
  let scheduled = false;
  let observedSelection = '';
  const sortIcons = {
    asc: 'arrow-up-03-stroke-sharp.svg',
    desc: 'arrow-down-03-stroke-sharp.svg',
    none: 'arrow-data-transfer-vertical-stroke-sharp.svg',
  };
  const contrastRatio = (background, foreground) => {
    const luminance = hex => {
      const channels = [1, 3, 5].map(offset => {
        const value = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
      });
      return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    };
    const a = luminance(background);
    const b = luminance(foreground);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  };
  const saveSettings = async values => {
    const response = await fetch(config.settingsEndpoint, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
      body: JSON.stringify(values),
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || __('Could not save setting.', 'etch-content-hub-sort'));
    return data;
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
  const applyButtonStyle = node => {
    const style = config.buttonStyles?.[node.dataset.buttonGroup];
    if (!style) return;
    node.dataset.display = style.display;
    node.dataset.customColors = String(style.custom_colors);
    if (style.custom_colors) {
      node.style.setProperty('--etch-button-bg', style.background);
      node.style.setProperty('--etch-button-text', style.text);
    } else {
      node.style.removeProperty('--etch-button-bg');
      node.style.removeProperty('--etch-button-text');
    }
  };
  const applyHierarchyStyle = root => {
    const style = config.hierarchyStyle;
    if (!style) return;
    root.dataset.hierarchyStyle = String(style.enabled);
    for (const [key, value] of Object.entries(style)) {
      if (key === 'enabled') continue;
      root.style.setProperty(`--etch-hub-${key.replaceAll('_', '-')}`, key === 'border_width' ? `${value}px` : value);
    }
  };
  const appendNativeIcon = (node, selector) => {
    const draw = () => {
      if (node.querySelector('.etch-hub-sort-native-icon')) return true;
      const source = document.querySelector(selector);
      if (!source) return false;
      const icon = element('span', 'etch-hub-sort-native-icon');
      icon.setAttribute('aria-hidden', 'true');
      icon.appendChild(source.cloneNode(true));
      node.appendChild(icon);
      return true;
    };
    if (draw()) return;
    const observer = new MutationObserver(() => {
      if (draw() || !node.isConnected) observer.disconnect();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    window.setTimeout(() => observer.disconnect(), 10000);
  };
  const nativeActionIcons = new WeakMap();
  const appendNativeActionIcon = (node, table, action) => {
    if (!nativeActionIcons.has(table)) {
      let icons = null;
      const cells = table.querySelectorAll('.datagrid-cell:last-child');
      for (const cell of cells) {
        const buttons = cell.querySelectorAll('button');
        if (buttons.length < 2) continue;
        const trash = buttons[0].querySelector('svg');
        const open = buttons[buttons.length - 1].querySelector('svg');
        if (!trash || !open) continue;
        icons = { trash, open };
        break;
      }
      nativeActionIcons.set(table, icons);
    }
    const svg = nativeActionIcons.get(table)?.[action];
    if (svg) {
      const icon = element('span', 'etch-hub-sort-native-icon');
      icon.setAttribute('aria-hidden', 'true');
      icon.appendChild(svg.cloneNode(true));
      node.appendChild(icon);
    }
  };
  const themedButton = (label, callback, className, group) => {
    const node = button(label, callback, `${className} etch-hub-sort-themed`);
    node.dataset.buttonGroup = group;
    if (label) node.title = label;
    applyButtonStyle(node);
    return node;
  };
  const wordpressEditLink = item => {
    if (!config.showWordPressEdit || !item.can_edit || !item.wordpress_edit_url) return null;
    const link = element('a', 'etch-hub-sort-button etch-hub-sort-themed etch-hub-sort-wordpress', __('Edit in WordPress', 'etch-content-hub-sort'));
    link.href = item.wordpress_edit_url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.dataset.buttonGroup = 'wordpress';
    link.title = __('Edit in WordPress (opens in a new tab)', 'etch-content-hub-sort');
    link.setAttribute('aria-label', sprintf(__('Edit %s in WordPress (opens in a new tab)', 'etch-content-hub-sort'), item.title || `#${item.id}`));
    applyButtonStyle(link);
    return link;
  };
  const repaintButtons = () => {
    if (view) for (const node of view.root.querySelectorAll('.etch-hub-sort-themed')) applyButtonStyle(node);
  };
  const syncSidebarShortcut = () => {
    if (settingsManager?.trigger) settingsManager.trigger.hidden = config.enabled && !config.showSidebarIcon;
  };
  const openSettingsPanel = () => {
    mountSettingsPanel();
    if (!settingsManager) return;
    settingsManager.returnToHub = settingsManager.pendingReturnToHub ?? !!document.querySelector('.content-hub__content');
    settingsManager.pendingReturnToHub = null;
    settingsManager.launch = document.activeElement;
    settingsManager.hubButton = document.querySelector('.settings-bar__section.top > button[title="Content Hub"], .settings-bar__section.top > button[aria-label="Content Hub"]')
      || document.querySelector('.settings-bar__section.top > button');
    settingsManager.panel.hidden = false;
    settingsManager.trigger?.setAttribute('aria-expanded', 'true');
    settingsManager.back.focus();
  };
  const selectedType = () => {
    const selected = document.querySelector('.content-hub__sidebar .content-hub-list__item--selected .content-hub-list__item-button');
    const label = selected?.textContent?.trim();
    if (!label) return null;
    const section = selected.closest('.content-hub-list');
    const sectionLabel = section?.querySelector('.content-hub-list__header')?.textContent?.trim() || '';
    if (sectionLabel === 'Taxonomies' && config.taxonomies?.[label]) return { kind: 'term', type: config.taxonomies[label], label };
    if (config.types[label]) return { kind: 'post', type: config.types[label], label };
    if (config.taxonomies?.[label]) return { kind: 'term', type: config.taxonomies[label], label };
    return null;
  };
  const stateFor = (type, kind) => {
    const key = `${kind}:${type}`;
    if (!states.has(key)) states.set(key, {
      search: '', sort: kind === 'term' ? config.termDefaultColumn : config.defaultColumn,
      order: kind === 'term' ? config.termDefaultOrder : config.defaultOrder,
      sortTouched: false, page: 1,
      perPage: 25, native: sessionNative, requestId: 0,
      hierarchy: (kind === 'term' ? config.hierarchicalTaxonomies : config.hierarchicalTypes || []).includes(type),
    });
    return states.get(key);
  };
  const formatDate = value => {
    if (!value || value.startsWith('0000-00-00')) return '-';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
  };

  function syncMode(current) {
    const nativeLoading = current.state.native && current.state.refreshingNative;
    current.root.classList.toggle('etch-hub-sort-native', current.state.native);
    if (current.kind === 'term') {
      if (current.nativeContainer) current.nativeContainer.hidden = !current.state.native || nativeLoading;
    } else if (current.table) current.table.hidden = !current.state.native || nativeLoading;
    current.results.hidden = current.state.native;
    current.controls.hidden = current.state.native;
    if (current.hierarchyTools) current.hierarchyTools.hidden = current.state.native;
    current.newButton.hidden = current.state.native;
    current.nativeLoading.hidden = !nativeLoading;
    current.switchButton.disabled = nativeLoading;
    const label = nativeLoading
      ? __('Updating Etch table…', 'etch-content-hub-sort')
      : current.state.native ? __('Browse all', 'etch-content-hub-sort') : __('Etch table', 'etch-content-hub-sort');
    current.switchButton.querySelector('.etch-hub-sort-switch-label').textContent = label;
    current.switchButton.setAttribute('aria-label', label);
    current.switchButton.dataset.viewMode = current.state.native ? 'browse' : 'etch';
    current.switchButton.title = current.state.native
      ? __('Search, sort, and page through all items', 'etch-content-hub-sort')
      : __('Use Etch’s original table for inline editing and creating items', 'etch-content-hub-sort');
  }

  function showQuickEdit(current, item = null) {
    const creating = !item;
    const hierarchical = (config.hierarchicalTypes || []).includes(current.type);
    const dialog = element('dialog', 'etch-hub-sort-dialog');
    const form = element('form', 'etch-hub-sort-edit-form');
    const heading = element('h2', '', creating
      ? __('New item', 'etch-content-hub-sort')
      : sprintf(__('Quick edit: %s', 'etch-content-hub-sort'), item.title || `#${item.id}`));
    heading.id = 'etch-hub-sort-edit-heading';
    dialog.setAttribute('aria-labelledby', heading.id);
    const header = element('div', 'etch-hub-sort-dialog-header');
    const description = element('p', '', creating
      ? __('Create an item as a draft or publish it now.', 'etch-content-hub-sort')
      : __('Update this item’s basic details without leaving the Hub.', 'etch-content-hub-sort'));
    header.append(heading, description);
    form.appendChild(header);

    const titleLabel = element('label', '', __('Title', 'etch-content-hub-sort'));
    const titleInput = element('input', 'etch-hub-sort-edit-input');
    titleInput.id = 'etch-hub-sort-edit-title';
    titleLabel.htmlFor = titleInput.id;
    titleInput.type = 'text';
    titleInput.required = creating;
    titleInput.maxLength = 200;
    titleInput.value = item?.edit_title ?? '';
    const titleField = element('div', 'etch-hub-sort-edit-field');
    titleField.append(titleLabel, titleInput);
    form.appendChild(titleField);

    let slugInput;
    let slugEdited = false;
    let autoSlug = false;
    if (!creating) {
      const slugLabel = element('label', '', __('Slug', 'etch-content-hub-sort'));
      slugInput = element('input', 'etch-hub-sort-edit-input');
      slugInput.id = 'etch-hub-sort-edit-slug';
      slugInput.type = 'text';
      slugInput.maxLength = 200;
      slugInput.value = item.slug || '';
      autoSlug = !titleInput.value.trim() && !slugInput.value.trim();
      if (autoSlug) {
        const slugify = value => value.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase()
          .replace(/['’]/g, '').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '');
        titleInput.addEventListener('input', () => {
          if (!slugEdited) slugInput.value = slugify(titleInput.value);
        });
        slugInput.addEventListener('input', () => { slugEdited = true; });
      }
      slugLabel.htmlFor = slugInput.id;
      const slugField = element('div', 'etch-hub-sort-edit-field');
      slugField.append(slugLabel, slugInput);
      form.appendChild(slugField);
    }

    const statusLabel = element('label', '', __('Status', 'etch-content-hub-sort'));
    const statusSelect = element('select', 'etch-hub-sort-edit-input etch-hub-sort-status-select');
    statusSelect.id = 'etch-hub-sort-edit-status';
    statusLabel.htmlFor = statusSelect.id;
    const statusOptions = creating
      ? Object.fromEntries(['draft', 'publish'].map(value => [value, statusLabelFor(value)]))
      : { ...(current.data?.quick_edit_statuses || {}) };
    if (item?.status && !Object.prototype.hasOwnProperty.call(statusOptions, item.status)) statusOptions[item.status] = statusLabelFor(item.status);
    const nativeGroup = element('optgroup');
    nativeGroup.label = __('WordPress', 'etch-content-hub-sort');
    const customGroup = element('optgroup');
    customGroup.label = __('Custom statuses', 'etch-content-hub-sort');
    for (const [value, label] of Object.entries(statusOptions)) {
      const option = element('option', '', label);
      option.value = value;
      if (value === 'publish' && !current.data?.can_publish) option.disabled = true;
      (isCustomStatus(value) ? customGroup : nativeGroup).appendChild(option);
    }
    if (nativeGroup.children.length) statusSelect.appendChild(nativeGroup);
    if (customGroup.children.length) statusSelect.appendChild(customGroup);
    statusSelect.value = item?.status || 'draft';
    const updateStatusAppearance = () => {
      statusSelect.dataset.statusKind = isCustomStatus(statusSelect.value) ? 'custom'
        : statusSelect.value;
    };
    statusSelect.addEventListener('change', updateStatusAppearance);
    updateStatusAppearance();
    const statusField = element('div', 'etch-hub-sort-edit-field');
    statusField.append(statusLabel, statusSelect);
    form.appendChild(statusField);

    let dateInput;
    if (!creating) {
      const dateField = element('div', 'etch-hub-sort-edit-field');
      const dateLabel = element('label', '', __('Date', 'etch-content-hub-sort'));
      dateInput = element('input', 'etch-hub-sort-edit-input');
      dateInput.id = 'etch-hub-sort-edit-date';
      dateInput.type = 'datetime-local';
      dateInput.step = '60';
      dateInput.required = true;
      dateInput.value = item.created?.slice(0, 16).replace(' ', 'T') || '';
      dateLabel.htmlFor = dateInput.id;
      const dateHelp = element('p', 'etch-hub-sort-edit-hint', sprintf(__('Site time: %s', 'etch-content-hub-sort'), config.siteTimezone));
      dateField.append(dateLabel, dateInput, dateHelp);
      form.appendChild(dateField);
    }

    let parentList;
    let parentSearch;
    let parentMessage;
    let searchParents;
    let parentId = item?.parent || 0;
    let parentName = item?.parent_title || '';
    if (hierarchical) {
      const parentLabel = element('span', 'etch-hub-sort-parent-label-heading', __('Parent', 'etch-content-hub-sort'));
      parentLabel.id = 'etch-hub-sort-edit-parent-label';
      parentList = element('div', 'etch-hub-sort-parent-list');
      parentList.setAttribute('role', 'radiogroup');
      parentList.setAttribute('aria-labelledby', parentLabel.id);
      const searchLabel = element('label', '', __('Find a parent', 'etch-content-hub-sort'));
      parentSearch = element('input', 'etch-hub-sort-edit-input');
      parentSearch.id = 'etch-hub-sort-parent-search';
      parentSearch.type = 'search';
      parentSearch.placeholder = __('Search by title…', 'etch-content-hub-sort');
      searchLabel.htmlFor = parentSearch.id;
      parentMessage = element('p', 'etch-hub-sort-parent-message');
      parentMessage.setAttribute('role', 'status');
      const parentField = element('div', 'etch-hub-sort-edit-field etch-hub-sort-parent-field');
      parentField.append(parentLabel, searchLabel, parentSearch, parentList, parentMessage);
      form.appendChild(parentField);
      const parentChoice = (id, title) => {
        const choice = element('label', 'etch-hub-sort-parent-choice');
        const radio = element('input');
        radio.type = 'radio';
        radio.name = 'etch-hub-sort-parent';
        radio.value = String(id);
        radio.checked = id === parentId;
        const text = element('span', '', title);
        choice.append(radio, text);
        radio.addEventListener('change', () => {
          if (!radio.checked) return;
          parentId = id;
          parentName = title;
        });
        return choice;
      };
      let searchTimer;
      let parentRequestId = 0;
      searchParents = async () => {
        const requestId = ++parentRequestId;
        parentMessage.textContent = __('Loading parents…', 'etch-content-hub-sort');
        const url = new URL(config.endpoint);
        for (const [key, value] of Object.entries({
          type: current.type, search: parentSearch.value.trim(), sort: 'title', order: 'asc',
          page: 1, per_page: 50, hierarchy: false,
        })) url.searchParams.set(key, value);
        try {
          const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || __('Could not load parents.', 'etch-content-hub-sort'));
          if (requestId !== parentRequestId || !dialog.isConnected) return;
          parentList.replaceChildren();
          parentList.appendChild(parentChoice(0, __('No parent', 'etch-content-hub-sort')));
          if (parentId && !data.items.some(candidate => candidate.id === parentId)) {
            parentList.appendChild(parentChoice(parentId, parentName || `#${parentId}`));
          }
          let matches = 0;
          for (const candidate of data.items) {
            if (candidate.id === item?.id || candidate.ancestors?.includes(item?.id)) continue;
            if (!candidate.can_edit && candidate.id !== parentId) continue;
            parentList.appendChild(parentChoice(candidate.id, candidate.title ? `${candidate.title} (#${candidate.id})` : `#${candidate.id}`));
            matches += 1;
          }
          parentMessage.textContent = data.pages > 1
            ? __('Showing the first 50 matches. Search to narrow the list.', 'etch-content-hub-sort')
            : matches ? '' : __('No matching parents.', 'etch-content-hub-sort');
        } catch (error) {
          if (requestId === parentRequestId && dialog.isConnected) parentMessage.textContent = error.message || __('Could not load parents.', 'etch-content-hub-sort');
        }
      };
      parentSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(searchParents, 250);
      });
    }

    const message = element('p', 'etch-hub-sort-edit-message');
    message.setAttribute('role', 'status');
    const actions = element('div', 'etch-hub-sort-dialog-actions');
    const cancel = button(__('Cancel', 'etch-content-hub-sort'), () => dialog.close());
    const save = element('button', 'etch-hub-sort-button', creating ? __('Create', 'etch-content-hub-sort') : __('Save', 'etch-content-hub-sort'));
    save.type = 'submit';
    actions.append(cancel, save);
    const body = element('div', 'etch-hub-sort-dialog-body');
    header.remove();
    body.append(...form.children);
    const footer = element('div', 'etch-hub-sort-dialog-footer');
    footer.append(message, actions);
    form.append(header, body, footer);
    dialog.appendChild(form);
    dialog.addEventListener('close', () => dialog.remove());
    dialog.addEventListener('cancel', event => { if (save.disabled) event.preventDefault(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const title = titleInput.value.trim();
      if (creating && !title) {
        titleInput.focus();
        return;
      }
      save.disabled = true;
      cancel.disabled = true;
      message.textContent = creating ? __('Creating…', 'etch-content-hub-sort') : __('Saving…', 'etch-content-hub-sort');
      try {
        const payload = { type: current.type, status: statusSelect.value, parent: hierarchical ? parentId : 0 };
        if (creating) payload.title = title;
        else {
          if (titleInput.value !== (item.edit_title ?? '')) payload.title = title;
          if ((!autoSlug || slugEdited) && slugInput.value.trim() !== (item.slug || '')) payload.slug = slugInput.value.trim();
          if (dateInput.value !== item.created?.slice(0, 16).replace(' ', 'T')) payload.date = dateInput.value;
        }
        const response = await fetch(creating ? config.createEndpoint : `${config.endpoint}/${item.id}`, {
          method: creating ? 'POST' : 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify(payload),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || __('Could not save the item.', 'etch-content-hub-sort'));
        dialog.close();
        current.state.etchDirty = true;
        if (view !== current) return;
        showSaveNotice(current, creating
          ? sprintf(__('Created “%s” as %s.', 'etch-content-hub-sort'), title, statusSelect.selectedOptions[0].textContent)
          : __('Changes saved.', 'etch-content-hub-sort'), creating ? result.id : item.id);
        load(current);
      } catch (error) {
        message.textContent = error.message || __('Could not save the item.', 'etch-content-hub-sort');
        save.disabled = false;
        cancel.disabled = false;
      }
    });
    current.root.appendChild(dialog);
    dialog.showModal();
    if (searchParents) searchParents();
    titleInput.focus();
  }

  function showTermEdit(current, item = null) {
    const creating = !item;
    const hierarchical = (config.hierarchicalTaxonomies || []).includes(current.type);
    const dialog = element('dialog', 'etch-hub-sort-dialog');
    const form = element('form', 'etch-hub-sort-edit-form');
    const heading = element('h2', '', creating ? __('New term', 'etch-content-hub-sort')
      : sprintf(__('Quick edit: %s', 'etch-content-hub-sort'), item.title));
    heading.id = 'etch-hub-sort-term-heading';
    dialog.setAttribute('aria-labelledby', heading.id);
    const header = element('div', 'etch-hub-sort-dialog-header');
    header.append(heading, element('p', '', creating
      ? __('Add a term to this taxonomy.', 'etch-content-hub-sort')
      : __('Edit this term and its parent.', 'etch-content-hub-sort')));
    form.appendChild(header);
    const field = (label, value, tag = 'input') => {
      const wrapper = element('div', 'etch-hub-sort-edit-field');
      const caption = element('label', '', label);
      const input = element(tag, 'etch-hub-sort-edit-input');
      input.id = `etch-hub-sort-term-${label.toLowerCase().replace(/[^a-z]+/g, '-')}`;
      caption.htmlFor = input.id;
      input.value = value;
      wrapper.append(caption, input);
      form.appendChild(wrapper);
      return input;
    };
    const name = field(__('Name', 'etch-content-hub-sort'), item?.title || '');
    name.required = true;
    name.maxLength = 200;
    const slug = field(__('Slug', 'etch-content-hub-sort'), item?.slug || '');
    slug.maxLength = 200;
    const description = field(__('Description', 'etch-content-hub-sort'), item?.description || '', 'textarea');
    description.rows = 3;

    let parentId = item?.parent || 0;
    let parentName = item?.parent_title || '';
    if (hierarchical) {
      const parentField = element('div', 'etch-hub-sort-edit-field etch-hub-sort-parent-field');
      const parentLabel = element('span', 'etch-hub-sort-parent-label-heading', __('Parent', 'etch-content-hub-sort'));
      parentLabel.id = 'etch-hub-sort-term-parent-label';
      const searchLabel = element('label', '', __('Find a parent', 'etch-content-hub-sort'));
      const parentSearch = element('input', 'etch-hub-sort-edit-input');
      parentSearch.id = 'etch-hub-sort-term-parent-search';
      parentSearch.type = 'search';
      parentSearch.placeholder = __('Search terms…', 'etch-content-hub-sort');
      searchLabel.htmlFor = parentSearch.id;
      const parentList = element('div', 'etch-hub-sort-parent-list');
      parentList.setAttribute('role', 'radiogroup');
      parentList.setAttribute('aria-labelledby', parentLabel.id);
      const parentMessage = element('p', 'etch-hub-sort-parent-message');
      parentMessage.setAttribute('role', 'status');
      parentField.append(parentLabel, searchLabel, parentSearch, parentList, parentMessage);
      form.appendChild(parentField);
      const choice = (id, title) => {
        const label = element('label', 'etch-hub-sort-parent-choice');
        const radio = element('input');
        radio.type = 'radio';
        radio.name = 'etch-hub-sort-term-parent';
        radio.value = String(id);
        radio.checked = id === parentId;
        radio.addEventListener('change', () => {
          if (radio.checked) { parentId = id; parentName = title; }
        });
        label.append(radio, element('span', '', title));
        return label;
      };
      let requestId = 0;
      const searchParents = async () => {
        const id = ++requestId;
        parentMessage.textContent = __('Loading parents…', 'etch-content-hub-sort');
        const url = new URL(config.termsEndpoint);
        for (const [key, value] of Object.entries({ type: current.type, search: parentSearch.value.trim(), sort: 'title', order: 'asc', page: 1, per_page: 50, hierarchy: false, parent_picker: true })) url.searchParams.set(key, value);
        try {
          const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
          const data = await response.json();
          if (!response.ok) throw new Error(data.message || __('Could not load parents.', 'etch-content-hub-sort'));
          if (id !== requestId || !dialog.isConnected) return;
          parentList.replaceChildren(choice(0, __('No parent', 'etch-content-hub-sort')));
          if (parentId && !data.items.some(candidate => candidate.id === parentId)) parentList.appendChild(choice(parentId, parentName || `#${parentId}`));
          let matches = 0;
          for (const candidate of data.items) {
            if (candidate.id === item?.id || candidate.ancestors?.includes(item?.id) || (!candidate.can_edit && candidate.id !== parentId)) continue;
            parentList.appendChild(choice(candidate.id, `${candidate.title} (#${candidate.id})`));
            matches++;
          }
          parentMessage.textContent = data.pages > 1
            ? __('Showing the first 50 matches. Search to narrow the list.', 'etch-content-hub-sort')
            : matches ? '' : __('No matching parents.', 'etch-content-hub-sort');
        } catch (error) {
          if (id === requestId && dialog.isConnected) parentMessage.textContent = error.message || __('Could not load parents.', 'etch-content-hub-sort');
        }
      };
      let timer;
      parentSearch.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(searchParents, 250); });
      dialog.addEventListener('close', () => { clearTimeout(timer); requestId++; });
      // The dialog must be in the document before the parent list loads.
      dialog.etchHubLoadParents = searchParents;
    }

    const message = element('p', 'etch-hub-sort-edit-message');
    message.setAttribute('role', 'status');
    const actions = element('div', 'etch-hub-sort-dialog-actions');
    const cancel = button(__('Cancel', 'etch-content-hub-sort'), () => dialog.close());
    const save = element('button', 'etch-hub-sort-button', creating ? __('Create', 'etch-content-hub-sort') : __('Save', 'etch-content-hub-sort'));
    save.type = 'submit';
    actions.append(cancel, save);
    const body = element('div', 'etch-hub-sort-dialog-body');
    header.remove();
    body.append(...form.children);
    const footer = element('div', 'etch-hub-sort-dialog-footer');
    footer.append(message, actions);
    form.append(header, body, footer);
    dialog.appendChild(form);
    dialog.addEventListener('close', () => dialog.remove());
    dialog.addEventListener('cancel', event => { if (save.disabled) event.preventDefault(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (!name.value.trim()) { name.focus(); return; }
      save.disabled = true;
      cancel.disabled = true;
      message.textContent = creating ? __('Creating…', 'etch-content-hub-sort') : __('Saving…', 'etch-content-hub-sort');
      try {
        const response = await fetch(creating ? config.termCreateEndpoint : `${config.termsEndpoint}/${item.id}`, {
          method: creating ? 'POST' : 'PATCH', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
          body: JSON.stringify({ type: current.type, name: name.value.trim(), slug: slug.value.trim(), description: description.value, parent: hierarchical ? parentId : 0 }),
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || __('Could not save the term.', 'etch-content-hub-sort'));
        dialog.close();
        current.state.etchDirty = true;
        if (view !== current) return;
        showSaveNotice(current, creating
          ? sprintf(__('Created "%s".', 'etch-content-hub-sort'), name.value.trim())
          : __('Term saved.', 'etch-content-hub-sort'), null);
        load(current);
      } catch (error) {
        message.textContent = error.message || __('Could not save the term.', 'etch-content-hub-sort');
        save.disabled = false;
        cancel.disabled = false;
      }
    });
    current.root.appendChild(dialog);
    dialog.showModal();
    dialog.etchHubLoadParents?.();
    name.focus();
  }

  function showSaveNotice(current, message, id) {
    current.state.lastNotice = { message, id };
    const notice = current.notice;
    notice.replaceChildren();
    notice.appendChild(element('span', '', message));
    if (current.kind !== 'term' && id) {
      const open = themedButton(__('Open in Builder', 'etch-content-hub-sort'), async () => {
        open.disabled = true;
        try {
          await window.etch.navigation.openPostAsync(id);
        } catch (error) {
          notice.querySelector('span').textContent = error.message || __('Could not open this item.', 'etch-content-hub-sort');
          open.disabled = false;
        }
      }, '', 'notice');
      appendNativeActionIcon(open, current.table, 'open');
      notice.appendChild(open);
    }
    const dismiss = themedButton(__('Dismiss', 'etch-content-hub-sort'), () => {
      current.state.lastNotice = null;
      notice.hidden = true;
    }, 'etch-hub-sort-notice-dismiss', 'notice');
    dismiss.dataset.noticeAction = 'dismiss';
    notice.appendChild(dismiss);
    notice.hidden = false;
  }

  const sortLoadedItems = (items, key, order) => {
    const direction = order === 'asc' ? 1 : -1;
    const collator = new Intl.Collator(undefined, { sensitivity: 'base' });
    return [...items].sort((a, b) => {
      const left = String(a[key] ?? '');
      const right = String(b[key] ?? '');
      let result = ['id', 'count', 'parent'].includes(key) ? Number(a[key]) - Number(b[key])
        : key === 'created' || key === 'modified' ? (left > right ? 1 : left < right ? -1 : 0)
          : collator.compare(left, right);
      if (result && key === 'status') return result * direction;
      if (key === 'status') {
        result = String(b.created ?? '').localeCompare(String(a.created ?? '')) || b.id - a.id;
        return result;
      }
      return result * direction;
    });
  };

  function sortLocallyOrLoad(current) {
    const { state, data, dataQuery } = current;
    const hierarchy = state.hierarchy && !state.search.trim();
    if (data && dataQuery && current.results.getAttribute('aria-busy') !== 'true'
      && data.page === 1 && state.page === 1 && data.total === data.items.length
      && data.total <= state.perPage && dataQuery.search === state.search
      && dataQuery.perPage === state.perPage && dataQuery.hierarchy === hierarchy) {
      ++state.requestId;
      renderRows(current, { ...data, items: sortLoadedItems(data.items, state.sort, state.order) });
      return;
    }
    load(current);
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
    current.newButton.disabled = !data.can_create;

    const scroller = element('div', 'etch-hub-sort-scroller');
    const table = element('table', 'etch-hub-sort-table');
    const hierarchy = !!data.hierarchy;
    const thead = element('thead');
    const headings = element('tr');
    const isTerm = current.kind === 'term';
    const activeColumns = isTerm
      ? ((config.hierarchicalTaxonomies || []).includes(current.type)
        ? [...termColumns.slice(0, 3), ['parent', __('Parent', 'etch-content-hub-sort')], ...termColumns.slice(3)]
        : termColumns)
      : columns;
    const splitActions = !isTerm && config.actionsLayout === 'split';
    const openStart = !isTerm && config.actionsLayout === 'open_start';
    const separateActions = splitActions || openStart;
    const actionsFirst = !isTerm && config.actionsLayout === 'start';
    const actionHeading = (label, single = false) => {
      const heading = element('th', `etch-hub-sort-actions-cell${single ? ' etch-hub-sort-action-single' : ''}`, label);
      heading.scope = 'col';
      return heading;
    };
    if (actionsFirst) headings.appendChild(actionHeading(__('Actions', 'etch-content-hub-sort')));
    if (openStart) headings.appendChild(actionHeading(__('Open', 'etch-content-hub-sort'), true));
    for (const [key, label] of activeColumns) {
      const active = state.sort === key;
      const th = element('th', active ? 'etch-hub-sort-active-heading' : '');
      th.scope = 'col';
      th.setAttribute('aria-sort', active ? (state.order === 'asc' ? 'ascending' : 'descending') : 'none');
      const heading = button(label, () => {
        state.order = state.sort === key && state.order === 'asc' ? 'desc' : 'asc';
        state.sort = key;
        state.sortTouched = true;
        state.page = 1;
        current.focusSort = key;
        sortLocallyOrLoad(current);
      }, 'etch-hub-sort-heading');
      heading.dataset.sort = key;
      heading.setAttribute('aria-label', active
        ? sprintf(__('Sort by %1$s, %2$s', 'etch-content-hub-sort'), label, state.order === 'asc' ? __('ascending', 'etch-content-hub-sort') : __('descending', 'etch-content-hub-sort'))
        : sprintf(__('Sort by %s', 'etch-content-hub-sort'), label));
      const icon = element('span', `etch-hub-sort-icon etch-hub-sort-icon-${active ? state.order : 'none'}`);
      icon.setAttribute('aria-hidden', 'true');
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
    const columnCount = activeColumns.length + (separateActions ? 2 : 1);
    const updateBulkButton = () => {
      if (!current.bulkButton) return;
      const canExpand = !!tbody.querySelector('.etch-hub-sort-expand[aria-expanded="false"], .etch-hub-sort-load-more');
      const hasExpanded = !!tbody.querySelector('.etch-hub-sort-expand[aria-expanded="true"]');
      const close = current.bulkOpening || (!canExpand && hasExpanded);
      current.bulkMode = close ? 'close' : 'open';
      current.bulkButton.dataset.bulkMode = current.bulkMode;
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
        const url = new URL(isTerm ? config.termsEndpoint : config.endpoint);
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
          cell.appendChild(themedButton(__('Load more children', 'etch-content-hub-sort'), () => {
            moreRow.etchHubChildrenPromise = loadChildren(parentRow, parentItem, page + 1, depth, moreRow);
          }, 'etch-hub-sort-load-more', 'load_more'));
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
              expand.textContent = __('Expand', 'etch-content-hub-sort');
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
      for (const [key] of activeColumns) {
        const value = key === 'id' ? `#${item.id}`
          : key === 'status' ? statusLabelFor(item.status)
          : key === 'parent' ? item.parent_title || (item.parent ? `#${item.parent}` : '—')
          : key === 'created' || key === 'modified' ? formatDate(item[key])
          : item[key] ?? '';
        const cell = element('td', `etch-hub-sort-cell-${key}`);
        if (key === 'status') cell.appendChild(element('span', `etch-hub-sort-status etch-hub-sort-status-${item.status}${isCustomStatus(item.status) ? ' etch-hub-sort-status-custom' : ''}`, value));
        else if (key === 'id' && hierarchy) {
          const id = element('div', 'etch-hub-sort-tree-id');
          id.style.setProperty('--etch-hub-depth', depth);
          if (item.has_children) {
            const expand = themedButton(__('Expand', 'etch-content-hub-sort'), () => {
              const opened = expand.getAttribute('aria-expanded') === 'true';
              expand.setAttribute('aria-expanded', String(!opened));
              expand.textContent = opened ? __('Expand', 'etch-content-hub-sort') : __('Collapse', 'etch-content-hub-sort');
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
            }, 'etch-hub-sort-expand', 'expand');
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
        } else { cell.textContent = value; cell.title = String(value); }
        row.appendChild(cell);
      }
      if (isTerm) {
        if (item.can_edit) {
          const titleCell = row.querySelector('.etch-hub-sort-cell-title');
          const edit = themedButton(__('Quick edit', 'etch-content-hub-sort'), () => showTermEdit(current, item), 'etch-hub-sort-quick-edit', 'quick_edit');
          edit.setAttribute('aria-label', sprintf(__('Quick edit %s', 'etch-content-hub-sort'), item.title));
          (titleCell.firstElementChild || titleCell).appendChild(edit);
        }
        const actions = element('td', 'etch-hub-sort-actions-cell');
        const buttons = element('div', 'etch-hub-sort-action-buttons');
        const wordpress = wordpressEditLink(item);
        if (wordpress) buttons.appendChild(wordpress);
        if (item.can_delete) {
          const remove = themedButton(__('Delete', 'etch-content-hub-sort'), async () => {
            const warning = item.has_children
              ? sprintf(__('Permanently delete "%s"? Its child terms will move up one level. This cannot be undone.', 'etch-content-hub-sort'), item.title)
              : sprintf(__('Permanently delete "%s"? This cannot be undone.', 'etch-content-hub-sort'), item.title);
            if (!window.confirm(warning)) return;
            remove.disabled = true;
            current.message.textContent = __('Deleting…', 'etch-content-hub-sort');
            try {
              const url = new URL(`${config.termsEndpoint}/${item.id}`);
              url.searchParams.set('type', current.type);
              const response = await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
              const result = await response.json();
              if (!response.ok) throw new Error(result.message || __('Could not delete the term.', 'etch-content-hub-sort'));
              state.etchDirty = true;
              if (view !== current) return;
              if (depth === 0 && data.items.length === 1 && state.page > 1) state.page--;
              showSaveNotice(current, sprintf(__('Deleted "%s".', 'etch-content-hub-sort'), item.title), null);
              load(current);
            } catch (error) {
              remove.disabled = false;
              current.message.textContent = error.message || __('Could not delete the term.', 'etch-content-hub-sort');
            }
          }, 'etch-hub-sort-trash', 'trash');
          remove.setAttribute('aria-label', sprintf(__('Permanently delete %s', 'etch-content-hub-sort'), item.title));
          remove.title = __('Permanently delete term', 'etch-content-hub-sort');
          buttons.appendChild(remove);
        }
        actions.appendChild(buttons);
        row.appendChild(actions);
        return row;
      }
      if (item.can_edit) {
        const titleCell = row.querySelector('.etch-hub-sort-cell-title');
        const edit = themedButton(__('Quick edit', 'etch-content-hub-sort'), () => showQuickEdit(current, item), 'etch-hub-sort-quick-edit', 'quick_edit');
        edit.setAttribute('aria-label', sprintf(__('Quick edit %s', 'etch-content-hub-sort'), item.title || `#${item.id}`));
        (titleCell.firstElementChild || titleCell).appendChild(edit);
      }
      const actions = element('td', `etch-hub-sort-actions-cell${separateActions ? ' etch-hub-sort-action-single' : ''}`);
      const actionButtons = element('div', 'etch-hub-sort-action-buttons');
      const trashActions = separateActions ? element('td', 'etch-hub-sort-actions-cell etch-hub-sort-action-single') : null;
      const trashButtons = separateActions ? element('div', 'etch-hub-sort-action-buttons') : null;
      const open = themedButton(__('Open', 'etch-content-hub-sort'), async () => {
        open.disabled = true;
        try {
          if (!window.etch?.navigation?.openPostAsync) throw new Error(__('Etch navigation is unavailable.', 'etch-content-hub-sort'));
          await window.etch.navigation.openPostAsync(item.id);
        } catch (error) {
          current.message.textContent = error.message || __('Could not open this item.', 'etch-content-hub-sort');
          open.disabled = false;
        }
      }, 'etch-hub-sort-open', 'open');
      appendNativeActionIcon(open, current.table, 'open');
      open.setAttribute('aria-label', sprintf(__('Open %s in Builder', 'etch-content-hub-sort'), item.title || `#${item.id}`));
      open.title = __('Open in Builder', 'etch-content-hub-sort');
      actionButtons.appendChild(open);
      const wordpress = wordpressEditLink(item);
      if (wordpress) actionButtons.appendChild(wordpress);
      if (item.can_delete) {
        const trash = themedButton(__('Trash', 'etch-content-hub-sort'), async () => {
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
            state.etchDirty = true;
            if (view !== current) return;
            if (depth === 0 && data.items?.length === 1 && state.page > 1) state.page--;
            current.focusSearch = true;
            load(current);
          } catch (error) {
            trash.disabled = false;
            current.message.textContent = error.message || __('Could not move this item to Trash.', 'etch-content-hub-sort');
          }
        }, 'etch-hub-sort-trash', 'trash');
        appendNativeActionIcon(trash, current.table, 'trash');
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
    const previous = themedButton(__('Previous', 'etch-content-hub-sort'), () => { state.page--; load(current); }, '', 'pagination');
    previous.dataset.pageDirection = 'previous';
    previous.disabled = data.page <= 1;
    const next = themedButton(__('Next', 'etch-content-hub-sort'), () => { state.page++; load(current); }, '', 'pagination');
    next.dataset.pageDirection = 'next';
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
    const dataQuery = { search: state.search, perPage: state.perPage, hierarchy: state.hierarchy && !state.search.trim() };
    const url = new URL(current.kind === 'term' ? config.termsEndpoint : config.endpoint);
    for (const [key, value] of Object.entries({
      type: current.type, search: state.search, sort: state.sort,
      order: state.order, page: state.page, per_page: state.perPage,
      hierarchy: state.hierarchy && !state.search.trim(), parent: 0,
    })) url.searchParams.set(key, value);
    current.message.textContent = current.kind === 'term'
      ? sprintf(__('Loading %s…', 'etch-content-hub-sort'), current.label)
      : __('Loading…', 'etch-content-hub-sort');
    current.results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
      const data = await response.json();
      if (requestId !== state.requestId || view !== current) return;
      if (!response.ok) throw new Error(data.message || __('Could not load items.', 'etch-content-hub-sort'));
      current.dataQuery = dataQuery;
      renderRows(current, data);
    } catch (error) {
      if (requestId !== state.requestId || view !== current) return;
      current.message.textContent = error.message || __('Could not load items.', 'etch-content-hub-sort');
      current.results.setAttribute('aria-busy', 'false');
    }
  }

  function mount(table, type, kind, content, nativeContainer, label) {
    const state = stateFor(type, kind);
    state.native = sessionNative;
    const root = element('section', 'etch-hub-sort');
    root.style.setProperty('--etch-hub-active-bg', config.activeBg);
    root.style.setProperty('--etch-hub-active-text', config.activeText);
    applyHierarchyStyle(root);
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
    const supportsHierarchy = (kind === 'term' ? config.hierarchicalTaxonomies : config.hierarchicalTypes || []).includes(type);
    const hierarchyTools = supportsHierarchy ? element('div', 'etch-hub-sort-hierarchy-tools') : null;
    const hierarchyButton = supportsHierarchy ? themedButton(__('Hierarchy', 'etch-content-hub-sort'), () => {
      state.hierarchy = !state.hierarchy;
      state.page = 1;
      syncHierarchy();
      load(current);
    }, 'etch-hub-sort-hierarchy', 'hierarchy') : null;
    const bulkButton = supportsHierarchy ? themedButton(__('Open all', 'etch-content-hub-sort'), () => {
      if (current.bulkMode === 'close') current.closeAll?.();
      else current.openAll?.();
    }, 'etch-hub-sort-bulk', 'bulk') : null;
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
    const switchButton = themedButton('', async () => {
      if (state.native) {
        state.native = false;
        sessionNative = false;
        syncMode(current);
        load(current);
        return;
      }
      const refresh = window.etch?.navigation?.listPostsAsync;
      if (state.etchDirty && kind === 'post' && typeof refresh !== 'function') {
        current.message.textContent = __('Etch table refresh is unavailable. Browse all is current.', 'etch-content-hub-sort');
        return;
      }
      state.native = true;
      sessionNative = true;
      state.refreshingNative = !!state.etchDirty;
      syncMode(current);
      if (state.refreshingNative) {
        let refreshed = false;
        try {
          if (kind === 'term') {
            const selectedButton = document.querySelector('.content-hub__sidebar .content-hub-list__item--selected .content-hub-list__item-button');
            const otherButton = [...document.querySelectorAll('.content-hub__sidebar .content-hub-list__item:not(.content-hub-list__item--selected) .content-hub-list__item-button')]
              .find(node => node !== selectedButton);
            if (!selectedButton || !otherButton) throw new Error(__('Could not refresh Etch terms.', 'etch-content-hub-sort'));
            otherButton.click();
            await new Promise(resolve => setTimeout(resolve, 100));
            selectedButton.click();
            await new Promise(resolve => setTimeout(resolve, 100));
          } else await refresh(current.type);
          refreshed = true;
          state.etchDirty = false;
        } catch {
          state.native = false;
          sessionNative = false;
        }
        state.refreshingNative = false;
        if (view?.state === state && view.type === current.type) {
          syncMode(view);
          if (!refreshed) view.message.textContent = __('Etch table could not be refreshed. Try again.', 'etch-content-hub-sort');
        }
      }
    }, 'etch-hub-sort-switch', 'view');
    switchButton.appendChild(element('span', 'etch-hub-sort-switch-label'));
    appendNativeIcon(switchButton, '.settings-bar__section.etch-logo svg');
    const newButton = themedButton(kind === 'term' ? __('New term', 'etch-content-hub-sort') : __('New item', 'etch-content-hub-sort'),
      () => kind === 'term' ? showTermEdit(current) : showQuickEdit(current), 'etch-hub-sort-new', 'new');
    const settingsButton = config.canManage ? themedButton(__('Hub settings', 'etch-content-hub-sort'), openSettingsPanel, 'etch-hub-sort-settings-launch', 'settings') : null;
    newButton.disabled = true;
    bar.append(controls);
    if (hierarchyTools) {
      bulkButton.title = __('Expand every branch on this page', 'etch-content-hub-sort');
      bulkButton.disabled = true;
      hierarchyTools.append(hierarchyButton, bulkButton);
    }
    const toolbarActions = element('div', 'etch-hub-sort-toolbar-actions');
    toolbarActions.setAttribute('role', 'group');
    toolbarActions.setAttribute('aria-label', __('Content Hub actions', 'etch-content-hub-sort'));
    if (hierarchyTools) toolbarActions.appendChild(hierarchyTools);
    if (settingsButton) toolbarActions.appendChild(settingsButton);
    toolbarActions.append(switchButton, newButton);
    bar.appendChild(toolbarActions);
    const notice = element('div', 'etch-hub-sort-notice');
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    const nativeLoading = element('div', 'etch-hub-sort-native-loading', __('Updating Etch table…', 'etch-content-hub-sort'));
    nativeLoading.setAttribute('role', 'status');
    nativeLoading.hidden = true;
    const results = element('div', 'etch-hub-sort-results');
    root.append(bar, notice, nativeLoading, results);
    if (kind === 'term') content.insertBefore(root, nativeContainer || content.firstChild);
    else table.before(root);
    const current = { table, nativeContainer, type, kind, label, state, root, controls, hierarchyTools, bulkButton, newButton, switchButton, message, notice, nativeLoading, results, search, syncHierarchy };
    view = current;
    syncMode(current);
    if (state.lastNotice) showSaveNotice(current, state.lastNotice.message, state.lastNotice.id);
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
    mountSettingsPanel();
    const selected = selectedType();
    const content = document.querySelector('.content-hub__content');
    const nativeContainer = selected?.kind === 'term' ? content?.querySelector('.taxonomy-terms-table') : null;
    const table = selected?.kind === 'term'
      ? nativeContainer?.querySelector('.raster-wrapper')
      : content?.querySelector('.datagrid-wrapper');
    if (!config.enabled || !content || !selected || (selected.kind === 'post' && !table)) {
      if (view) {
        if (view.kind === 'term') {
          if (view.nativeContainer) view.nativeContainer.hidden = false;
        } else if (view.table) view.table.hidden = false;
        view.root.remove();
        view = null;
      }
      return;
    }
    if (selected.kind === 'post' && table.querySelectorAll('.datagrid-header > .datagrid-cell').length !== 7) return;
    if (view?.type === selected.type && view.kind === selected.kind && view.root.isConnected) {
      if (view.table !== table || view.nativeContainer !== nativeContainer) {
        view.table = table;
        view.nativeContainer = nativeContainer;
        syncMode(view);
      }
      return;
    }
    if (view) {
      if (view.kind === 'term') {
        if (view.nativeContainer && selected.kind !== 'term') view.nativeContainer.hidden = false;
      } else if (view.table) view.table.hidden = false;
      view.root.remove();
      view = null;
    }
    mount(table, selected.type, selected.kind, content, nativeContainer, selected.label);
  }

  function mountSettingsPanel() {
    if (!config.canManage || settingsManager) return;
    const panel = element('section', 'etch-hub-sort-manager');
    panel.id = 'etch-hub-sort-manager';
    panel.hidden = true;
    panel.setAttribute('aria-label', __('Hub search and sort settings', 'etch-content-hub-sort'));
    const sidebar = element('div', 'etch-hub-sort-manager-sidebar');
    const header = element('div', 'etch-hub-sort-manager-header');
    const back = button(__('Back', 'etch-content-hub-sort'), () => {
      const returnToHub = settingsManager?.returnToHub;
      const hubButton = settingsManager?.hubButton;
      panel.hidden = true;
      settingsManager?.trigger?.setAttribute('aria-expanded', 'false');
      if (returnToHub && !document.querySelector('.content-hub__content')) hubButton?.click();
      if (settingsManager) settingsManager.returnToHub = false;
      const target = settingsManager?.launch?.isConnected && !settingsManager.launch.hidden ? settingsManager.launch : hubButton;
      target?.focus();
    }, 'etch-hub-sort-manager-back');
    const title = element('h1', '', __('Hub search and sort', 'etch-content-hub-sort'));
    header.append(back, title);
    const nav = element('nav', 'etch-hub-sort-manager-nav');
    nav.setAttribute('aria-label', __('Hub settings sections', 'etch-content-hub-sort'));
    const generalTab = button(__('General', 'etch-content-hub-sort'), () => showSection('general'), 'etch-hub-sort-manager-nav-button');
    const hierarchyTab = button(__('Hierarchy', 'etch-content-hub-sort'), () => showSection('hierarchy'), 'etch-hub-sort-manager-nav-button');
    const buttonsTab = button(__('Buttons', 'etch-content-hub-sort'), () => showSection('buttons'), 'etch-hub-sort-manager-nav-button');
    const statusesTab = button(__('Status colors', 'etch-content-hub-sort'), () => showSection('statuses'), 'etch-hub-sort-manager-nav-button');
    nav.append(generalTab, hierarchyTab, buttonsTab, statusesTab);
    sidebar.append(header, nav);
    const body = element('div', 'etch-hub-sort-manager-body');
    const content = element('div', 'etch-hub-sort-settings-panel');
    const hierarchyContent = element('div', 'etch-hub-sort-hierarchy-settings-panel');
    const buttonContent = element('div', 'etch-hub-sort-button-settings-panel');
    const statusContent = element('div', 'etch-hub-sort-button-settings-panel etch-hub-sort-status-settings-panel');
    body.append(content, hierarchyContent, buttonContent, statusContent);
    panel.append(sidebar, body);
    document.body.appendChild(panel);
    const showSection = section => {
      content.hidden = section !== 'general';
      hierarchyContent.hidden = section !== 'hierarchy';
      buttonContent.hidden = section !== 'buttons';
      statusContent.hidden = section !== 'statuses';
      generalTab.setAttribute('aria-current', section === 'general' ? 'page' : 'false');
      hierarchyTab.setAttribute('aria-current', section === 'hierarchy' ? 'page' : 'false');
      buttonsTab.setAttribute('aria-current', section === 'buttons' ? 'page' : 'false');
      statusesTab.setAttribute('aria-current', section === 'statuses' ? 'page' : 'false');
      body.scrollTop = 0;
    };
    settingsManager = { panel, back, launch: null, trigger: null };
    showSection('general');
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); back.click(); }
      if (event.key === 's' && (event.metaKey || event.ctrlKey)) event.stopPropagation();
    });
    panel.addEventListener('keyup', event => event.stopPropagation());
    let attempts = 0;
    const addToSettingsBar = window.setInterval(() => {
      const api = window.etchControls?.builder?.settingsBar?.bottom;
      const bar = document.querySelector('.settings-bar__section.bottom');
      if (!api || !bar?.querySelector('button')) {
        if (++attempts > 120) window.clearInterval(addToSettingsBar);
        return;
      }
      const before = new Set(bar.querySelectorAll('button'));
      api.addBefore({ id: 'etch-hub-sort-settings', icon: 'settings', tooltip: __('Hub search and sort', 'etch-content-hub-sort'), callback: () => panel.hidden ? openSettingsPanel() : back.click() });
      window.clearInterval(addToSettingsBar);
      const observer = new MutationObserver(() => {
        const trigger = [...bar.querySelectorAll('button')].find(node => !before.has(node));
        if (!trigger) return;
        observer.disconnect();
        trigger.setAttribute('aria-label', __('Hub search and sort settings', 'etch-content-hub-sort'));
        trigger.setAttribute('aria-controls', panel.id);
        trigger.setAttribute('aria-expanded', 'false');
        trigger.classList.add('etch-hub-sort-bar-trigger');
        const rememberOrigin = () => {
          settingsManager.pendingReturnToHub = !!document.querySelector('.content-hub__content');
        };
        trigger.addEventListener('pointerdown', rememberOrigin, { capture: true });
        trigger.addEventListener('keydown', event => {
          if (event.key === 'Enter' || event.key === ' ') rememberOrigin();
        }, { capture: true });
        const drawIcon = () => {
          if (trigger.querySelector('.etch-hub-sort-bar-icon')) return;
          const icon = element('img', 'etch-hub-sort-bar-icon');
          icon.src = `${config.assetUrl}add-to-list-stroke-rounded.svg`;
          icon.alt = '';
          trigger.appendChild(icon);
        };
        drawIcon();
        new MutationObserver(drawIcon).observe(trigger, { childList: true, subtree: true });
        settingsManager.trigger = trigger;
        syncSidebarShortcut();
      });
      observer.observe(bar, { childList: true, subtree: true });
    }, 250);

    const addSetting = ({ label, ariaLabel, description, key, configKey }) => {
      const row = element('div', 'settings-toggle svelte-2pz811 etch-hub-sort-setting');
      const wrapper = element('div', 'settings-toggle-input-wrapper svelte-2pz811');
      const name = element('span', 'settings-toggle-name svelte-2pz811', label);
      const toggle = element('button', 'etch-hub-sort-enable-switch');
      toggle.type = 'button';
      toggle.setAttribute('role', 'switch');
      toggle.setAttribute('aria-label', ariaLabel);
      const thumb = element('span', 'etch-hub-sort-enable-switch-thumb');
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
          config[configKey] = !!data[key];
          config.actionsLayout = data.actions_layout;
          paint();
          status.textContent = __('Saved', 'etch-content-hub-sort');
          if (key === 'enabled') refresh();
          if (key === 'enabled' || key === 'show_sidebar_icon') syncSidebarShortcut();
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
      return { row, name, toggle, help, status };
    };
    const enableSetting = addSetting({
      label: __('Enable search and sorting', 'etch-content-hub-sort'),
      ariaLabel: __('Enable Content Hub search and sorting', 'etch-content-hub-sort'),
      description: __('Search, sort, and page through all content. Changes save immediately.', 'etch-content-hub-sort'),
      key: 'enabled', configKey: 'enabled',
    });
    const sidebarSetting = addSetting({
      label: __('Show settings shortcut in sidebar', 'etch-content-hub-sort'),
      ariaLabel: __('Show Hub settings shortcut in the left sidebar', 'etch-content-hub-sort'),
      description: __('Hide this shortcut to leave room for other add-ons. It stays available when search and sorting is off.', 'etch-content-hub-sort'),
      key: 'show_sidebar_icon', configKey: 'showSidebarIcon',
    });
    const wordpressEditSetting = addSetting({
      label: __('Show Edit in WordPress', 'etch-content-hub-sort'),
      ariaLabel: __('Show Edit in WordPress buttons in the Content Hub table', 'etch-content-hub-sort'),
      description: __('Open the WordPress editor and custom fields for items you can edit.', 'etch-content-hub-sort'),
      key: 'show_wordpress_edit', configKey: 'showWordPressEdit',
    });
    const viewRow = element('div', 'etch-hub-sort-setting');
    const viewWrapper = element('div', 'settings-toggle-input-wrapper svelte-2pz811');
    const viewLabel = element('span', 'settings-toggle-name svelte-2pz811', __('Default view', 'etch-content-hub-sort'));
    const viewSelect = element('select', 'etch-hub-sort-placement-select');
    viewSelect.setAttribute('aria-label', __('Default Content Hub view', 'etch-content-hub-sort'));
    for (const [value, label] of [
      ['browse', __('Search and Sort', 'etch-content-hub-sort')],
      ['etch', __('Etch table', 'etch-content-hub-sort')],
    ]) {
      const option = element('option', '', label);
      option.value = value;
      viewSelect.appendChild(option);
    }
    viewSelect.value = config.defaultView;
    const viewHelp = element('p', 'settings-toggle-description svelte-2pz811', __('Used when the builder loads. A view you switch to stays active across content types until you switch back or reload.', 'etch-content-hub-sort'));
    const viewStatus = element('span', 'etch-hub-sort-setting-status');
    viewStatus.setAttribute('role', 'status');
    viewSelect.addEventListener('change', async () => {
      viewSelect.disabled = true;
      viewStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const data = await saveSettings({ default_view: viewSelect.value });
        config.defaultView = data.default_view;
        viewStatus.textContent = __('Saved', 'etch-content-hub-sort');
      } catch (error) {
        viewSelect.value = config.defaultView;
        viewStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      } finally {
        viewSelect.disabled = false;
      }
    });
    viewWrapper.append(viewLabel, viewSelect);
    viewRow.append(viewWrapper, viewHelp, viewStatus);
    content.appendChild(viewRow);
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

    const defaultRow = element('div', 'etch-hub-sort-setting etch-hub-sort-default-setting');
    const defaultLabel = element('span', 'settings-toggle-name svelte-2pz811', __('Initial sorting', 'etch-content-hub-sort'));
    const defaultControls = element('div', 'etch-hub-sort-default-controls');
    const columnSelect = element('select', 'etch-hub-sort-placement-select');
    columnSelect.setAttribute('aria-label', __('Default sort column', 'etch-content-hub-sort'));
    for (const [key, label] of columns) {
      const option = element('option', '', label);
      option.value = key;
      columnSelect.appendChild(option);
    }
    columnSelect.value = config.defaultColumn;
    const orderSelect = element('select', 'etch-hub-sort-placement-select');
    orderSelect.setAttribute('aria-label', __('Default sort direction', 'etch-content-hub-sort'));
    for (const [key, label] of [
      ['asc', __('Ascending', 'etch-content-hub-sort')],
      ['desc', __('Descending', 'etch-content-hub-sort')],
    ]) {
      const option = element('option', '', label);
      option.value = key;
      orderSelect.appendChild(option);
    }
    orderSelect.value = config.defaultOrder;
    const defaultHelp = element('p', 'settings-toggle-description svelte-2pz811', __('Used when a content type is first opened. Clicking a column header still sorts that view.', 'etch-content-hub-sort'));
    const defaultStatus = element('span', 'etch-hub-sort-setting-status');
    defaultStatus.setAttribute('role', 'status');
    const saveDefault = async () => {
      columnSelect.disabled = orderSelect.disabled = true;
      defaultStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const data = await saveSettings({ default_column: columnSelect.value, default_order: orderSelect.value });
        config.defaultColumn = data.default_column;
        config.defaultOrder = data.default_order;
        for (const [key, state] of states) {
          if (!key.startsWith('post:') || state.sortTouched) continue;
          state.sort = config.defaultColumn;
          state.order = config.defaultOrder;
          state.page = 1;
        }
        if (view?.kind === 'post' && !view.state.sortTouched && !view.state.native) sortLocallyOrLoad(view);
        defaultStatus.textContent = __('Saved', 'etch-content-hub-sort');
      } catch (error) {
        columnSelect.value = config.defaultColumn;
        orderSelect.value = config.defaultOrder;
        defaultStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      } finally {
        columnSelect.disabled = orderSelect.disabled = false;
      }
    };
    columnSelect.addEventListener('change', saveDefault);
    orderSelect.addEventListener('change', saveDefault);
    defaultControls.append(columnSelect, orderSelect);
    defaultRow.append(defaultLabel, defaultControls, defaultHelp, defaultStatus);
    content.appendChild(defaultRow);

    const termDefaultRow = element('div', 'etch-hub-sort-setting etch-hub-sort-default-setting');
    const termDefaultLabel = element('span', 'settings-toggle-name svelte-2pz811', __('Initial taxonomy sorting', 'etch-content-hub-sort'));
    const termDefaultControls = element('div', 'etch-hub-sort-default-controls');
    const termColumnSelect = element('select', 'etch-hub-sort-placement-select');
    termColumnSelect.setAttribute('aria-label', __('Default taxonomy sort column', 'etch-content-hub-sort'));
    for (const [key, label] of termColumns) {
      const option = element('option', '', label);
      option.value = key;
      termColumnSelect.appendChild(option);
    }
    termColumnSelect.value = config.termDefaultColumn;
    const termOrderSelect = element('select', 'etch-hub-sort-placement-select');
    termOrderSelect.setAttribute('aria-label', __('Default taxonomy sort direction', 'etch-content-hub-sort'));
    for (const [key, label] of [['asc', __('Ascending', 'etch-content-hub-sort')], ['desc', __('Descending', 'etch-content-hub-sort')]]) {
      const option = element('option', '', label);
      option.value = key;
      termOrderSelect.appendChild(option);
    }
    termOrderSelect.value = config.termDefaultOrder;
    const termDefaultHelp = element('p', 'settings-toggle-description svelte-2pz811', __('Used when a taxonomy is first opened. Its column headings remain sortable.', 'etch-content-hub-sort'));
    const termDefaultStatus = element('span', 'etch-hub-sort-setting-status');
    termDefaultStatus.setAttribute('role', 'status');
    const saveTermDefault = async () => {
      termColumnSelect.disabled = termOrderSelect.disabled = true;
      termDefaultStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const data = await saveSettings({ term_default_column: termColumnSelect.value, term_default_order: termOrderSelect.value });
        config.termDefaultColumn = data.term_default_column;
        config.termDefaultOrder = data.term_default_order;
        for (const [key, state] of states) {
          if (!key.startsWith('term:') || state.sortTouched) continue;
          state.sort = config.termDefaultColumn;
          state.order = config.termDefaultOrder;
          state.page = 1;
        }
        if (view?.kind === 'term' && !view.state.sortTouched && !view.state.native) sortLocallyOrLoad(view);
        termDefaultStatus.textContent = __('Saved', 'etch-content-hub-sort');
      } catch (error) {
        termColumnSelect.value = config.termDefaultColumn;
        termOrderSelect.value = config.termDefaultOrder;
        termDefaultStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      } finally {
        termColumnSelect.disabled = termOrderSelect.disabled = false;
      }
    };
    termColumnSelect.addEventListener('change', saveTermDefault);
    termOrderSelect.addEventListener('change', saveTermDefault);
    termDefaultControls.append(termColumnSelect, termOrderSelect);
    termDefaultRow.append(termDefaultLabel, termDefaultControls, termDefaultHelp, termDefaultStatus);
    content.appendChild(termDefaultRow);

    const colorRow = element('div', 'etch-hub-sort-setting etch-hub-sort-color-setting');
    const colorHeading = element('span', 'settings-toggle-name svelte-2pz811', __('Active sort heading colors', 'etch-content-hub-sort'));
    const colorControls = element('div', 'etch-hub-sort-color-controls');
    const colorField = (id, label, value) => {
      const field = element('label', 'etch-hub-sort-color-field');
      const name = element('span', '', label);
      const input = element('input', 'etch-hub-sort-color-input');
      input.type = 'color';
      input.id = id;
      input.value = value;
      const code = element('span', 'etch-hub-sort-color-code', value);
      input.addEventListener('input', () => { code.textContent = input.value; updateColorPreview(); });
      field.append(name, input, code);
      return { field, input };
    };
    const background = colorField('etch-hub-active-bg', __('Background', 'etch-content-hub-sort'), config.activeBg);
    const foreground = colorField('etch-hub-active-text', __('Text', 'etch-content-hub-sort'), config.activeText);
    const preview = element('div', 'etch-hub-sort-color-preview', __('TITLE ↑', 'etch-content-hub-sort'));
    const colorHelp = element('p', 'settings-toggle-description svelte-2pz811', __('Colors apply to the sorted column heading. Text contrast must be at least 4.5:1.', 'etch-content-hub-sort'));
    const contrastStatus = element('span', 'etch-hub-sort-setting-status');
    contrastStatus.setAttribute('role', 'status');
    const saveColors = button(__('Save colors', 'etch-content-hub-sort'), async () => {
      saveColors.disabled = true;
      contrastStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const data = await saveSettings({ active_bg: background.input.value, active_text: foreground.input.value });
        config.activeBg = data.active_bg;
        config.activeText = data.active_text;
        if (view) {
          view.root.style.setProperty('--etch-hub-active-bg', config.activeBg);
          view.root.style.setProperty('--etch-hub-active-text', config.activeText);
        }
        updateColorPreview();
        contrastStatus.textContent = __('Saved', 'etch-content-hub-sort');
      } catch (error) {
        updateColorPreview();
        contrastStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      }
    }, 'etch-hub-sort-save-colors');
    function updateColorPreview() {
      const ratio = contrastRatio(background.input.value, foreground.input.value);
      preview.style.backgroundColor = background.input.value;
      preview.style.color = foreground.input.value;
      saveColors.disabled = ratio < 4.5 || (background.input.value === config.activeBg && foreground.input.value === config.activeText);
      contrastStatus.textContent = ratio < 4.5
        ? sprintf(__('Contrast %s:1 is too low. Choose at least 4.5:1.', 'etch-content-hub-sort'), ratio.toFixed(2))
        : sprintf(__('Contrast %s:1', 'etch-content-hub-sort'), ratio.toFixed(2));
    }
    colorControls.append(background.field, foreground.field);
    colorRow.append(colorHeading, colorControls, preview, colorHelp, contrastStatus, saveColors);
    content.appendChild(colorRow);
    updateColorPreview();

    const arrangeSetting = (row, label, help, status, ...actions) => {
      const copy = element('div', 'etch-hub-sort-setting-copy');
      const controls = element('div', 'etch-hub-sort-setting-actions');
      copy.append(label, help, status);
      controls.append(...actions);
      row.replaceChildren(copy, controls);
    };
    arrangeSetting(enableSetting.row, enableSetting.name, enableSetting.help, enableSetting.status, enableSetting.toggle);
    arrangeSetting(sidebarSetting.row, sidebarSetting.name, sidebarSetting.help, sidebarSetting.status, sidebarSetting.toggle);
    arrangeSetting(wordpressEditSetting.row, wordpressEditSetting.name, wordpressEditSetting.help, wordpressEditSetting.status, wordpressEditSetting.toggle);
    arrangeSetting(viewRow, viewLabel, viewHelp, viewStatus, viewSelect);
    arrangeSetting(placement, placementLabel, placementHelp, placementStatus, placementSelect);
    arrangeSetting(defaultRow, defaultLabel, defaultHelp, defaultStatus, defaultControls);
    arrangeSetting(termDefaultRow, termDefaultLabel, termDefaultHelp, termDefaultStatus, termDefaultControls);
    arrangeSetting(colorRow, colorHeading, colorHelp, contrastStatus, colorControls, preview, saveColors);
    const generalHeading = element('div', 'etch-hub-sort-manager-intro');
    generalHeading.appendChild(element('h2', '', __('General', 'etch-content-hub-sort')));
    content.prepend(generalHeading);
    const settingsGroup = (title, ...rows) => {
      const section = element('section', 'etch-hub-sort-settings-group');
      section.setAttribute('aria-label', title);
      section.appendChild(element('h3', 'etch-hub-sort-settings-group-title', title));
      const list = element('div', 'etch-hub-sort-settings-group-list');
      list.append(...rows);
      section.appendChild(list);
      content.appendChild(section);
    };
    settingsGroup(__('Content Hub', 'etch-content-hub-sort'), enableSetting.row, viewRow, sidebarSetting.row);
    settingsGroup(__('Table', 'etch-content-hub-sort'), wordpressEditSetting.row, placement, defaultRow, termDefaultRow);
    settingsGroup(__('Appearance', 'etch-content-hub-sort'), colorRow);

    const hierarchyIntro = element('div', 'etch-hub-sort-manager-intro');
    hierarchyIntro.append(
      element('h2', '', __('Hierarchy indicators', 'etch-content-hub-sort')),
      element('p', '', __('Style parent and child rows. Choosing a color turns on custom styling. Text needs 4.5:1 contrast; borders and carets need 3:1.', 'etch-content-hub-sort'))
    );
    hierarchyContent.appendChild(hierarchyIntro);
    const hierarchyToggle = element('label', 'etch-hub-sort-hierarchy-custom-toggle');
    const hierarchyEnabled = element('input');
    hierarchyEnabled.type = 'checkbox';
    hierarchyEnabled.checked = config.hierarchyStyle.enabled;
    hierarchyToggle.append(hierarchyEnabled, element('span', '', __('Use custom hierarchy styling', 'etch-content-hub-sort')));
    hierarchyContent.appendChild(hierarchyToggle);
    const hierarchyInputs = {};
    const hierarchyFieldErrors = {};
    const hierarchyColorValues = {};
    const hierarchyPreviews = {};
    for (const [level, name, symbol] of [
      ['parent', __('Parent rows', 'etch-content-hub-sort'), '⌄'],
      ['child', __('Child rows', 'etch-content-hub-sort'), '›'],
    ]) {
      const card = element('section', 'etch-hub-sort-hierarchy-card');
      card.appendChild(element('h3', '', name));
      const fields = element('div', 'etch-hub-sort-hierarchy-fields');
      for (const [property, label] of [
        ['bg', __('Background', 'etch-content-hub-sort')],
        ['text', __('Text', 'etch-content-hub-sort')],
        ['border', __('Left border', 'etch-content-hub-sort')],
        ['caret', __('Caret', 'etch-content-hub-sort')],
      ]) {
        const field = element('label', 'etch-hub-sort-hierarchy-field');
        field.appendChild(element('span', 'etch-hub-sort-hierarchy-field-label', label));
        const control = element('span', 'etch-hub-sort-hierarchy-color-control');
        const input = element('input', 'etch-hub-sort-color-input');
        input.type = 'color';
        input.value = config.hierarchyStyle[`${level}_${property}`];
        const value = element('span', 'etch-hub-sort-hierarchy-color-value', input.value.toUpperCase());
        const error = element('span', 'etch-hub-sort-hierarchy-field-error');
        error.id = `etch-hub-sort-${level}-${property}-error`;
        input.setAttribute('aria-describedby', error.id);
        control.append(input, value);
        field.append(control, error);
        fields.appendChild(field);
        hierarchyInputs[`${level}_${property}`] = input;
        hierarchyFieldErrors[`${level}_${property}`] = error;
        hierarchyColorValues[`${level}_${property}`] = value;
        input.addEventListener('input', () => {
          hierarchyEnabled.checked = true;
          updateHierarchyPreview();
        });
      }
      const preview = element('div', 'etch-hub-sort-hierarchy-preview');
      preview.append(element('span', 'etch-hub-sort-hierarchy-preview-caret', symbol), element('span', '', name));
      hierarchyPreviews[level] = preview;
      card.append(fields, preview);
      hierarchyContent.appendChild(card);
    }
    const hierarchyList = element('div', 'etch-hub-sort-settings-group-list');
    hierarchyList.append(...hierarchyContent.querySelectorAll('.etch-hub-sort-hierarchy-card'));
    hierarchyContent.appendChild(hierarchyList);
    const widthField = element('label', 'etch-hub-sort-hierarchy-width');
    widthField.appendChild(element('span', '', __('Left border width', 'etch-content-hub-sort')));
    const borderWidth = element('select', 'etch-hub-sort-placement-select');
    for (let width = 1; width <= 6; width++) {
      const option = element('option', '', sprintf(__('%d px', 'etch-content-hub-sort'), width));
      option.value = String(width);
      borderWidth.appendChild(option);
    }
    borderWidth.value = String(config.hierarchyStyle.border_width);
    borderWidth.addEventListener('change', () => {
      hierarchyEnabled.checked = true;
      updateHierarchyPreview();
    });
    widthField.appendChild(borderWidth);
    hierarchyContent.appendChild(widthField);
    const hierarchyStatus = element('span', 'etch-hub-sort-setting-status');
    hierarchyStatus.setAttribute('role', 'status');
    const saveHierarchy = button(__('Save hierarchy styling', 'etch-content-hub-sort'), async () => {
      const invalidFields = updateHierarchyPreview();
      if (hierarchyEnabled.checked && invalidFields.length) {
        hierarchyInputs[invalidFields[0]].focus();
        return;
      }
      saveHierarchy.disabled = true;
      hierarchyStatus.textContent = __('Saving…', 'etch-content-hub-sort');
      try {
        const data = await saveSettings({ hierarchy_style: hierarchyValues() });
        config.hierarchyStyle = data.hierarchy_style;
        if (view) applyHierarchyStyle(view.root);
        updateHierarchyPreview();
        hierarchyStatus.textContent = __('Saved', 'etch-content-hub-sort');
      } catch (error) {
        updateHierarchyPreview();
        hierarchyStatus.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
      }
    }, 'etch-hub-sort-hierarchy-save');
    hierarchyContent.append(hierarchyStatus, saveHierarchy);
    hierarchyEnabled.addEventListener('change', () => {
      if (!hierarchyEnabled.checked) {
        for (const [key, input] of Object.entries(hierarchyInputs)) input.value = config.hierarchyStyle[key];
        borderWidth.value = String(config.hierarchyStyle.border_width);
      }
      updateHierarchyPreview();
    });
    function hierarchyValues() {
      const values = { enabled: hierarchyEnabled.checked, border_width: Number(borderWidth.value) };
      for (const [key, input] of Object.entries(hierarchyInputs)) values[key] = input.value;
      return values;
    }
    function updateHierarchyPreview() {
      const values = hierarchyValues();
      const invalidFields = [];
      for (const level of ['parent', 'child']) {
        const preview = hierarchyPreviews[level];
        preview.style.backgroundColor = values[`${level}_bg`];
        preview.style.color = values[`${level}_text`];
        preview.style.borderLeft = `${values.border_width}px solid ${values[`${level}_border`]}`;
        preview.firstElementChild.style.color = values[`${level}_caret`];
        for (const [property, minimum] of [['text', 4.5], ['border', 3], ['caret', 3]]) {
          const key = `${level}_${property}`;
          const ratio = contrastRatio(values[`${level}_bg`], values[key]);
          const invalid = ratio < minimum;
          const input = hierarchyInputs[key];
          input.setAttribute('aria-invalid', String(invalid));
          hierarchyFieldErrors[key].textContent = invalid
            ? sprintf(__('Contrast %1$s:1; needs %2$s:1', 'etch-content-hub-sort'), ratio.toFixed(1), minimum.toFixed(1))
            : '';
          if (invalid) invalidFields.push(key);
        }
      }
      for (const [key, input] of Object.entries(hierarchyInputs)) hierarchyColorValues[key].textContent = input.value.toUpperCase();
      const changed = Object.entries(values).some(([key, value]) => value !== config.hierarchyStyle[key]);
      saveHierarchy.disabled = !changed;
      hierarchyStatus.textContent = invalidFields.length
        ? __('Adjust the colors marked above before saving.', 'etch-content-hub-sort')
        : changed ? __('Ready to save.', 'etch-content-hub-sort') : __('No unsaved changes.', 'etch-content-hub-sort');
      return invalidFields;
    }
    updateHierarchyPreview();

    const buttonGroups = [
      ['new', __('New item', 'etch-content-hub-sort')],
      ['view', __('Etch table / Browse all', 'etch-content-hub-sort')],
      ['settings', __('Hub settings', 'etch-content-hub-sort')],
      ['hierarchy', __('Hierarchy', 'etch-content-hub-sort')],
      ['bulk', __('Open all / Close all', 'etch-content-hub-sort')],
      ['expand', __('Expand / Collapse row', 'etch-content-hub-sort')],
      ['load_more', __('Load more children', 'etch-content-hub-sort')],
      ['quick_edit', __('Quick edit', 'etch-content-hub-sort')],
      ['open', __('Open in Builder', 'etch-content-hub-sort')],
      ['wordpress', __('Edit in WordPress', 'etch-content-hub-sort')],
      ['trash', __('Trash', 'etch-content-hub-sort')],
      ['pagination', __('Previous / Next', 'etch-content-hub-sort')],
      ['notice', __('Saved item actions', 'etch-content-hub-sort')],
    ];
    const buttonHeading = element('div', 'etch-hub-sort-manager-intro');
    buttonHeading.append(
      element('h2', '', __('Button appearance', 'etch-content-hub-sort')),
      element('p', '', __('Open a button to change its label or colors. Etch colors stay in place unless you enable custom colors.', 'etch-content-hub-sort'))
    );
    buttonContent.appendChild(buttonHeading);
    const buttonList = element('div', 'etch-hub-sort-settings-group-list');
    buttonContent.appendChild(buttonList);
    const makeColorInput = (label, value) => {
      const field = element('label', 'etch-hub-sort-button-color-field');
      field.appendChild(element('span', '', label));
      const input = element('input', 'etch-hub-sort-color-input');
      input.type = 'color';
      input.value = value;
      field.appendChild(input);
      return { field, input };
    };
    const statusIntro = element('div', 'etch-hub-sort-manager-intro');
    statusIntro.append(
      element('h2', '', __('Status colors', 'etch-content-hub-sort')),
      element('p', '', __('Set the background and text colors used by status badges and the status picker. Third-party statuses share one color pair.', 'etch-content-hub-sort'))
    );
    const statusList = element('div', 'etch-hub-sort-settings-group-list');
    statusContent.append(statusIntro, statusList);
    for (const [group, label] of [
      ['publish', __('Published', 'etch-content-hub-sort')],
      ['draft', __('Draft', 'etch-content-hub-sort')],
      ['future', __('Scheduled', 'etch-content-hub-sort')],
      ['pending', __('Pending Review', 'etch-content-hub-sort')],
      ['private', __('Private', 'etch-content-hub-sort')],
      ['custom', __('Third-party statuses', 'etch-content-hub-sort')],
    ]) {
      const card = element('details', 'etch-hub-sort-button-card');
      const summary = element('summary', 'etch-hub-sort-button-card-summary');
      summary.appendChild(element('span', 'etch-hub-sort-button-card-title', label));
      const preview = element('span', 'etch-hub-sort-status', group === 'custom' ? __('Custom status', 'etch-content-hub-sort') : label);
      summary.appendChild(preview);
      const editor = element('div', 'etch-hub-sort-button-card-editor');
      const colors = element('div', 'etch-hub-sort-button-card-colors');
      const bg = makeColorInput(__('Background', 'etch-content-hub-sort'), config.statusStyles[group].background);
      const fg = makeColorInput(__('Text', 'etch-content-hub-sort'), config.statusStyles[group].text);
      bg.input.setAttribute('aria-label', sprintf(__('%s background', 'etch-content-hub-sort'), label));
      fg.input.setAttribute('aria-label', sprintf(__('%s text', 'etch-content-hub-sort'), label));
      const bgHex = element('code', 'etch-hub-sort-color-code');
      const fgHex = element('code', 'etch-hub-sort-color-code');
      bg.field.appendChild(bgHex);
      fg.field.appendChild(fgHex);
      colors.append(bg.field, fg.field);
      const notice = element('span', 'etch-hub-sort-setting-status');
      notice.setAttribute('role', 'status');
      const save = button(__('Save', 'etch-content-hub-sort'), async () => {
        save.disabled = true;
        notice.textContent = __('Saving…', 'etch-content-hub-sort');
        try {
          const result = await saveSettings({ status_style: { group, background: bg.input.value, text: fg.input.value } });
          config.statusStyles = result.status_styles;
          applyStatusColors();
          updateStatusPreview();
          notice.textContent = __('Saved', 'etch-content-hub-sort');
        } catch (error) {
          updateStatusPreview();
          notice.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
        }
      }, 'etch-hub-sort-button-save');
      const reset = button(__('Use default colors', 'etch-content-hub-sort'), () => {
        bg.input.value = config.statusDefaults[group].background;
        fg.input.value = config.statusDefaults[group].text;
        updateStatusPreview();
      });
      const updateStatusPreview = () => {
        const ratio = contrastRatio(bg.input.value, fg.input.value);
        const changed = bg.input.value !== config.statusStyles[group].background || fg.input.value !== config.statusStyles[group].text;
        preview.style.backgroundColor = bg.input.value;
        preview.style.color = fg.input.value;
        bgHex.textContent = bg.input.value.toUpperCase();
        fgHex.textContent = fg.input.value.toUpperCase();
        bg.input.setAttribute('aria-invalid', String(ratio < 4.5));
        fg.input.setAttribute('aria-invalid', String(ratio < 4.5));
        save.disabled = !changed || ratio < 4.5;
        notice.textContent = ratio < 4.5
          ? sprintf(__('Contrast %s:1 is too low. Choose at least 4.5:1.', 'etch-content-hub-sort'), ratio.toFixed(2))
          : changed ? __('Unsaved changes', 'etch-content-hub-sort') : '';
      };
      for (const input of [bg.input, fg.input]) input.addEventListener('input', updateStatusPreview);
      const actions = element('div', 'etch-hub-sort-button-card-actions');
      actions.append(notice, reset, save);
      editor.append(colors, actions);
      card.append(summary, editor);
      statusList.appendChild(card);
      updateStatusPreview();
    }
    for (const [group, label] of buttonGroups) {
      const saved = config.buttonStyles[group];
      const card = element('details', 'etch-hub-sort-button-card');
      const summary = element('summary', 'etch-hub-sort-button-card-summary');
      const summaryCopy = element('span', 'etch-hub-sort-button-card-summary-copy');
      const title = element('span', 'etch-hub-sort-button-card-title', label);
      const choice = element('span', 'etch-hub-sort-button-card-choice');
      summaryCopy.append(title, choice);
      const controls = element('div', 'etch-hub-sort-button-card-controls');
      const displayField = element('label', 'etch-hub-sort-button-display-field');
      displayField.appendChild(element('span', '', __('Label style', 'etch-content-hub-sort')));
      const display = element('select', 'etch-hub-sort-placement-select');
      display.setAttribute('aria-label', sprintf(__('%s label style', 'etch-content-hub-sort'), label));
      for (const [value, text] of [
        ['icon', __('Icon only', 'etch-content-hub-sort')],
        ['both', __('Icon + text', 'etch-content-hub-sort')],
        ['text', __('Text only', 'etch-content-hub-sort')],
      ]) {
        const option = element('option', '', text);
        option.value = value;
        display.appendChild(option);
      }
      display.value = saved.display;
      displayField.appendChild(display);
      const bg = makeColorInput(__('Background', 'etch-content-hub-sort'), saved.background);
      const fg = makeColorInput(__('Text / icon', 'etch-content-hub-sort'), saved.text);
      const customField = element('label', 'etch-hub-sort-button-custom-field');
      const custom = element('input');
      custom.type = 'checkbox';
      custom.checked = saved.custom_colors;
      customField.append(custom, element('span', '', __('Use custom colors', 'etch-content-hub-sort')));
      const preview = element('span', 'etch-hub-sort-button etch-hub-sort-themed etch-hub-sort-button-preview');
      preview.dataset.buttonGroup = group;
      preview.setAttribute('aria-hidden', 'true');
      applyButtonStyle(preview);
      preview.appendChild(element('span', 'etch-hub-sort-button-preview-label', label));
      if (group === 'view') {
        preview.dataset.viewMode = 'etch';
        appendNativeIcon(preview, '.settings-bar__section.etch-logo svg');
      }
      summary.append(summaryCopy, preview);
      const editor = element('div', 'etch-hub-sort-button-card-editor');
      const status = element('span', 'etch-hub-sort-setting-status');
      status.setAttribute('role', 'status');
      const save = button(__('Save', 'etch-content-hub-sort'), async () => {
        save.disabled = true;
        status.textContent = __('Saving…', 'etch-content-hub-sort');
        try {
          const result = await saveSettings({ button_style: { group, background: bg.input.value, text: fg.input.value, display: display.value, custom_colors: custom.checked } });
          config.buttonStyles = result.button_styles;
          repaintButtons();
          updatePreview();
          status.textContent = __('Saved', 'etch-content-hub-sort');
        } catch (error) {
          updatePreview();
          status.textContent = error.message || __('Could not save setting.', 'etch-content-hub-sort');
        }
      }, 'etch-hub-sort-button-save');
      const updatePreview = () => {
        const ratio = contrastRatio(bg.input.value, fg.input.value);
        const changed = bg.input.value !== config.buttonStyles[group].background || fg.input.value !== config.buttonStyles[group].text || display.value !== config.buttonStyles[group].display || custom.checked !== config.buttonStyles[group].custom_colors;
        preview.dataset.display = display.value;
        preview.dataset.customColors = String(custom.checked);
        bg.input.disabled = !custom.checked;
        fg.input.disabled = !custom.checked;
        colors.hidden = !custom.checked;
        preview.style.setProperty('--etch-button-bg', bg.input.value);
        preview.style.setProperty('--etch-button-text', fg.input.value);
        choice.textContent = `${display.options[display.selectedIndex].textContent} · ${custom.checked ? __('Custom colors', 'etch-content-hub-sort') : __('Etch colors', 'etch-content-hub-sort')}${changed ? ` · ${__('Unsaved', 'etch-content-hub-sort')}` : ''}`;
        save.disabled = (custom.checked && ratio < 4.5) || !changed;
        status.textContent = custom.checked && ratio < 4.5
          ? sprintf(__('Contrast %s:1 is too low. Choose at least 4.5:1.', 'etch-content-hub-sort'), ratio.toFixed(2))
          : changed ? __('Unsaved changes', 'etch-content-hub-sort') : '';
      };
      for (const input of [display, custom, bg.input, fg.input]) input.addEventListener('input', updatePreview);
      const colors = element('div', 'etch-hub-sort-button-card-colors');
      colors.append(bg.field, fg.field);
      const actions = element('div', 'etch-hub-sort-button-card-actions');
      actions.append(status, save);
      controls.append(displayField, customField);
      editor.append(controls, colors, actions);
      card.append(summary, editor);
      buttonList.appendChild(card);
      updatePreview();
    }
  }

  function scheduleRefresh() {
    const selected = selectedType();
    const selection = selected ? `${selected.kind}:${selected.type}` : '';
    if (selection !== observedSelection) {
      observedSelection = selection;
      refresh();
      return;
    }
    if (scheduled) return;
    scheduled = true;
    setTimeout(() => { scheduled = false; refresh(); }, 60);
  }
  function start() {
    const selected = selectedType();
    observedSelection = selected ? `${selected.kind}:${selected.type}` : '';
    refresh();
    new MutationObserver(scheduleRefresh).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
