// ACF builds "N field(s) require attention" from acf.__() strings; append field labels to them.
const singular = '1 field requires attention';
const plural = '%d fields require attention';

acf.addFilter('validation_complete', function (json, $form) {
  const l10n = window.acfL10n;
  l10n[singular] = singular;
  l10n[plural] = plural;

  const labels = [];
  (json.errors || []).forEach(function (error) {
    if (!error.input) {
      return;
    }
    const $input = $form.find('[name="' + error.input + '"]').first();
    const label = $input.closest('.acf-field').find('> .acf-label label').first().text().replace('*', '').trim();
    if (label && !labels.includes(label)) {
      labels.push(label);
    }
  });

  if (labels.length) {
    l10n[singular] = singular + ': ' + labels.join(', ');
    l10n[plural] = plural + ': ' + labels.join(', ');
  }

  return json;
});

// Flag end-before-start when a date picker value changes; server-side validation remains the backstop.
jQuery(function ($) {
  const endMessage = 'End must be after start.';
  let checkTimer;

  function checkEventDates() {
    const start = acf.getField('field_event_start');
    const end = acf.getField('field_event_end');
    if (!start || !end) {
      return;
    }

    // Own element, not end.showError(): ACF clears its errors on its own events.
    const $input = end.$el.find('.acf-input').first();
    $input.find('.event-end-error').remove();
    // Both values are 'Y-m-d H:i:s', so string comparison is chronological.
    if (start.val() && end.val() && end.val() <= start.val()) {
      $input.append($('<div class="acf-notice -error acf-error-message event-end-error" role="alert"></div>').append($('<p>').text(endMessage)));
    }
  }

  // One change fires several events (visible + hidden input); debounce so we run once, after ACF's own handlers.
  $(document).on(
    'change',
    '.acf-field[data-key="field_event_start"] input, .acf-field[data-key="field_event_end"] input',
    function () {
      clearTimeout(checkTimer);
      checkTimer = setTimeout(checkEventDates, 150);
    }
  );
});
