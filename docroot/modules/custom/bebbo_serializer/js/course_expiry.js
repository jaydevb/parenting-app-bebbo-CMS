(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.bebboSerializerCourseExpiry = {
    attach: function (context) {
      once('course-expiry-min', 'input[name="field_course_expiry[0][value][date]"]', context)
        .forEach(function (el) {
          var today = new Date();
          var yyyy = today.getFullYear();
          var mm = String(today.getMonth() + 1).padStart(2, '0');
          var dd = String(today.getDate() + 1).padStart(2, '0');
          el.setAttribute('min', yyyy + '-' + mm + '-' + dd);

          // Picking a date without a time means end of that day. Fill it in as
          // soon as the editor commits the date so the value is visible rather
          // than appearing out of nowhere on save. The server applies the same
          // default, so this is convenience, not the guarantee.
          var time = el.form.querySelector('input[name="field_course_expiry[0][value][time]"]');
          if (!time) {
            return;
          }

          var fillTime = function () {
            if (el.value && !time.value) {
              time.value = '23:59:59';
              time.dispatchEvent(new Event('change', { bubbles: true }));
            }
          };

          el.addEventListener('change', fillTime);
          el.addEventListener('blur', fillTime);
        });
    }
  };

})(jQuery, Drupal, once);
