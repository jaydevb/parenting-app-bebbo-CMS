(function ($, Drupal) {
  'use strict';

  function updateModuleCount() {
    var $wrapper = $('[data-drupal-selector="edit-field-module-wrapper"]');
    if (!$wrapper.length) {
      return;
    }

    // Each paragraph item has a .paragraphs-subform element.
    var count = $wrapper.find('.paragraphs-subform').length;

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
