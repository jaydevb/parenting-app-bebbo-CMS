(function ($, Drupal, drupalSettings, once) {
  'use strict';

  var SINGLE_QUESTION = (drupalSettings.bebboQuizType && drupalSettings.bebboQuizType.singleQuestionValue) || 'single_question_quiz';

  // Flag to track whether auto-removal is in progress so we don't
  // re-trigger during the AJAX rebuild attach cycles.
  var removing = FALSE;

  function getQuestionCount() {
    var $wrapper = $('[data-drupal-selector="edit-field-question-wrapper"]');
    if (!$wrapper.length) {
      return 0;
    }
    return $wrapper.find('.paragraphs-subform').length;
  }

  function getQuizType() {
    var $select = $('select[data-drupal-selector="edit-field-quiz-type"]');
    return $select.length ? $select.val() : '';
  }

  /**
   * Toggle visibility of paragraph add/duplicate controls based on quiz type.
   *
   * When switching to single_question_quiz with >1 questions, auto-removes
   * extra paragraphs one at a time via the widget's own AJAX remove buttons.
   */
  function toggleAddControls() {
    var $wrapper = $('[data-drupal-selector="edit-field-question-wrapper"]');
    if (!$wrapper.length) {
      return;
    }

    var isSingle = getQuizType() === SINGLE_QUESTION;

    // Hide/show the main "Add Quiz Questions" button area.
    $wrapper.find('.paragraphs-add-wrapper').toggle(!isSingle);

    // Hide/show duplicate buttons on each existing paragraph.
    $wrapper.find('input[name*="_duplicate"]').closest('.paragraphs-dropdown').toggle(!isSingle);

    // Auto-remove extra paragraphs: trigger one remove per attach cycle.
    if (isSingle && !removing && getQuestionCount() > 1) {
      removeLastParagraph($wrapper);
    }
  }

  /**
   * Triggers the remove button on the last paragraph item.
   *
   * After the AJAX rebuild, Drupal.behaviors.attach fires again,
   * toggleAddControls() re-checks the count, and removes the next
   * one — repeating until only 1 question remains.
   */
  function removeLastParagraph($wrapper) {
    var $removeButtons = $wrapper.find('input[name$="_remove"]');
    if ($removeButtons.length <= 1) {
      return;
    }

    removing = TRUE;
    var $lastRemove = $removeButtons.last();
    $lastRemove.trigger('mousedown');
  }

  function updateQuestionCount() {
    var count = getQuestionCount();

    // Update the number of questions field.
    var $countInput = $('[data-drupal-selector="edit-field-number-of-questions-0-value"]');
    if ($countInput.length) {
      $countInput.val(count);
    }

    // Update passing score max attribute and help text.
    var $scoreInput = $('[data-drupal-selector="edit-field-passing-score-0-value"]');
    if ($scoreInput.length) {
      $scoreInput.attr('max', count);

      // Update help text.
      var $description = $scoreInput.closest('.form-item').find('.description, .form-item__description');
      if ($description.length) {
        $description.text('The passing score out of ' + count);
      }

      validatePassingScore($scoreInput, count);
    }
  }

  function validatePassingScore($scoreInput, count) {
    var currentVal = parseInt($scoreInput.val(), 10);
    var $error = $scoreInput.closest('.form-item').find('.passing-score-error');

    if (currentVal > count && count > 0) {
      var message = 'Passing score cannot exceed ' + count + ' (total questions).';
      if (!$error.length) {
        $scoreInput.after(
          '<div class="passing-score-error messages messages--error" style="margin-top:5px;">' + message + '</div>'
        );
      }
      else {
        $error.text(message);
      }
    }
    else {
      $error.remove();
    }
  }

  Drupal.behaviors.bebboSerializerQuestionCount = {
    attach: function (context) {
      // Reset removal flag on every attach (AJAX rebuild completed).
      removing = FALSE;

      // Runs on initial load and every AJAX rebuild (paragraph add/remove).
      updateQuestionCount();
      toggleAddControls();

      // Bind change listener on quiz type select (once).
      once('quiz-type-toggle', 'select[data-drupal-selector="edit-field-quiz-type"]', context)
        .forEach(function (el) {
          $(el).on('change', function () {
            toggleAddControls();
          });
        });

      // Bind input listener on the passing score field (once).
      once('passing-score-validate', '[data-drupal-selector="edit-field-passing-score-0-value"]', context)
        .forEach(function (el) {
          $(el).on('input', function () {
            validatePassingScore($(this), getQuestionCount());
          });
        });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
