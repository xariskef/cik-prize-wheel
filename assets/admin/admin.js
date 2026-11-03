(function ($) {
    function updateOrders($body) {
        $body.children('tr').each(function (index) {
            $(this).find('.cikw-order').val(index + 1);
        });
    }

    function formatPercent(value) {
        var rounded = Math.round(value * 1000) / 1000;
        return String(rounded).replace(/\.0+$/, '');
    }

    function updatePrizeSummary($group) {
        var sum = 0;
        var $summary = $group.find('[data-cikw-summary]');

        $group.find('tbody tr').each(function () {
            var $row = $(this);
            var $active = $row.find('.cikw-prize-active');
            var $probability = $row.find('.cikw-prize-probability');

            if ($active.length && $active.is(':checked') && $probability.length) {
                sum += parseFloat($probability.val()) || 0;
            }
        });

        var noWin = Math.max(0, 100 - sum);
        var text = 'Σύνολο ' + formatPercent(sum) + '% - Καμία νίκη ' + formatPercent(noWin) + '%';

        $summary.toggleClass('cikw-summary-error', sum > 100);
        if (sum > 100) {
            text += ' Υπέρβαση ορίου';
        }

        $summary.text(text);
    }

    $(function () {
        $('.cikw-sortable-body').each(function () {
            var $body = $(this);
            $body.sortable({
                axis: 'y',
                handle: '.cikw-drag-handle',
                items: '> tr.cikw-sortable-row',
                placeholder: 'cikw-sortable-placeholder',
                update: function () {
                    updateOrders($body);
                }
            });
            updateOrders($body);
        });

        $('.cikw-category-prize-group').each(function () {
            updatePrizeSummary($(this));
        });

        $(document).on('input change', '.cikw-prize-probability, .cikw-prize-active', function () {
            updatePrizeSummary($(this).closest('.cikw-category-prize-group'));
        });

        $(document).on('click', '.cikw-media-select', function (event) {
            event.preventDefault();
            var targetId = $(this).data('target');
            var $target = $('#' + targetId);
            var frame = wp.media({
                title: 'Επιλογή εικόνας',
                button: { text: 'Χρήση εικόνας' },
                multiple: false
            });

            frame.on('select', function () {
                var attachment = frame.state().get('selection').first().toJSON();
                $target.val(attachment.id).trigger('change');
            });

            frame.open();
        });
    });
})(jQuery);