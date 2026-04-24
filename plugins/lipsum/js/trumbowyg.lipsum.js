(function ($) {
    'use strict';
    if (!$ || !$.trumbowyg) return;

    $.extend(true, $.trumbowyg, {
        langs: {
            en: { lipsum: 'Insert Lorem Ipsum' }
        },
        plugins: {
            lipsum: {
                init: function (trumbowyg) {
                    trumbowyg.addBtnDef('lipsum', {
                        fn: function () {
                            var text = window.ncLipsumText || 'Lorem ipsum dolor sit amet.';
                            trumbowyg.execCmd('insertHTML', '<p>' + text + '</p>', false);
                        },
                        title: 'Insert Lorem Ipsum',
                        hasIcon: false,
                        text: 'Lorem'
                    });
                }
            }
        }
    });
}(jQuery));
