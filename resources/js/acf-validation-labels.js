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
