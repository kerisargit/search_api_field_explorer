(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.searchApiFieldExplorerSelectAll = {
    attach: function (context) {
      once('sasfe-select-all', '.search-api-field-explorer-select-all-controls', context).forEach(function (wrapper) {
        var scope = wrapper.closest('form') || document;

        var setAll = function (checked) {
          scope.querySelectorAll('input[type="checkbox"].search-api-field-explorer-select-checkbox').forEach(function (checkbox) {
            if (checkbox.checked !== checked) {
              checkbox.checked = checked;
              checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            }
          });
        };

        var selectAllLink = wrapper.querySelector('[data-action="select-all"]');
        if (selectAllLink) {
          selectAllLink.addEventListener('click', function (event) {
            event.preventDefault();
            setAll(true);
          });
        }

        var selectNoneLink = wrapper.querySelector('[data-action="select-none"]');
        if (selectNoneLink) {
          selectNoneLink.addEventListener('click', function (event) {
            event.preventDefault();
            setAll(false);
          });
        }
      });
    },
  };

})(Drupal, once);
