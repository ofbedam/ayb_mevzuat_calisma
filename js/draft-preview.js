(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aybDraftPreview = {
    attach(context) {
      // Drupal still supplies the AJAX dialog transport, but the legislative
      // UI presents it as an application drawer. Keep this lifecycle here so
      // preview, revision, edit and decision drawers behave consistently.
      once('ayb-drawer-lifecycle', 'body', context).forEach((body) => {
        let activeDrawers = 0;
        const isDrawer = (element) => element?.classList.contains('ayb-draft-drawer-dialog');
        window.addEventListener('dialog:aftercreate', (event) => {
          const panel = event.target?.closest('.ui-dialog');
          if (!isDrawer(panel)) return;
          activeDrawers += 1;
          body.classList.add('ayb-draft-drawer-open');
          panel.setAttribute('aria-modal', 'true');
          panel.setAttribute('data-ayb-drawer', 'true');
          panel.querySelector('.ui-dialog-titlebar-close')?.setAttribute('aria-label', Drupal.t('Çekmeceyi kapat'));
          const focusTarget = panel.querySelector('.ui-dialog-titlebar-close, input, select, textarea, button, a[href]');
          window.requestAnimationFrame(() => focusTarget?.focus({ preventScroll: true }));
        });
        window.addEventListener('dialog:afterclose', (event) => {
          const panel = event.target?.closest('.ui-dialog');
          if (isDrawer(panel)) activeDrawers = Math.max(0, activeDrawers - 1);
          // Drupal may remove the dialog wrapper before dispatching the close
          // event. Check the DOM on the next frame instead of relying only on
          // closest(), otherwise body scrolling can remain locked.
          window.requestAnimationFrame(() => {
            if (!document.querySelector('.ui-dialog.ayb-draft-drawer-dialog')) {
              activeDrawers = 0;
              body.classList.remove('ayb-draft-drawer-open');
            }
          });
        });
      });

      once('ayb-draft-sibling-navigation', '.ayb-draft-sibling-select[data-ayb-draft-navigate]', context).forEach((select) => {
        select.addEventListener('change', () => {
          if (select.value) {
            window.location.assign(select.value);
          }
        });
      });
      const modals = [];
      if (context.nodeType === 1 && context.matches('.ayb-draft-preview-modal')) {
        modals.push(context);
      }
      once('ayb-draft-preview', '.ayb-draft-preview-modal', context).forEach((modal) => modals.push(modal));
      modals.forEach((modal) => {
        if (modal.dataset.aybPreviewAttached === 'true') {
          return;
        }
        modal.dataset.aybPreviewAttached = 'true';
        const options = modal.querySelectorAll('[data-preview-target]');
        options.forEach((option) => {
          const target = modal.querySelector(`#${option.dataset.previewTarget}`);
          if (!target) {
            return;
          }
          const activate = () => {
            options.forEach((item) => {
              const active = item === option;
              item.classList.toggle('is-active', active);
              item.setAttribute('aria-expanded', active ? 'true' : 'false');
              const itemTarget = modal.querySelector(`#${item.dataset.previewTarget}`);
              if (itemTarget) {
                itemTarget.open = active;
              }
            });
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            target.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
          };
          option.addEventListener('click', activate);
        });

        modal.querySelectorAll('.ayb-draft-preview-section').forEach((section) => {
          section.addEventListener('toggle', () => {
            const option = modal.querySelector(`[data-preview-target="${section.id}"]`);
            if (option) {
              option.classList.toggle('is-active', section.open);
              option.setAttribute('aria-expanded', section.open ? 'true' : 'false');
            }
          });
        });
      });
    },
  };
})(Drupal, once);
