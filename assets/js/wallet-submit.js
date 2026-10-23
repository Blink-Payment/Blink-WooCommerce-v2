/* global module */
(function (root, factory) {
    const walletSubmit = factory(root);

    root.blinkWalletSubmit = walletSubmit;

    if (typeof module === 'object' && module.exports) {
        module.exports = walletSubmit;
    }
}(typeof window !== 'undefined' ? window : globalThis, function (root) {
    const walletNames = {
        apple: 'apple-pay',
        google: 'google-pay'
    };
    const formStates = new WeakMap();
    const appleWrapperMarker = '__blinkApplePayWooWrapper';
    const generatedFieldAttribute = 'data-blink-wallet-submit-field';
    const cardTokenAttribute = 'data-blink-card-payment-token';
    const managedControlSelector = 'input[name], select[name], textarea[name]';

    const getState = (form) => {
        if (!formStates.has(form)) {
            formStates.set(form, {
                activeWallet: '',
                intentId: '',
                intentExpiryDate: '',
                installed: false,
                controlDisabledStates: new Map()
            });
        }

        return formStates.get(form);
    };

    const getNamedInput = (form, name) => {
        return form.querySelector(`input[name="${name}"]`);
    };

    const storeIntentContext = (form) => {
        const state = getState(form);
        const intentId = getNamedInput(form, 'intent_id');
        const intentExpiryDate = getNamedInput(form, 'intent_expiry_date');

        if (intentId && intentId.value) {
            state.intentId = intentId.value;
        }
        if (intentExpiryDate && intentExpiryDate.value) {
            state.intentExpiryDate = intentExpiryDate.value;
        }
    };

    const ensureHiddenInput = (form, name, value) => {
        const inputs = Array.from(form.querySelectorAll(`input[name="${name}"]`));
        let input = inputs.find((candidate) => !candidate.hasAttribute(generatedFieldAttribute)) || inputs[0];

        if (input && !input.hasAttribute(generatedFieldAttribute)) {
            inputs.forEach((candidate) => {
                if (candidate !== input && candidate.hasAttribute(generatedFieldAttribute)) {
                    candidate.remove();
                }
            });
        }

        if (!input && value) {
            input = form.ownerDocument.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.id = name;
            input.setAttribute(generatedFieldAttribute, 'true');
            form.appendChild(input);
        }

        if (input && value) {
            input.value = value;
        }

        return input;
    };

    const getWalletFromPaymentBy = (paymentBy) => {
        return Object.keys(walletNames).find((wallet) => walletNames[wallet] === paymentBy) || '';
    };

    const detectActiveWallet = (form) => {
        const paymentBy = getNamedInput(form, 'payment_by');
        const state = getState(form);
        const paymentByWallet = getWalletFromPaymentBy(paymentBy ? paymentBy.value : '');

        if (paymentByWallet) {
            return paymentByWallet;
        }

        return state.activeWallet;
    };

    const applyWalletControlState = (form, activeWallet) => {
        const state = getState(form);

        state.controlDisabledStates.forEach((originallyDisabled, control) => {
            if (!form.contains(control)) {
                state.controlDisabledStates.delete(control);
            }
        });

        Object.keys(walletNames).forEach((wallet) => {
            const wrapper = form.querySelector(`[data-blink-wallet="${wallet}"]`);
            if (!wrapper) {
                return;
            }

            const controls = new Set(wrapper.querySelectorAll(managedControlSelector));
            state.controlDisabledStates.forEach((originalState, control) => {
                if (wrapper.contains(control)) {
                    controls.add(control);
                }
            });

            controls.forEach((control) => {
                if (!state.controlDisabledStates.has(control)) {
                    state.controlDisabledStates.set(control, {
                        disabled: control.disabled,
                        name: control.name
                    });
                }

                const originalState = state.controlDisabledStates.get(control);
                if (wallet === activeWallet) {
                    control.name = originalState.name;
                    control.disabled = originalState.disabled;
                } else {
                    control.disabled = true;
                    control.removeAttribute('name');
                }
            });
        });

        const cardToken = form.querySelector(`[${cardTokenAttribute}]`);
        if (cardToken) {
            cardToken.disabled = true;
            cardToken.removeAttribute('name');
        }
    };

    const resetWallet = (form) => {
        if (!form) {
            return;
        }

        const state = getState(form);
        state.activeWallet = '';

        const paymentBy = getNamedInput(form, 'payment_by');
        if (paymentBy && getWalletFromPaymentBy(paymentBy.value)) {
            paymentBy.value = '';
        }

        applyWalletControlState(form, '');
    };

    const prepareCardPaymentToken = (form) => {
        if (!form) {
            return null;
        }

        resetWallet(form);

        let cardToken = form.querySelector(`[${cardTokenAttribute}]`);
        if (!cardToken) {
            cardToken = form.ownerDocument.createElement('input');
            cardToken.type = 'hidden';
            cardToken.setAttribute(cardTokenAttribute, 'true');
            form.appendChild(cardToken);
        }

        cardToken.name = 'paymentToken';
        cardToken.value = '';
        cardToken.disabled = false;

        return cardToken;
    };

    const setActiveWallet = (form, wallet) => {
        if (!form || !walletNames[wallet]) {
            return;
        }

        const state = getState(form);
        storeIntentContext(form);
        state.activeWallet = wallet;
        ensureHiddenInput(form, 'payment_by', walletNames[wallet]);
        // Keep this DOM state until another wallet activates or the payment
        // method resets. Checkout plugins may serialize long after submit.
        applyWalletControlState(form, wallet);
    };

    const normalizeSubmission = (form) => {
        if (!form) {
            return;
        }

        storeIntentContext(form);

        const state = getState(form);
        const activeWallet = detectActiveWallet(form);
        if (!walletNames[activeWallet]) {
            resetWallet(form);
            return;
        }

        state.activeWallet = activeWallet;
        ensureHiddenInput(form, 'payment_by', walletNames[activeWallet]);
        ensureHiddenInput(form, 'intent_id', state.intentId);
        ensureHiddenInput(form, 'intent_expiry_date', state.intentExpiryDate);
        applyWalletControlState(form, activeWallet);
    };

    const install = (form) => {
        if (!form) {
            return;
        }

        const state = getState(form);
        storeIntentContext(form);
        ensureHiddenInput(form, 'payment_by', '');
        ensureHiddenInput(form, 'intent_id', '');
        ensureHiddenInput(form, 'intent_expiry_date', '');

        const paymentBy = getNamedInput(form, 'payment_by');
        const activeWallet = getWalletFromPaymentBy(paymentBy ? paymentBy.value : '');
        if (activeWallet) {
            state.activeWallet = activeWallet;
            applyWalletControlState(form, activeWallet);
        } else {
            resetWallet(form);
        }

        if (state.installed) {
            return;
        }

        state.installed = true;
        form.addEventListener('submit', () => {
            normalizeSubmission(form);
        }, true);
        form.addEventListener('change', (event) => {
            const target = event.target;
            if (!target || !target.name) {
                return;
            }

            if (
                (target.name === 'payment_method' && target.checked && target.value !== 'blink') ||
                (target.name === 'switchPayment' && target.checked && !getWalletFromPaymentBy(target.value))
            ) {
                resetWallet(form);
            }
        });
    };

    const observeApplePayButton = (getForm) => {
        let observer;

        const wrapApplePayButton = () => {
            const original = root.onApplePayButtonClicked;
            if (typeof original !== 'function') {
                return false;
            }
            if (original[appleWrapperMarker]) {
                return true;
            }

            const wrapped = function (...args) {
                const form = getForm();
                if (form) {
                    install(form);
                    setActiveWallet(form, 'apple');
                }

                return original.apply(this, args);
            };
            wrapped[appleWrapperMarker] = true;
            root.onApplePayButtonClicked = wrapped;

            return true;
        };

        if (wrapApplePayButton()) {
            return () => {};
        }

        observer = new root.MutationObserver(() => {
            if (wrapApplePayButton()) {
                observer.disconnect();
            }
        });
        observer.observe(root.document, { childList: true, subtree: true });

        return () => observer.disconnect();
    };

    // Initial absence means Blink is still loading. Removal is meaningful only
    // after Blink has inserted its Apple Pay button at least once.
    const observeApplePayButtonRemoval = (container, onRemoved) => {
        if (!container || !root.MutationObserver) {
            return () => {};
        }

        const buttonSelector = '#apple-pay-btn';
        let hasSeenButton = !!container.querySelector(buttonSelector);
        const notifyIfRemoved = () => {
            if (container.querySelector(buttonSelector)) {
                hasSeenButton = true;
                return false;
            }

            if (!hasSeenButton) {
                return false;
            }

            onRemoved();
            return true;
        };

        const observer = new root.MutationObserver(() => {
            if (notifyIfRemoved()) {
                observer.disconnect();
            }
        });
        observer.observe(container, { childList: true, subtree: true });

        return () => observer.disconnect();
    };

    return {
        detectActiveWallet,
        install,
        normalizeSubmission,
        observeApplePayButton,
        observeApplePayButtonRemoval,
        prepareCardPaymentToken,
        resetWallet,
        setActiveWallet
    };
}));
