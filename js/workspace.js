(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aybWorkspacePanels = {
    attach(context) {
      once('ayb-workspace-panels', '.ayb-legislation-workspace', context).forEach((workspace) => {
        const key = `ayb-workspace-panels-${workspace.dataset.workspaceDraft || 'default'}`;
        let saved = {};
        try { saved = JSON.parse(window.localStorage.getItem(key) || '{}'); } catch (error) { saved = {}; }

        const setState = (panel, collapsed, persist = true) => {
          const className = panel === 'tree' ? 'is-tree-collapsed' : 'is-inspector-collapsed';
          workspace.classList.toggle(className, collapsed);
          const button = workspace.querySelector(`[data-workspace-toggle="${panel}"]`);
          if (button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.setAttribute('title', collapsed ? Drupal.t('Paneli aç') : Drupal.t('Paneli kapat'));
            const label = button.querySelector('.ayb-workspace-toggle__label');
            if (label) label.textContent = collapsed ? Drupal.t('Aç') : Drupal.t('Kapat');
          }
          if (persist) {
            try { saved[panel] = collapsed; window.localStorage.setItem(key, JSON.stringify(saved)); } catch (error) { /* no-op */ }
          }
        };

        const setExpanded = (panel, expanded, persist = true) => {
          const className = `is-${panel}-expanded`;
          workspace.classList.toggle(className, expanded);
          const button = workspace.querySelector(`[data-workspace-expand="${panel}"]`);
          if (button) {
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            button.setAttribute('title', expanded ? Drupal.t('Paneli daralt') : Drupal.t('Paneli genişlet'));
            const label = button.querySelector('.ayb-workspace-toggle__label');
            if (label) label.textContent = expanded ? Drupal.t('Daralt') : Drupal.t('Genişlet');
          }
          if (persist) {
            try { saved[`${panel}Expanded`] = expanded; window.localStorage.setItem(key, JSON.stringify(saved)); } catch (error) { /* no-op */ }
          }
        };

        ['tree', 'inspector'].forEach((panel) => setState(panel, saved[panel] === true, false));
        setExpanded('tree', saved.treeExpanded === true, false);

        // Use one delegated handler so a panel button cannot trigger another
        // panel's listener after Drupal reattaches behaviors or an AJAX update.
        workspace.addEventListener('click', (event) => {
          const edit = event.target.closest('[data-workspace-edit]');
          if (edit && workspace.contains(edit)) {
            const editor = workspace.querySelector('#ayb-workspace-edit');
            if (editor) editor.open = true;
          }
          const button = event.target.closest('[data-workspace-toggle], [data-workspace-expand]');
          if (!button || !workspace.contains(button)) return;
          event.preventDefault();
          event.stopPropagation();
          const panel = button.dataset.workspaceToggle || button.dataset.workspaceExpand;
          if (button.dataset.workspaceToggle) {
            const className = panel === 'tree' ? 'is-tree-collapsed' : 'is-inspector-collapsed';
            setState(panel, !workspace.classList.contains(className));
          }
          else if (button.dataset.workspaceExpand) {
            setExpanded(panel, !workspace.classList.contains(`is-${panel}-expanded`));
          }
        });
      });

      once('ayb-tree-filter', '[data-ayb-tree-filter]', context).forEach((input) => {
        const tree = input.closest('.ayb-legislation-workspace__tree');
        if (!tree) return;
        const status = tree.querySelector('.ayb-legislation-tree__filter-status');
        const items = [...tree.querySelectorAll('.ayb-legislation-tree__item')];
        // Read each record's own label once; innerText on nested branches
        // repeatedly traverses descendants and forces layout on every keystroke.
        const labels = new Map(items.map((item) => {
          const label = item.tagName === 'DETAILS'
            ? item.querySelector(':scope > summary')
            : item.querySelector(':scope > .ayb-legislation-tree__link');
          return [item, (label?.textContent || '').toLocaleLowerCase('tr-TR')];
        }));
        const filter = () => {
          const query = input.value.trim().toLocaleLowerCase('tr-TR');
          let visible = 0;
          items.forEach((item) => {
            const match = !query || labels.get(item).includes(query);
            item.classList.toggle('is-filter-hidden', !match);
            if (match) visible++;
            if (query && match) {
              let parent = item.parentElement?.closest('.ayb-legislation-tree__item');
              while (parent) {
                parent.classList.remove('is-filter-hidden');
                if (parent.tagName === 'DETAILS') parent.open = true;
                parent = parent.parentElement?.closest('.ayb-legislation-tree__item');
              }
            }
          });
          if (status) status.textContent = query ? Drupal.formatPlural(visible, '1 eşleşen kayıt', '@count eşleşen kayıt') : Drupal.t('Yazdıkça eşleşen kayıtlar gösterilir.');
        };
        let timer;
        input.addEventListener('input', () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(filter, 100);
        });
      });

      once('ayb-workspace-shortcuts', '.ayb-legislation-workspace', context).forEach((workspace) => {
        workspace.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            workspace.querySelectorAll('details[open].ayb-legislation-tree__item').forEach((details) => { details.open = false; });
            return;
          }
          if (!event.altKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
          if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.target.isContentEditable) return;
          const links = [...workspace.querySelectorAll('.ayb-legislation-tree__item[data-draft-record] a.ayb-legislation-tree__link:not(.ayb-legislation-tree__link--jump)')];
          const active = workspace.querySelector('.ayb-legislation-tree__item.is-active a.ayb-legislation-tree__link:not(.ayb-legislation-tree__link--jump)');
          const currentIndex = active ? links.findIndex((link) => link.href === active.href) : -1;
          const nextIndex = event.key === 'ArrowDown' ? currentIndex + 1 : currentIndex - 1;
          if (links[nextIndex]) {
            event.preventDefault();
            links[nextIndex].click();
          }
        });
      });

      once('ayb-workspace-unsaved', '.ayb-legislation-workspace form.ayb-draft-quick-edit-form', context).forEach((form) => {
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('change', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', (event) => {
          if (!dirty || !form.isConnected) return;
          event.preventDefault();
          event.returnValue = '';
        });
      });

      once('ayb-sync-scroll', '[data-sync-scroll-group]', context).forEach((column) => {
        const group = column.dataset.syncScrollGroup;
        const peers = [...document.querySelectorAll(`[data-sync-scroll-group="${group}"]`)].filter((peer) => peer !== column);
        let syncing = false;
        column.addEventListener('scroll', () => {
          if (syncing || !peers.length) return;
          syncing = true;
          const ratio = column.scrollTop / Math.max(1, column.scrollHeight - column.clientHeight);
          peers.forEach((peer) => { peer.scrollTop = ratio * Math.max(0, peer.scrollHeight - peer.clientHeight); });
          window.requestAnimationFrame(() => { syncing = false; });
        });
      });

      once('ayb-diff-line-numbers', '.ayb-legislation-side-by-side__column', context).forEach((column) => {
        let line = 0;
        column.querySelectorAll('p, li, blockquote, h6').forEach((element) => {
          line++;
          element.classList.add('ayb-legislation-side-by-side__line');
          element.setAttribute('data-line-number', String(line));
        });
      });

      once('ayb-tree-dnd', '.ayb-legislation-tree__item[data-draft-record]', context).forEach((item) => {
        const workspace = item.closest('.ayb-legislation-workspace');
        if (!workspace) return;
        item.addEventListener('dragstart', (event) => {
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', item.dataset.draftRecord);
          item.classList.add('is-dragging');
        });
        item.addEventListener('dragend', () => item.classList.remove('is-dragging'));
        item.addEventListener('dragover', (event) => {
          if (event.dataTransfer.types.includes('text/plain')) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            item.classList.add('is-drag-over');
          }
        });
        item.addEventListener('dragleave', () => item.classList.remove('is-drag-over'));
        item.addEventListener('drop', async (event) => {
          event.preventDefault();
          item.classList.remove('is-drag-over');
          const record = event.dataTransfer.getData('text/plain');
          const dragged = record ? workspace.querySelector(`[data-draft-record="${CSS.escape(record)}"]`) : null;
          if (!record || !dragged || record === item.dataset.draftRecord) return;
          const url = workspace.dataset.workspaceReorderUrl;
          const token = workspace.dataset.workspaceReorderToken;
          if (!url || !token) return;
          const structuralTypes = ['kitap', 'kisim', 'bolum', 'alt_baslik', 'madde_basligi'];
          const isParentDrop = structuralTypes.includes(item.dataset.draftType || '');
          if (!isParentDrop && item.dataset.draftParent !== (dragged.dataset.draftParent || '')) {
            window.alert(Drupal.t('Kayıtlar yalnızca aynı seviyede sıralanabilir. Yapısal bir bölümün üzerine bırakarak altına taşıyabilirsiniz.'));
            return;
          }
          const payload = isParentDrop
            ? {record, parent: item.dataset.draftRecord, before: 0}
            : {record, before: item.dataset.draftRecord};
          try {
            const response = await fetch(`${url}?_token=${encodeURIComponent(token)}`, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: JSON.stringify(payload)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || Drupal.t('Sıralama güncellenemedi.'));
            if (isParentDrop) {
              const children = item.querySelector(':scope > .details-wrapper');
              if (!children) throw new Error(Drupal.t('Hedef bölümün alt kayıt alanı bulunamadı.'));
              children.appendChild(dragged);
              dragged.dataset.draftParent = item.dataset.draftRecord;
              item.open = true;
            }
            else {
              item.parentNode.insertBefore(dragged, item);
            }
            dragged.classList.remove('is-dragging');
            dragged.classList.add('is-reordered');
            window.setTimeout(() => dragged.classList.remove('is-reordered'), 900);
            const status = workspace.querySelector('.ayb-legislation-workspace__status, [data-workspace-status]');
            if (status) status.textContent = result.message || Drupal.t('Belge sırası güncellendi.');
          }
          catch (error) {
            window.alert(error.message || Drupal.t('Sıralama güncellenemedi.'));
          }
        });
      });
    },
  };
})(Drupal, once);
