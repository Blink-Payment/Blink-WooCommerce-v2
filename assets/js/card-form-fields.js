/* global module */
(function (root, factory) {
    const cardFormFields = factory();

    root.blinkCardFormFields = cardFormFields;

    if (typeof module === 'object' && module.exports) {
        module.exports = cardFormFields;
    }
}(typeof window !== 'undefined' ? window : globalThis, function () {
    // Blink's card form asks for these, but the plugin fills them in from the
    // billing details, so the shopper never needs to see them.
    const billingFieldNames = ['customer_email', 'customer_address', 'customer_postcode'];
    const fieldGroupSelector = '.blink-form__group';
    const visibleContentSelector = 'input:not([type="hidden"]), select, textarea, iframe, button, img, svg';

    const toElement = (container) => (container && container.jquery ? container.get(0) : container);

    const isEmptyGroup = (group) => !group.querySelector(visibleContentSelector) && group.textContent.trim() === '';

    // Hides the billing fields on Blink's card form in a way theme CSS cannot undo:
    // each input becomes type="hidden" and its label is removed, instead of being
    // hidden with an inline display style. Elements are found by input name and
    // label "for", never by label text, which Blink translates. The inputs keep
    // their names and values, so they are still filled in and submitted.
    const concealBillingFields = (container) => {
        const element = toElement(container);

        if (!element || typeof element.querySelectorAll !== 'function') {
            return;
        }

        const touchedGroups = [];

        billingFieldNames.forEach((name) => {
            element.querySelectorAll('label[for="' + name + '"]').forEach((label) => label.remove());

            element.querySelectorAll('input[name="' + name + '"]').forEach((input) => {
                input.setAttribute('type', 'hidden');

                // Lift the input out of its field wrapper so an empty wrapper,
                // with its margin, can be removed without losing the value.
                const group = input.closest(fieldGroupSelector);
                if (group && group !== element && element.contains(group)) {
                    group.parentNode.insertBefore(input, group);
                    if (touchedGroups.indexOf(group) === -1) {
                        touchedGroups.push(group);
                    }
                }
            });
        });

        touchedGroups.forEach((group) => {
            if (isEmptyGroup(group)) {
                group.remove();
            }
        });
    };

    return {
        concealBillingFields
    };
}));
