(function ($, Drupal) {
  'use strict';

  function updateModuleCount() {
    var $wrapper = $('[data-drupal-selector="edit-field-module-wrapper"]');
    if (!$wrapper.length) {
      return;
    }

    // Count only top-level paragraph rows (not nested field tables inside subforms).
    var count = $wrapper.find('.field-multiple-table:first > tbody > tr.draggable').length;

    var $input = $('[data-drupal-selector="edit-field-number-of-modules-0-value"]');
    if ($input.length) {
      $input.val(count);
    }
  }

  Drupal.behaviors.bebboSerializerModuleCount = {
    attach: function () {
      updateModuleCount();
    }
  };

})(jQuery, Drupal);
