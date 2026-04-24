(function ($) {
    'use strict';

    function initSelect($el) {
        if ($el.data('abcnorio-enhanced')) {
            return;
        }

        var canCreateTerms = String($el.data('can-create-terms')) === '1';

        if (typeof $.fn.selectWoo === 'function') {
            $el.selectWoo({
                width: '100%',
                closeOnSelect: false,
                tags: canCreateTerms,
                tokenSeparators: canCreateTerms ? [','] : [],
            });
            $el.data('abcnorio-enhanced', true);
        }
    }

    function splitTermValues(values) {
        var termIds = [];
        var newTerms = [];

        (values || []).forEach(function (value) {
            var normalized = String(value || '').trim();

            if (!normalized) {
                return;
            }

            if (/^\d+$/.test(normalized)) {
                termIds.push(parseInt(normalized, 10));
                return;
            }

            newTerms.push(normalized);
        });

        return {
            termIds: termIds,
            newTerms: newTerms,
        };
    }

    function setStatus($el, message, isError) {
        var $status = $el.nextAll('.abcnorio-term-editor__status').first();

        if (!$status.length) {
            return;
        }

        $status
            .text(message || '')
            .toggleClass('is-error', !!isError)
            .toggleClass('is-success', !isError && !!message);
    }

    function saveTerms($el) {
        var split = splitTermValues($el.val() || []);

        setStatus($el, abcnorioTermEditor.messages.saving, false);
        $el.prop('disabled', true);

        $.post(abcnorioTermEditor.ajaxUrl, {
            action: 'abcnorio_set_post_terms',
            nonce: abcnorioTermEditor.nonce,
            post_id: $el.data('post-id'),
            taxonomy: $el.data('taxonomy'),
            term_ids: split.termIds,
            new_terms: split.newTerms,
        })
            .done(function (response) {
                if (!response || !response.success) {
                    setStatus($el, abcnorioTermEditor.messages.error, true);
                    return;
                }

                setStatus($el, abcnorioTermEditor.messages.saved, false);
            })
            .fail(function () {
                setStatus($el, abcnorioTermEditor.messages.error, true);
            })
            .always(function () {
                $el.prop('disabled', false);
            });
    }

    $(function () {
        $('.abcnorio-term-editor__select').each(function () {
            var $el = $(this);
            initSelect($el);
            $el.on('change', function () {
                saveTerms($el);
            });
        });
    });
})(jQuery);
